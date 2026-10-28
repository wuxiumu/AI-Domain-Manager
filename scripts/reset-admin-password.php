<?php
/** Reset the configured administrator password from a local interactive terminal. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 1 || !function_exists('stream_isatty') || !stream_isatty(STDIN)) {
    fwrite(STDERR, "请在服务器交互式终端运行：php scripts/reset-admin-password.php\n");
    exit(1);
}
$root = dirname(__DIR__);
require $root . '/app/core/PrivateJsonStore.php';
require $root . '/app/services/SettingsService.php';

function readSecret(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $state = shell_exec('stty -g');
    if (!is_string($state) || !preg_match('/^[a-zA-Z0-9:;=+-]+$/D', trim($state))) {
        throw new RuntimeException('无法读取终端设置，未更改密码');
    }
    $state = trim($state);
    $restored = false;
    $restore = static function () use ($state, &$restored): void {
        if (!$restored) { system('stty ' . escapeshellarg($state)); $restored = true; }
    };
    register_shutdown_function($restore);
    system('stty -echo', $status);
    if ($status !== 0) { $restore(); throw new RuntimeException('无法关闭终端回显，未更改密码'); }
    try { $line = fgets(STDIN); }
    finally { $restore(); fwrite(STDOUT, "\n"); }
    if ($line === false) { throw new RuntimeException('未读取到密码'); }
    return rtrim($line, "\r\n");
}
try {
    $settings = new SettingsService($root . '/config', $root . '/data');
    if (!$settings->configured()) { throw new DomainException('尚未配置管理员，请使用首次配置页面'); }
    fwrite(STDOUT, '管理员账号：' . $settings->all()['auth']['user'] . "\n");
    $password = readSecret('输入新密码（12–72 字节）：');
    $confirmation = readSecret('再次输入新密码：');
    if (!hash_equals($password, $confirmation)) { throw new InvalidArgumentException('两次输入的密码不一致'); }
    $settings->resetPasswordFromCli($password);
    fwrite(STDOUT, "密码已重置。原登录会话和 API 密钥已失效，请重新登录并更新浏览器保存的密码。\n");
} catch (Throwable $error) {
    fwrite(STDERR, '重置失败：' . $error->getMessage() . "\n");
    exit(1);
}
