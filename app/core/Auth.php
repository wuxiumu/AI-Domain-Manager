<?php

/**
 * Session / Bearer authentication. Password entry is guarded by AuthController.
 */
class Auth
{
    private static bool $bearerResolved = false;
    private static ?array $bearerIdentity = null;

    public static function isBearer(): bool
    {
        return isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    /** 当前 Bearer 密钥持有的权限范围；会话调用返回空数组。 */
    public static function scopes(): array
    {
        return self::isBearer() ? (self::bearer()['scopes'] ?? []) : [];
    }

    private static function bearer(): ?array
    {
        if (!self::$bearerResolved) {
            self::$bearerResolved = true;
            $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
            if (preg_match('/^Bearer\s+(\S+)$/iD', $header, $matches)) {
                self::$bearerIdentity = (new ApiTokenService(DATA_DIR, settings_service()))->authenticate($matches[1]);
            }
        }
        return self::$bearerIdentity;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function user(): ?string
    {
        if (self::isBearer()) { return self::bearer()['user'] ?? null; }
        $user = $_SESSION['auth_user'] ?? null;
        if (!$user) { return null; }
        $settings = settings_service();
        if ($user !== ($settings->all()['auth']['user'] ?? null)) { return null; }
        $fingerprint = $settings->fingerprint();
        if (!isset($_SESSION['auth_fingerprint'])) {
            // Accept pre-upgrade sessions only while still using legacy credentials.
            if (!empty($settings->all()['auth']['pass_hash'])) { return null; }
            $_SESSION['auth_fingerprint'] = $fingerprint;
        }
        return hash_equals($_SESSION['auth_fingerprint'], $fingerprint) ? $user : null;
    }

    public static function verifyCredentials(string $user, string $pass): bool
    {
        $cfg = config('auth');
        $validPassword = settings_service()->verifyPassword($pass);
        return hash_equals((string) ($cfg['user'] ?? ''), $user) && $validPassword;
    }

    public static function establish(string $user): void
    {
        session_regenerate_id(true);
        $_SESSION = ['auth_user' => $user, 'auth_fingerprint' => settings_service()->fingerprint(), 'csrf_token' => bin2hex(random_bytes(32))];
    }

    public static function requireSession(): void
    {
        if (self::isBearer()) { json_out(['ok' => false, 'error' => '配置和密钥管理需要管理员登录会话', 'code' => 'session_required'], 403); }
        self::requireLogin();
    }

    /** Deny unlisted routes to tokens; browser sessions keep CSRF protection. */
    public static function authorizeRoute(string $method, string $route): void
    {
        if (in_array($route, ['/api/login', '/api/login/captcha', '/api/me', '/api/setup/status', '/api/setup', '/api/openapi.json', '/api/capabilities', '/api/env-check'], true)) { return; }
        if (strpos($route, '/api/') !== 0) { return; }
        self::requireLogin();
        if (!self::isBearer()) {
            if (!in_array($method, ['GET', 'HEAD'], true)) { self::requireCsrf(); }
            return;
        }
        $scope = null;
        if ($method === 'POST' && ($route === '/api/ips/{ip}/location'
            || substr($route, -13) === '/access-check')) { $scope = 'checks:run'; }
        elseif ($route === '/api/sync' || $route === '/api/test-all' || $route === '/api/accounts/{id}/test' || $route === '/api/accounts/{id}/sync') { $scope = 'sync:run'; }
        elseif ($route === '/api/proposals/{id}/approve' || $route === '/api/proposals/{id}/reject') { $scope = null; } // 仅管理员会话：批准会执行 DNS 写入
        elseif ($route === '/api/proposals') { $scope = 'proposals:write'; }
        elseif (strpos($route, '/records') !== false) { $scope = $method === 'GET' ? 'dns:read' : 'dns:write'; }
        elseif (in_array($route, ['/api/accounts', '/api/domains', '/api/domains/screen', '/api/domains/screen.md', '/api/domains/screen/report', '/api/domains/stats', '/api/sync/logs', '/api/ips/screen', '/api/ips/screen.md'], true) && $method === 'GET') { $scope = 'assets:read'; }
        elseif (in_array($route, ['/api/domains', '/api/ips', '/api/ips/{id}'], true) && $method !== 'GET') { $scope = 'assets:write'; }
        if ($scope === null || !in_array($scope, self::bearer()['scopes'] ?? [], true)) {
            json_out(['ok' => false, 'error' => $scope ? 'API 密钥缺少权限：' . $scope : '此接口仅供管理员会话使用', 'code' => 'insufficient_scope', 'required_scope' => $scope], 403);
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** 开发环境免登录：仅回环请求 + 显式开启（AW_DEV_UNLOCK=1 或 config/dev-unlock.flag），生产不会意外启用。 */
    public static function isDevUnlock(): bool
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remote !== '127.0.0.1' && $remote !== '::1') { return false; }
        if (getenv('AW_DEV_UNLOCK') === '1') { return true; }
        return defined('CONFIG_DIR') && is_file(CONFIG_DIR . '/dev-unlock.flag');
    }

    /** 开发模式下为未登录的回环请求自动建立会话；未启用或已登录时不动。 */
    public static function devUnlock(): void
    {
        if (self::check() || !self::isDevUnlock()) { return; }
        // 回环开发模式：用在用账号名自动建立会话，与正常登录等价（含 CSRF 与指纹校验），并打标供界面提示。
        error_log('DEV-UNLOCK: loopback request auto-login (dev mode)');
        $configured = (string) (settings_service()->all()['auth']['user'] ?? '');
        self::establish($configured !== '' ? $configured : 'dev');
        $_SESSION['dev_unlock'] = true;
    }

    public static function requireLogin(): void
    {
        self::devUnlock();
        if (!self::check()) {
            if (self::isBearer()) { header('WWW-Authenticate: Bearer realm="Asset Workspace"'); }
            json_out(['ok' => false, 'error' => self::isBearer() ? 'API 密钥无效、已过期或已撤销' : '未登录', 'code' => 'unauthenticated'], 401);
        }
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function requireCsrf(): void
    {
        $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($token === '' || !hash_equals(self::csrfToken(), $token)) {
            json_out(['ok' => false, 'error' => '页面验证已过期，请刷新后重试'], 403);
        }
    }

}
