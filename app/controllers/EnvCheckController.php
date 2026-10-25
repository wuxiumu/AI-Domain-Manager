<?php

/**
 * 环境检查：免登录，用于虚拟主机部署排查。
 * 检查 PHP 版本、扩展、配置、目录权限、Session 与部署路径。
 */
class EnvCheckController
{
    /** 环境检查页面（独立 HTML，不依赖主前端资源） */
    public function page(): void
    {
        require PUBLIC_DIR . '/views/env.php';
    }

    /** 环境检查结果 JSON */
    public function index(): void
    {
        $checks = [];

        // 1. PHP 版本（项目要求 >= 7.4）
        $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
        $checks[] = [
            'name'   => 'PHP 版本',
            'status' => $phpOk ? 'ok' : 'fail',
            'detail' => '当前 ' . PHP_VERSION . '（项目最低要求 7.4）' . ($phpOk ? '' : '，版本过低会导致页面直接白屏/500，请在主机面板升级 PHP 到 7.4+'),
        ];

        // 2. 服务器软件与 SAPI
        $checks[] = [
            'name'   => '服务器环境',
            'status' => 'info',
            'detail' => ($_SERVER['SERVER_SOFTWARE'] ?? '未知') . ' ｜ SAPI: ' . php_sapi_name(),
        ];

        // 3. 必需扩展
        foreach (['curl', 'mbstring', 'json', 'openssl'] as $ext) {
            $ok = extension_loaded($ext);
            $checks[] = [
                'name'   => "扩展 {$ext}",
                'status' => $ok ? 'ok' : 'fail',
                'detail' => $ok ? '已加载' : "未加载，请在虚拟主机面板启用（php.ini 中去掉 extension={$ext} 注释或面板勾选）",
            ];
        }

        // 4. 配置文件
        $cfgFile = CONFIG_DIR . '/config.php';
        $runtimeFile = DATA_DIR . '/settings.json';
        $cfgOk = is_readable($cfgFile) || is_readable($runtimeFile);
        $checks[] = [
            'name'   => '工作台配置',
            'status' => $cfgOk ? 'ok' : 'info',
            'detail' => $cfgOk ? '已有可读的工作台配置，兼容旧文件与界面保存的配置' : '尚未保存配置，打开工作台完成首次初始化即可',
        ];

        // 5. 登录配置完整性（不回显密码）
        $auth = config('auth') ?? [];
        $authOk = settings_service()->configured();
        $checks[] = [
            'name'   => '登录配置',
            'status' => $authOk ? 'ok' : 'fail',
            'detail' => $authOk ? '管理员登录信息已配置' : '尚未初始化，请回到工作台完成首次配置',
        ];

        // 6. data 目录可写（文件数据库依赖）
        $dataDir = DATA_DIR;
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0775, true);
        }
        $dataWritable = is_dir($dataDir) && is_writable($dataDir);
        if ($dataWritable) {
            $dataWritable = @file_put_contents($dataDir . '/.write_test', 'ok') !== false;
        }
        $checks[] = [
            'name'   => 'data 目录可写',
            'status' => $dataWritable ? 'ok' : 'fail',
            'detail' => $dataWritable
                ? $dataDir . ' 可读写（域名数据/检测结果存放于此）'
                : $dataDir . ' 不可写，登录与同步都会失败，请在主机面板将 data 目录权限设为 755 或 775（属主为运行用户）',
        ];

        // 7. Session 可用性
        $sp = session_save_path();
        $sessionOk = is_writable($sp !== '' ? $sp : sys_get_temp_dir());
        $sessOk = false;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['__envtest'] = '1';
            $sessOk = ($_SESSION['__envtest'] ?? '') === '1';
            unset($_SESSION['__envtest']);
        }
        $checks[] = [
            'name'   => 'Session',
            'status' => ($sessionOk && $sessOk) ? 'ok' : 'fail',
            'detail' => 'save_path=' . ($sp !== '' ? $sp : '（系统临时目录）') . ($sessOk ? '，读写正常' : '，无法写入 Session（登录将失效），请检查 session.save_path 目录权限'),
        ];

        // 8. 部署路径（虚拟主机子目录部署时前端资源会 404）
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $publicReal = realpath(PUBLIC_DIR);
        $docReal = realpath($docRoot ?: '');
        $deployOk = false;
        if ($docReal && $publicReal) {
            $deployOk = $docReal === $publicReal || strpos($publicReal . '/', $docReal . '/') === 0;
        }
        $checks[] = [
            'name'   => '站点部署目录',
            'status' => $deployOk ? 'ok' : ($publicReal ? 'warn' : 'fail'),
            'detail' => '网站根目录=' . ($docRoot ?: '未知')
                . '；项目 public 目录=' . ($publicReal ?: PUBLIC_DIR)
                . ($deployOk ? '' : '。两者不一致：请确认通过「public 目录」访问（如 https://域名/public/ 或把 public 内容放到网站根目录），否则页面样式/脚本(/assets)会 404'),
        ];

        json_out([
            'ok'           => true,
            'php_version'  => PHP_VERSION,
            'server'       => $_SERVER['SERVER_SOFTWARE'] ?? '未知',
            'sapi'         => php_sapi_name(),
            'time'         => date('Y-m-d H:i:s'),
            'checks'       => $checks,
        ]);
    }
}
