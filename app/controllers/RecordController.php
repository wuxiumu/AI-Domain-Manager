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
        $this->releaseSession();
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
                'snapshot_at' => $snapshot['snapshot_at'], 'cached' => $snapshot['cached'], 'revision' => $snapshot['revision']]);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => 'DNS 记录读取未完成，请检查账号权限与网络后刷新', 'code' => 'dns_read_failed'], 502);
        }
    }

    /** 检测该域名全部解析记录的可访问性 */
    public function accessCheck(string $provider, string $domain): void
    {
        Auth::requireLogin();
        $this->releaseSession();
        try {
            $acc = self::account($provider);
            $snapshot = DnsRecordCache::read(DATA_DIR, $provider, $domain, static function () use ($acc, $domain) { return provider_factory($acc)->listRecords($domain); }, true);
            $records = $snapshot['records'];
            json_out(['ok' => true] + RecordAccessService::runCheck($provider, $domain, $records));
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => 'DNS 访问检查未完成，请检查账号权限与网络后重试', 'code' => 'dns_check_failed'], 502);
        }
    }

    public function add(string $provider, string $domain): void
    {
        $this->change($provider, $domain, 'add', null);
    }

    public function update(string $provider, string $domain, string $recordId): void
    {
        $this->change($provider, $domain, 'update', $recordId);
    }

    public function delete(string $provider, string $domain, string $recordId): void
    {
        $this->change($provider, $domain, 'delete', $recordId);
    }

    public function changes(): void
    {
        Auth::requireSession();
        $this->releaseSession();
        header('Cache-Control: no-store');
        json_out(['ok' => true, 'changes' => DnsChangeService::recent(DATA_DIR)]);
    }

    private function change(string $provider, string $domain, string $action, ?string $recordId): void
    {
        Auth::requireLogin();
        $this->releaseSession();
        header('Cache-Control: no-store');
        $account = self::account($provider);
        $body = body_json();
        if ($action === 'add' && (!isset($body['line']) || $body['line'] === '')) {
            $body['line'] = $account['provider'] === 'aliyun' ? 'default' : ($account['provider'] === 'tencent' ? '默认' : '');
        }
        $service = new DnsChangeService(DATA_DIR, provider_factory($account), static function () use ($provider, $domain): void {
            (new HealthService())->invalidateDnsHealth($provider, $domain);
        });
        $result = $service->apply($provider, $domain, $action, $recordId, $body,
            ['user' => Auth::user(), 'via' => Auth::isBearer() ? 'api' : 'browser']);
        json_out($result, $result['verified'] ? 200 : 202);
    }

    private function releaseSession(): void
    {
        // Authorization is complete; cloud I/O must not block the user's other requests.
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
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
