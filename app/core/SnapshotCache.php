<?php

/** Derived snapshots only. Source files remain the source of truth. */
class SnapshotCache
{
    public static function remember(string $dir, string $key, array $sources, callable $build, int $validUntil): array
    {
        $cacheDir = $dir . '/cache';
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0770, true) && !is_dir($cacheDir)) {
            return $build();
        }
        $path = $cacheDir . '/' . hash('sha256', $key) . '.json';
        $fingerprint = self::fingerprint($sources);
        $cached = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        if (is_array($cached) && ($cached['fingerprint'] ?? '') === $fingerprint
            && ($cached['valid_until'] ?? 0) > time() && is_array($cached['data'] ?? null)) {
            $cached['data']['cache'] = ['hit' => true, 'saved_at' => $cached['saved_at'], 'source' => 'file']
                + self::ttlInfo($cached['valid_until'] ?? 0, isset($cached['ttl']) ? (int) $cached['ttl'] : null);
            return $cached['data'];
        }
        $data = $build();
        $savedAt = date('Y-m-d H:i:s');
        // A source changed while rendering: never publish the mixed snapshot.
        if (self::fingerprint($sources) === $fingerprint) {
            JsonStore::write($path, ['fingerprint' => $fingerprint, 'valid_until' => $validUntil,
                'ttl' => max(0, $validUntil - time()), 'saved_at' => $savedAt, 'data' => $data]);
        }
        $data['cache'] = ['hit' => false, 'saved_at' => $savedAt, 'source' => 'file']
            + self::ttlInfo($validUntil);
        return $data;
    }

    /** 有效期信息随快照透出：ttl 为配置时长（写入时固定，不随剩余秒数递减），前端据此提示联动关系。 */
    private static function ttlInfo(int $validUntil, ?int $duration = null): array
    {
        return ['ttl' => $duration ?? max(0, $validUntil - time()), 'ttl_ends_at' => $validUntil];
    }

    private static function fingerprint(array $sources): string
    {
        return hash('sha256', implode('|', array_map(static function ($path) {
            return $path . ':' . (is_file($path) ? (hash_file('sha256', $path) ?: 'unreadable') : 'missing');
        }, $sources)));
    }
}
