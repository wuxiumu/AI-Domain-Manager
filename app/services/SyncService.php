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

        $stats = self::mergeStore($freshByProvider, $successIds);
        $failed = count(array_filter($results, fn ($r) => !$r['ok']));

        Logger::log('sync', [
            'accounts' => $results,
            'total'    => $stats['total'],
            'new'      => $stats['new'],
            'stale'    => $stats['stale'],
        ]);

        JsonStore::write(DATA_DIR . '/meta.json', [
            'last_sync' => date('Y-m-d H:i:s'),
            'success'   => count($successIds),
            'failed'    => $failed,
        ]);

        return [
            'results' => $results,
            'stats'   => $stats,
            'last_sync' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 增量合并：freshByProvider 为本次拉取成功的数据；
     * 仅对"本次成功"的账号，把未再返回的旧域名标记为失效（valid=false）。
     */
    private static function mergeStore(array $freshByProvider, array $successIds): array
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
