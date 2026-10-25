<?php

/**
 * DNS 解析记录管理：代理到各云商 DNS API。
 * 路径：/api/domains/{provider}/{domain}/records[/{recordId}]
 */
class RecordController
{
    /** 列出解析记录（合并最近一次访问检测状态） */
    public function list(string $provider, string $domain): void
    {
        Auth::requireLogin();
        try {
            $acc = self::account($provider);
            $snapshot = DnsRecordCache::read(DATA_DIR, $provider, $domain,
                static function () use ($acc, $domain) { return provider_factory($acc)->listRecords($domain); },
                ($_GET['refresh'] ?? '') === '1');
            $records = $snapshot['records'];
            $accessMap = RecordAccessService::mapOf($provider, $domain);
            foreach ($records as &$r) {
                $r['access'] = $accessMap[$r['id']] ?? null;
            }
            unset($r);
            json_out(['ok' => true, 'domain' => $domain, 'provider' => $provider, 'records' => $records,
                'snapshot_at' => $snapshot['snapshot_at'], 'cached' => $snapshot['cached']]);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** 检测该域名全部解析记录的可访问性 */
    public function accessCheck(string $provider, string $domain): void
    {
        Auth::requireLogin();
        try {
            $acc = self::account($provider);
            $records = provider_factory($acc)->listRecords($domain);
            json_out(['ok' => true] + RecordAccessService::runCheck($provider, $domain, $records));
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** 新增解析记录 */
    public function add(string $provider, string $domain): void
    {
        Auth::requireLogin();
        $b = self::recordParams();
        try {
            $acc = provider_factory(self::account($provider));
            $new = $acc->addRecord($domain, $b);
            (new HealthService())->invalidateDnsHealth($provider, $domain);
            DnsRecordCache::invalidate(DATA_DIR, $provider, $domain);
            json_out(['ok' => true, 'domain' => $domain, 'record' => $new]);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** 修改解析记录 */
    public function update(string $provider, string $domain, string $recordId): void
    {
        Auth::requireLogin();
        $b = self::recordParams();
        try {
            provider_factory(self::account($provider))->updateRecord($domain, $recordId, $b);
            (new HealthService())->invalidateDnsHealth($provider, $domain);
            DnsRecordCache::invalidate(DATA_DIR, $provider, $domain);
            json_out(['ok' => true]);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** 删除解析记录 */
    public function delete(string $provider, string $domain, string $recordId): void
    {
        Auth::requireLogin();
        try {
            provider_factory(self::account($provider))->deleteRecord($domain, $recordId);
            (new HealthService())->invalidateDnsHealth($provider, $domain);
            DnsRecordCache::invalidate(DATA_DIR, $provider, $domain);
            json_out(['ok' => true]);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private static function recordParams(): array
    {
        $b = body_json();
        $type = strtoupper(trim((string) ($b['type'] ?? 'A')));
        $name = trim((string) ($b['name'] ?? '@'));
        $value = trim((string) ($b['value'] ?? ''));
        if ($name === '') {
            $name = '@';
        }
        if ($value === '') {
            json_out(['error' => '记录值不能为空'], 422);
        }
        if (($type === 'A' && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
            || ($type === 'AAAA' && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false)) {
            json_out(['ok' => false, 'error' => 'A 记录需要有效 IPv4，AAAA 记录需要有效 IPv6'], 422);
        }
        return [
            'type'     => $type,
            'name'     => $name,
            'value'    => $value,
            'ttl'      => (int) ($b['ttl'] ?? 600) ?: 600,
            'priority' => (int) ($b['priority'] ?? 0),
            'line'     => trim((string) ($b['line'] ?? '默认')),
        ];
    }

    private static function account(string $provider): array
    {
        if ($provider === 'manual') {
            json_out(['error' => '手动添加的域名不支持解析管理'], 400);
        }
        foreach (config('accounts') as $a) {
            if ($a['id'] === $provider) {
                return $a;
            }
        }
        json_out(['error' => '账号不存在'], 404);
    }
}
