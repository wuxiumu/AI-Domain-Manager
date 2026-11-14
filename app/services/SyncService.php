<?php

/**
 * 同步服务：遍历全部云商账号拉取域名 → 增量合并写入文件数据库。
 */
class SyncService
{
    /**
     * 同步全部账号，返回汇总结果。
     */
    public static function syncAll(): array
    {
        @set_time_limit(300);
        $epoch = WorkspaceData::locked(DATA_DIR, static function () { return WorkspaceData::epoch(DATA_DIR); });

        $results       = [];
        $successIds    = [];
        $freshByProvider = [];

        foreach (config('accounts') as $acc) {
            if (($acc['enabled'] ?? true) === false) { continue; }
            $start = microtime(true);
            try {
                $domains = provider_factory($acc)->listDomains();
                array_walk($domains, function (&$d) use ($acc) {
                    $d['provider']      = $acc['id'];
                    $d['provider_name'] = $acc['name'];
                });
                $freshByProvider[$acc['id']] = $domains;
                $successIds[] = $acc['id'];

                $results[] = [
                    'id'         => $acc['id'],
                    'name'       => $acc['name'],
                    'ok'         => true,
                    'count'      => count($domains),
                    'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'id'    => $acc['id'],
                    'name'  => $acc['name'],
                    'ok'    => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $failed = count(array_filter($results, fn ($r) => !$r['ok']));
        $syncedAt = date('Y-m-d H:i:s');
        $syncStatus = !$results ? 'skipped' : (!$successIds ? 'failed' : ($failed ? 'partial' : 'success'));
        $saved = WorkspaceData::locked(DATA_DIR, static function () use ($freshByProvider, $successIds, $epoch, $failed, $syncedAt, $syncStatus) {
            if ($epoch !== WorkspaceData::epoch(DATA_DIR)) { throw new DomainException('同步期间资产已恢复，本次结果已丢弃，请重新同步'); }
            $stats = self::mergeStore($freshByProvider, $successIds);
            $meta = WorkspaceData::read(DATA_DIR . '/meta.json');
            $meta = array_merge($meta, ['last_attempt_at' => $syncedAt, 'status' => $syncStatus, 'success' => count($successIds), 'failed' => $failed]);
            if ($successIds) { $meta['last_sync'] = $syncedAt; }
            if ($syncStatus === 'success') { $meta['last_full_sync_at'] = $syncedAt; }
            JsonStore::write(DATA_DIR . '/meta.json', $meta);
            return ['stats' => $stats, 'meta' => $meta];
        });
        $stats = $saved['stats'];

        Logger::log('sync', [
            'accounts' => $results,
            'total'    => $stats['total'],
            'new'      => $stats['new'],
            'stale'    => $stats['stale'],
        ]);

        return [
            'results' => $results,
            'stats'   => $stats,
            'last_sync' => $saved['meta']['last_sync'] ?? null,
            'last_attempt_at' => $syncedAt,
            'status' => $syncStatus,
        ];
    }

    /**
     * 同步单个云账号：拉取域名 → 单账号增量合并 → 更新同步时间。
     * meta 的全局 status/success/failed 只由全量同步维护，避免以单账号结果覆盖全局状态。
     */
    public static function syncOne(array $acc): array
    {
        $start = microtime(true);
        $domains = provider_factory($acc)->listDomains();
        array_walk($domains, function (&$d) use ($acc) {
            $d['provider']      = $acc['id'];
            $d['provider_name'] = $acc['name'];
        });
        $epoch = WorkspaceData::locked(DATA_DIR, static function () { return WorkspaceData::epoch(DATA_DIR); });
        $stats = WorkspaceData::locked(DATA_DIR, static function () use ($acc, $domains, $epoch) {
            if ($epoch !== WorkspaceData::epoch(DATA_DIR)) { throw new DomainException('同步期间资产已恢复，本次结果已丢弃，请重新同步'); }
            $stats = self::mergeLocked([$acc['id'] => $domains], [$acc['id']]);
            $meta = WorkspaceData::read(DATA_DIR . '/meta.json');
            $now = date('Y-m-d H:i:s');
            $meta['last_attempt_at'] = $now;
            $meta['last_sync'] = $now;
            JsonStore::write(DATA_DIR . '/meta.json', $meta);
            return $stats;
        });
        Logger::log('sync', ['account' => $acc['id'], 'total' => $stats['total'], 'new' => $stats['new'], 'stale' => $stats['stale']]);
        return [
            'count'      => $stats['total'],
            'new'        => $stats['new'],
            'stale'      => $stats['stale'],
            'latency_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
    }

    /**
     * 逐个刷新该账号未过期域名的解析缓存（显式触发才发起云请求）。
     * 超出时间预算即停止、连续失败过多时熔断以保护云配额；未刷新域名记入 pending。
     */
    public static function refreshDnsCache(array $acc, float $deadline, int $maxConsecutiveFailures = 8): array
    {
        $pid = (string) $acc['id'];
        $store = JsonStore::read(DATA_DIR . '/domains.json', []);
        if (!is_array($store)) { $store = []; }

        $provider = null;
        $refreshed = 0;
        $failed = 0;
        $pending = [];
        $consecutiveFailures = 0;
        $aborted = false;
        $firstError = null;

        foreach ($store as $d) {
            if (($d['provider'] ?? '') !== $pid || ($d['valid'] ?? true) === false) { continue; }
            $days = ScreenService::daysLeft($d['expire_at'] ?? null);
            if ($days !== null && $days < 0) { continue; }
            $domain = trim((string) ($d['domain'] ?? ''));
            if ($domain === '') { continue; }
            if ($aborted || microtime(true) >= $deadline) { $pending[] = $domain; continue; }
            try {
                $provider = $provider ?? provider_factory($acc);
                DnsRecordCache::read(DATA_DIR, $pid, $domain,
                    static function () use ($provider, $domain) { return $provider->listRecords($domain); }, true);
                $refreshed++;
                $consecutiveFailures = 0;
            } catch (Throwable $e) {
                $failed++;
                $consecutiveFailures++;
                $firstError = $firstError ?? $e->getMessage();
                if ($consecutiveFailures >= $maxConsecutiveFailures) { $aborted = true; }
            }
        }

        return ['refreshed' => $refreshed, 'failed' => $failed, 'pending' => $pending, 'error' => $firstError];
    }

    /**
     * 增量合并：freshByProvider 为本次拉取成功的数据；
     * 仅对"本次成功"的账号，把未再返回的旧域名标记为失效（valid=false）。
     */
    private static function mergeStore(array $freshByProvider, array $successIds): array
    {
        return WorkspaceData::locked(DATA_DIR, static function () use ($freshByProvider, $successIds) {
            return self::mergeLocked($freshByProvider, $successIds);
        });
    }

    private static function mergeLocked(array $freshByProvider, array $successIds): array
    {
        $store = JsonStore::read(DATA_DIR . '/domains.json', []);
        if (!is_array($store)) {
            $store = [];
        }

        $keyed = [];
        foreach ($store as $d) {
            if (isset($d['provider'], $d['domain'])) {
                $keyed[$d['provider'] . '|' . $d['domain']] = $d;
            }
        }

        $now       = date('Y-m-d H:i:s');
        $freshKeys = [];
        $newCount  = 0;

        foreach ($freshByProvider as $pid => $domains) {
            foreach ($domains as $d) {
                if (empty($d['domain'])) {
                    continue;
                }
                $k          = $pid . '|' . $d['domain'];
                $freshKeys[$k] = true;
                $prev       = $keyed[$k] ?? [];
                $entry      = array_merge($prev, $d, [
                    'provider'      => $pid,
                    'provider_name' => $prev['provider_name'] ?? ($d['provider_name'] ?? $pid),
                    'valid'         => true,
                    'synced_at'     => $now,
                    'first_seen'    => $prev['first_seen'] ?? $now,
                    'invalid_at'    => null,
                ]);
                if (!isset($keyed[$k])) {
                    $newCount++;
                }
                $keyed[$k] = $entry;
            }
        }

        // 失效标记：仅对本次成功的账号
        $staleCount = 0;
        foreach ($keyed as $k => $d) {
            $pid = explode('|', $k, 2)[0];
            if (in_array($pid, $successIds, true) && !isset($freshKeys[$k]) && ($d['valid'] ?? true) !== false) {
                $keyed[$k]['valid']      = false;
                $keyed[$k]['invalid_at'] = $now;
                $staleCount++;
            }
        }

        JsonStore::write(DATA_DIR . '/domains.json', array_values($keyed));

        return [
            'total' => count($freshKeys),
            'new'   => $newCount,
            'stale' => $staleCount,
            'all'   => count($keyed),
        ];
    }
}
