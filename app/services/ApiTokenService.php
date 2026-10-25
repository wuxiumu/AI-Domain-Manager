<?php

/** High-entropy API tokens are shown once; only hashes are retained. */
class ApiTokenService
{
    public const SCOPES = [
        'assets:read' => '读取域名、IP、关系与统计',
        'assets:write' => '维护本地资产与绑定',
        'dns:read' => '读取并缓存 DNS 记录',
        'dns:write' => '修改云端 DNS 解析',
        'checks:run' => '执行健康与访问检查',
        'sync:run' => '同步域名与测试云连接',
    ];
    private PrivateJsonStore $store;
    private SettingsService $settings;

    public function __construct(string $dataDir, SettingsService $settings)
    {
        $this->store = new PrivateJsonStore($dataDir . '/api_tokens.json');
        $this->settings = $settings;
    }

    public function listing(): array
    {
        $fingerprint = $this->settings->fingerprint();
        return array_values(array_map(static function (array $token) use ($fingerprint): array {
            $token['invalidated'] = !hash_equals($token['auth_fingerprint'], $fingerprint);
            unset($token['hash'], $token['auth_fingerprint']);
            $token['expired'] = strtotime($token['expires_at']) <= time();
            return $token;
        }, $this->store->read()['tokens'] ?? []));
    }

    public function create(array $body): array
    {
        if (isset($body['name']) && !is_string($body['name'])) { throw new InvalidArgumentException('密钥名称必须为字符串'); }
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) { throw new InvalidArgumentException('请填写 1–100 字的密钥名称'); }
        $scopes = $body['scopes'] ?? ['assets:read', 'dns:read'];
        if (!is_array($scopes) || $scopes === [] || count(array_filter($scopes, 'is_string')) !== count($scopes)
            || array_diff($scopes, array_keys(self::SCOPES))) { throw new InvalidArgumentException('请选择有效的 API 权限'); }
        $days = SettingsService::integer($body['expires_in_days'] ?? 90, 1, 365, '密钥有效天数');
        $value = 'aw_' . bin2hex(random_bytes(32));
        $record = ['id' => bin2hex(random_bytes(12)), 'name' => $name, 'prefix' => substr($value, 0, 11),
            'hash' => hash('sha256', $value), 'scopes' => array_values(array_unique($scopes)),
            'user' => $this->settings->all()['auth']['user'], 'auth_fingerprint' => $this->settings->fingerprint(),
            'created_at' => date('c'), 'expires_at' => date('c', time() + $days * 86400)];
        $this->store->update(static function (array $stored) use ($record): array {
            $tokens = $stored['tokens'] ?? [];
            if (count($tokens) >= 100) { throw new InvalidArgumentException('最多保存 100 个 API 密钥，请先撤销不再使用的密钥'); }
            $tokens[] = $record;
            $stored['tokens'] = $tokens;
            return $stored;
        });
        unset($record['hash'], $record['auth_fingerprint']);
        return ['ok' => true, 'token' => $value, 'metadata' => $record];
    }

    public function revoke(string $id): void
    {
        $this->store->update(static function (array $stored) use ($id): array {
            $tokens = $stored['tokens'] ?? [];
            $next = array_values(array_filter($tokens, static fn (array $token): bool => $token['id'] !== $id));
            if (count($next) === count($tokens)) { throw new OutOfBoundsException('API 密钥不存在或已撤销'); }
            $stored['tokens'] = $next;
            return $stored;
        });
    }

    public function authenticate(string $value): ?array
    {
        if (!preg_match('/^aw_[a-f0-9]{64}$/D', $value)) { return null; }
        $hash = hash('sha256', $value);
        $fingerprint = $this->settings->fingerprint();
        foreach ($this->store->read()['tokens'] ?? [] as $token) {
            if (hash_equals($token['hash'], $hash) && hash_equals($token['auth_fingerprint'], $fingerprint)
                && strtotime($token['expires_at']) > time()) { return $token; }
        }
        return null;
    }
}
