<?php

class AccountController
{
    /** 账号列表（密钥脱敏） */
    public function index(): void
    {
        Auth::requireLogin();
        header('Cache-Control: no-store');
        json_out(settings_service()->publicAccounts());
    }

    public function create(): void
    {
        Auth::requireSession();
        $id = settings_service()->saveAccount(body_json());
        json_out(['ok' => true, 'id' => $id], 201);
    }

    public function update(string $id): void
    {
        Auth::requireSession();
        settings_service()->saveAccount(body_json(), $id);
        json_out(['ok' => true, 'id' => $id]);
    }

    public function delete(string $id): void
    {
        Auth::requireSession();
        settings_service()->deleteAccount($id);
        json_out(['ok' => true]);
    }

    /** 测试单个账号密钥（真实调用云商 API） */
    public function test(string $id): void
    {
        Auth::requireLogin();
        $acc = self::find($id);
        if (!$acc) {
            json_out(['error' => '账号不存在'], 404);
        }
        try {
            $result = provider_factory($acc)->testConnection();
            json_out(['ok' => true, 'account' => $id, 'result' => $result]);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'account' => $id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 同步单个云账号的域名台账；refresh_dns 时同时刷新该账号未过期域名的解析缓存。
     * 供大屏等页面的"手动更新指定账户"按钮显式调用。
     */
    public function sync(string $id): void
    {
        Auth::requireLogin();
        $acc = self::find($id);
        if (!$acc) {
            json_out(['error' => '账号不存在'], 404);
        }
        $this->releaseSession();
        header('Cache-Control: no-store');
        @set_time_limit(300);
        $refreshDns = !empty(body_json()['refresh_dns']);
        $start = microtime(true);
        try {
            $domains = SyncService::syncOne($acc);
            $dns = $refreshDns ? SyncService::refreshDnsCache($acc, microtime(true) + 240) : null;
            json_out(['ok' => true, 'account' => ['id' => $acc['id'], 'name' => $acc['name']],
                'domains' => $domains, 'dns' => $dns,
                'latency_ms' => (int) round((microtime(true) - $start) * 1000)]);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'account' => $id, 'error' => '同步未完成：' . $e->getMessage(), 'code' => 'account_sync_failed'], 502);
        }
    }

    /** 测试全部账号 */
    public function testAll(): void
    {
        Auth::requireLogin();
        $results = [];
        foreach (config('accounts') as $a) {
            if (($a['enabled'] ?? true) === false) { continue; }
            try {
                $result = provider_factory($a)->testConnection();
                $results[] = ['id' => $a['id'], 'name' => $a['name'], 'ok' => true, 'result' => $result];
            } catch (Throwable $e) {
                $results[] = ['id' => $a['id'], 'name' => $a['name'], 'ok' => false, 'error' => $e->getMessage()];
            }
        }
        json_out($results);
    }

    private static function keyOf(array $a): string
    {
        return $a['access_key_id'] ?? $a['secret_id'] ?? $a['api_key'] ?? '';
    }

    private function releaseSession(): void
    {
        // Authorization is complete; cloud I/O must not block the user's other requests.
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    }

    private static function find(string $id): ?array
    {
        foreach (config('accounts') as $a) {
            if ($a['id'] === $id) {
                return $a;
            }
        }
        return null;
    }
}
