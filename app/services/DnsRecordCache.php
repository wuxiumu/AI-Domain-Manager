<?php

/** DNS records are fetched on first use, explicit refresh or after a write. */
class DnsRecordCache
{
    public static function peek(string $dir, string $account, string $domain): ?array
    {
        $path = self::path($dir, $account, $domain);
        $cached = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        return is_array($cached) && is_array($cached['records'] ?? null) ? $cached : null;
    }

    public static function read(string $dir, string $account, string $domain, callable $fetch, bool $refresh = false): array
    {
        $path = self::path($dir, $account, $domain);
        if (!$refresh && is_file($path)) {
            $cached = json_decode((string) @file_get_contents($path), true);
            if (is_array($cached) && is_array($cached['records'] ?? null)) {
                $cached['cached'] = true;
                return $cached;
            }
        }
        $result = ['records' => $fetch(), 'snapshot_at' => date('Y-m-d H:i:s'), 'cached' => false];
        JsonStore::write($path, $result);
        return $result;
    }

    public static function invalidate(string $dir, string $account, string $domain): void
    {
        $path = self::path($dir, $account, $domain);
        if (is_file($path) && !unlink($path)) { throw new RuntimeException('DNS 缓存更新失败，请主动刷新记录'); }
    }

    private static function path(string $dir, string $account, string $domain): string
    {
        return $dir . '/cache/dns-' . hash('sha256', $account . '|' . strtolower($domain)) . '.json';
    }
}
