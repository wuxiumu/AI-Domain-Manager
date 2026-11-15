<?php
/**
 * 独立设置页（/settings）：云账号、API 密钥、通用设置。
 * 自包含页面：不加载 SPA 的 CSS/JS；由 SettingsController::page() 引入，需登录会话。
 *
 * @var array|null $settingsBootstrap
 */
$boot = is_array($settingsBootstrap ?? null) ? $settingsBootstrap : [];
$assetVer = static function (string $path): string {
    return htmlspecialchars($path) . '?v=' . filemtime(PUBLIC_DIR . $path);
};
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0b1220">
<title>产品设置 · 资产大屏</title>
<link rel="icon" type="image/svg+xml" href="/assets/img/asset-workspace-logo.svg">
<link rel="stylesheet" href="<?= $assetVer('/assets/css/admin-page.css') ?>">
<link rel="stylesheet" href="<?= $assetVer('/assets/vendor/hljs-github-dark.min.css') ?>">
<link rel="stylesheet" href="<?= $assetVer('/assets/css/settings-page.css') ?>">
</head>
<body>
<header class="ap-head">
    <div class="ap-brand">
        <h1>产品设置</h1>
        <span class="ap-sub">云账号 · API 密钥 · 通用配置</span>
    </div>
    <nav class="ap-nav">
        <a href="/screen">首页</a>
        <a href="/calendar">日历</a>
        <a class="is-current" href="/settings">设置</a>
        <a href="/docs">文档</a>
        <button type="button" id="ap-logout" title="退出登录">退出</button>
    </nav>
</header>

<main class="ap-main ap-wide">
    <div class="ap-tabs" role="tablist" aria-label="设置分类">
        <button type="button" data-sp-tab="accounts" aria-selected="true">云账号</button>
        <button type="button" data-sp-tab="api" aria-selected="false">AI API</button>
        <button type="button" data-sp-tab="records" aria-selected="false">修改记录与文档</button>
        <button type="button" data-sp-tab="general" aria-selected="false">通用设置</button>
    </div>

    <!-- 云账号 -->
    <section data-sp-panel="accounts" style="display:flex;flex-direction:column;gap:14px">
        <div class="sp-section-head">
            <h2>云账号</h2>
            <span class="ap-muted">密钥保存后仅显示配置状态；保存后可测试连接，再到大屏显式同步。</span>
            <div class="ap-actions">
                <button type="button" class="ap-btn" id="sp-test-all">测试全部连接</button>
                <button type="button" class="ap-btn ap-btn-primary" id="sp-account-add">添加云账号</button>
            </div>
        </div>
        <div class="sp-accounts" id="sp-accounts"></div>
        <section id="sp-result-section" hidden>
            <div class="sp-section-head"><h2 style="font-size:14px">连接测试记录</h2><span class="ap-muted">当前会话</span></div>
            <div class="sp-results" id="sp-result-list"></div>
        </section>
    </section>

    <!-- AI API -->
    <section data-sp-panel="api" hidden style="display:flex;flex-direction:column;gap:14px">
        <div class="sp-intro">
            <div>
                <h2>让 AI 读懂资产，也能按权限操作。</h2>
                <p class="ap-muted">支持标准 OpenAPI、能力发现和 Bearer Token。密码与云密钥不会通过查询接口返回。</p>
            </div>
            <div class="ap-actions">
                <a class="ap-btn ap-btn-sm" href="/api/openapi.json" target="_blank" rel="noopener">OpenAPI 3.1</a>
                <a class="ap-btn ap-btn-sm" href="/.well-known/asset-workspace.json" target="_blank" rel="noopener">能力发现</a>
                <a class="ap-btn ap-btn-sm" href="/llms.txt" target="_blank" rel="noopener">AI 使用说明</a>
            </div>
        </div>
        <div class="sp-grid">
            <section class="ap-panel">
                <div class="ap-panel-head"><h2>创建 API 密钥</h2><span class="ap-badge">BEARER</span></div>
                <form id="sp-token-form" class="ap-form" autocomplete="off">
                    <div class="ap-row">
                        <label>用途名称<input id="sp-token-name" required maxlength="100" placeholder="如：AI 资产巡检"></label>
                        <label>有效天数<input id="sp-token-days" type="number" min="1" max="365" value="90" required></label>
                    </div>
                    <fieldset class="sp-scopes"><legend>授权范围</legend><div id="sp-scopes"></div></fieldset>
                    <label>当前管理员密码<input id="sp-token-password" type="password" required autocomplete="current-password"></label>
                    <p class="ap-error hidden" role="alert"></p>
                    <button class="ap-btn ap-btn-primary" type="submit">创建密钥</button>
                </form>
            </section>
            <section class="ap-panel">
                <div class="ap-panel-head"><h2>调用方式</h2><span class="ap-badge">JSON API</span></div>
                <p class="ap-muted">将接口描述导入支持 OpenAPI 的 AI 工具，认证方式选 Bearer Token。密码登录 API 需要图形验证码；无人值守接入使用 API 密钥。</p>
                <pre class="sp-code" id="sp-curl-example"></pre>
                <p class="ap-help">读取大屏快照用 <code>GET /api/domains/screen</code>；AI 可读文档用 <code>/api/domains/screen.md</code>；DNS 修改、同步有独立权限。</p>
                <div id="sp-token-once" class="sp-token-once hidden" role="status">
                    <strong>请保存新密钥，离开后无法再次查看。</strong>
                    <code id="sp-token-value"></code>
                    <button type="button" class="ap-btn ap-btn-sm" id="sp-token-copy">复制密钥</button>
                    <button type="button" class="ap-btn ap-btn-sm" id="sp-token-dismiss">已保存，隐藏密钥</button>
                    <div class="sp-token-usage">
                        <div class="sp-token-usage-head">
                            <span>AI 接入材料（复制整段交给 AI 即可开始使用）</span>
                            <button type="button" class="ap-btn ap-btn-sm" id="sp-token-copy-usage">复制接入材料</button>
                        </div>
                        <pre id="sp-token-usage"></pre>
                    </div>
                </div>
            </section>
        </div>
        <section class="ap-panel">
            <div class="ap-panel-head"><h2>已授权的 API 密钥</h2><span class="ap-muted">可随时撤销</span></div>
            <div class="sp-token-list" id="sp-token-list"></div>
        </section>
    </section>

    <!-- 修改记录与文档：内部子标签吸顶切换 -->
    <section data-sp-panel="records" hidden style="display:flex;flex-direction:column;gap:14px">
        <div class="sp-subtabs" role="tablist" aria-label="记录分类">
            <button type="button" data-sp-sub="overview" aria-selected="true">系统概况</button>
            <button type="button" data-sp-sub="changelog" aria-selected="false">产品修改记录</button>
            <button type="button" data-sp-sub="dns" aria-selected="false">DNS 变更记录</button>
            <button type="button" data-sp-sub="audit" aria-selected="false">操作记录</button>
        </div>

        <section data-sp-subpanel="overview">
            <div class="ap-panel">
                <div class="ap-panel-head"><h2>系统概况</h2><span class="ap-badge">CODE &amp; MEMORY</span></div>
                <p class="ap-muted">当前部署实例的代码规模与运行时内存；数据保存在服务器 data/ 目录（0600 私有存储）。</p>
                <div id="sp-stats" class="sp-stats"><p class="ap-muted">正在读取…</p></div>
            </div>
        </section>

        <section data-sp-subpanel="changelog" hidden>
            <div class="ap-panel">
                <div class="ap-panel-head"><h2>产品修改记录</h2><span class="ap-badge">CHANGELOG</span></div>
                <p class="ap-muted">每一个版本的变更如实记录在仓库 CHANGELOG.md，页面直接展示原文。</p>
                <div class="sp-md" id="sp-changelog"><p class="ap-muted">正在读取…</p></div>
            </div>
        </section>

        <section data-sp-subpanel="dns" hidden>
            <div class="ap-panel">
                <div class="ap-panel-head"><h2>DNS 变更记录</h2><span class="ap-muted">最近 50 条 · 服务器保留 200 条</span></div>
                <p class="ap-muted">每一次 DNS 写入（含 AI 提案批准与暂停 / 恢复）的前后值与回读核实结果。待核实表示结果未确认，请先刷新云端记录，避免重复提交。</p>
                <div class="sp-table-wrap"><table class="sp-table"><thead><tr><th>发起时间</th><th>目标</th><th>操作</th><th>操作者</th><th>前后值</th></tr></thead><tbody id="sp-dns-changes"><tr><td colspan="5" class="ap-muted">尚未加载</td></tr></tbody></table></div>
            </div>
        </section>

        <section data-sp-subpanel="audit" hidden>
            <div class="ap-panel">
                <div class="ap-panel-head"><h2>操作记录</h2><span class="ap-muted">最近 100 条 · 服务器保留 1,000 条</span></div>
                <p class="ap-muted">记录已通过登录与权限校验的写入请求；不含请求正文与任何秘密。</p>
                <div class="sp-table-wrap"><table class="sp-table"><thead><tr><th>发起时间</th><th>操作者</th><th>操作</th><th>结果</th></tr></thead><tbody id="sp-audit"><tr><td colspan="4" class="ap-muted">尚未加载</td></tr></tbody></table></div>
            </div>
        </section>
    </section>

    <!-- 通用设置 -->
    <section data-sp-panel="general" hidden class="sp-grid">
        <section class="ap-panel">
            <div class="ap-panel-head"><h2>网络与云接口</h2><span class="ap-badge">HTTP</span></div>
            <p class="ap-muted">用于云账号连接与域名同步。</p>
            <form id="sp-http-form" class="ap-form">
                <label>代理地址<input id="sp-proxy" type="password" autocomplete="new-password" placeholder="留空保留现有配置，支持 http / https / socks5"></label>
                <p id="sp-proxy-state" class="ap-help"></p>
                <label class="ap-check"><input id="sp-clear-proxy" type="checkbox"> 清除现有代理，改为直接连接</label>
                <div class="ap-row">
                    <label>请求超时（秒）<input id="sp-timeout" type="number" min="5" max="120" value="25" required></label>
                    <label>连接超时（秒）<input id="sp-connect-timeout" type="number" min="1" max="30" value="10" required></label>
                </div>
                <p class="ap-error hidden" role="alert"></p>
                <button class="ap-btn ap-btn-primary" type="submit">保存网络配置</button>
            </form>
        </section>
        <section class="ap-panel">
            <div class="ap-panel-head"><h2>大屏自动刷新</h2><span class="ap-badge">SCREEN</span></div>
            <p class="ap-muted">默认手动：大屏点「刷新」重新读取缓存快照，点「更新数据」拉取云端。开启后按周期自动轮询快照。</p>
            <form id="sp-screen-form" class="ap-form">
                <label class="ap-check"><input id="sp-screen-auto" type="checkbox"> 开启大屏自动刷新</label>
                <label>刷新周期（秒）<input id="sp-screen-seconds" type="number" min="60" max="3600" value="300" required></label>
                <p class="ap-help">范围 60–3600 秒；弹窗打开或批量操作期间自动暂停轮询。</p>
                <p class="ap-error hidden" role="alert"></p>
                <button class="ap-btn ap-btn-primary" type="submit">保存刷新配置</button>
            </form>
        </section>
        <section class="ap-panel">
            <div class="ap-panel-head"><h2>登录账号与密码</h2><span id="sp-admin" class="ap-badge"></span></div>
            <p class="ap-muted">更新后需重新登录，其他会话和旧 API 密钥也会失效。</p>
            <form id="sp-auth-form" class="ap-form" autocomplete="off">
                <label>当前密码<input id="sp-current-password" type="password" required autocomplete="current-password"></label>
                <label>管理员账号<input id="sp-user" required minlength="3" maxlength="64" autocomplete="username"></label>
                <label>新密码<input id="sp-new-password" type="password" required minlength="12" maxlength="72" autocomplete="new-password" placeholder="12–72 字节"></label>
                <label>确认新密码<input id="sp-confirm-password" type="password" required autocomplete="new-password"></label>
                <p class="ap-error hidden" role="alert"></p>
                <button class="ap-btn ap-btn-primary" type="submit">更新登录信息</button>
            </form>
            <div class="sp-forget">
                <p class="ap-help">公用设备上一键删除此浏览器自动填入的账号密码（下次登录需手动输入）；云账号凭据与 API 密钥不受影响。</p>
                <button type="button" id="sp-forget-remembered" class="ap-btn ap-btn-danger">清除本机记住的密码</button>
            </div>
        </section>
    </section>
</main>

<footer class="ap-foot">资产大屏 · 产品设置</footer>
<div class="ap-toast" id="ap-toast" role="status"></div>

<script>
window.__ADMIN__ = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= $assetVer('/assets/vendor/marked.min.js') ?>"></script>
<script src="<?= $assetVer('/assets/vendor/purify.min.js') ?>"></script>
<script src="<?= $assetVer('/assets/vendor/highlight.min.js') ?>"></script>
<script src="<?= $assetVer('/assets/vendor/mermaid.min.js') ?>"></script>
<script src="<?= $assetVer('/assets/js/admin-page.js') ?>"></script>
<script src="<?= $assetVer('/assets/js/settings-page.js') ?>"></script>
</body>
</html>
