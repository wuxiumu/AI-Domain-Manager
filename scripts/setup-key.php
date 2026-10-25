<?php
/** Run on the installation's server. The one-time key is never served by HTTP. */
require __DIR__ . '/../app/bootstrap.php';
if (settings_service()->configured()) {
    if (!in_array('--if-needed', $argv, true)) { echo "工作台已初始化，请登录后进入产品设置。\n"; }
    exit(0);
}
if (!is_dir(CONFIG_DIR) && !mkdir(CONFIG_DIR, 0700, true)) { fwrite(STDERR, "无法创建 config 目录\n"); exit(1); }
$path = CONFIG_DIR . '/setup.key';
$file = @fopen($path, 'x');
if ($file) {
    chmod($path, 0600);
    fwrite($file, bin2hex(random_bytes(24)));
    fclose($file);
}
$key = trim((string) file_get_contents($path));
if ($key === '') { fwrite(STDERR, "初始化凭据无法读取\n"); exit(1); }
echo "首次配置凭据（完成初始化后失效）：\n" . $key . "\n";
