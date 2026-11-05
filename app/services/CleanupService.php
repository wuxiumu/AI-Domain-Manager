<?php

/**
 * 数据清理：账号不可用的遗留记录与日志按维度删除。
 * 只清已确认不可用账号（已删除或已停用）的孤儿数据与纯日志，从不触碰启用中账号的记录；
 * DNS 发现的 IP 由解析结果读取时派生，观测删除后自动消失，无需单独处理。
 */
class CleanupService
{
    private string $dir;
    /** @var array<string, array> 已配置账号（含停用），键为账号 id */
    private array $accounts;
    /** @var array<string, true> 启用中的账号 id */
    private array $enabled;

    /** @param array $accounts settings 配置的账号列表（含 enabled 字段） */
    public function __construct(string $dir, array $accounts)
    {
        $this->dir = $dir;
        $this->accounts = [];
        $this->enabled = [];
        foreach ($accounts as $account) {
            if (!is_array($account) || !isset($account['id'])) { continue; }
            $id = (string) $account['id'];
            $this->accounts[$id] = $account;
            if (($account['enabled'] ?? true) !== false) { $this->enabled[$id] = true; }
        }
    }

    public function overview(): array
    {
        $groups = [];
        foreach (JsonStore::read($this->dir . '/domains.json', []) as $row) {
            if (!is_array($row)) { continue; }
            $provider = (string) ($row['provider'] ?? '');
            if ($provider === '' || $provider === 'manual' || isset($this->enabled[$provider])) { continue; }
            $groups[$provider] ??= [
                'provider' => $provider,
                'label' => (string) ($this->accounts[$provider]['name'] ?? $provider),
                'disabled' => isset($this->accounts[$provider]),
                'domains' => [],
            ];
            $groups[$provider]['domains'][] = [
                'domain' => (string) ($row['domain'] ?? ''),
                'expire_at' => $row['expire_at'] ?? null,
                'note' => (string) ($row['note'] ?? ''),
                'valid' => ($row['valid'] ?? true) !== false,
            ];
        }
        foreach ($groups as &$group) {
            usort($group['domains'], static fn ($a, $b) => strcmp($a['domain'], $b['domain']));
        }
        unset($group);
        return ['ok' => true, 'groups' => array_values($groups), 'logs' => $this->logStats()];
    }

    /** 清理一个不可用账号下的域名记录：delete 删除台账行，manual 转为手工资产；两者都清残留缓存与健康数据。 */
    public function cleanOrphans(string $provider, string $action, array $domains = []): array
    {
        $provider = trim($provider);
        if ($provider === '' || $provider === 'manual') { throw new InvalidArgumentException('请选择要清理的不可用账号'); }
        if (isset($this->enabled[$provider])) { throw new DomainException('该账号仍在启用中，请在域名列表单独管理其记录'); }
        if (!in_array($action, ['delete', 'manual'], true)) { throw new InvalidArgumentException('无效的清理动作'); }
        $wanted = [];
        foreach ($domains as $d) {
            $name = self::normalizeDomain((string) $d);
            if ($name !== '') { $wanted[$name] = true; }
        }
        return WorkspaceData::locked($this->dir, function () use ($provider, $action, $wanted) {
            $rows = JsonStore::read($this->dir . '/domains.json', []);
            $targets = [];
            foreach ($rows as $i => $row) {
                if (!is_array($row) || (string) ($row['provider'] ?? '') !== $provider) { continue; }
                $name = self::normalizeDomain((string) ($row['domain'] ?? ''));
                if ($name === '' || ($wanted && !isset($wanted[$name]))) { continue; }
                $targets[$i] = $name;
            }
            if ($wanted) {
                $missing = array_diff(array_keys($wanted), array_values($targets));
                if ($missing) { throw new OutOfBoundsException('以下域名不在该账号的记录中：' . implode('、', $missing)); }
            }
            if (!$targets) { throw new OutOfBoundsException('该账号没有匹配的域名记录'); }
            $report = ['action' => $action, 'domains' => count($targets), 'caches_removed' => 0,
                'observations_removed' => 0, 'events_removed' => 0, 'bindings_removed' => 0,
                'legacy_checks_removed' => 0, 'record_access_removed' => 0];
            $health = new HealthService($this->dir);
            foreach ($targets as $name) {
                // ① 健康观测/绑定/检查事件：子域名清单来自 DNS 缓存，必须先于缓存删除执行。
                $counts = $health->forgetDomainArtifacts($provider, [$name]);
                $report['observations_removed'] += $counts['observations_removed'];
                $report['events_removed'] += $counts['events_removed'];
                $report['bindings_removed'] += $counts['bindings_removed'];
                // ② DNS 缓存文件
                $key = hash('sha256', $provider . '|' . $name);
                if (is_file($this->dir . '/cache/dns-' . $key . '.json')) { $report['caches_removed']++; }
                DnsRecordCache::invalidate($this->dir, $provider, $name);
                // ③④ 遗留访问检测快照与解析记录访问检测
                if ($this->forgetAccessEntry($provider, $name)) { $report['legacy_checks_removed']++; }
                if ($this->forgetRecordAccess($provider, $name)) { $report['record_access_removed']++; }
            }
            // ⑤ 台账行：删除或转为手工（保留域名资料，健康历史随观测清理归零，需重新检查）
            if ($action === 'delete') {
                $kept = [];
                foreach ($rows as $i => $row) { if (!isset($targets[$i])) { $kept[] = $row; } }
                JsonStore::write($this->dir . '/domains.json', $kept);
            } else {
                foreach ($targets as $i => $name) {
                    if (!is_array($rows[$i])) { continue; }
                    $rows[$i] = array_merge($rows[$i], ['provider' => 'manual', 'provider_name' => '手动添加', 'synced_at' => date('Y-m-d H:i:s')]);
                }
                JsonStore::write($this->dir . '/domains.json', $rows);
            }
            return ['ok' => true] + $report;
        });
    }

    /** 日志按维度删除：store ∈ check_events|dns_changes|audit|sync_log，各维度见 clean* 私有方法。 */
    public function cleanLogs(string $store, array $filter = []): array
    {
        $all = (bool) ($filter['all'] ?? false);
        $before = null;
        if (!$all && isset($filter['before']) && (string) $filter['before'] !== '') {
            $before = strtotime((string) $filter['before']);
            if ($before === false) { throw new InvalidArgumentException('时间格式不正确'); }
        }
        $domain = self::normalizeDomain((string) ($filter['domain'] ?? ''));
        switch ($store) {
            case 'check_events':
                $health = new HealthService($this->dir);
                if ($all) { $counts = $health->trimCheckHistory(['all' => true]); }
                elseif ($before !== null) { $counts = $health->trimCheckHistory(['before' => date('Y-m-d H:i:s', $before)]); }
                elseif ($domain !== '') {
                    $provider = isset($filter['provider']) && (string) $filter['provider'] !== '' ? (string) $filter['provider'] : null;
                    $counts = $health->trimCheckHistory(['provider' => $provider, 'domains' => [$domain]]);
                } else { throw new InvalidArgumentException('请选择域名、时间或选择清空全部'); }
                return ['ok' => true, 'store' => $store, 'removed' => $counts['events_removed'] + $counts['history_removed']] + $counts;
            case 'dns_changes':
                if (!$all && $domain === '') { throw new InvalidArgumentException('请选择域名或选择清空全部'); }
                return ['ok' => true, 'store' => $store] + $this->cleanDnsChanges($domain, $all);
            case 'audit':
                if (!$all && $before === null) { throw new InvalidArgumentException('请选择时间或选择清空全部'); }
                return ['ok' => true, 'store' => $store] + $this->cleanAudit($all, $before);
            case 'sync_log':
                $type = trim((string) ($filter['type'] ?? ''));
                if (!$all && $type === '' && $before === null) { throw new InvalidArgumentException('请选择类型、时间或选择清空全部'); }
                return ['ok' => true, 'store' => $store] + $this->cleanSyncLog($type, $all, $before);
            default:
                throw new InvalidArgumentException('未知的日志类型');
        }
    }

    private function cleanDnsChanges(string $domain, bool $all): array
    {
        $removed = 0;
        (new PrivateJsonStore($this->dir . '/dns_changes.json'))->update(static function ($state) use ($domain, $all, &$removed) {
            $entries = $state['entries'] ?? [];
            $kept = [];
            foreach ($entries as $entry) {
                if ($all || strcasecmp((string) ($entry['domain'] ?? ''), $domain) === 0) { $removed++; continue; }
                $kept[] = $entry;
            }
            $state['entries'] = $kept;
            return $state;
        });
        return ['removed' => $removed];
    }

    private function cleanAudit(bool $all, ?int $before): array
    {
        $removed = 0;
        (new PrivateJsonStore($this->dir . '/audit.json'))->update(static function ($state) use ($all, $before, &$removed) {
            $kept = [];
            foreach ($state['entries'] ?? [] as $entry) {
                $time = strtotime((string) ($entry['started_at'] ?? ''));
                if ($all || (is_int($before) && $time !== false && $time < $before)) { $removed++; continue; }
                $kept[] = $entry;
            }
            $state['entries'] = $kept;
            return $state;
        });
        return ['removed' => $removed];
    }

    private function cleanSyncLog(string $type, bool $all, ?int $before): array
    {
        $path = $this->dir . '/sync_log.json';
        $removed = 0;
        return WorkspaceData::locked($this->dir, static function () use ($path, $type, $all, $before, &$removed) {
            $logs = JsonStore::read($path, []);
            $kept = [];
            foreach ($logs as $entry) {
                $time = strtotime((string) ($entry['time'] ?? ''));
                if ($all || ((string) ($entry['type'] ?? '') === $type && $type !== '') || (is_int($before) && $time !== false && $time < $before)) { $removed++; continue; }
                $kept[] = $entry;
            }
            JsonStore::write($path, $kept);
            return ['removed' => $removed];
        });
    }

    private function logStats(): array
    {
        $health = JsonStore::read($this->dir . '/health.json', []);
        $events = is_array($health['check_events'] ?? null) ? $health['check_events'] : [];
        $historyEntries = 0;
        foreach ((is_array($health['observations'] ?? null) ? $health['observations'] : []) as $observation) {
            if (is_array($observation['history'] ?? null)) { $historyEntries += count($observation['history']); }
        }
        $dns = (new PrivateJsonStore($this->dir . '/dns_changes.json'))->read();
        $audit = (new PrivateJsonStore($this->dir . '/audit.json'))->read();
        $sync = JsonStore::read($this->dir . '/sync_log.json', []);
        $types = [];
        foreach ($sync as $entry) {
            $t = (string) ($entry['type'] ?? '');
            if ($t !== '') { $types[$t] = ($types[$t] ?? 0) + 1; }
        }
        return [
            'check_events' => ['total' => count($events), 'oldest' => self::oldest($events, 'checked_at'), 'history_entries' => $historyEntries],
            'dns_changes' => ['total' => count($dns['entries'] ?? []), 'oldest' => self::oldest($dns['entries'] ?? [], 'started_at')],
            'audit' => ['total' => count($audit['entries'] ?? []), 'oldest' => self::oldest($audit['entries'] ?? [], 'started_at')],
            'sync_log' => ['total' => count($sync), 'oldest' => self::oldest($sync, 'time'), 'types' => $types],
        ];
    }

    private static function oldest(array $entries, string $field): ?string
    {
        $min = null;
        foreach ($entries as $entry) {
            $time = strtotime((string) ($entry[$field] ?? ''));
            if ($time === false) { continue; }
            if ($min === null || $time < $min) { $min = $time; }
        }
        return $min === null ? null : date('Y-m-d H:i:s', $min);
    }

    private function forgetAccessEntry(string $provider, string $domain): bool
    {
        $path = $this->dir . '/access_check.json';
        if (!is_file($path)) { return false; }
        $data = JsonStore::read($path, []);
        if (!is_array($data['results'] ?? null)) { return false; }
        $removed = false;
        foreach (array_keys($data['results']) as $key) {
            if (strcasecmp((string) $key, $provider . '|' . $domain) === 0) { unset($data['results'][$key]); $removed = true; }
        }
        if ($removed) { JsonStore::write($path, $data); }
        return $removed;
    }

    private function forgetRecordAccess(string $provider, string $domain): bool
    {
        $path = $this->dir . '/record_access.json';
        if (!is_file($path)) { return false; }
        $store = WorkspaceData::read($path);
        $removed = false;
        foreach (array_keys($store) as $key) {
            if (strcasecmp((string) $key, $provider . '|' . $domain) === 0) { unset($store[$key]); $removed = true; }
        }
        if ($removed) { JsonStore::write($path, $store); }
        return $removed;
    }

    private static function normalizeDomain(string $domain): string
    {
        return strtolower(rtrim(trim($domain), '.'));
    }
}
