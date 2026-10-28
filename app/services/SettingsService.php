<?php

/** Editable overrides preserve legacy configuration and stable cloud account IDs. */
class SettingsService
{
    private PrivateJsonStore $store;
    private array $legacy;
    private ?array $cached = null;

    public function __construct(string $configDir, string $dataDir)
    {
        $legacyFile = $configDir . '/config.php';
        $this->legacy = is_file($legacyFile) ? require $legacyFile : [];
        if (!is_array($this->legacy)) { throw new RuntimeException('旧配置文件格式错误'); }
        $this->store = new PrivateJsonStore($dataDir . '/settings.json');
    }

    private function merge(array $stored): array
    {
        $all = array_replace(['auth' => [], 'accounts' => [], 'http' => []], $this->legacy, $stored['config'] ?? []);
        if (isset($all['http.proxy']) && !array_key_exists('proxy', $all['http'])) { $all['http']['proxy'] = $all['http.proxy']; }
        unset($all['http.proxy']);
        return $all;
    }

    public function all(): array
    {
        if ($this->cached === null) { $this->cached = $this->merge($this->store->read()); }
        return $this->cached;
    }

    public function configured(): bool
    {
        $auth = $this->all()['auth'];
        return !empty($auth['user']) && (!empty($auth['pass_hash']) || !empty($auth['pass']));
    }

    public function fingerprint(): string
    {
        $auth = $this->all()['auth'];
        return hash('sha256', ($auth['user'] ?? '') . "\0" . ($auth['version'] ?? $auth['pass_hash'] ?? $auth['pass'] ?? ''));
    }

    public function verifyPassword(string $password): bool
    {
        $auth = $this->all()['auth'];
        if (!empty($auth['pass_hash'])) { return password_verify($password, $auth['pass_hash']); }
        return !empty($auth['pass']) && hash_equals((string) $auth['pass'], $password);
    }

    public static function providerSchema(): array
    {
        return [
            ['id' => 'aliyun', 'name' => '阿里云', 'fields' => [
                ['key' => 'access_key_id', 'label' => 'AccessKey ID'],
                ['key' => 'access_key_secret', 'label' => 'AccessKey Secret'],
            ]],
            ['id' => 'tencent', 'name' => '腾讯云', 'fields' => [
                ['key' => 'secret_id', 'label' => 'SecretId'],
                ['key' => 'secret_key', 'label' => 'SecretKey'],
            ]],
            ['id' => 'godaddy', 'name' => 'GoDaddy', 'fields' => [
                ['key' => 'api_key', 'label' => 'API Key'],
                ['key' => 'api_secret', 'label' => 'API Secret'],
            ]],
        ];
    }

    public function publicAccounts(): array
    {
        return array_values(array_map(static function (array $account): array {
            $fields = [];
            foreach (self::providerSchema() as $provider) {
                if ($provider['id'] !== $account['provider']) { continue; }
                foreach ($provider['fields'] as $field) {
                    $value = (string) ($account[$field['key']] ?? '');
                    $fields[$field['key']] = ['configured' => $value !== '', 'masked' => $value === '' ? '' : '••••••••'];
                }
            }
            $public = ['id' => $account['id'], 'name' => $account['name'], 'provider' => $account['provider'],
                'enabled' => ($account['enabled'] ?? true) !== false, 'note' => $account['note'] ?? '', 'credentials' => $fields,
                'key_masked' => mask_secret((string) ($account['access_key_id'] ?? $account['secret_id'] ?? $account['api_key'] ?? ''))];
            if ($account['provider'] === 'godaddy') { $public['api_base_url'] = ($account['api_base_url'] ?? '') ?: 'https://api.godaddy.com'; }
            return $public;
        }, $this->all()['accounts']));
    }

    public function summary(): array
    {
        $all = $this->all();
        $http = $all['http'];
        $proxy = (string) ($http['proxy'] ?? $all['http.proxy'] ?? '');
        $parts = $proxy === '' ? [] : (parse_url($proxy) ?: []);
        $proxyLabel = $parts ? ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') : '';
        return ['ok' => true, 'configured' => $this->configured(), 'admin_user' => $all['auth']['user'] ?? '',
            'accounts' => $this->publicAccounts(), 'providers' => self::providerSchema(),
            'http' => ['proxy_configured' => $proxy !== '', 'proxy_label' => $proxyLabel,
                'timeout' => $http['timeout'] ?? 25, 'connect_timeout' => $http['connect_timeout'] ?? 10],
            'api' => ['openapi' => '/api/openapi.json', 'discovery' => '/.well-known/asset-workspace.json', 'guide' => '/llms.txt']];
    }

    private function change(callable $work): void
    {
        $this->store->update(function (array $stored) use ($work): array {
            $patch = $work($this->merge($stored));
            $stored['config'] = array_replace($stored['config'] ?? [], $patch);
            $stored['version'] = 1;
            $stored['updated_at'] = date('c');
            return $stored;
        });
        $this->cached = null;
    }

    private static function authData(string $user, string $password): array
    {
        if (!preg_match('/^[a-zA-Z0-9_.@-]{3,64}$/D', $user)) {
            throw new InvalidArgumentException('账号需为 3–64 位字母、数字或 _.@-');
        }
        if (strlen($password) < 12 || strlen($password) > 72 || strpos($password, "\0") !== false) {
            throw new InvalidArgumentException('新密码长度需为 12–72 字节');
        }
        return ['user' => $user, 'pass_hash' => password_hash($password, PASSWORD_DEFAULT), 'version' => bin2hex(random_bytes(16))];
    }

    private static function stringFields(array $input, array $fields): void
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $input) && !is_string($input[$field])) {
                throw new InvalidArgumentException($field . ' 必须为字符串');
            }
        }
    }

    public function setup(array $body): void
    {
        self::stringFields($body, ['user', 'password']);
        $auth = self::authData(trim((string) ($body['user'] ?? '')), (string) ($body['password'] ?? ''));
        $this->change(function (array $all) use ($auth): array {
            if (!empty($all['auth']['user']) && (!empty($all['auth']['pass']) || !empty($all['auth']['pass_hash']))) {
                throw new DomainException('工作台已初始化，请登录后管理配置');
            }
            return ['auth' => $auth];
        });
    }

    public function updateCredentials(array $body): void
    {
        self::stringFields($body, ['user', 'password', 'current_password']);
        $this->change(function (array $all) use ($body): array {
            $auth = $all['auth'];
            $current = (string) ($body['current_password'] ?? '');
            $valid = !empty($auth['pass_hash']) ? password_verify($current, $auth['pass_hash'])
                : (!empty($auth['pass']) && hash_equals((string) $auth['pass'], $current));
            if (!$valid) { throw new InvalidArgumentException('当前密码不正确'); }
            return ['auth' => self::authData(trim((string) ($body['user'] ?? '')), (string) ($body['password'] ?? ''))];
        });
    }

    public function saveAccount(array $body, ?string $id = null): string
    {
        self::stringFields($body, ['provider', 'name', 'note', 'access_key_id', 'access_key_secret', 'secret_id', 'secret_key', 'api_key', 'api_secret', 'api_base_url']);
        $savedId = $id ?? '';
        $this->change(function (array $all) use ($body, $id, &$savedId): array {
            $accounts = $all['accounts'];
            $index = null;
            foreach ($accounts as $i => $account) { if ($account['id'] === $id) { $index = $i; break; } }
            if ($id !== null && $index === null) { throw new OutOfBoundsException('云账号不存在'); }
            $previous = $index === null ? [] : $accounts[$index];
            $providerId = $previous['provider'] ?? (string) ($body['provider'] ?? '');
            if (isset($body['provider']) && $body['provider'] !== $providerId) { throw new InvalidArgumentException('已有账号不能更换云商类型，请新增账号'); }
            $schema = null;
            foreach (self::providerSchema() as $provider) { if ($provider['id'] === $providerId) { $schema = $provider; break; } }
            if (!$schema) { throw new InvalidArgumentException('请选择支持的云商'); }
            $name = trim((string) ($body['name'] ?? $previous['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100) { throw new InvalidArgumentException('账号名称需为 1–100 个字符'); }
            $note = trim($body['note'] ?? $previous['note'] ?? '');
            if (mb_strlen($note) > 2000) { throw new InvalidArgumentException('备注不能超过 2000 个字符'); }
            $savedId = $id ?? $providerId . '_' . bin2hex(random_bytes(6));
            $next = array_replace($previous, ['id' => $savedId, 'name' => $name, 'provider' => $providerId, 'note' => $note,
                'enabled' => $body['enabled'] ?? $previous['enabled'] ?? true]);
            if (!is_bool($next['enabled'])) { throw new InvalidArgumentException('启用状态必须为布尔值'); }
            if ($providerId === 'godaddy' && array_key_exists('api_base_url', $body)) {
                $url = trim($body['api_base_url']);
                if ($url === '') { $url = 'https://api.godaddy.com'; }
                $parts = parse_url($url);
                if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) || !filter_var($url, FILTER_VALIDATE_URL)
                    || !$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
                    || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                    throw new InvalidArgumentException('GoDaddy API 地址需为 HTTPS 地址，不能包含账号、密码、查询参数或片段');
                }
                $next['api_base_url'] = rtrim($url, '/');
            }
            foreach ($schema['fields'] as $field) {
                $key = $field['key'];
                $value = trim((string) ($body[$key] ?? ''));
                if ($value !== '') { $next[$key] = $value; }
                if (empty($next[$key]) || strlen($next[$key]) > 1024 || preg_match('/[\x00-\x20\x7f]/', $next[$key])) {
                    throw new InvalidArgumentException('请填写有效的 ' . $field['label']);
                }
            }
            if ($index === null) { $accounts[] = $next; } else { $accounts[$index] = $next; }
            return ['accounts' => array_values($accounts)];
        });
        return $savedId;
    }

    public function deleteAccount(string $id): void
    {
        $this->change(static function (array $all) use ($id): array {
            $accounts = array_values(array_filter($all['accounts'], static function ($account) use ($id) { return $account['id'] !== $id; }));
            if (count($accounts) === count($all['accounts'])) { throw new OutOfBoundsException('云账号不存在'); }
            return ['accounts' => $accounts];
        });
    }

    public function updateHttp(array $body): void
    {
        self::stringFields($body, ['proxy']);
        if (isset($body['clear_proxy']) && !is_bool($body['clear_proxy'])) { throw new InvalidArgumentException('clear_proxy 必须为布尔值'); }
        $this->change(static function (array $all) use ($body): array {
            $http = $all['http'];
            if (isset($body['timeout'])) { $http['timeout'] = self::integer($body['timeout'], 5, 120, '请求超时'); }
            if (isset($body['connect_timeout'])) { $http['connect_timeout'] = self::integer($body['connect_timeout'], 1, 30, '连接超时'); }
            if (($http['connect_timeout'] ?? 10) > ($http['timeout'] ?? 25)) { throw new InvalidArgumentException('连接超时不能大于请求超时'); }
            if (!empty($body['clear_proxy'])) { $http['proxy'] = ''; }
            elseif (isset($body['proxy']) && trim((string) $body['proxy']) !== '') {
                $proxy = trim((string) $body['proxy']);
                $parts = parse_url($proxy);
                if (!$parts || !isset($parts['host'], $parts['scheme']) || !in_array($parts['scheme'], ['http', 'https', 'socks5', 'socks5h'], true)
                    || preg_match('/[\x00-\x20\x7f]/', $proxy) || strlen($proxy) > 2048) {
                    throw new InvalidArgumentException('代理地址需为 http(s)://host:port 或 socks5(h)://host:port');
                }
                $http['proxy'] = $proxy;
            }
            return ['http' => $http];
        });
    }

    public static function integer($value, int $min, int $max, string $label): int
    {
        if (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) {
            throw new InvalidArgumentException($label . '需在 ' . $min . '–' . $max . ' 之间');
        }
        return (int) $value;
    }
}
