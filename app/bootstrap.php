<?php

/**
 * 应用引导：常量、自动加载、Session、公共函数。
 */

define('APP_ROOT', dirname(__DIR__));          // 项目根
define('CONFIG_DIR', APP_ROOT . '/config');
define('DATA_DIR', APP_ROOT . '/data');
define('PUBLIC_DIR', APP_ROOT . '/public');

date_default_timezone_set('Asia/Shanghai');
// Diagnostics belong in the server log, never mixed into JSON or public HTML.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');

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

/**
 * 条件请求（ETag/304）：响应前声明 ETag，命中 If-None-Match 时只回 304，
 * 让调用方省去快照重建与全量传输。GET 请求不落操作日志，提前退出不影响审计。
 */
function conditional_get(string $etag): void
{
    header('ETag: "' . $etag . '"');
    header('Cache-Control: no-cache');
    $header = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($header === '') { return; }
    if ($header === '*') { http_response_code(304); exit; }
    foreach (explode(',', $header) as $candidate) {
        if (trim(preg_replace('/^W\//i', '', trim($candidate)), '"') === $etag) {
            http_response_code(304);
            exit;
        }
    }
}

/** 资产数据指纹：核心台账（WorkspaceData::FILES）+ 快照附加数据源 + 5 分钟时间桶（覆盖过时翻转与快照 TTL）。 */
function asset_etag(string $query = ''): string
{
    $parts = [WorkspaceData::revision(DATA_DIR), $query, (string) (int) (time() / 300)];
    $extras = array_merge([DATA_DIR . '/ip_locations.json'], glob(DATA_DIR . '/cache/dns-*.json') ?: []);
    foreach ($extras as $file) {
        $parts[] = is_file($file) ? (string) filemtime($file) : 'missing';
    }
    return hash('sha256', implode('|', $parts));
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
