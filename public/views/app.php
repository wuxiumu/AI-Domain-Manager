<?php
// Version each independent asset so deployments cannot mix old and new modules.
$assetUrl = static function (string $path): string {
    return $path . '?v=' . filemtime(PUBLIC_DIR . $path);
};
$styles = ['app', 'components', 'layout', 'health', 'domains', 'relations', 'filters', 'settings', 'desktop'];
$scripts = ['ui', 'cache', 'core', 'filter-tabs', 'dashboard', 'domains', 'dns-changes', 'records', 'accounts',
    'asset-links', 'health', 'relationship-graph', 'relations', 'domain-details', 'workspace-ui', 'settings', 'maintenance', 'setup', 'login-promo', 'login', 'app'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f6f2">
    <title>资产工作台 · Asset Workspace</title>
<?php foreach ($styles as $style): ?>
    <link rel="stylesheet" href="<?= $assetUrl('/assets/css/' . $style . '.css') ?>">
<?php endforeach; ?>
</head>
<body>
<svg class="icon-library" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="icon-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.7"/><rect x="14" y="3" width="7" height="7" rx="1.7"/><rect x="3" y="14" width="7" height="7" rx="1.7"/><rect x="14" y="14" width="7" height="7" rx="1.7"/></symbol>
    <symbol id="icon-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18M5 6.5h14M5 17.5h14"/></symbol>
    <symbol id="icon-server" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6.5h.01M7 17.5h.01M12 6.5h5M12 17.5h5"/></symbol>
    <symbol id="icon-link" viewBox="0 0 24 24"><path d="m10 13 4-4M8.5 15.5l-1 1a4.25 4.25 0 0 1-6-6l4-4a4.25 4.25 0 0 1 6 0m1 2 1-1a4.25 4.25 0 0 1 6 6l-4 4a4.25 4.25 0 0 1-6 0" transform="translate(1 0)"/></symbol>
    <symbol id="icon-refresh" viewBox="0 0 24 24"><path d="M20 7v5h-5M4 17v-5h5M5.6 7a7.5 7.5 0 0 1 12.7-2L20 7M4 17l1.7 2A7.5 7.5 0 0 0 18.4 17"/></symbol>
    <symbol id="icon-pulse" viewBox="0 0 24 24"><path d="M2 12h5l3-8 4 16 3-8h5"/></symbol>
    <symbol id="icon-search" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/></symbol>
    <symbol id="icon-arrow" viewBox="0 0 24 24"><path d="M5 12h14m-5-5 5 5-5 5"/></symbol>
    <symbol id="icon-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
</svg>
<?php require __DIR__ . '/setup.php'; ?>

<div id="login-view" class="view hidden">
    <div class="login-cosmos" aria-hidden="true"><div class="login-stars"></div><div class="login-orbit login-orbit-one"></div><div class="login-orbit login-orbit-two"></div><div class="login-beam"></div></div>
    <main class="login-shell">
    <section class="login-promo" aria-label="产品介绍">
        <div class="promo-brand"><img src="/assets/img/asset-workspace-logo.svg" alt=""><span>资产工作台<small>ASSET WORKSPACE</small></span></div>
        <div class="promo-slides" id="promo-slides">
            <article class="promo-slide promo-slide-overview" data-promo-slide="0">
                <div class="promo-copy"><p class="promo-kicker"><span>01 / 03</span> YOUR DIGITAL ASSETS, IN VIEW</p><h2>让每一份资产<br><em>都清晰可见。</em></h2><p>域名、IP、解析与运行状态，在同一个工作空间里，从容掌握。</p><div class="promo-tags"><span>域名管理</span><span>IP 台账</span><span>状态检查</span></div></div>
                <div class="promo-art promo-art-orbit" aria-hidden="true"><div class="art-halo"></div><div class="art-ring ring-one"></div><div class="art-ring ring-two"></div><div class="art-core"><img src="/assets/img/asset-workspace-logo.svg" alt=""></div><div class="art-node node-one"><b>DOMAIN</b><span>域名资产</span></div><div class="art-node node-two"><b>IP ASSET</b><span>网络资产</span></div><div class="art-node node-three"><b>DNS</b><span>解析记录</span></div><i class="art-dot dot-one"></i><i class="art-dot dot-two"></i></div>
            </article>
            <article class="promo-slide promo-slide-relations" data-promo-slide="1" hidden>
                <div class="promo-copy"><p class="promo-kicker"><span>02 / 03</span> CONNECTIONS THAT MAKE SENSE</p><h2>从资产到关系，<br><em>一眼看懂。</em></h2><p>连接域名、IP 与网站信息，让分散的线索汇成完整的资产视图。</p><div class="promo-tags"><span>关系图谱</span><span>用途备注</span><span>快速检索</span></div></div>
                <div class="promo-art promo-art-map" aria-hidden="true"><svg viewBox="0 0 500 360" fill="none"><path d="M250 180 95 85M250 180 405 87M250 180 84 281M250 180 411 276M95 85 405 87M84 281 411 276" stroke="#66dfdd" stroke-opacity=".35" stroke-width="1.5" stroke-dasharray="5 8"/><circle cx="250" cy="180" r="78" stroke="#76e8db" stroke-opacity=".18"/><circle cx="250" cy="180" r="51" fill="#123a4e" stroke="#7be2d4" stroke-opacity=".7"/><circle cx="250" cy="180" r="8" fill="#9ff6e5"/><circle cx="95" cy="85" r="8" fill="#77dcd3"/><circle cx="405" cy="87" r="8" fill="#849dff"/><circle cx="84" cy="281" r="8" fill="#849dff"/><circle cx="411" cy="276" r="8" fill="#77dcd3"/></svg><div class="map-label map-a">DOMAIN <strong>域名</strong></div><div class="map-label map-b">IP ASSET <strong>IP 资产</strong></div><div class="map-label map-c">WEBSITE <strong>网站</strong></div><div class="map-label map-d">DNS <strong>解析</strong></div></div>
            </article>
            <article class="promo-slide promo-slide-health" data-promo-slide="2" hidden>
                <div class="promo-copy"><p class="promo-kicker"><span>03 / 03</span> CLARITY FOR EVERY CHECK</p><h2>状态有迹可循，<br><em>决策更有底气。</em></h2><p>按需检查域名与 IP，留存结果与时间，让每一次维护更有依据。</p><div class="promo-tags"><span>按需检查</span><span>结果留存</span><span>集中查看</span></div></div>
                <div class="promo-art promo-art-health" aria-hidden="true"><div class="health-grid"></div><div class="health-card"><div class="health-card-head"><span>ASSET HEALTH</span><span class="health-live">● CHECK</span></div><div class="health-number">清晰<span> / 可追溯</span></div><svg viewBox="0 0 320 100" fill="none"><path d="M0 72H58L78 52 99 70 130 29 154 60 181 45 204 54 238 18 264 35 320 12" stroke="#88f0df" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><path d="M0 72H58L78 52 99 70 130 29 154 60 181 45 204 54 238 18 264 35 320 12V100H0Z" fill="#70e6d7" fill-opacity=".09"/></svg><div class="health-card-foot"><span>DOMAIN</span><span>IP</span><span>DNS</span></div></div></div>
            </article>
        </div>
        <div class="promo-bottom"><div class="promo-selector" role="group" aria-label="切换宣传海报"><button type="button" data-promo-select="0" aria-pressed="true"><span>01</span> 资产总览</button><button type="button" data-promo-select="1" aria-pressed="false"><span>02</span> 资产关系</button><button type="button" data-promo-select="2" aria-pressed="false"><span>03</span> 状态检查</button></div><span class="promo-caption">DESIGNED FOR CLARITY <i>✳</i></span></div>
    </section>
    <div class="login-card">
        <div class="login-card-top"><span>SECURE ACCESS</span><span class="login-status"><i></i> SYSTEM ONLINE</span></div>
        <div class="login-logo"><img src="/assets/img/asset-workspace-logo.svg" alt=""></div>
        <p class="eyebrow">ASSET WORKSPACE</p>
        <h1>资产工作台</h1>
        <p class="login-sub">把域名、IP 与运行状态放在一起。</p>
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

<div id="app-view" class="view hidden">
    <aside class="sidebar" id="workspace-sidebar">
        <a class="brand" href="/" aria-label="资产工作台首页">
            <span class="brand-mark"><img src="/assets/img/asset-workspace-logo.svg" alt=""></span>
            <span>资产工作台<small>ASSET WORKSPACE</small></span>
        </a>
        <p class="nav-caption">工作空间</p>
        <nav class="nav-tabs" id="nav-tabs" aria-label="主导航">
            <button class="tab-btn active" data-tab="dashboard"><svg class="icon" aria-hidden="true"><use href="#icon-grid"/></svg><span>健康看板</span></button>
            <button class="tab-btn" data-tab="relations"><svg class="icon" aria-hidden="true"><use href="#icon-link"/></svg><span>资产关系</span></button>
            <button class="tab-btn" data-tab="domains"><svg class="icon" aria-hidden="true"><use href="#icon-globe"/></svg><span>域名管理</span></button>
            <button class="tab-btn" data-tab="ips"><svg class="icon" aria-hidden="true"><use href="#icon-server"/></svg><span>IP 资产</span></button>
            <button class="tab-btn" data-tab="keys"><svg class="icon" aria-hidden="true"><use href="#icon-link"/></svg><span>云账号</span></button>
            <button class="tab-btn" data-tab="settings"><svg class="icon" aria-hidden="true"><use href="#icon-grid"/></svg><span>产品设置</span></button>
        </nav>
        <div class="sidebar-note"><span class="sidebar-note-line"></span><strong>看清资产，也看清状态。</strong><p>域名与 IP 统一归档<br>每一次检查都有迹可循</p></div>
        <div class="sidebar-foot"><span class="workspace-dot"></span> 统一资产管理 <span class="version-tag">V1</span></div>
    </aside>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="topbar-start"><button id="sidebar-toggle" class="btn btn-icon shell-toggle" type="button" aria-label="收起侧栏" aria-expanded="true" aria-controls="workspace-sidebar" title="收起侧栏"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16m7-11-3 3 3 3"/></svg></button><div class="breadcrumb">工作空间 <span>/</span> <strong id="page-title">健康看板</strong></div></div>
            <div class="topbar-right"><div class="display-density" role="group" aria-label="显示密度"><button type="button" data-density="comfortable" aria-pressed="true">舒适</button><button type="button" data-density="compact" aria-pressed="false">紧凑</button></div><span class="user-avatar" aria-hidden="true">A</span><span id="current-user" class="muted">管理账号</span><button id="logout-btn" class="btn btn-ghost btn-sm">退出</button></div>
        </div>
    </header>

    <main class="container" id="main-content">
        <section id="view-dashboard" class="inventory-page tab-view" aria-labelledby="dashboard-title">
            <div class="page-head dashboard-head">
                <div><p class="eyebrow">WORKSPACE OVERVIEW</p><h1 id="dashboard-title">健康看板<span class="heading-dot">.</span> <span id="health-count" class="count-tag">加载中</span></h1><p class="muted" id="dash-sub">正在获取资产与检查状态…</p></div>
                <div class="head-actions">
                    <button class="btn" data-open-health-stats>统计概况</button><button id="health-refresh" class="btn btn-icon" aria-label="刷新看板" title="刷新看板"><svg class="icon" aria-hidden="true"><use href="#icon-refresh"/></svg></button>
                    <button id="sync-btn" class="btn"><svg class="icon" aria-hidden="true"><use href="#icon-globe"/></svg>同步域名</button>
                    <button id="health-check-all" class="btn btn-primary"><svg class="icon" aria-hidden="true"><use href="#icon-pulse"/></svg>检查全部资产</button>
                </div>
            </div>
            <div id="health-progress-slot"></div><div id="health-progress" class="health-progress hidden" role="status" aria-live="polite"><div><span class="running-dot"></span><strong id="health-progress-text">正在检查资产…</strong></div><div class="health-progress-track"><div id="health-progress-fill"></div></div></div>
            <div id="health-list-panel" role="region" aria-label="资产列表" data-panel-group="health">
            <section class="asset-panel" aria-labelledby="asset-list-title">
                <div class="panel-heading"><div><h2 id="asset-list-title">资产健康状态 </h2><p class="muted">从一个入口查看域名与 IP，快速定位需要关注的资产。</p></div></div>
                <div class="filters health-filters unified-filters">
                    <button type="button" class="btn" data-open-filter="health-filter-options">筛选</button><label class="search-field"><svg class="icon" aria-hidden="true"><use href="#icon-search"/></svg><input type="search" id="health-query" aria-label="搜索资产" placeholder="搜索域名、IP 或资产名称"></label><div id="health-filter-options" class="inventory-filter-options hidden"><select id="health-kind" aria-label="筛选资产类型"><option value="">全部资产类型</option><option value="domain">域名</option><option value="ip">IP 资产</option></select>
                    <select id="health-status" aria-label="筛选检查状态"><option value="">全部检查状态</option><option value="healthy">正常</option><option value="unhealthy">异常</option><option value="stale">结果已过期</option><option value="unchecked">未检查</option></select>
                </div></div>
                <div class="health-selection-bar hidden"><span id="health-selected-count">未选择域名</span><button class="btn btn-sm btn-primary" id="health-to-relations" disabled>查看选中域名关系图</button><button class="btn btn-sm" id="health-selection-clear">清除选择</button></div><div class="table-wrap"><table id="health-table"><thead><tr><th><input type="checkbox" id="health-select-all" aria-label="选择当前结果中的域名"></th><th>资产</th><th>类型与来源</th><th>检查状态</th><th>检查摘要</th><th>上次检查</th><th>操作</th></tr></thead><tbody><tr><td colspan="7" class="table-loading"><span class="loading-ring"></span>正在加载资产…</td></tr></tbody></table></div>
                <div id="health-empty" class="empty-state hidden"><svg class="icon" aria-hidden="true"><use href="#icon-grid"/></svg><strong>暂无匹配的资产</strong><p>调整筛选条件，或先同步域名、添加 IP 资产。</p></div>
                <div class="panel-caption"><span class="info-dot">i</span> 检查结果反映探测时的网络状态；未检查与结果过期的资产不会计为正常。</div>
            </section>
            </div>
            <div id="health-summary-panel" class="hidden" role="region" aria-label="健康统计" data-panel-group="health">
            <div class="inventory-toolbar"><div id="dns-inventory-summary" class="muted">子域名与 DNS 快照：读取中…</div><button class="btn btn-sm" id="dns-cache-missing">缓存尚未读取的 DNS</button><span id="dns-cache-progress" class="muted" role="status"></span></div>
            <div id="health-stats" class="health-stats" aria-live="polite">
                <div class="hc-stat skeleton-card"><span class="skeleton skeleton-label"></span><span class="skeleton skeleton-number"></span><span class="skeleton skeleton-caption"></span></div>
                <div class="hc-stat skeleton-card"><span class="skeleton skeleton-label"></span><span class="skeleton skeleton-number"></span><span class="skeleton skeleton-caption"></span></div>
                <div class="hc-stat skeleton-card"><span class="skeleton skeleton-label"></span><span class="skeleton skeleton-number"></span><span class="skeleton skeleton-caption"></span></div>
                <div class="hc-stat skeleton-card"><span class="skeleton skeleton-label"></span><span class="skeleton skeleton-number"></span><span class="skeleton skeleton-caption"></span></div>
                <div class="hc-stat skeleton-card"><span class="skeleton skeleton-label"></span><span class="skeleton skeleton-number"></span><span class="skeleton skeleton-caption"></span></div>
                <div class="hc-stat skeleton-card"><span class="skeleton skeleton-label"></span><span class="skeleton skeleton-number"></span><span class="skeleton skeleton-caption"></span></div>
            </div>
            <section class="health-distribution" aria-label="资产健康分布"><div class="distribution-heading"><span class="section-kicker">健康分布</span><span class="muted">以最近一次检查结果为准</span></div><div id="health-status-bar" class="health-status-bar"><span class="health-bar-empty" title="等待检查数据"></span></div></section>
            </div>
            <div id="health-domains-panel" class="hidden" role="region" aria-label="域名统计与同步" data-panel-group="health">
            <div class="section-heading"><h2>域名资产概况</h2><span class="muted">注册状态与到期提醒</span></div>
            <div id="stats-grid" class="stats-grid compact-stats"></div>
            <div class="two-col supplementary-grid"><section class="panel"><div class="panel-title"><h3>账号资产分布</h3><span class="panel-meta">DOMAINS</span></div><div id="provider-dist"><p class="muted">正在加载…</p></div></section><section class="panel"><div class="panel-title"><h3>最近同步</h3><span class="panel-meta">ACTIVITY</span></div><div id="recent-sync"><p class="muted">正在加载…</p></div></section></div>
            </div>
        </section>

        <section id="view-relations" class="inventory-page tab-view hidden" aria-labelledby="relations-title">
            <div class="page-head"><div><p class="eyebrow">ASSET RELATIONSHIPS</p><h1 id="relations-title">域名与服务器<span class="heading-dot">.</span> <span class="count-tag" id="rel-count"></span></h1><p class="muted">从解析线索到确认绑定，一眼看清承载关系与影响范围。</p></div><div class="head-actions"><button class="btn" data-rel-open="graph">资产关联图</button><button class="btn" data-rel-open="summary">关联统计</button><button class="btn" id="rel-refresh">刷新</button></div></div>
            <div id="rel-scope-note" class="rel-scope-note hidden"></div>
            <p id="rel-cache-note" class="muted" role="status">正在读取文件缓存…</p>
            <div id="rel-summary-panel" class="hidden" role="region" aria-label="关联统计">
            <div id="rel-summary" class="rel-summary"></div>
            <div class="two-col rel-charts"><section class="panel"><h2>关联覆盖率</h2><div id="rel-coverage"></div></section><section class="panel"><h2>IP 承载分布 <small class="muted">点击查看关联域名</small></h2><div id="rel-ranking"></div></section></div>
            </div>
            <section id="rel-results-panel" class="asset-panel rel-panel"><div class="panel-heading"><div><h2><span id="rel-result-title">关联明细表</span> </h2><p class="muted">实线：人工确认绑定　虚线：DNS 解析关联（可能是 CDN）</p></div></div>
            <div class="filters unified-filters"><button type="button" class="btn" data-open-filter="rel-filter-options">筛选</button><label class="search-field"><svg class="icon" aria-hidden="true"><use href="#icon-search"/></svg><input type="search" id="rel-query" aria-label="搜索关联资产" placeholder="搜索域名、IP、服务器名称或备注"></label><div id="rel-filter-options" class="inventory-filter-options hidden"><select id="rel-ip" aria-label="按 IP 查询"><option value="">全部 IP / 服务器</option></select>
            <div id="rel-tabs" class="rel-quick-tabs" role="tablist" aria-label="关联快捷查询"><button role="tab" aria-selected="true" data-rel-tab="all">全部</button><button role="tab" aria-selected="false" data-rel-tab="confirmed">已确认绑定</button><button role="tab" aria-selected="false" data-rel-tab="dns">仅 DNS 关联</button><button role="tab" aria-selected="false" data-rel-tab="unlinked">未关联</button><button role="tab" aria-selected="false" data-rel-tab="unhealthy">检查异常</button></div></div></div>
            <div id="rel-graph-panel" class="hidden"><div id="rel-drilldown-note" class="rel-scope-note hidden"></div><div id="rel-graph" class="rel-graph" aria-label="域名和 IP 关系图"></div></div><div id="rel-table" class="table-wrap"></div>
            <div id="rel-pagination" class="rel-pagination"><label class="muted">每页 <input type="number" id="rel-page-size" value="12" min="1" max="10000" aria-label="每页域名数量"></label><label class="muted"><input type="checkbox" id="rel-show-all"> 展示全部</label><button class="btn btn-sm" id="rel-fullscreen">全屏展示</button><button class="btn btn-sm" id="rel-prev">上一页</button><span id="rel-page" class="muted"></span><button class="btn btn-sm" id="rel-next">下一页</button></div>
            <div class="panel-caption">点击节点查看详情；从详情可编辑资产、管理绑定或进入 DNS。绑定操作只更新台账。</div></section>
        </section>

        <section id="view-domains" class="inventory-page tab-view hidden" aria-labelledby="domains-title">
            <div class="page-head"><div><p class="eyebrow">DOMAIN INVENTORY</p><h1 id="domains-title">域名管理<span class="heading-dot">.</span></h1><span class="count-tag" id="domains-sub">共 0 个域名</span><p class="muted" id="access-summary"></p></div><div class="head-actions"><button class="btn" data-open-domain-stats>域名统计</button><button id="access-check-btn" class="btn" title="探测全部域名能否访问"><svg class="icon" aria-hidden="true"><use href="#icon-pulse"/></svg>访问检测</button><button id="domain-add-btn" class="btn"><svg class="icon" aria-hidden="true"><use href="#icon-plus"/></svg>新增域名</button><button id="domains-sync-btn" class="btn btn-primary">立即同步</button></div></div>
            <section class="asset-panel">
                <div class="filters domain-filters">
                    <button type="button" class="btn" data-open-filter="domain-filter-options">筛选</button><label class="search-field"><svg class="icon" aria-hidden="true"><use href="#icon-search"/></svg><input type="search" id="f-q" aria-label="搜索域名或备注" placeholder="搜索域名 / 备注…"></label><div id="domain-filter-options" class="inventory-filter-options hidden"><div class="domain-filter-group"><span class="domain-filter-label">云账号</span><input type="hidden" id="f-provider" value=""><div class="domain-filter-tabs" data-filter-tabs="f-provider" role="group" aria-label="云账号"></div></div>
                    <div class="domain-filter-group"><span class="domain-filter-label">资产状态</span><input type="hidden" id="f-valid" value=""><div class="domain-filter-tabs" data-filter-tabs="f-valid" role="group" aria-label="资产状态"></div></div>
                    <div class="domain-filter-group"><span class="domain-filter-label">到期时间</span><input type="hidden" id="f-expiring" value=""><div class="domain-filter-tabs" data-filter-tabs="f-expiring" role="group" aria-label="到期时间"></div></div>
                    <div class="domain-filter-group"><span class="domain-filter-label">健康状态</span><input type="hidden" id="f-access" value=""><div class="domain-filter-tabs" data-filter-tabs="f-access" role="group" aria-label="健康状态"></div></div>
                    <div class="domain-filter-group"><span class="domain-filter-label">排序</span><input type="hidden" id="f-sort" value="expire"><div class="domain-filter-tabs" data-filter-tabs="f-sort" role="group" aria-label="排序"></div></div>
                </div></div>
                <div class="health-selection-bar hidden"><span id="domain-selected-count">未选择域名</span><button class="btn btn-sm btn-primary" id="domains-to-relations" disabled>查看选中域名关系图</button><button class="btn btn-sm" id="domain-selection-clear">清除选择</button></div><div class="table-wrap"><table id="domains-table"><thead><tr><th><input type="checkbox" id="domain-select-all" aria-label="选择当前列表中的有效域名"></th><th>域名</th><th>云商</th><th>状态</th><th>到期时间</th><th>健康状态</th><th>备注</th><th>操作</th></tr></thead><tbody></tbody></table></div>
                <div id="domains-empty" class="empty-state hidden"><svg class="icon" aria-hidden="true"><use href="#icon-globe"/></svg><strong>暂无域名数据</strong><p>同步云账号中的域名，或手动添加一条资产。</p></div>
            </section>
        </section>

        <section id="view-ips" class="inventory-page tab-view hidden" aria-labelledby="ips-title">
            <div class="page-head"><div><p class="eyebrow">IP INVENTORY</p><h1 id="ips-title">IP 资产<span class="heading-dot">.</span> <span id="ip-count" class="count-tag">加载中</span></h1><p class="muted">统一收录服务器与 DNS 发现的 IP，管理网站链接并检查 HTTP 访问状态。</p></div><div class="head-actions"><button id="ip-open-create" class="btn"><svg class="icon" aria-hidden="true"><use href="#icon-plus"/></svg>添加 IP 资产</button><button id="ip-check-all" class="btn btn-primary"><svg class="icon" aria-hidden="true"><use href="#icon-pulse"/></svg>检查全部 IP</button></div></div>
            <div id="ip-progress-slot"></div><section class="asset-panel"><div class="panel-heading"><div><h2>IP 资产列表 </h2></div></div><div class="filters unified-filters"><button type="button" class="btn" data-open-filter="ip-filter-options">筛选</button><label class="search-field"><svg class="icon" aria-hidden="true"><use href="#icon-search"/></svg><input type="search" id="ip-query" aria-label="搜索 IP 资产" placeholder="搜索名称、IP、备注、网站或关联域名"></label><div id="ip-filter-options" class="inventory-filter-options hidden"><select id="ip-version" aria-label="地址类型"><option value="">全部</option><option value="IPv4">IPv4</option><option value="IPv6">IPv6</option></select><select id="ip-status" aria-label="IP 健康状态"><option value="">全部</option><option value="healthy">正常</option><option value="unhealthy">检查异常</option><option value="stale">结果过时</option><option value="unchecked">未检查</option></select></div></div><div class="table-wrap"><table id="ip-table"><thead><tr><th>名称与 IP</th><th>网站链接</th><th>用途与备注</th><th>关联域名</th><th>状态</th><th>检查时间</th><th>操作</th></tr></thead><tbody><tr><td colspan="7" class="table-loading"><span class="loading-ring"></span>正在加载 IP 资产…</td></tr></tbody></table></div><div id="ip-empty" class="empty-state hidden"><svg class="icon" aria-hidden="true"><use href="#icon-server"/></svg><strong>还没有 IP 资产</strong><p>点击“添加 IP 资产”，或检查域名以发现其解析地址。</p></div><div class="panel-caption"><span class="info-dot">i</span> 检查 http://IP/，HTTP 2xx / 3xx 显示正常（重定向不继续跟随）。结果与检查时间保存在服务器；点击状态可单次检查。</div></section>
        </section>

        <?php require __DIR__ . '/accounts.php'; ?>
        <?php require __DIR__ . '/settings.php'; ?>
        <footer class="workspace-footer"><span>资产工作台</span><span>每一项资产，都有清晰的状态。</span></footer>
    </main>
</div>
<template id="ip-create-template">
    <section class="modal ip-create-modal" role="dialog" aria-modal="true" aria-labelledby="ip-create-title">
        <div class="modal-head"><h3 id="ip-create-title">添加 IP 资产</h3><button type="button" class="modal-close" aria-label="关闭添加 IP">×</button></div>
        <form id="ip-add-form" class="ip-create-form">
            <div class="modal-body"><div class="ip-add-form"><label for="ip-name">资产名称 <span class="muted">选填</span><input id="ip-name" type="text" placeholder="如：生产服务器 · 上海" maxlength="120"></label><label for="ip-address">IP 地址 <span class="required-dot">*</span><input id="ip-address" type="text" placeholder="IPv4 或 IPv6 地址" required spellcheck="false" autocomplete="off"></label><label for="ip-note" class="ip-note-field">用途与备注 <span class="muted">选填</span><textarea id="ip-note" rows="2" maxlength="2000" placeholder="记录服务器用途或维护说明"></textarea></label><fieldset class="asset-links-field"><legend>网站链接 <span class="muted">选填</span></legend><div id="ip-links-editor"></div></fieldset><p id="ip-add-error" class="error-text hidden" role="alert"></p></div></div>
            <div class="modal-foot"><button type="button" class="btn" data-ip-cancel>取消</button><button id="ip-add-btn" class="btn btn-primary" type="submit">添加资产</button></div>
        </form>
    </section>
</template>

<?php foreach ($scripts as $script): ?>
<script src="<?= $assetUrl('/assets/js/' . $script . '.js') ?>"></script>
<?php endforeach; ?>
</body>
</html>
