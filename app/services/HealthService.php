<?php

require_once __DIR__ . '/../core/WorkspaceData.php';

/**
 * Public-asset health inventory. Existing domain files are read-only inputs;
 * all new state is changed under a stable lock, with atomic replacement.
 */
class HealthService
{
    public const FRESHNESS_SECONDS = 86400;
    private const MAX_BATCH = 5;
    private const MAX_HISTORY = 10;
    private $dir;
    private $probe;
    private $clock;
    private $dnsLookup;
    private $httpProbe;

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
            array_merge([$this->dir . '/domains.json', $this->dir . '/health.json', $this->dir . '/access_check.json'], glob($this->dir . '/cache/dns-*.json') ?: []),
            function () { return $this->buildDashboard(); }, time() + 60);
    }

    private function buildDashboard(): array
    {
        $state = $this->readState();
        $assets = $this->assets($state);
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
        foreach ($ids as $id) {
            $asset = $assets[$id];
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
            $observations[$id] = $health;
        }
        $histories = [];
        $this->transaction(function (&$state) use ($observations, $assets, &$histories, $epoch) {
            if ($epoch !== WorkspaceData::epoch($this->dir)) { throw new DomainException('检查期间资产已恢复，本批结果已丢弃，请重新检查'); }
            foreach ($observations as $id => $health) {
                $history = $state['observations'][$id]['history'] ?? [];
                array_unshift($history, $health);
                $state['observations'][$id] = ['health' => $health, 'history' => array_slice($history, 0, self::MAX_HISTORY)];
                $histories[$id] = $state['observations'][$id]['history'];
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

    private function assets(array $state): array
    {
        $assets = [];
        $legacy = $this->readJson($this->dir . '/access_check.json', [])['results'] ?? [];
        foreach ($this->readJson($this->dir . '/domains.json', []) as $domain) {
            if (!is_array($domain) || ($domain['valid'] ?? true) === false || empty($domain['domain'])) {
                continue;
            }
            $name = strtolower(rtrim(trim((string) $domain['domain']), '.'));
            $provider = (string) ($domain['provider'] ?? 'manual');
            $id = 'domain_' . substr(hash('sha256', $provider . '|' . $name), 0, 24);
            $health = $this->healthOf($id, $state);
            $history = $state['observations'][$id]['history'] ?? [];
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
            'history' => $state['observations'][$id]['history'] ?? []];
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
        $ok = $results['http']['status'] === 'healthy' && $results['https']['status'] === 'healthy';
        return ['status' => $ok ? 'healthy' : 'unhealthy', 'message' => $ok ? 'HTTP 和 HTTPS 均正常' : 'HTTP 或 HTTPS 检查异常',
            'http_code' => $results['https']['code'], 'protocols' => $results, 'checks' => $checks,
            'resolved_ips' => $ips, 'probed_ip' => $ips[0], 'latency_ms' => $results['http']['latency_ms'] + $results['https']['latency_ms']];
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
        curl_exec($ch);
        $result = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'errno' => curl_errno($ch),
            'error' => curl_error($ch), 'ms' => (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000)];
        // A bounded GET intentionally stops reading large bodies after headers.
        if ($limited && $result['errno'] === CURLE_WRITE_ERROR && $result['code'] > 0) {
            $result['errno'] = 0;
            $result['error'] = '';
        }
        curl_close($ch);
        return $result;
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
            $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false) { throw new RuntimeException('无法编码健康数据'); }
            $tmp = $this->dir . '/health.json.tmp.' . bin2hex(random_bytes(8));
            if (@file_put_contents($tmp, $json) !== strlen($json)) { throw new RuntimeException('健康数据写入失败'); }
            @chmod($tmp, 0600);
            if (!@rename($tmp, $this->dir . '/health.json')) { throw new RuntimeException('健康数据保存失败'); }
            $tmp = null;
        } finally {
            if ($tmp !== null && is_file($tmp)) { @unlink($tmp); }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
