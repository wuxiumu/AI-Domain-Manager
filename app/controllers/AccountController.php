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

    /** 测试全部账号 */
    public function testAll(): void
    {
        Auth::requireLogin();
        $results = [];
        foreach (config('accounts') as $a) {
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
