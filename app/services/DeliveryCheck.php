<?php

/** Administrator-only, path-free delivery checks; no remote requests are made. */
class DeliveryCheck
{
    public static function run(): array
    {
        $auth = config('auth') ?? [];
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        $checks = [
            ['name' => '运行环境', 'status' => version_compare(PHP_VERSION, '7.4.0', '>=') ? 'ok' : 'fail', 'detail' => '要求 PHP 7.4+；生产环境使用仍受维护的 PHP 版本。'],
            ['name' => '网站根目录', 'status' => realpath($_SERVER['DOCUMENT_ROOT'] ?? '') === realpath(PUBLIC_DIR) ? 'ok' : 'fail', 'detail' => 'Web 服务根目录必须严格指向 public，禁止公开项目根目录。'],
            ['name' => '数据目录', 'status' => is_dir(DATA_DIR) && is_writable(DATA_DIR) ? 'ok' : 'fail', 'detail' => '资产、备份与日志保存在网站根目录之外。'],
            ['name' => '管理员密码', 'status' => !empty($auth['pass_hash']) ? 'ok' : 'warn', 'detail' => !empty($auth['pass_hash']) ? '使用密码哈希。' : '当前为旧配置明文密码，请在工作台配置中更新登录信息。'],
            ['name' => '访问加密', 'status' => $secure ? 'ok' : 'warn', 'detail' => $secure ? '当前连接为 HTTPS。' : ($local ? '当前是本地 HTTP，正式交付需配置 HTTPS。' : '当前未识别到 HTTPS，请配置证书及可信反向代理。')],
            ['name' => '诊断访问', 'status' => 'ok', 'detail' => '初始化后环境诊断仅管理员会话可访问，API 密钥不能读取。'],
        ];
        foreach (['curl', 'mbstring', 'json', 'openssl'] as $extension) {
            $checks[] = ['name' => $extension . ' 扩展', 'status' => extension_loaded($extension) ? 'ok' : 'fail', 'detail' => extension_loaded($extension) ? '已加载' : '请在 PHP 环境中启用'];
        }
        return $checks;
    }
}
