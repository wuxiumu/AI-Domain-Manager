<?php

/**
 * 应用引导：常量、自动加载、Session、公共函数。
 */

define('APP_ROOT', dirname(__DIR__));          // 项目根
define('CONFIG_DIR', APP_ROOT . '/config');
define('DATA_DIR', APP_ROOT . '/data');
define('PUBLIC_DIR', APP_ROOT . '/public');

date_default_timezone_set('Asia/Shanghai');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    // Keep this installation's sessions separate from other local PHP apps.
    session_name('AW_' . substr(hash('sha256', APP_ROOT), 0, 12));
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}

/** 自动加载：在 app 各子目录（core/providers/services/controllers）按类名找文件 */
spl_autoload_register(function (string $class): void {
    if (strpos($class, '\\') !== false) {
        return;
    }
    foreach (['core', 'providers', 'services', 'controllers', ''] as $dir) {
        $file = APP_ROOT . '/app/' . ($dir ? $dir . '/' : '') . $class . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

/** Config files remain compatible; editable overrides live outside the document root. */
function settings_service(): SettingsService
{
    static $service = null;
    if ($service === null) { $service = new SettingsService(CONFIG_DIR, DATA_DIR); }
    return $service;
}

/** Read either a top-level or a dotted configuration key. */
function config(?string $key = null)
{
    $cfg = settings_service()->all();
    if ($key === null) { return $cfg; }
    if (array_key_exists($key, $cfg)) { return $cfg[$key]; }
    foreach (explode('.', $key) as $part) {
        if (!is_array($cfg) || !array_key_exists($part, $cfg)) { return null; }
        $cfg = $cfg[$part];
    }
    return $cfg;
}

/** 输出 JSON 并结束 */
function json_out($data, int $code = 200): void
{
    AuditLog::finish($data, $code);
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 读取请求 JSON body */
function body_json(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

/** 密钥脱敏：保留首 4 位与末 4 位 */
function mask_secret(string $s): string
{
    if ($s === '') {
        return '';
    }
    $len = mb_strlen($s);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    return mb_substr($s, 0, 4) . str_repeat('*', $len - 8) . mb_substr($s, -4);
}

/** 云商 Provider 工厂 */
function provider_factory(array $account): CloudProvider
{
    if (($account['enabled'] ?? true) === false) { throw new RuntimeException('该云账号已停用，请先在云账号配置中启用'); }
    $map = [
        'aliyun'  => AliyunProvider::class,
        'tencent' => TencentProvider::class,
        'godaddy' => GoDaddyProvider::class,
    ];
    $cls = $map[$account['provider'] ?? ''] ?? null;
    if (!$cls) {
        throw new RuntimeException('未知云商类型: ' . ($account['provider'] ?? ''));
    }
    return new $cls($account);
}
