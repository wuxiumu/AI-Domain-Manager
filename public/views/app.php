<?php
/**
 * 登录壳（/ 与所有未匹配页面）：首次初始化 + 登录，成功后跳转 /screen。
 * 管理能力在独立页面（/screen 大屏、/settings 设置、/calendar 日历），本壳只负责进入。
 */
$assetUrl = static function (string $path): string {
    return $path . '?v=' . filemtime(PUBLIC_DIR . $path);
};
$styles = ['app', 'components', 'layout'];
$scripts = ['ui', 'cache', 'core', 'setup', 'login', 'app'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f6f2">
    <title>资产大屏 · 登录</title>
<?php foreach ($styles as $style): ?>
    <link rel="stylesheet" href="<?= $assetUrl('/assets/css/' . $style . '.css') ?>">
<?php endforeach; ?>
</head>
<body>
<svg class="icon-library" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="icon-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.7"/><rect x="14" y="3" width="7" height="7" rx="1.7"/><rect x="3" y="14" width="7" height="7" rx="1.7"/><rect x="14" y="14" width="7" height="7" rx="1.7"/></symbol>
    <symbol id="icon-arrow" viewBox="0 0 24 24"><path d="M5 12h14m-5-5 5 5-5 5"/></symbol>
</svg>
<?php require __DIR__ . '/setup.php'; ?>

<div id="login-view" class="view hidden">
    <div class="login-cosmos" aria-hidden="true"><div class="login-stars"></div><div class="login-orbit login-orbit-one"></div><div class="login-orbit login-orbit-two"></div><div class="login-beam"></div></div>
    <main class="login-shell">
    <div class="login-card">
        <div class="login-card-top"><span>SECURE ACCESS</span><span class="login-status"><i></i> SYSTEM ONLINE</span></div>
        <div class="login-logo"><img src="/assets/img/asset-workspace-logo.svg" alt=""></div>
        <p class="eyebrow">ASSET WORKSPACE</p>
        <h1>资产大屏</h1>
        <p class="login-sub">域名与 IP 资产，一屏掌握。</p>
        <form id="login-form" autocomplete="on">
            <label for="login-user">账号</label>
            <input type="text" id="login-user" autocomplete="username" required autofocus placeholder="输入管理账号">
            <label for="login-pass">密码</label>
            <input type="password" id="login-pass" autocomplete="current-password" required placeholder="输入密码">
            <label class="login-remember"><input type="checkbox" id="login-remember"><span>记住密码</span></label>
            <p class="login-remember-hint">勾选后密码将保存在此浏览器中，请勿在公用设备使用。</p>
            <label for="login-captcha">图形验证码</label>
            <div class="login-captcha-row">
                <input type="text" id="login-captcha" autocomplete="off" autocapitalize="characters" spellcheck="false" required maxlength="5" pattern="[A-Za-z0-9]{5}" aria-describedby="login-captcha-hint" placeholder="输入 5 位验证码">
                <button type="button" id="login-captcha-refresh" class="login-captcha-refresh" aria-label="更换验证码" title="点击更换验证码"><img id="login-captcha-image" alt="图形验证码" width="180" height="54"></button>
            </div>
            <p id="login-captcha-hint" class="login-captcha-hint" role="status" aria-live="polite">正在获取验证码…</p>
            <p id="login-error" class="error-text hidden" role="alert"></p>
            <button type="submit" id="login-btn" class="btn btn-primary btn-block" disabled>登录工作台</button>
        </form>
        <p class="login-env"><a href="/env-check" target="_blank" rel="noopener">无法登录？检查运行环境</a></p>
    </div>
    </main>
</div>

<!-- 已登录用户直接跳转 /screen；保留节点以兼容 setup.js / core.js 的显隐逻辑 -->
<div id="app-view" class="view hidden"></div>

<?php foreach ($scripts as $script): ?>
<script src="<?= $assetUrl('/assets/js/' . $script . '.js') ?>"></script>
<?php endforeach; ?>
</body>
</html>
