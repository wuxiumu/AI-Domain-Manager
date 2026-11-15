<?php

/** Installation hints are public; configured instances require a browser session. */
class EnvCheckController
{
    public function page(): void
    {
        if (settings_service()->configured()) { Auth::requireSession(); }
        header('Cache-Control: no-store');
        require PUBLIC_DIR . '/views/env.php';
    }

    public function index(): void
    {
        header('Cache-Control: no-store');
        if (settings_service()->configured()) {
            Auth::requireSession();
            json_out(['ok' => true, 'time' => date('c'), 'checks' => DeliveryCheck::run()]);
        }
        $checks = [['name' => 'PHP 要求', 'status' => version_compare(PHP_VERSION, '7.4.0', '>=') ? 'ok' : 'fail', 'detail' => '要求 PHP 7.4+，当前 ' . PHP_VERSION . '。']];
        foreach (['curl', 'mbstring', 'json', 'openssl', 'gd'] as $ext) {
            $loaded = extension_loaded($ext);
            $detail = $loaded ? '已加载' : ($ext === 'gd' ? '登录图形验证码需要 GD' : '请启用该扩展');
            $checks[] = ['name' => $ext . ' 扩展', 'status' => $loaded ? 'ok' : 'fail', 'detail' => $detail];
        }
        // 首次安装最常见故障：data/ 缺失或 Web 进程不可写（表现为所有写状态接口 500）。
        $writable = is_dir(DATA_DIR) && is_writable(DATA_DIR);
        $checks[] = ['name' => '数据目录可写', 'status' => $writable ? 'ok' : 'fail', 'detail' => $writable ? 'data/ 已就绪' : '请创建 data/ 并授予 Web 运行用户写权限（mkdir data && chown <运行用户> data）'];
        json_out(['ok' => true, 'checks' => $checks]);
    }
}
