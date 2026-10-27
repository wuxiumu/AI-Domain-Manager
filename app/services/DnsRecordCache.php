<?php

/** DNS records are fetched on first use, explicit refresh or after a write. */
class DnsRecordCache
{
    private static array $locks = [];

    /** Stable per-zone lock shared by cloud reads and writes; supports nested cache refreshes. */
    public static function locked(string $dir, string $account, string $domain, callable $action)
    {
        $key = hash('sha256', $account . '|' . strtolower($domain));
        $folder = $dir . '/dns-locks';
        $lockPath = $folder . '/' . $key . '.lock';
        if (isset(self::$locks[$lockPath])) { return $action(); }
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) { throw new RuntimeException('无法创建 DNS 操作锁'); }
        $lock = fopen($lockPath, 'c');
        if (!$lock) { throw new RuntimeException('无法锁定 DNS 操作'); }
        try {
            if (!chmod($lockPath, 0600) || !flock($lock, LOCK_EX)) { throw new RuntimeException('DNS 操作正忙，请稍后重试'); }
            self::$locks[$lockPath] = $lock;
            return $action();
        } finally {
            unset(self::$locks[$lockPath]); flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public static function recordVersion(array $record): string
    {
        $fields = [];
        foreach (['id', 'type', 'name', 'value', 'ttl', 'priority', 'line', 'status'] as $field) {
            $fields[$field] = in_array($field, ['ttl', 'priority'], true) ? (int) ($record[$field] ?? 0) : (string) ($record[$field] ?? '');
        }
        return hash('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function versioned(array $snapshot): array
    {
        $versions = [];
        foreach ($snapshot['records'] as &$record) {
            $record['version'] = self::recordVersion($record);
            $versions[] = $record['version'];
        }
        unset($record);
        sort($versions, SORT_STRING);
        $snapshot['revision'] = hash('sha256', implode('|', $versions));
        return $snapshot;
    }

    public static function peek(string $dir, string $account, string $domain): ?array
    {
        $path = self::path($dir, $account, $domain);
        $cached = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        return is_array($cached) && is_array($cached['records'] ?? null) ? self::versioned($cached) : null;
    }

    public static function read(string $dir, string $account, string $domain, callable $fetch, bool $refresh = false): array
    {
        return self::locked($dir, $account, $domain, static function () use ($dir, $account, $domain, $fetch, $refresh) {
            return self::readUnlocked($dir, $account, $domain, $fetch, $refresh);
        });
    }

    private static function readUnlocked(string $dir, string $account, string $domain, callable $fetch, bool $refresh): array
    {
        $path = self::path($dir, $account, $domain);
        if (!$refresh && is_file($path)) {
            $cached = json_decode((string) @file_get_contents($path), true);
            if (is_array($cached) && is_array($cached['records'] ?? null)) {
                $cached['cached'] = true;
                return self::versioned($cached);
            }
        }
        $result = ['records' => $fetch(), 'snapshot_at' => date('Y-m-d H:i:s'), 'cached' => false];
        JsonStore::write($path, $result);
        return self::versioned($result);
    }

    public static function invalidate(string $dir, string $account, string $domain): void
    {
        self::locked($dir, $account, $domain, static function () use ($dir, $account, $domain): void {
            $path = self::path($dir, $account, $domain);
            if (is_file($path) && !unlink($path)) { throw new RuntimeException('DNS 缓存更新失败，请主动刷新记录'); }
        });
    }

    private static function path(string $dir, string $account, string $domain): string
    {
        return $dir . '/cache/dns-' . hash('sha256', $account . '|' . strtolower($domain)) . '.json';
    }
}
