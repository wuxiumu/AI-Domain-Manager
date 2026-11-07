<?php

require_once __DIR__ . '/../core/WorkspaceData.php';
require_once __DIR__ . '/../core/ConcurrentCurl.php';
require_once __DIR__ . '/SettingsService.php';

/**
 * Public-asset health inventory. Existing domain files are read-only inputs;
 * all new state is changed under a stable lock, with atomic replacement.
 */
class HealthService
{
    public const FRESHNESS_SECONDS = 86400;
    private const MAX_BATCH = 5;
    private const MAX_HISTORY = 10;
    /** 检查事件封顶（最新在前），防止日志随检查次数无限增长。 */
    private const MAX_EVENTS = 5000;
    private $dir;
    private $probe;
    private $clock;
    private $dnsLookup;
    private $httpProbe;
    /** 旧版主文件内嵌观测缓存：迁移兼容读取只解析一次，避免重建看板时按资产反复全量解析。 */
    private $legacyObservations;
    /** 并发探测时的 curl 调度器；null 表示阻塞模式。 */
    private $curlScheduler;

    public function __construct(?string $dataDir = null, ?callable $probe = null, ?callable $clock = null, ?callable $dnsLookup = null, ?callable $httpProbe = null)
    {
        $this->dir = $dataDir ?? DATA_DIR;
        $this->probe = $probe;
        $this->clock = $clock ?? static function () { return time(); };
        $this->dnsLookup = $dnsLookup;
        $this->httpProbe = $httpProbe;
    }

    public function dashboard(): array
    {
        // Keep injected-clock tests deterministic; production expires on the next
        // minute so health-age and calendar classifications remain accurate.
        if (!class_exists('SnapshotCache') || $this->probe || $this->dnsLookup || $this->httpProbe) { return $this->buildDashboard(); }
        return SnapshotCache::remember($this->dir, 'health-dashboard-v6',
            array_merge([$this->dir . '/domains.json', $this->dir . '/health.json', $this->dir . '/access_check.json', $this->dir . '/ip_locations.json'], glob($this->dir . '/cache/dns-*.json') ?: []),
            function () { return $this->buildDashboard(); }, time() + 60);
    }

    public function operations(): array
    {
        $dashboard = $this->dashboard();
        $pending = []; $unreachable = [];
        foreach ($dashboard['assets'] as $asset) {
            if ($asset['kind'] !== 'domain') { continue; }
            $health = $asset['health'];
            $https = $health['protocols']['https']['status'] ?? null;
            if (!empty($health['invalidated'])) { $https = null; }
            $item = ['id' => $asset['id'], 'domain' => $asset['name'], 'provider' => $asset['provider'],
                'checked_at' => $health['checked_at'] ?? null, 'health_status' => $health['status'],
                'https_status' => $https ?? 'unknown', 'resolved_ips' => $health['resolved_ips'] ?? [],
                'message' => $health['message'] ?? ''];
            if ($https !== 'healthy') { $pending[] = $item; }
            if (($health['last_status'] ?? null) === 'unhealthy') { $unreachable[] = $item; }
        }
        return ['ok' => true, 'generated_at' => $dashboard['generated_at'], 'pending_domains' => $pending,
            'unhealthy_domains' => $unreachable];
    }

    private function buildDashboard(): array
    {
        $state = $this->readState();
        $assets = $this->assets($state);
        $locations = class_exists('PrivateJsonStore') ? (new PrivateJsonStore($this->dir . '/ip_locations.json'))->read() : [];
        foreach ($assets as &$asset) {
            if ($asset['kind'] === 'ip') { $asset['location'] = $locations[$asset['ip']] ?? null; }
        }
        unset($asset);
        $summary = ['domains' => 0, 'ips' => 0, 'healthy' => 0, 'unhealthy' => 0, 'stale' => 0, 'unchecked' => 0, 'expiring_30' => 0];
        $last = null;
        foreach ($assets as $asset) {
            $summary[$asset['kind'] === 'domain' ? 'domains' : 'ips']++;
            $summary[$asset['health']['status']]++;
            $checked = $asset['health']['checked_at'];
            if ($checked && ($last === null || strcmp($checked, $last) > 0)) {
                $last = $checked;
            }
            if ($asset['expires_at']) {
                $expiry = strtotime($asset['expires_at'] . ' 23:59:59');
                if ($expiry !== false && $expiry >= $this->now() && $expiry <= $this->now() + 30 * 86400) {
                    $summary['expiring_30']++;
                }
            }
        }
        $domainAssets = array_filter($assets, static function ($a) { return $a['kind'] === 'domain'; });
        $linked = count(array_filter($domainAssets, static function ($a) { return count($a['linked_ips']) > 0; }));
        $confirmed = count(array_filter($domainAssets, static function ($a) { return count($a['bound_ips']) > 0; }));
        $summary['linked_domains'] = $linked;
        $summary['unlinked_domains'] = count($domainAssets) - $linked;
        $summary['confirmed_domains'] = $confirmed;
        $summary['unique_ips'] = count(array_unique(array_column(array_filter($assets, static function ($a) { return $a['kind'] === 'ip'; }), 'ip')));
        $summary['subdomains'] = array_sum(array_column($domainAssets, 'subdomain_count'));
        $summary['dns_cached_domains'] = count(array_filter($domainAssets, static function($a){return $a['dns_cached'] ?? false;}));
        $summary['dns_uncached_domains'] = count($domainAssets) - $summary['dns_cached_domains'];
        return ['ok' => true, 'generated_at' => $this->timestamp(), 'freshness_seconds' => self::FRESHNESS_SECONDS,
            'last_checked_at' => $last, 'summary' => $summary, 'assets' => array_values($assets)];
    }

    public function addIp(array $input): array
    {
        $ip = self::canonicalIp(trim((string) ($input['ip'] ?? '')));
        if (!$ip || !self::isPublicIp($ip)) {
            throw new InvalidArgumentException('请输入有效公网 IPv4 或 IPv6 地址；不支持内网、回环或保留地址');
        }
        $port = $input['port'] ?? 443;
        if (filter_var($port, FILTER_VALIDATE_INT) === false || (int) $port < 1 || (int) $port > 65535) {
            throw new InvalidArgumentException('端口必须是 1 至 65535 的整数');
        }
        $port = (int) $port;
        if (isset($input['name']) && !is_string($input['name'])) { throw new InvalidArgumentException('名称必须为文本'); }
        $name = trim($input['name'] ?? '');
        if (mb_strlen($name) > 120) {
            throw new InvalidArgumentException('名称不能超过 120 个字符');
        }
        $entry = ['id' => self::ipId($ip, $port), 'ip' => $ip, 'port' => $port,
            'name' => $name !== '' ? $name : $ip, 'note' => self::note($input['note'] ?? ''),
            'links' => self::links($input['links'] ?? []), 'created_at' => $this->timestamp()];
        $this->transaction(function (&$state) use ($entry) {
            if (isset($state['manual_ips'][$entry['id']])) {
                throw new DomainException('此 IP 资产已经添加');
            }
            $state['manual_ips'][$entry['id']] = $entry;
        });
        $assets = $this->assets($this->readState());
        return ['ok' => true, 'asset' => $assets[$entry['id']]];
    }

    public function deleteIp(string $id): array
    {
        $this->transaction(function (&$state) use ($id) {
            if (!isset($state['manual_ips'][$id])) {
                throw new OutOfBoundsException('只能删除手工添加的 IP，或该 IP 已不存在');
            }
            unset($state['manual_ips'][$id]);
            foreach ($state['bindings'] ?? [] as $domainId => $links) {
                unset($state['bindings'][$domainId][$id]);
            }
            // Keep observations: the same address may still be referenced by DNS.
        });
        return ['ok' => true];
    }

    /** Metadata editing also lets a discovered endpoint become a named server. */
    public function updateIp(string $id, array $input): array
    {
        $assets = $this->assets($this->readState());
        if (!isset($assets[$id]) || $assets[$id]['kind'] !== 'ip') { throw new OutOfBoundsException('IP 资产不存在'); }
        $asset = $assets[$id];
        $name = trim((string) ($input['name'] ?? $asset['name']));
        $note = self::note($input['note'] ?? $asset['note'] ?? '');
        $links = self::links($input['links'] ?? $asset['links'] ?? []);
        $provider = trim((string) ($input['server_provider'] ?? $asset['server_provider'] ?? ''));
        $configuration = self::note($input['configuration'] ?? $asset['configuration'] ?? '');
        $expires = trim((string) ($input['expires_at'] ?? $asset['expires_at'] ?? ''));
        if (mb_strlen($name) > 120 || mb_strlen($note) > 2000 || mb_strlen($provider) > 120) {
            throw new InvalidArgumentException('名称、供应商或备注超出长度限制');
        }
        $date = $expires === '' ? null : DateTime::createFromFormat('!Y-m-d', $expires);
        if ($expires !== '' && (!$date || $date->format('Y-m-d') !== $expires)) {
            throw new InvalidArgumentException('请输入有效的到期日期');
        }
        $this->transaction(function (&$state) use ($id, $asset, $name, $note, $links, $provider, $expires, $configuration) {
            $state['manual_ips'][$id] = array_replace($state['manual_ips'][$id] ?? [], ['id' => $id, 'ip' => $asset['ip'], 'port' => $asset['port'],
                'name' => $name ?: $asset['ip'], 'note' => $note, 'links' => $links, 'server_provider' => $provider,
                'expires_at' => $expires, 'configuration' => $configuration, 'updated_at' => $this->timestamp()]);
        });
        return ['ok' => true];
    }

    private static function note($value): string
    {
        if (!is_string($value) || mb_strlen($value) > 2000) { throw new InvalidArgumentException('备注需为不超过 2000 个字符的文本'); }
        return trim($value);
    }

    public static function links($value): array
    {
        if (!is_array($value) || count($value) > 30) { throw new InvalidArgumentException('网站链接最多添加 30 项'); }
        $links = [];
        foreach ($value as $link) {
            if (!is_array($link) || !is_string($link['name'] ?? null) || !is_string($link['url'] ?? null)) {
                throw new InvalidArgumentException('网站链接需包含名称和 URL');
            }
            $name = trim($link['name']); $url = trim($link['url']);
            $parts = parse_url($url);
            if ($name === '' || mb_strlen($name) > 120 || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)
                || !$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException('请填写网站名称（最多 120 字）和有效的 http(s):// 网站 URL');
            }
            $links[] = ['name' => $name, 'url' => $url];
        }
        return $links;
    }

    public function saveBindings(string $domainId, array $input): array
    {
        $assets = $this->assets($this->readState());
        if (!isset($assets[$domainId]) || $assets[$domainId]['kind'] !== 'domain') { throw new OutOfBoundsException('域名不存在'); }
        $ids = $input['ip_ids'] ?? null;
        if (!is_array($ids) || count($ids) > 100) { throw new InvalidArgumentException('绑定 IP 列表无效'); }
        $links = [];
        foreach ($ids as $id) {
            if (!is_string($id) || !isset($assets[$id]) || $assets[$id]['kind'] !== 'ip') { throw new InvalidArgumentException('绑定中包含不存在的 IP 资产'); }
            $links[$id] = ['ip' => $assets[$id]['ip'], 'port' => $assets[$id]['port'], 'confirmed_at' => $this->timestamp()];
        }
        $this->transaction(function (&$state) use ($domainId, $links, $assets) {
            // Preserve confirmed targets even when a later DNS result changes.
            foreach ($links as $id => $link) {
                if (!isset($state['manual_ips'][$id])) {
                    $state['manual_ips'][$id] = ['id' => $id, 'ip' => $link['ip'], 'port' => $link['port'], 'name' => $assets[$id]['name']];
                }
            }
            $state['bindings'][$domainId] = $links;
        });
        return ['ok' => true];
    }

    public function invalidateDnsHealth(string $provider, string $name): void
    {
        $targets = [];
        foreach ($this->assets($this->readState()) as $asset) {
            if ($asset['kind'] !== 'domain' || $asset['provider'] !== $provider || $asset['name'] !== strtolower($name)) continue;
            $targets[] = $asset['id'];
            foreach ($asset['subdomains'] ?? [] as $host) $targets[] = $host['id'];
        }
        $this->transaction(function(&$state) use($targets){
            foreach ($targets as $id) {
                if (!isset($state['observations'][$id]['health'])) continue;
                $state['observations'][$id]['health']['invalidated'] = true;
                $state['observations'][$id]['health']['resolved_ips'] = [];
            }
        });
    }

    /**
     * 数据清理：收集某账号一批域名的观测 id（主域名 + 子域名）。
     * 资产表优先；孤儿账号（不在展示资产表时）退回直查 id 哈希，子域名清单来自尚未删除的 DNS 缓存。
     * @return string[]
     */
    private function cleanupTargetIds(string $provider, array $domains): array
    {
        $ids = [];
        foreach ($this->assets($this->readState()) as $asset) {
            if ($asset['kind'] !== 'domain' || $asset['provider'] !== $provider) { continue; }
            if (!in_array($asset['name'], $domains, true)) { continue; }
            $ids[] = $asset['id'];
            foreach ($asset['subdomains'] ?? [] as $host) { $ids[] = $host['id']; }
        }
        // 资产表可能不含孤儿账号的行（同名域名优先展示在用账号），或缺少子域名清单；
        // 直查 id 哈希并从待删的 DNS 缓存提取子域名，保证两类来源合并成完整超集。
        foreach ($domains as $name) {
            $ids[] = 'domain_' . substr(hash('sha256', $provider . '|' . $name), 0, 24);
            $cached = class_exists('DnsRecordCache') ? DnsRecordCache::peek($this->dir, $provider, $name) : null;
            foreach ($cached['records'] ?? [] as $record) {
                $fqdn = strtolower(rtrim(trim((string) ($record['fqdn'] ?? '')), '.'));
                if ($fqdn === '' || $fqdn === $name) { continue; }
                $ids[] = 'host_' . substr(hash('sha256', $provider . '|' . $fqdn), 0, 24);
            }
        }
        return array_values(array_unique($ids));
    }

    /** 数据清理：删除某账号一批域名（含子域名）的观测、绑定与检查事件。返回清理数量。 */
    public function forgetDomainArtifacts(string $provider, array $domains): array
    {
        $domains = array_values(array_unique(array_filter(array_map(
            static fn ($d): string => strtolower(rtrim(trim((string) $d), '.')),
            $domains
        ), static fn ($d): bool => $d !== '')));
        if (!$domains) { return ['observations_removed' => 0, 'events_removed' => 0, 'bindings_removed' => 0]; }
        $ids = $this->cleanupTargetIds($provider, $domains);
        $idSet = array_fill_keys($ids, true);
        $domainSet = array_fill_keys($domains, true);
        $removed = ['observations_removed' => 0, 'events_removed' => 0, 'bindings_removed' => 0];
        $this->transaction(function (&$state) use ($idSet, $domainSet, $provider, &$removed) {
            foreach (array_keys($idSet) as $id) {
                if (isset($state['observations'][$id])) { unset($state['observations'][$id]); $removed['observations_removed']++; }
                if (isset($state['bindings'][$id])) { unset($state['bindings'][$id]); $removed['bindings_removed']++; }
                if (is_file($this->historyPath((string) $id))) { @unlink($this->historyPath((string) $id)); $removed['history_removed']++; }
            }
            $kept = [];
            foreach ($state['check_events'] ?? [] as $event) {
                $sameDomain = (($event['provider'] ?? '') === $provider) && isset($domainSet[strtolower((string) ($event['domain'] ?? ''))]);
                if (isset($idSet[(string) ($event['asset_id'] ?? '')]) || $sameDomain) { $removed['events_removed']++; continue; }
                $kept[] = $event;
            }
            $state['check_events'] = $kept;
        });
        return $removed;
    }

    /** 数据清理：按资产（账号+域名）/时间/全部修剪检查历史，保留各资产最近一次健康状态。 */
    public function trimCheckHistory(array $filter): array
    {
        $all = (bool) ($filter['all'] ?? false);
        $before = null;
        if (!$all && isset($filter['before'])) {
            $before = strtotime((string) $filter['before']);
            if ($before === false) { throw new InvalidArgumentException('时间格式不正确'); }
        }
        $provider = isset($filter['provider']) && (string) $filter['provider'] !== '' ? (string) $filter['provider'] : null;
        $domains = array_values(array_unique(array_filter(array_map(
            static fn ($d): string => strtolower(rtrim(trim((string) $d), '.')),
            (array) ($filter['domains'] ?? [])
        ), static fn ($d): bool => $d !== '')));
        $idSet = array_fill_keys($provider !== null ? $this->cleanupTargetIds($provider, $domains) : [], true);
        if ($provider === null && $domains) {
            foreach ($this->assets($this->readState()) as $asset) {
                if ($asset['kind'] !== 'domain' || !in_array($asset['name'], $domains, true)) { continue; }
                $idSet[$asset['id']] = true;
                foreach ($asset['subdomains'] ?? [] as $host) { $idSet[$host['id']] = true; }
            }
        }
        $domainSet = array_fill_keys($domains, true);
        $removed = ['events_removed' => 0, 'history_removed' => 0];
        $this->transaction(function (&$state) use ($all, $before, $idSet, $domainSet, $provider, &$removed) {
            $kept = [];
            foreach ($state['check_events'] ?? [] as $event) {
                $time = strtotime((string) ($event['checked_at'] ?? ''));
                if (is_int($before) && $time !== false && $time >= $before) { $kept[] = $event; continue; }
                if (!$all && $before === null) {
                    $sameDomain = ($provider === null || ($event['provider'] ?? '') === $provider) && isset($domainSet[strtolower((string) ($event['domain'] ?? ''))]);
                    if (!isset($idSet[(string) ($event['asset_id'] ?? '')]) && !$sameDomain) { $kept[] = $event; continue; }
                }
                $removed['events_removed']++;
            }
            $state['check_events'] = $kept;
            foreach (array_keys(($state['observations'] ?? [])) as $id) {
                $targeted = $all || isset($idSet[(string) $id]);
                if (!$all && $before === null && !$targeted) { continue; }
                $history = $this->historyOf((string) $id, $state);
                $keptHistory = [];
                foreach ($history as $entry) {
                    $time = strtotime((string) ($entry['checked_at'] ?? ''));
                    if (is_int($before) && $time !== false && $time >= $before) { $keptHistory[] = $entry; continue; }
                    $removed['history_removed']++;
                }
                // 历史已拆分为独立小文件：修剪后写回或删除文件，主文件不再内嵌历史。
                if ($keptHistory) { $this->writeHistory((string) $id, $keptHistory); }
                else { @unlink($this->historyPath((string) $id)); }
                if (isset($state['observations'][$id]) && is_array($state['observations'][$id])) { unset($state['observations'][$id]['history']); }
            }
        });
        return $removed;
    }

    public function check(array $ids): array
    {
        $epoch = WorkspaceData::locked($this->dir, function () { return WorkspaceData::epoch($this->dir); });
        if (!$ids || count($ids) > self::MAX_BATCH) {
            throw new InvalidArgumentException('每批请选择 1 至 5 个资产');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || $id === '') {
                throw new InvalidArgumentException('资产 ID 格式不正确');
            }
        }
        $ids = array_values(array_unique($ids));
        $assets = $this->assets($this->readState());
        foreach (array_values($assets) as $asset) {
            if ($asset['kind'] !== 'domain') continue;
            foreach ($asset['subdomains'] ?? [] as $host) {
                if ($host['checkable']) $assets[$host['id']] = $host;
            }
        }
        foreach ($ids as $id) {
            if (!isset($assets[$id])) {
                throw new OutOfBoundsException('资产不存在，请刷新后再检测');
            }
        }
        $observations = [];
        if ($this->probe !== null || $this->httpProbe !== null || !ConcurrentCurl::supported()) {
            // 注入探测器/HTTP 传输（离线测试）或环境不支持 Fiber：保持串行路径，行为与历史版本一致。
            foreach ($ids as $id) {
                $observations[$id] = $this->probeOne($assets[$id]);
            }
        } else {
            // 并发探测：同一资产的请求序列（HTTP→重试→HTTPS）仍串行，跨资产并行；
            // 句柄并发数由批量大小决定（≤ MAX_BATCH ≤ MAX_CONCURRENCY），结果结构与串行完全一致。
            $jobs = [];
            foreach ($ids as $id) {
                $jobs[$id] = function (ConcurrentCurl $scheduler) use ($assets, $id) {
                    $this->curlScheduler = $scheduler;
                    return $this->probeOne($assets[$id]);
                };
            }
            try {
                $outcomes = ConcurrentCurl::run($jobs);
            } finally {
                $this->curlScheduler = null;
            }
            foreach ($outcomes as $id => $outcome) {
                $observations[$id] = $outcome['ok'] ? $outcome['value']
                    : ['status' => 'unhealthy', 'message' => '检测未完成：' . $outcome['error']->getMessage(),
                        'checks' => [['label' => '检测', 'status' => 'unhealthy', 'message' => $outcome['error']->getMessage()]], 'resolved_ips' => []];
            }
        }
        $histories = [];
        $this->transaction(function (&$state) use ($observations, $assets, &$histories, $epoch) {
            if ($epoch !== WorkspaceData::epoch($this->dir)) { throw new DomainException('检查期间资产已恢复，本批结果已丢弃，请重新检查'); }
            foreach ($observations as $id => $health) {
                $history = $this->historyOf($id, $state);
                array_unshift($history, $health);
                $kept = array_slice($history, 0, self::MAX_HISTORY);
                // 历史写入独立小文件：一批检查只触碰被检查资产，不再全量重写台账。
                $this->writeHistory($id, $kept);
                $histories[$id] = $kept;
                $state['observations'][$id] = ['health' => $health];
                $asset = $assets[$id];
                $event = ['id' => bin2hex(random_bytes(12)), 'asset_id' => $id, 'kind' => $asset['kind'],
                    'name' => $asset['kind'] === 'ip' ? $asset['ip'] : $asset['name'], 'provider' => $asset['provider'] ?? '',
                    'domain' => $asset['domain'] ?? '', 'checked_at' => $health['checked_at'],
                    'status' => $health['status'], 'message' => $health['message'], 'checks' => $health['checks']];
                array_unshift($state['check_events'], $event);
            }
        });
        $results = [];
        foreach ($observations as $id => $health) {
            $results[] = ['id' => $id, 'health' => $health, 'history' => $histories[$id]];
        }
        return ['ok' => true, 'checked' => count($results), 'results' => $results];
    }

    /** 单资产探测与结果规整（串行/并发共用，保证结果结构一致）。 */
    private function probeOne(array $asset): array
    {
        $started = microtime(true);
        try {
            $health = $this->probe ? call_user_func($this->probe, $asset) : $this->probeAsset($asset);
            if (!is_array($health) || !in_array($health['status'] ?? '', ['healthy', 'unhealthy'], true)) {
                throw new RuntimeException('探测器没有返回有效状态');
            }
        } catch (Throwable $e) {
            $health = ['status' => 'unhealthy', 'message' => '检测未完成：' . $e->getMessage(),
                'checks' => [['label' => '检测', 'status' => 'unhealthy', 'message' => $e->getMessage()]], 'resolved_ips' => []];
        }
        $health['last_status'] = $health['status'];
        $health['checked_at'] = $this->timestamp();
        $health['latency_ms'] = $health['latency_ms'] ?? (int) round((microtime(true) - $started) * 1000);
        $health['message'] = (string) ($health['message'] ?? '');
        $health['checks'] = $health['checks'] ?? [];
        $health['resolved_ips'] = array_values(array_unique(array_filter($health['resolved_ips'] ?? [], [self::class, 'isPublicIp'])));
        $health['source'] = 'verified';
        if ($asset['kind'] === 'ip') { $health['check_type'] = 'http'; }
        else { $health['check_type'] = 'http_https'; }
        return $health;
    }

    /** 在用账号集合（已配置且未停用）；配置不可读时返回 null，保持不去重的旧行为。 */
    private function activeProviders(): ?array
    {
        try {
            $settings = new SettingsService(defined('CONFIG_DIR') ? CONFIG_DIR : $this->dir, $this->dir);
            $active = [];
            foreach ($settings->all()['accounts'] ?? [] as $account) {
                if (is_array($account) && isset($account['id']) && ($account['enabled'] ?? true) !== false) {
                    $active[(string) $account['id']] = true;
                }
            }
            return $active;
        } catch (Throwable) {
            return null;
        }
    }

    /** 同一域名多账号时优先在用账号的台账行，避免已移除账号的重复与历史残留进入展示。 */
    private function preferredDomains(array $domains, ?array $active): array
    {
        if ($active === null) {
            return $domains;
        }
        $groups = [];
        foreach ($domains as $domain) {
            if (!is_array($domain) || empty($domain['domain'])) {
                continue;
            }
            $groups[strtolower(rtrim(trim((string) $domain['domain']), '.'))][] = $domain;
        }
        $preferred = [];
        foreach ($groups as $rows) {
            $pick = $rows[0];
            foreach ($rows as $row) {
                $inUse = isset($active[(string) ($row['provider'] ?? '')]);
                $valid = ($row['valid'] ?? true) !== false;
                if ($inUse && $valid) { $pick = $row; break; }
            }
            if (!isset($active[(string) ($pick['provider'] ?? '')])) {
                foreach ($rows as $row) {
                    if (isset($active[(string) ($row['provider'] ?? '')])) { $pick = $row; break; }
                }
            }
            $preferred[] = $pick;
        }
        return $preferred;
    }

    private function assets(array $state): array
    {
        $assets = [];
        $legacy = $this->readJson($this->dir . '/access_check.json', [])['results'] ?? [];
        $preferredDomains = $this->preferredDomains($this->readJson($this->dir . '/domains.json', []), $this->activeProviders());
        foreach ($preferredDomains as $domain) {
            if (!is_array($domain) || ($domain['valid'] ?? true) === false || empty($domain['domain'])) {
                continue;
            }
            $name = strtolower(rtrim(trim((string) $domain['domain']), '.'));
            $provider = (string) ($domain['provider'] ?? 'manual');
            $id = 'domain_' . substr(hash('sha256', $provider . '|' . $name), 0, 24);
            $health = $this->healthOf($id, $state);
            $history = $this->historyOf($id, $state);
            $old = $legacy[$provider . '|' . $domain['domain']] ?? null;
            if ($health['status'] === 'unchecked' && is_array($old)) {
                $health['legacy'] = ['source' => 'legacy', 'checked_at' => $old['checked_at'] ?? null,
                    'status' => $old['status'] ?? 'unknown', 'http_code' => $old['http_code'] ?? null];
                $health['status'] = 'stale';
                $health['last_status'] = ($old['status'] ?? '') === 'ok' ? 'healthy' : (($old['status'] ?? '') === 'fail' ? 'unhealthy' : null);
                $health['checked_at'] = $old['checked_at'] ?? null;
                $health['latency_ms'] = $old['ms'] ?? null;
                $health['source'] = 'legacy';
                $health['message'] = '旧版 HTTP 历史记录，未验证 TLS 证书，需重新检查';
                $health['checks'] = [['label' => '历史 HTTP', 'status' => 'stale',
                    'message' => 'HTTP ' . ($old['http_code'] ?? '未知') . '；未验证 TLS 证书，不代表当前健康']];
                $history = [$health];
            }
            $assets[$id] = ['id' => $id, 'kind' => 'domain', 'name' => $name, 'provider' => $provider,
                'provider_name' => (string) ($domain['provider_name'] ?? $provider),
                'source' => $provider === 'manual' ? 'manual' : 'legacy', 'linked_domains' => [],
                'expires_at' => $domain['expire_at'] ?? null, 'note' => $domain['note'] ?? '',
                'linked_ips' => [], 'bound_ips' => [], 'health' => $health,
                'history' => $history];
        }
        foreach ($state['manual_ips'] as $id => $entry) {
            if (!is_array($entry) || !self::isPublicIp($entry['ip'] ?? '')) {
                continue;
            }
            $assets[$id] = $this->ipAsset($entry, 'manual', $state);
        }
        // Only current domain assets create DNS-derived addresses. These are
        // observed endpoints, never asserted to be the origin server.
        foreach (array_values($assets) as $domain) {
            if ($domain['kind'] !== 'domain') {
                continue;
            }
            foreach ($domain['health']['resolved_ips'] as $ip) {
                if (!self::isPublicIp($ip)) {
                    continue;
                }
                $ip = self::canonicalIp($ip);
                $id = self::ipId($ip, 443);
                if (!isset($assets[$id])) {
                    $assets[$id] = $this->ipAsset(['id' => $id, 'ip' => $ip, 'port' => 443, 'name' => $ip], 'dns', $state);
                }
                if (!in_array($domain['name'], $assets[$id]['linked_domains'], true)) {
                    $assets[$id]['linked_domains'][] = $domain['name'];
                }
                $assets[$id]['association_note'] = '来自域名 DNS 解析，可能为 CDN 或代理地址，不等于源站';
                $assets[$domain['id']]['linked_ips'][] = $id;
            }
        }
        foreach ($state['bindings'] ?? [] as $domainId => $links) {
            if (!isset($assets[$domainId]) || $assets[$domainId]['kind'] !== 'domain') { continue; }
            foreach ($links as $ipId => $link) {
                if (!isset($assets[$ipId]) || $assets[$ipId]['kind'] !== 'ip') { continue; }
                $assets[$domainId]['bound_ips'][] = $ipId;
                $assets[$ipId]['bound_domains'][] = $domainId;
                $assets[$domainId]['linked_ips'][] = $ipId;
            }
            $assets[$domainId]['linked_ips'] = array_values(array_unique($assets[$domainId]['linked_ips']));
        }
        if (class_exists('DomainInventory')) {
            DomainInventory::enrich($assets, $this->dir,
                function($id) use($state){return $this->healthOf($id,$state);},
                function($entry) use($state){return $this->ipAsset($entry,'dns',$state);});
        }
        return $assets;
    }

    private function ipAsset(array $entry, string $source, array $state): array
    {
        $id = $entry['id'];
        return ['id' => $id, 'kind' => 'ip', 'name' => $entry['name'], 'ip' => $entry['ip'], 'port' => (int) $entry['port'],
            'provider' => 'manual', 'provider_name' => $source === 'manual' ? '手工添加' : 'DNS 解析发现',
            'source' => $source, 'linked_domains' => [], 'bound_domains' => [],
            'linked_subdomains' => [], 'dns_links' => [],
            'server_provider' => $entry['server_provider'] ?? '', 'configuration' => $entry['configuration'] ?? '', 'note' => $entry['note'] ?? '', 'links' => $entry['links'] ?? [],
            'expires_at' => $entry['expires_at'] ?? null, 'health' => $this->healthOf($id, $state),
            'history' => $this->historyOf($id, $state)];
    }

    private function healthOf(string $id, array $state): array
    {
        $health = $state['observations'][$id]['health'] ?? null;
        if (!is_array($health)) {
            return ['status' => 'unchecked', 'last_status' => null, 'checked_at' => null, 'latency_ms' => null,
                'message' => '尚未检测', 'checks' => [], 'resolved_ips' => []];
        }
        $health['last_status'] = $health['last_status'] ?? $health['status'];
        if (strpos($id, 'ip_') === 0 && ($health['check_type'] ?? '') !== 'http') {
            $health['status'] = 'stale';
            $health['message'] = '旧版端口检查记录，请重新执行 HTTP 访问检查';
            return $health;
        }
        if ((strpos($id, 'domain_') === 0 || strpos($id, 'host_') === 0) && ($health['source'] ?? '') === 'verified' && ($health['check_type'] ?? '') !== 'http_https') {
            $health['status'] = 'stale';
            $health['message'] = '旧版域名检查未包含 HTTP 和 HTTPS，请重新检查';
            return $health;
        }
        $checked = strtotime($health['checked_at'] ?? '');
        if (!empty($health['invalidated']) || !$checked || $this->now() - $checked >= self::FRESHNESS_SECONDS || $checked > $this->now() + 300) {
            $health['status'] = 'stale';
            $health['message'] = (!empty($health['invalidated']) ? 'DNS 已更新，请重新检查；上次结果：' : '检测结果已过时；上次结果：') . ($health['message'] ?? '未知');
        }
        return $health;
    }

    private function probeAsset(array $asset): array
    {
        return $asset['kind'] === 'ip' ? $this->probeIp($asset) : $this->probeDomain($asset['name']);
    }

    private function probeIp(array $asset): array
    {
        $ip = $asset['ip'];
        if (!self::isPublicIp($ip)) {
            throw new RuntimeException('拒绝探测非公网 IP');
        }
        $start = microtime(true);
        $response = $this->request($ip, $ip, true, 4000, 'http');
        if (in_array($response['code'], [405, 501], true) && $response['errno'] === 0) {
            $remaining = 4000 - (int) round((microtime(true) - $start) * 1000);
            if ($remaining > 100) { $response = $this->request($ip, $ip, false, $remaining, 'http'); }
        }
        $ok = $response['errno'] === 0 && $response['code'] >= 200 && $response['code'] < 400;
        $url = 'http://' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip) . '/';
        $message = $response['errno'] !== 0 ? 'HTTP 访问失败：' . $response['error'] : 'HTTP 返回 ' . $response['code'];
        if ($response['code'] >= 300 && $response['code'] < 400 && $response['errno'] === 0) { $message .= '（未跟随重定向）'; }
        return ['status' => $ok ? 'healthy' : 'unhealthy', 'message' => $message,
            'latency_ms' => $response['ms'], 'http_code' => $response['code'], 'url' => $url,
            'checks' => [['label' => 'HTTP', 'status' => $ok ? 'healthy' : 'unhealthy', 'message' => $url . ' · ' . $message]],
            'resolved_ips' => []];
    }

    private function probeDomain(string $name): array
    {
        $host = self::normalizeHost($name);
        if ($host === null) {
            return $this->dnsFailure('域名格式无效，未发起网络请求');
        }
        $ips = [];
        $seen = [];
        $pending = [$host];
        $blocked = 0;
        $dnsDeadline = microtime(true) + 4.0;
        // Follow a bounded CNAME chain, but connect only to validated public IPs.
        for ($i = 0; $pending && $i < 6; $i++) {
            $lookup = array_shift($pending);
            if (isset($seen[$lookup])) {
                continue;
            }
            $seen[$lookup] = true;
            if (!$this->dnsLookup && microtime(true) >= $dnsDeadline) {
                return $this->dnsFailure('DNS 查询超时，请稍后重试');
            }
            try {
                $records = $this->dnsLookup ? call_user_func($this->dnsLookup, $lookup)
                    : DnsResolver::lookup($lookup, $dnsDeadline - microtime(true));
            } catch (RuntimeException $e) {
                return $this->dnsFailure($e->getMessage());
            }
            if (!is_array($records)) {
                continue;
            }
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if ($ip !== null) {
                    if (self::isPublicIp($ip)) {
                        $ips[] = self::canonicalIp($ip);
                    } else {
                        $blocked++;
                    }
                }
                if (($record['type'] ?? '') === 'CNAME') {
                    $alias = self::normalizeHost($record['target'] ?? '');
                    if ($alias && !isset($seen[$alias])) {
                        $pending[] = $alias;
                    }
                }
            }
            if ($ips) { break; }
        }
        $ips = array_values(array_unique($ips));
        if (!$ips) {
            return $this->dnsFailure($blocked ? 'DNS 返回非公网或保留地址，已拒绝探测' : 'DNS 未返回可用的公网 A / AAAA 地址');
        }
        // Prefer IPv4 for hosts without IPv6 connectivity; record all addresses.
        usort($ips, static function ($a, $b) { return (int) (strpos($a, ':') !== false) <=> (int) (strpos($b, ':') !== false); });
        $checks = [['label' => 'DNS', 'status' => 'healthy', 'message' => implode(', ', $ips) . ($blocked ? '；已过滤非公网地址' : '')]];
        $results = [];
        foreach (['http', 'https'] as $scheme) {
            $started = microtime(true);
            $response = $this->request($host, $ips[0], true, 4000, $scheme);
            if (in_array($response['code'], [405, 501], true) && $response['errno'] === 0) {
                $remaining = 4000 - (int) round((microtime(true) - $started) * 1000);
                if ($remaining > 100) { $response = $this->request($host, $ips[0], false, $remaining, $scheme); }
            }
            $ok = $response['errno'] === 0 && $response['code'] >= 200 && $response['code'] < 400;
            $label = strtoupper($scheme);
            $message = $response['errno'] !== 0 ? $label . ' 连接或证书验证失败：' . $response['error'] : $label . ' 返回 ' . $response['code'];
            if ($response['errno'] === 0 && $response['code'] >= 300 && $response['code'] < 400) { $message .= '（未跟随重定向）'; }
            $checks[] = ['label' => $label, 'status' => $ok ? 'healthy' : 'unhealthy', 'message' => $message];
            $results[$scheme] = ['status' => $ok ? 'healthy' : 'unhealthy', 'code' => $response['code'], 'latency_ms' => $response['ms']];
        }
        // 可达性以 HTTP 为准：HTTPS 证书或连接问题单独记录，不改变可达性判定（在 HTTPS 跟进清单中处理）。
        $httpOk = $results['http']['status'] === 'healthy';
        $httpsOk = $results['https']['status'] === 'healthy';
        $message = $httpOk
            ? ($httpsOk ? 'HTTP 和 HTTPS 均正常' : 'HTTP 可访问；HTTPS 待跟进（' . ($checks[2]['message'] ?? '未通过') . '）')
            : 'HTTP 检查异常';
        return ['status' => $httpOk ? 'healthy' : 'unhealthy', 'message' => $message,
            'http_code' => $results['http']['code'], 'protocols' => $results, 'checks' => $checks,
            'url' => 'http://' . strtolower($host) . '/', 'https_url' => 'https://' . strtolower($host) . '/',
            'resolved_ips' => $ips, 'probed_ip' => $ips[0], 'latency_ms' => $results['http']['latency_ms'] + $results['https']['latency_ms']];
    }

    /** 记录浏览器侧探测结果：可达计入健康状态并标注来源 browser；不可达不改变既有证据。 */
    public function recordBrowserProbe(string $id, bool $reachable, int $ms): array
    {
        if (!$reachable) {
            return ['ok' => true, 'recorded' => false, 'message' => '浏览器侧也未可达，不改变既有记录'];
        }
        $epoch = WorkspaceData::epoch($this->dir);
        $this->transaction(function (&$state) use ($id, $ms, $epoch) {
            if ($epoch !== WorkspaceData::epoch($this->dir)) { throw new DomainException('记录期间资产已恢复，本条结果已丢弃，请重新检测'); }
            $assets = $this->assets($state);
            if (!isset($assets[$id])) { throw new OutOfBoundsException('资产不存在，请刷新后再检测'); }
            if ($assets[$id]['kind'] !== 'ip') { throw new InvalidArgumentException('浏览器探测仅支持 IP 资产'); }
            $ip = $assets[$id]['ip'];
            $url = 'http://' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip) . '/';
            $health = ['status' => 'healthy',
                'message' => '浏览器探测可达：HTTP 有响应（经管理员浏览器网络，可能经系统代理）',
                'checked_at' => $this->timestamp(), 'latency_ms' => $ms, 'http_code' => null, 'url' => $url,
                'checks' => [['label' => 'HTTP（浏览器）', 'status' => 'healthy', 'message' => $url . ' · 浏览器探测可达 · ' . $ms . ' ms']],
                'resolved_ips' => [], 'source' => 'browser', 'check_type' => 'http'];
            $history = $this->historyOf($id, $state);
            array_unshift($history, $health);
            $this->writeHistory($id, $history);
            $state['observations'][$id] = ['health' => $health];
            $event = ['id' => bin2hex(random_bytes(12)), 'asset_id' => $id, 'kind' => 'ip', 'name' => $ip,
                'provider' => $assets[$id]['provider'] ?? '', 'domain' => '', 'checked_at' => $health['checked_at'],
                'status' => 'healthy', 'message' => $health['message'], 'checks' => $health['checks']];
            array_unshift($state['check_events'], $event);
        });
        return ['ok' => true, 'recorded' => true];
    }

    private function dnsFailure(string $message): array
    {
        return ['status' => 'unhealthy', 'message' => $message, 'resolved_ips' => [],
            'checks' => [['label' => 'DNS', 'status' => 'unhealthy', 'message' => $message],
                ['label' => 'HTTP', 'status' => 'unchecked', 'message' => 'DNS 检查未通过'],
                ['label' => 'HTTPS', 'status' => 'unchecked', 'message' => 'DNS 检查未通过']]];
    }

    private function request(string $host, string $ip, bool $head, int $timeoutMs, string $scheme = 'https'): array
    {
        return $this->httpProbe ? call_user_func($this->httpProbe, $host, $ip, $head, $timeoutMs, $scheme)
            : $this->httpRequest($host, $ip, $head, $timeoutMs, $scheme);
    }

    private function httpRequest(string $host, string $ip, bool $head, int $timeoutMs, string $scheme): array
    {
        if (!self::isPublicIp($ip) || (!in_array($scheme, ['http', 'https'], true) || ($host !== $ip && self::normalizeHost($host) !== $host) || ($scheme === 'https' && $host === $ip))) {
            throw new RuntimeException('拒绝不安全的探测目标');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('服务器缺少 cURL 扩展');
        }
        $urlHost = strpos($host, ':') !== false ? '[' . $host . ']' : $host;
        $ch = curl_init($scheme . '://' . $urlHost . '/');
        $bytes = 0;
        $limited = false;
        $pinned = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => $host !== $ip ? [$host . ':' . ($scheme === 'https' ? '443' : '80') . ':' . $pinned] : [], CURLOPT_PROXY => '',
            CURLOPT_NOBODY => $head, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT_MS => min(2000, $timeoutMs), CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'AssetWorkbenchHealth/1.0', CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function ($handle, $body) use (&$bytes, &$limited) {
                $bytes += strlen($body);
                if ($bytes > 65536) { $limited = true; return 0; }
                return strlen($body);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, $scheme === 'http' ? CURLPROTO_HTTP : CURLPROTO_HTTPS);
        }
        if (!$head) {
            curl_setopt($ch, CURLOPT_RANGE, '0-1023');
        }
        $result = $this->execCurl($ch);
        // A bounded GET intentionally stops reading large bodies after headers.
        if ($limited && $result['errno'] === CURLE_WRITE_ERROR && $result['code'] > 0) {
            $result['errno'] = 0;
            $result['error'] = '';
        }
        curl_close($ch);
        return $result;
    }

    /** 发起 HTTP 请求：无并发任务时阻塞执行；并发任务内经调度器挂起，句柄完成后恢复。 */
    private function execCurl($ch): array
    {
        $scheduler = $this->curlScheduler ?? new ConcurrentCurl();
        return $scheduler->exec($ch);
    }

    public static function normalizeHost(string $host): ?string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if (preg_match('/[^\x00-\x7f]/', $host)) {
            if (!function_exists('idn_to_ascii')) { return null; }
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($host === false) { return null; }
        }
        if (strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) || strpos($host, '.') === false) { return null; }
        foreach (explode('.', $host) as $label) {
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label)) { return null; }
        }
        return $host;
    }

    public static function isPublicIp($ip): bool
    {
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (strpos($ip, ':') !== false) {
            // Global unicast only; reject transition/translation, documentation,
            // special-purpose prefixes and IPv4-mapped forms.
            if (!self::inCidr($ip, '2000::', 3)) { return false; }
            foreach ([['2001::', 23], ['2001:db8::', 32], ['2002::', 16], ['3fff::', 20]] as $range) {
                if (self::inCidr($ip, $range[0], $range[1])) { return false; }
            }
            return true;
        }
        foreach ([['0.0.0.0', 8], ['100.64.0.0', 10], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24],
            ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as $range) {
            if (self::inCidr($ip, $range[0], $range[1])) { return false; }
        }
        return true;
    }

    private static function inCidr(string $ip, string $network, int $bits): bool
    {
        $packed = @inet_pton($ip); $base = @inet_pton($network);
        if ($packed === false || $base === false || strlen($packed) !== strlen($base)) { return false; }
        $bytes = intdiv($bits, 8); $remaining = $bits % 8;
        return substr($packed, 0, $bytes) === substr($base, 0, $bytes)
            && (!$remaining || ((ord($packed[$bytes]) ^ ord($base[$bytes])) & (0xff << (8 - $remaining))) === 0);
    }

    private static function canonicalIp(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) { return null; }
        return inet_ntop(inet_pton($ip));
    }

    private static function ipId(string $ip, int $port): string
    {
        return 'ip_' . substr(hash('sha256', $ip . '|' . $port), 0, 24);
    }

    private function now(): int { return (int) call_user_func($this->clock); }
    private function timestamp(): string { return date('Y-m-d H:i:s', $this->now()); }

    private function readJson(string $file, array $default): array
    {
        if (!is_file($file)) { return $default; }
        $raw = @file_get_contents($file);
        if ($raw === false) { throw new RuntimeException('无法读取资产数据文件'); }
        $data = json_decode($raw, true);
        if (!is_array($data)) { throw new RuntimeException('资产数据文件格式损坏，已停止操作以保护数据'); }
        return $data;
    }

    private function readState(): array
    {
        $state = $this->readJson($this->dir . '/health.json', []);
        if ((isset($state['manual_ips']) && !is_array($state['manual_ips'])) || (isset($state['observations']) && !is_array($state['observations']))) {
            throw new RuntimeException('健康数据文件格式损坏，已停止操作以保护数据');
        }
        if (isset($state['check_events']) && !is_array($state['check_events'])) { throw new RuntimeException('检查日志格式错误'); }
        return array_merge(['version' => 1, 'manual_ips' => [], 'observations' => [], 'check_events' => []], $state);
    }

    private function transaction(callable $mutate): void
    {
        WorkspaceData::locked($this->dir, function () use ($mutate) { $this->transactionLocked($mutate); });
    }

    private function transactionLocked(callable $mutate): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            throw new RuntimeException('无法创建健康数据目录');
        }
        $lock = @fopen($this->dir . '/health.lock', 'c');
        if ($lock === false) { throw new RuntimeException('无法打开健康数据锁'); }
        $tmp = null;
        try {
            if (!flock($lock, LOCK_EX)) { throw new RuntimeException('无法锁定健康数据'); }
            $state = $this->readState();
            $mutate($state);
            // 事件日志封顶（最新在前，保留最近 N 条）：与 sync_log 的防膨胀策略一致，
            // 日历与趋势只读最近窗口，更早的历史可经数据清理按需修剪。
            if (isset($state['check_events']) && is_array($state['check_events']) && count($state['check_events']) > self::MAX_EVENTS) {
                $state['check_events'] = array_slice($state['check_events'], 0, self::MAX_EVENTS);
            }
            // 历史拆分迁移：主文件只保留当前健康状态，按资产历史写入 data/history/ 独立小文件，
            // 让批量检查不再为追加历史而全量重写 977×10 条记录。
            foreach (($state['observations'] ?? []) as $id => $observation) {
                if (is_array($observation) && array_key_exists('history', $observation)) {
                    $this->writeHistory((string) $id, is_array($observation['history']) ? $observation['history'] : []);
                    unset($state['observations'][$id]['history']);
                }
            }
            // 紧凑编码：这是机器读写的台账文件，缩进会让每次全量重写的字节量翻倍。
            $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) { throw new RuntimeException('无法编码健康数据'); }
            $tmp = $this->dir . '/health.json.tmp.' . bin2hex(random_bytes(8));
            if (@file_put_contents($tmp, $json) !== strlen($json)) { throw new RuntimeException('健康数据写入失败'); }
            @chmod($tmp, 0600);
            if (!@rename($tmp, $this->dir . '/health.json')) { throw new RuntimeException('健康数据保存失败'); }
            $tmp = null;
            // 主文件已剥离内嵌历史：失效迁移缓存，保证同请求内后续读取不拿到过期数据。
            $this->legacyObservations = null;
        } finally {
            if ($tmp !== null && is_file($tmp)) { @unlink($tmp); }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** 单资产历史文件路径；ID 先经字符集校验，杜绝路径拼接风险。 */
    private function historyPath(string $id): string
    {
        return $this->dir . '/history/' . $id . '.json';
    }

    /** 读取单资产最近检查历史：独立小文件优先，兼容迁移前主文件内嵌历史。 */
    public function readHistory(string $id): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/', (string) $id)) { return []; }
        $path = $this->historyPath($id);
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            return is_array($data) ? array_values($data) : [];
        }
        return $this->legacyHistory($id);
    }

    /** 迁移兼容：内嵌历史只解析一次主文件并缓存；迁移完成后缓存为空表，仅为零成本兜底。 */
    private function legacyHistory(string $id): array
    {
        if ($this->legacyObservations === null) {
            $observations = $this->readJson($this->dir . '/health.json', [])['observations'] ?? [];
            $this->legacyObservations = is_array($observations) ? $observations : [];
        }
        $history = $this->legacyObservations[$id]['history'] ?? [];
        return is_array($history) ? array_values($history) : [];
    }

    /** 写入单资产历史（原子替换 + 0600）；仅在 transaction 锁内调用。 */
    private function writeHistory(string $id, array $history): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/', (string) $id)) { throw new InvalidArgumentException('资产 ID 格式不正确'); }
        $dir = $this->dir . '/history';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) { throw new RuntimeException('无法创建历史目录'); }
        $json = json_encode(array_values(array_slice($history, 0, self::MAX_HISTORY)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) { throw new RuntimeException('无法编码检查历史'); }
        $tmp = $dir . '/' . $id . '.json.tmp.' . bin2hex(random_bytes(8));
        if (@file_put_contents($tmp, $json) !== strlen($json)) { throw new RuntimeException('检查历史写入失败'); }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $dir . '/' . $id . '.json')) { @unlink($tmp); throw new RuntimeException('检查历史保存失败'); }
    }

    /** 内存态中的资产历史：迁移前的内嵌历史优先，迁移后读取独立文件。 */
    private function historyOf(string $id, array $state): array
    {
        $embedded = $state['observations'][$id]['history'] ?? null;
        if (is_array($embedded)) { return $embedded; }
        return $this->readHistory($id);
    }

    /** 单资产最近检查历史（列表出口不再内嵌，详情按需读取）。 */
    public function historyFor(string $id): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/', (string) $id)) { throw new InvalidArgumentException('asset_id 格式不正确'); }
        if (!isset($this->assets($this->readState())[$id])) { throw new OutOfBoundsException('资产不存在，请刷新后再试'); }
        return ['ok' => true, 'asset_id' => $id, 'history' => $this->readHistory($id)];
    }

    /** 清空全部按资产历史（恢复资产/清空检查历史时调用；当前健康状态保留）。 */
    public static function purgeHistories(string $dir): void
    {
        $path = $dir . '/history';
        if (!is_dir($path)) { return; }
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') { continue; }
            if (is_file($path . '/' . $name)) { @unlink($path . '/' . $name); }
        }
    }
}
