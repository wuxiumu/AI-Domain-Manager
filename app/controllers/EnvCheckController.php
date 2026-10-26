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
        $checks = [['name' => 'PHP 要求', 'status' => version_compare(PHP_VERSION, '7.4.0', '>=') ? 'ok' : 'fail', 'detail' => '要求 PHP 7.4+，初始化后可查看完整交付自检。']];
        foreach (['curl', 'mbstring', 'json', 'openssl'] as $ext) {
            $checks[] = ['name' => $ext . ' 扩展', 'status' => extension_loaded($ext) ? 'ok' : 'fail', 'detail' => extension_loaded($ext) ? '已加载' : '请启用该扩展'];
        }
        json_out(['ok' => true, 'checks' => $checks]);
    }
}
