<?php
/**
 * 独立大屏页（/screen）：深色全屏展示未过期域名与子域名 A 记录，含 IP 视图。
 * 自包含页面：共享 admin-page.css（统一头部与基础 token）+ screen.css（大屏专属）；
 * 数据与交互见 assets/js/screen.js + screen-ip.js。
 * 由 ScreenController::page() 引入，需登录会话；$screenBootstrap 携带 CSRF。
 *
 * @var array|null $screenBootstrap
 */
$boot = is_array($screenBootstrap ?? null) ? $screenBootstrap : [];
$assetVer = static function (string $path): string {
    return htmlspecialchars($path) . '?v=' . filemtime(PUBLIC_DIR . $path);
};
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>资产大屏</title>
<link rel="stylesheet" href="<?= $assetVer('/assets/css/admin-page.css') ?>">
<link rel="stylesheet" href="<?= $assetVer('/assets/css/screen.css') ?>">
</head>
<body>
<header class="ap-head">
    <div class="ap-brand">
        <h1>资产大屏</h1>
        <span class="ap-sub" id="sb-sub">域名 · 未过期域名与子域名 A 记录（IPv4）</span>
    </div>
    <div class="ap-clock" id="sb-clock">--:--:--</div>
    <div class="ap-tools">
        <div class="sb-mode" role="tablist" aria-label="视图切换">
            <button type="button" class="sb-mode-btn" data-mode="domain" aria-pressed="true">域名</button>
            <button type="button" class="sb-mode-btn" data-mode="ip" aria-pressed="false">IP</button>
        </div>
        <span class="ap-cache" id="sb-cache">读取中…</span>
        <div class="sb-sync">
            <button type="button" class="sb-btn" id="sb-refresh-btn" title="重新读取缓存快照（不访问云商）">刷新</button>
            <select id="sb-sync-account" title="选择要更新数据的云账号"></select>
            <button type="button" class="sb-btn" id="sb-sync-btn">更新数据</button>
        </div>
        <nav class="ap-nav" aria-label="管理页面">
            <a class="is-current" href="/screen">首页</a>
            <a href="/calendar">日历</a>
            <a href="/settings">设置</a>
            <a href="/docs">文档</a>
            <button type="button" id="ap-logout" title="退出登录">退出</button>
        </nav>
    </div>
</header>

<nav class="sb-tabs" id="sb-tabs" aria-label="云账号筛选"></nav>

<div class="sb-toolbar">
    <div class="sb-search">
        <input type="search" id="sb-q" placeholder="搜索域名 / 子域名 / IP" autocomplete="off">
    </div>
    <select id="sb-status" title="访问状态筛选" hidden>
        <option value="">全部访问状态</option>
        <option value="ok">访问正常</option>
        <option value="fail">访问异常</option>
        <option value="unchecked">未检查</option>
    </select>
    <button type="button" class="sb-btn hidden" id="sb-domain-fecheck" title="浏览器直连探测当前列表全部主域名">前端检查</button>
    <button type="button" class="sb-btn hidden" id="sb-domain-becheck" title="服务端逐域名探测当前列表全部解析记录">后端检查</button>
    <select id="sb-sort" title="排序">
        <option value="expire">到期时间（先到期优先）</option>
        <option value="expire_desc">到期时间（后到期优先）</option>
        <option value="domain">域名 A → Z</option>
        <option value="subdomains">子域名数量</option>
        <option value="a">A 记录数量</option>
        <option value="synced">最近同步</option>
    </select>
    <select id="sb-size" title="每页条数（状态自动记忆）">
        <option value="12">每页 12</option>
        <option value="24" selected>每页 24</option>
        <option value="48">每页 48</option>
        <option value="all">全部展示</option>
    </select>
    <button type="button" class="sb-btn hidden" id="sb-addip" title="手工登记一个公网 IP">＋ 添加 IP</button>
    <button type="button" class="sb-btn hidden" id="sb-ip-fecheck" title="浏览器直连探测当前列表全部 IP">前端检查</button>
    <button type="button" class="sb-btn hidden" id="sb-ip-becheck" title="服务端探测当前列表全部 IP（不跟随跳转）">后端检查</button>
    <span class="sb-count" id="sb-count"></span>
    <div class="sb-page">
        <button type="button" id="sb-prev" title="上一页">‹</button>
        <span id="sb-page-info"></span>
        <button type="button" id="sb-next" title="下一页">›</button>
    </div>
</div>

<main class="sb-grid" id="sb-grid"></main>
<div class="sb-empty hidden" id="sb-empty"></div>

<div class="sb-modal hidden" id="sb-modal" role="dialog" aria-modal="true" aria-label="子域名与 IP 管理">
    <div class="sb-modal-mask" data-close="1"></div>
    <div class="sb-modal-box">
        <div class="sb-modal-head">
            <div id="sb-modal-head" class="sb-modal-head-main"></div>
            <button type="button" class="sb-modal-close" id="sb-modal-close" aria-label="关闭">✕</button>
        </div>
        <div class="sb-modal-tools" id="sb-modal-tools">
            <label class="sb-pickall"><input type="checkbox" id="sb-pickall"> 全选</label>
            <button type="button" class="sb-mini" id="sb-invert" title="反选：可见行的勾选状态互换">反选</button>
            <button type="button" class="sb-mini sb-mini-primary" id="sb-editip">修改 IP</button>
            <button type="button" class="sb-mini sb-mini-danger" id="sb-del">删除</button>
            <button type="button" class="sb-mini sb-mini-primary" id="sb-batchadd">批量添加</button>
            <span class="sb-tool-sep"></span>
            <button type="button" class="sb-mini" id="sb-fecheck">前端检查</button>
            <button type="button" class="sb-mini" id="sb-becheck">后端检查</button>
            <span class="sb-tool-hint" id="sb-tool-hint">前端 / 后端任一通过即通过 · 勾选后只检查选中项，未勾选检查全部 · 前端结果缓存于本浏览器 · 修改与删除直接写入云端 DNS</span>
        </div>
        <div class="sb-inline-bar hidden" id="sb-ipedit">
            <label>新 IP <input type="text" id="sb-ipinput" placeholder="IPv4，如 192.0.2.10" autocomplete="off" spellcheck="false"></label>
            <button type="button" class="sb-mini sb-mini-primary" id="sb-ipok">应用到选中</button>
            <button type="button" class="sb-mini" id="sb-ipcancel">取消</button>
        </div>
        <div class="sb-inline-bar sb-inline-danger hidden" id="sb-delconf">
            <span>确认删除选中的 <b id="sb-delcount">0</b> 组解析记录？只写一次并回读核实。</span>
            <button type="button" class="sb-mini sb-mini-danger" id="sb-delyes">确认删除</button>
            <button type="button" class="sb-mini" id="sb-delno">取消</button>
        </div>
        <div class="sb-inline-bar sb-inline-add hidden" id="sb-addbar">
            <textarea id="sb-addinput" rows="5" placeholder="如：www 192.0.2.10" spellcheck="false"></textarea>
            <div class="sb-addbar-foot">
                <span class="sb-tool-hint">格式：<b>主机 [类型] 值</b>，一行一条；简写「主机 IPv4」即 A 记录；# 为注释。支持 A / AAAA / CNAME / MX / TXT / NS；已存在的相同记录自动跳过；逐条写入云端并回读核实。</span>
                <button type="button" class="sb-mini" id="sb-record-demo" title="各类型解析的示例与常见规则">解析示例</button>
                <span class="sb-addbar-btns">
                    <button type="button" class="sb-mini sb-mini-primary" id="sb-addok">开始添加</button>
                    <button type="button" class="sb-mini" id="sb-addcancel">取消</button>
                </span>
            </div>
        </div>
        <div class="sb-modal-filter hidden" id="sb-modal-filter">
            <input type="search" id="sb-modal-q" placeholder="搜索主机名 / IP" autocomplete="off" spellcheck="false">
            <div class="sb-status-tabs" id="sb-status-tabs" role="group" aria-label="访问状态筛选">
                <button type="button" class="sb-status-tab is-active" data-mstatus="">全部</button>
                <button type="button" class="sb-status-tab" data-mstatus="ok">通过</button>
                <button type="button" class="sb-status-tab" data-mstatus="fail">异常</button>
                <button type="button" class="sb-status-tab" data-mstatus="unchecked">未检查</button>
            </div>
            <span class="sb-filter-count" id="sb-modal-count"></span>
        </div>
        <div class="sb-modal-body" id="sb-modal-body"></div>
    </div>
</div>

<footer class="sb-foot">
    <span id="sb-stats"></span>
    <span>页面数据为缓存快照 · 解析 IP 可能是 CDN · 未检查不等于正常 · 更新数据仅拉取云端，不写入 DNS</span>
</footer>

<div class="sb-toast" id="sb-toast" role="status"></div>

<script>
window.__SCREEN__ = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= $assetVer('/assets/js/admin-page.js') ?>"></script>
<script src="<?= $assetVer('/assets/js/screen.js') ?>"></script>
<script src="<?= $assetVer('/assets/js/screen-modal.js') ?>"></script>
<script src="<?= $assetVer('/assets/js/screen-ip.js') ?>"></script>
</body>
</html>
