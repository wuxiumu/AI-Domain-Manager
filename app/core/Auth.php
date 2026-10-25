<?php

/**
 * 登录鉴权：Session + 登录失败限流（同一 IP 连续失败锁定 5 分钟）。
 */
class Auth
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_SECONDS = 300;
    private static bool $bearerResolved = false;
    private static ?array $bearerIdentity = null;

    public static function isBearer(): bool
    {
        return isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
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

    /** 执行登录，成功返回 true，失败返回错误信息字符串 */
    public static function attempt(string $user, string $pass)
    {
        if (self::isLocked()) {
            return '尝试次数过多，请 ' . self::remainingLockSeconds() . ' 秒后再试';
        }
        $cfg = config('auth');
        $validPassword = settings_service()->verifyPassword($pass);
        $ok = hash_equals((string) ($cfg['user'] ?? ''), $user) && $validPassword;
        if ($ok) {
            self::clearFailures();
            self::establish($user);
            return true;
        }
        self::recordFailure();
        return '用户名或密码错误';
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
        if (in_array($route, ['/api/login', '/api/me', '/api/setup/status', '/api/setup', '/api/openapi.json', '/api/capabilities', '/api/env-check'], true)) { return; }
        if (strpos($route, '/api/') !== 0) { return; }
        self::requireLogin();
        if (!self::isBearer()) {
            if (!in_array($method, ['GET', 'HEAD'], true)) { self::requireCsrf(); }
            return;
        }
        $scope = null;
        if ($method === 'POST' && ($route === '/api/health/check' || $route === '/api/access-check'
            || substr($route, -13) === '/access-check')) { $scope = 'checks:run'; }
        elseif ($route === '/api/sync' || $route === '/api/test-all' || $route === '/api/accounts/{id}/test') { $scope = 'sync:run'; }
        elseif (strpos($route, '/records') !== false) { $scope = $method === 'GET' ? 'dns:read' : 'dns:write'; }
        elseif (in_array($route, ['/api/health', '/api/accounts', '/api/domains', '/api/domains/stats', '/api/sync/logs', '/api/access-check'], true) && $method === 'GET') { $scope = 'assets:read'; }
        elseif (in_array($route, ['/api/domains', '/api/health/ips', '/api/health/ips/{id}', '/api/health/domains/{id}/bindings'], true) && $method !== 'GET') { $scope = 'assets:write'; }
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

    public static function requireLogin(): void
    {
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

    private static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'cli';
    }

    private static function state(): array
    {
        return JsonStore::read(DATA_DIR . '/ratelimit.json', []);
    }

    private static function save(array $state): void
    {
        JsonStore::write(DATA_DIR . '/ratelimit.json', $state);
    }

    public static function isLocked(): bool
    {
        $s = self::state();
        $ip = self::ip();
        return isset($s[$ip]['lock_until']) && time() < $s[$ip]['lock_until'];
    }

    public static function remainingLockSeconds(): int
    {
        $s = self::state();
        $ip = self::ip();
        return max(0, (int) ($s[$ip]['lock_until'] ?? 0) - time());
    }

    private static function recordFailure(): void
    {
        $s = self::state();
        $ip = self::ip();
        $s[$ip]['count'] = (int) ($s[$ip]['count'] ?? 0) + 1;
        if ($s[$ip]['count'] >= self::MAX_ATTEMPTS) {
            $s[$ip]['lock_until'] = time() + self::LOCK_SECONDS;
        }
        self::save($s);
    }

    private static function clearFailures(): void
    {
        $s = self::state();
        unset($s[self::ip()]);
        self::save($s);
    }
}
