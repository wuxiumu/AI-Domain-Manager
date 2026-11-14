<?php
/**
 * 独立文档页（/docs）：浏览 docs/ 与根白名单文档，支持查看、编辑、新建与删除。
 * 自包含页面：共享 admin-page.css（统一头部与基础 token）+ docs-page.css；
 * 由 DocsController::page() 引入，需登录会话；$docsBootstrap 携带 CSRF。
 *
 * @var array|null $docsBootstrap
 */
$boot = is_array($docsBootstrap ?? null) ? $docsBootstrap : [];
$assetVer = static function (string $path): string {
    return htmlspecialchars($path) . '?v=' . filemtime(PUBLIC_DIR . $path);
};
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>文档库 · 资产大屏</title>
<link rel="stylesheet" href="<?= $assetVer('/assets/css/admin-page.css') ?>">
<link rel="stylesheet" href="<?= $assetVer('/assets/vendor/hljs-github-dark.min.css') ?>">
<link rel="stylesheet" href="<?= $assetVer('/assets/css/docs-page.css') ?>">
</head>
<body>
<header class="ap-head">
    <div class="ap-brand">
        <h1>文档库</h1>
        <span class="ap-sub">浏览 · 编辑 · 新建 · 删除（docs/ 目录与根白名单文档）</span>
    </div>
    <nav class="ap-nav">
        <a href="/screen">首页</a>
        <a href="/calendar">日历</a>
        <a href="/settings">设置</a>
        <a class="is-current" href="/docs">文档</a>
        <button type="button" id="ap-logout" title="退出登录">退出</button>
    </nav>
</header>

<main class="ap-main ap-wide">
    <div class="dc-layout">
        <section class="dc-list-panel ap-panel">
            <div class="ap-actions">
                <button type="button" class="ap-btn ap-btn-sm ap-btn-primary" id="dc-new">＋ 新建文档</button>
                <button type="button" class="ap-btn ap-btn-sm" id="dc-refresh" title="刷新清单">刷新</button>
            </div>
            <div class="dc-list" id="dc-list"></div>
        </section>

        <section class="dc-editor">
            <div class="ap-panel dc-editor" id="dc-editor" hidden>
                <div class="dc-editor-head">
                    <span class="dc-path" id="dc-path">--</span>
                    <span class="dc-dirty hidden" id="dc-dirty">● 未保存</span>
                    <div class="ap-actions">
                        <button type="button" class="ap-btn ap-btn-sm" id="dc-view-toggle">编辑</button>
                        <button type="button" class="ap-btn ap-btn-sm ap-btn-primary" id="dc-save">保存</button>
                        <button type="button" class="ap-btn ap-btn-sm ap-btn-danger" id="dc-delete">删除</button>
                    </div>
                </div>
                <div class="dc-code" id="dc-code" hidden>
                    <pre class="dc-lines" id="dc-lines" aria-hidden="true">1</pre>
                    <textarea class="dc-textarea" id="dc-textarea" spellcheck="false" placeholder="Markdown 内容"></textarea>
                </div>
                <div class="dc-preview sp-md" id="dc-preview"></div>
            </div>
            <div class="ap-panel dc-empty" id="dc-empty">从左侧选择一篇文档，或点击「＋ 新建文档」。<br>默认进入预览；点「编辑」修改内容（带行号）。<br>根目录白名单文档（README / AGENTS / CHANGELOG）可编辑但不可删除。</div>
        </section>
    </div>
</main>

<footer class="ap-foot">资产大屏 · 文档库（保存与删除自动进入操作审计）</footer>
<div class="ap-toast" id="ap-toast" role="status"></div>

<script>
window.__ADMIN__ = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= $assetVer('/assets/vendor/marked.min.js') ?>"></script>
<script src="<?= $assetVer('/assets/vendor/purify.min.js') ?>"></script>
<script src="<?= $assetVer('/assets/vendor/highlight.min.js') ?>"></script>
<script src="<?= $assetVer('/assets/vendor/mermaid.min.js') ?>"></script>
<script src="<?= $assetVer('/assets/js/admin-page.js') ?>"></script>
<script src="<?= $assetVer('/assets/js/docs-page.js') ?>"></script>
</body>
</html>
