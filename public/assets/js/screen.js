/**
 * 大屏展示：未过期域名 + 子域名 A 记录（IPv4）。
 * 海报式卡片 + 弹窗多选批量修改 / 删除 + 前后端访问检查（前端结果缓存在本浏览器）。
 * 独立自包含模块；纯函数挂 window.ScreenBoard（无 DOM 时跳过交互绑定）。
 */
(function () {
'use strict';

var CACHE_KEY = 'asset-workspace-screen:v1';        // 最近一次服务端快照（秒开）
var IPS_CACHE_KEY = 'asset-workspace-screen:ips:v1'; // IP 视图快照（秒开）
var VIEW_KEY = 'asset-workspace-screen:view:v1';    // 视图状态：tab / 搜索 / 排序 / 每页条数 / 页码 / 视图模式
var ACCESS_KEY = 'asset-workspace-screen:access:v1'; // 前端访问检查结果（本浏览器缓存）
var REFRESH_MS = 60000;
var SEARCH_DELAY_MS = 300;
var FRONT_CHECK_TIMEOUT_MS = 6000;

// IP 视图的排序键与选项（与域名排序共用一个下拉，切换视图时重建）。
var DOMAIN_SORT_OPTIONS = [
    ['expire', '到期时间（先到期优先）'], ['expire_desc', '到期时间（后到期优先）'], ['domain', '域名 A → Z'],
    ['subdomains', '子域名数量'], ['a', 'A 记录数量'], ['synced', '最近同步']];
var IP_SORT_OPTIONS = [
    ['domains', '关联域名数'], ['records', 'A 记录数'], ['ip', 'IP 排序'], ['country', '国家 / 地区']];
var IP_SORT_KEYS = IP_SORT_OPTIONS.map(function (o) { return o[0]; });

function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// ---- 纯函数：筛选 / 排序 / 分页 ----

function applyFilters(data, tab, q, status) {
    var list = (data && data.domains) || [];
    var kw = (q || '').trim().toLowerCase();
    if (!tab && !kw && !status) { return list.slice(); }
    return list.filter(function (d) {
        if (tab && d.provider !== tab) { return false; }
        if (status) {
            var st = domainStatus(d); // ok / fail / none（前端缓存 + 后端结果合并）
            if ((st === 'none' ? 'unchecked' : st) !== status) { return false; }
        }
        if (!kw) { return true; }
        // 关键词覆盖：账号名、主域名、子域名与解析 IP
        if (String(d.provider_name || '').toLowerCase().indexOf(kw) !== -1) { return true; }
        if (String(d.domain || '').toLowerCase().indexOf(kw) !== -1) { return true; }
        var subs = d.subdomains || [];
        for (var i = 0; i < subs.length; i++) {
            if (String(subs[i].fqdn || '').toLowerCase().indexOf(kw) !== -1) { return true; }
            var ips = subs[i].ips || [];
            for (var j = 0; j < ips.length; j++) {
                if (String(ips[j]).indexOf(kw) !== -1) { return true; }
            }
        }
        return false;
    });
}

function daysKey(d) { return d && d.days_left != null ? d.days_left : Number.MAX_SAFE_INTEGER; }
function tie(a, b) {
    return String(a.provider || '').localeCompare(String(b.provider || ''))
        || String(a.domain || '').localeCompare(String(b.domain || ''));
}

var SORTS = {
    expire: function (a, b) { return daysKey(a) - daysKey(b) || tie(a, b); },
    expire_desc: function (a, b) { return daysKey(b) - daysKey(a) || tie(a, b); },
    domain: function (a, b) { return String(a.domain || '').localeCompare(String(b.domain || '')) || tie(a, b); },
    subdomains: function (a, b) { return (b.subdomain_count || 0) - (a.subdomain_count || 0) || tie(a, b); },
    a: function (a, b) { return (b.a_count || 0) - (a.a_count || 0) || tie(a, b); },
    synced: function (a, b) { return String(b.synced_at || '').localeCompare(String(a.synced_at || '')) || tie(a, b); }
};

function sortDomains(list, sort) {
    var fn = SORTS[sort] || SORTS.expire;
    return list.slice().sort(fn);
}

function pageSlice(list, page, size, showAll) {
    if (showAll) { return { items: list.slice(), page: 1, pages: 1 }; }
    var pages = Math.max(1, Math.ceil(list.length / size));
    var current = Math.min(Math.max(1, page), pages);
    return { items: list.slice((current - 1) * size, current * size), page: current, pages: pages };
}

var ScreenBoard = { applyFilters: applyFilters, sortDomains: sortDomains, pageSlice: pageSlice, escapeHtml: escapeHtml };
if (typeof window !== 'undefined') { window.ScreenBoard = ScreenBoard; }
if (typeof document === 'undefined') { return; } // 离线环境无 DOM，到此为止

// ---- 页面交互 ----

var boot = window.__SCREEN__ || {};
var SIZES = ['12', '24', '48', 'all'];
var state = {
    data: null, localAt: null, lastView: [],
    ipsData: null, ipsLocalAt: null,
    mode: 'domain',
    tab: '', q: '', sort: 'expire', size: '24', page: 1,
    autoRefresh: { enabled: false, seconds: 0 }, domainStatus: '', ipStatus: '', loadingDomains: false, loadingIps: false, syncing: false,
    modalKey: null, selected: {}, busyCheck: false, busyWrite: false, locateIp: null,
    modalQ: '', modalStatus: ''
};

function $(id) { return document.getElementById(id); }

function fetchJson(url, opts) {
    opts = opts || {};
    // 会话写操作依赖 X-CSRF-Token；统一携带，GET 携带无副作用。
    return window.fetch(url, {
        method: opts.method || 'GET',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': boot.csrf || '' },
        body: opts.body ? JSON.stringify(opts.body) : null,
        credentials: 'same-origin'
    }).then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
            if (!res.ok || data.ok === false) {
                var err = new Error(data.error || ('请求失败 HTTP ' + res.status));
                err.status = res.status;
                throw err;
            }
            return data;
        });
    });
}

// IP 视图模块（screen-ip.js）需要的共享工具：HTTP、提示与刷新入口。
ScreenBoard.fetchJson = fetchJson;
ScreenBoard.toast = function (message, type, duration) { toast(message, type, duration); };
ScreenBoard.reload = function (force) { loadData(force === true); };
// 浏览器直连探测 + 本地结果缓存（IP 清单弹窗的前端检查复用）。
ScreenBoard.frontProbe = frontProbe;
ScreenBoard.copyText = function (text, okMessage) {
    // 大屏用自己的 toast（页面无 ap-toast）；clipboard 挂起 400ms 时 execCommand 兜底
    var done = function () { toast(okMessage || '已复制'); };
    var fail = function () { toast('复制失败，请手动复制', 'error'); };
    var write = navigator.clipboard && navigator.clipboard.writeText;
    if (write) {
        var settled = false;
        navigator.clipboard.writeText(text).then(function () { if (!settled) { settled = true; done(); } },
            function () { if (!settled) { settled = true; fail(); } });
        setTimeout(function () { if (!settled) { settled = true; fail(); } }, 400);
    } else {
        var input = document.createElement('textarea');
        input.value = text;
        input.style.cssText = 'position:fixed;opacity:0';
        document.body.appendChild(input);
        input.select();
        try { document.execCommand('copy'); done(); } catch (e) { fail(); }
        input.remove();
    }
};
ScreenBoard.saveAccess = saveAccess;
// 解析管理弹窗模块（screen-modal.js）依赖的共享工具与状态（引用同一 state 对象）。
ScreenBoard.state = state;
ScreenBoard.$ = $;
ScreenBoard.httpLink = httpLink;
ScreenBoard.expireBadge = expireBadge;
ScreenBoard.groupStatus = groupStatus;
ScreenBoard.accessStore = accessStore;
ScreenBoard.fmtNow = fmtNow;
ScreenBoard.render = render;
ScreenBoard.loadData = loadData;

// IP 视图点击域名标签 → 打开该域名的子域名管理弹窗（支持前端 / 后端访问检查；弹窗标题可跳转网站）。
// source 携带来源 IP 卡片的 IP 与被点击的 FQDN，弹窗内高亮对应记录，解释“一个域名多条解析指向不同 IP”的视角差异。
// 域名快照尚未加载时先拉取再打开；域名不在未过期清单（已过期 / 未同步）时给出提示。
ScreenBoard.openDomainModal = function (key, source) {
    var open = function () {
        if (ScreenBoard.modalItemFor(key)) { ScreenBoard.openModal(key, source); }
        else { toast('该域名不在未过期清单中（可能已过期或未同步），无法在弹窗管理', 'info', 6000); }
    };
    if (state.data && Array.isArray(state.data.domains)) { open(); return; }
    toast('正在读取域名数据…', 'info', 2000);
    loadDomainData().then(open);
};

function loadData(force) {
    // 按当前视图刷新对应数据；弹窗打开时（如从 IP 视图进入域名管理）域名快照一并刷新供弹窗渲染。
    // force=true：带 ?refresh=1 绕过服务端快照 TTL 立即重建（手动刷新按钮）。
    var qs = force ? '?refresh=1' : '';
    var jobs = [state.mode === 'ip' ? loadIpData(qs) : loadDomainData(qs)];
    if (state.mode === 'ip' && !$('sb-modal').classList.contains('hidden')) { jobs.push(loadDomainData(qs)); }
    return Promise.all(jobs).then(function (results) { return results[0]; });
}

function loadDomainData(qs) {
    if (state.loadingDomains) { return Promise.resolve(state.data); }
    state.loadingDomains = true;
    return fetchJson('/api/domains/screen' + (qs || '')).then(function (payload) {
        state.data = payload;
        state.localAt = null;
        try { localStorage.setItem(CACHE_KEY, JSON.stringify({ at: Date.now(), payload: payload })); } catch (e) { /* 隐私模式等场景忽略 */ }
        render();
        return payload;
    }).catch(function (err) {
        render();
        toast(err && err.status === 401
            ? '会话已过期：请重新登录后刷新本页'
            : ((err && err.message) || '读取失败，请稍后重试'), 'error', 8000);
        return null;
    }).then(function (payload) {
        state.loadingDomains = false;
        return payload;
    });
}

function loadIpData(qs) {
    if (state.loadingIps) { return Promise.resolve(state.ipsData); }
    state.loadingIps = true;
    return fetchJson('/api/ips/screen' + (qs || '')).then(function (payload) {
        state.ipsData = payload;
        state.ipsLocalAt = null;
        try { localStorage.setItem(IPS_CACHE_KEY, JSON.stringify({ at: Date.now(), payload: payload })); } catch (e) { /* 忽略 */ }
        render();
        return payload;
    }).catch(function (err) {
        render();
        toast(err && err.status === 401
            ? '会话已过期：请重新登录后刷新本页'
            : ((err && err.message) || 'IP 数据读取失败，请稍后重试'), 'error', 8000);
        return null;
    }).then(function (payload) {
        state.loadingIps = false;
        return payload;
    });
}

// ---- 视图状态持久化：页码 / 排序 / 条数 / Tab / 搜索词在刷新后保持 ----

function saveView() {
    try {
        localStorage.setItem(VIEW_KEY, JSON.stringify({ tab: state.tab, q: state.q, sort: state.sort, size: state.size, page: state.page, mode: state.mode, ipSort: state.ipSort, ipStatus: state.ipStatus, domainStatus: state.domainStatus }));
    } catch (e) { /* 忽略 */ }
}

function restoreView() {
    try {
        var v = JSON.parse(localStorage.getItem(VIEW_KEY) || 'null');
        if (v && typeof v === 'object') {
            state.tab = typeof v.tab === 'string' ? v.tab : '';
            state.q = typeof v.q === 'string' ? v.q : '';
            state.sort = SORTS[v.sort] ? v.sort : 'expire';
            state.ipSort = IP_SORT_KEYS.indexOf(v.ipSort) !== -1 ? v.ipSort : 'domains';
            state.ipStatus = ['ok', 'fail', 'unchecked'].indexOf(v.ipStatus) !== -1 ? v.ipStatus : '';
            state.domainStatus = ['ok', 'fail', 'unchecked'].indexOf(v.domainStatus) !== -1 ? v.domainStatus : '';
            state.size = SIZES.indexOf(v.size) !== -1 ? v.size : '24';
            state.page = Number(v.page) > 0 ? Math.floor(Number(v.page)) : 1;
            state.mode = v.mode === 'ip' ? 'ip' : 'domain';
        }
    } catch (e) { /* 忽略 */ }
    // URL hash 优先（#mode=ip），其次本地记忆
    var hashMode = (location.hash.match(/^#mode=(ip|domain)/) || [])[1];
    if (hashMode) { state.mode = hashMode; }
    $('sb-q').value = state.q;
    applyModeUi(); // 内部已按当前视图重建排序下拉并赋值，不再重复赋值
    $('sb-size').value = state.size;
}

// ---- 视图模式：域名 / IP ----

function applyModeUi() {
    var ip = state.mode === 'ip';
    document.querySelectorAll('.sb-mode-btn').forEach(function (btn) {
        var active = btn.getAttribute('data-mode') === state.mode;
        btn.classList.toggle('active', active);
        btn.setAttribute('aria-pressed', String(active));
    });
    // 排序下拉按视图重建；状态值取当前视图记忆的排序。
    var options = ip ? IP_SORT_OPTIONS : DOMAIN_SORT_OPTIONS;
    var wanted = ip ? state.ipSort : state.sort;
    $('sb-sort').innerHTML = options.map(function (o) {
        return '<option value="' + escapeHtml(o[0]) + '">' + escapeHtml(o[1]) + '</option>';
    }).join('');
    $('sb-sort').value = wanted;
    $('sb-sub').textContent = ip ? '公网 IP · DNS 解析自动发现 + 手工登记' : '域名 · 未过期域名与子域名 A 记录（IPv4）';
    $('sb-addip').classList.toggle('hidden', !ip);
    $('sb-status').hidden = false;
    $('sb-status').value = ip ? state.ipStatus : state.domainStatus;
    $('sb-ip-fecheck').classList.toggle('hidden', !ip);
    $('sb-ip-becheck').classList.toggle('hidden', !ip);
    $('sb-domain-fecheck').classList.toggle('hidden', ip);
    $('sb-domain-becheck').classList.toggle('hidden', ip);
    $('sb-q').placeholder = ip ? '搜索 IP / 域名 / 归属地' : '搜索域名 / 子域名 / IP';
    // 校验当前排序键属于当前视图。
    if (options.every(function (o) { return o[0] !== wanted; })) {
        wanted = ip ? 'domains' : 'expire';
        $('sb-sort').value = wanted;
        if (ip) { state.ipSort = wanted; } else { state.sort = wanted; }
    }
}

function setMode(mode) {
    if (state.mode === mode) { return; }
    state.mode = mode;
    closeModal(); // 视图切换时关闭打开的弹窗
    var modeHash = '#mode=' + mode;
    if (location.hash !== modeHash) { history.replaceState(null, '', modeHash); }
    applyModeUi();
    // 记忆每套视图的排序键；tab / 搜索词 / 分页保持上下文延续。
    var saved = null;
    try { saved = JSON.parse(localStorage.getItem(VIEW_KEY) || 'null'); } catch (e) { /* 忽略 */ }
    saved = saved && typeof saved === 'object' ? saved : {};
    if (mode === 'ip') { state.ipSort = IP_SORT_KEYS.indexOf(saved.ipSort) !== -1 ? saved.ipSort : 'domains'; $('sb-sort').value = state.ipSort; }
    else { state.sort = SORTS[saved.sort] ? saved.sort : 'expire'; $('sb-sort').value = state.sort; }
    state.page = 1;
    render();
    if (mode === 'ip' && !state.ipsData) { loadIpData(); }
}

function render() {
    window.__SCREEN_MODE__ = state.mode; // 供 screen-ip.js 的卡片事件委托判断当前视图
    // IP 视图：委托给 screen-ip.js（模块加载完成前先走域名渲染兜底）。
    if (state.mode === 'ip' && window.ScreenIp) {
        window.ScreenIp.render({
            data: state.ipsData,
            localAt: state.ipsLocalAt,
            tab: state.tab, q: state.q, sort: state.ipSort, status: state.ipStatus, size: state.size, page: state.page,
            onTab: function (t) { state.tab = t; state.page = 1; render(); },
        });
        renderCacheTime();
        saveView();
        return;
    }
    renderTabs();
    renderSyncSelect();
    var dataOk = state.data && Array.isArray(state.data.domains);
    var filtered = dataOk ? applyFilters(state.data, state.tab, state.q, state.domainStatus) : [];
    var sorted = sortDomains(filtered, state.sort);
    var view = pageSlice(sorted, state.page, state.size === 'all' ? 1 : Number(state.size), state.size === 'all');
    state.lastView = view.items;
    renderGrid(view.items, dataOk);
    renderPager(sorted.length, view);
    renderCacheTime();
    renderStats();
    saveView();
}

function renderTabs() {
    var dataOk = state.data && Array.isArray(state.data.domains);
    var accounts = (state.data && state.data.accounts) || [];
    // Tab 计数随搜索联动：按当前关键词（不限账号）过滤后计数。
    var searched = dataOk ? applyFilters(state.data, '', state.q, state.domainStatus) : [];
    var counts = {};
    for (var i = 0; i < searched.length; i++) {
        var pid = searched[i].provider;
        counts[pid] = (counts[pid] || 0) + 1;
    }
    $('sb-tabs').innerHTML = tabHtml('', '全部', searched.length, state.tab === '')
        + accounts.map(function (a) {
            return tabHtml(a.id, a.name || a.id, counts[a.id] || 0, state.tab === a.id);
        }).join('');
}

function tabHtml(id, label, count, active) {
    return '<button type="button" class="sb-tab' + (active ? ' active' : '') + '" data-tab="' + escapeHtml(id) + '">'
        + escapeHtml(label) + '<span class="sb-tab-count">' + Number(count || 0) + '</span></button>';
}

function renderSyncSelect() {
    // 两个视图共用同一个同步下拉：列云账号（IP 视图的 accounts 含“手工”，需过滤）。
    var accounts = ((state.mode === 'ip' ? state.ipsData : state.data) || {}).accounts || [];
    accounts = accounts.filter(function (a) { return a.id !== 'manual'; });
    var sel = $('sb-sync-account');
    var current = sel.value;
    sel.innerHTML = accounts.map(function (a) {
        return '<option value="' + escapeHtml(a.id) + '">' + escapeHtml(a.name || a.id) + '</option>';
    }).join('');
    var wanted = state.tab === 'manual' ? current : (state.tab || current);
    if (wanted && accounts.some(function (a) { return a.id === wanted; })) { sel.value = wanted; }
}

// ---- 访问状态：前端（本浏览器缓存） + 后端（快照携带） 任一通过即通过 ----

function accessStore() {
    try {
        var v = JSON.parse(localStorage.getItem(ACCESS_KEY) || 'null');
        return v && typeof v === 'object' ? v : {};
    } catch (e) { return {}; }
}

function saveAccess(fqdn, kind, entry) {
    var store = accessStore();
    (store[fqdn] = store[fqdn] || {})[kind] = entry;
    try { localStorage.setItem(ACCESS_KEY, JSON.stringify(store)); } catch (e) { /* 忽略 */ }
}

function groupStatus(grp) {
    var cached = accessStore()[grp.fqdn] || {};
    if ((cached.fe && cached.fe.ok === true) || grp.access === 'ok') { return 'ok'; }
    if ((cached.fe && cached.fe.ok === false) || grp.access === 'fail') { return 'fail'; }
    return 'none';
}

function domainStatus(item) {
    var subs = item.subdomains || [];
    var anyChecked = false;
    for (var i = 0; i < subs.length; i++) {
        var s = groupStatus(subs[i]);
        if (s === 'ok') { return 'ok'; }
        if (s === 'fail') { anyChecked = true; }
    }
    return anyChecked ? 'fail' : 'none';
}



// 域名卡片访问状态展示数据：最快成功记录的响应时间
function domainStatusMs(item) {
    var subs = item.subdomains || [];
    var best = null;
    for (var i = 0; i < subs.length; i++) {
        var records = subs[i].records || [];
        for (var j = 0; j < records.length; j++) {
            var access = records[j].access;
            if (access && access.status === 'ok' && access.ms) {
                if (best === null || access.ms < best) { best = access.ms; }
            }
        }
    }
    if (best === null) {
        var cached = accessStore()[item.domain] || {};
        if (cached.fe && cached.fe.ok && cached.fe.ms) { best = cached.fe.ms; }
    }
    return best;
}

// ---- 海报式卡片 ----

function urgencyClass(d) {
    if (d.days_left == null) { return 'u-gray'; }
    if (d.days_left <= 30) { return 'u-red'; }
    if (d.days_left <= 90) { return 'u-amber'; }
    return 'u-green';
}

function httpLink(domain) {
    return 'http://' + String(domain || '').replace(/["<>\\]/g, '') + '/';
}

function cardHtml(d) {
    var chips = [];
    if (d.provider_name) { chips.push('<span class="sb-chip">' + escapeHtml(d.provider_name) + '</span>'); }
    chips.push('<span class="sb-chip">' + escapeHtml(d.expire_at || '到期未知') + '</span>');
    if (d.dns_cached) {
        chips.push('<span class="sb-chip sb-dim">解析快照 ' + escapeHtml(shortTime(d.dns_snapshot_at)) + '</span>');
    } else {
        chips.push('<span class="sb-chip sb-warn">解析未缓存</span>');
    }

    var cls = urgencyClass(d);
    var cardSt = domainStatus(d);
    var stLabel = cardSt === 'ok' ? '正常' : cardSt === 'fail' ? '异常' : '检查';
    var stColorCls = cardSt === 'ok' ? 'c-green' : cardSt === 'fail' ? 'c-red' : 'c-gray';
    var stMs = domainStatusMs(d);
    var zoneKey = d.provider + '|' + d.domain;
    var daysCell = d.days_left == null
        ? '<b class="sb-stat-num c-gray">未知</b>'
        : '<b class="sb-stat-num ' + cls.replace('u-', 'c-') + '">' + Number(d.days_left) + '</b>';

    var subs = d.subdomains || [];
    var hosts;
    if (!d.dns_cached) {
        hosts = '<div class="sb-hosts-empty">更新该账号数据后展示子域名</div>';
    } else if (!subs.length) {
        hosts = '<div class="sb-hosts-empty">该域名暂无 A 记录</div>';
    } else {
        // 单行标签：IP 可点（点击打开解析管理弹窗并定位该子域名）
        var tags = subs.map(function (s) {
            var ipBtns = (s.ips || []).map(function (ip) {
                return '<button type="button" class="sb-tag-ip" data-ip-goto="' + escapeHtml(ip) + '" title="点击打开解析管理并定位此 IP">' + escapeHtml(ip) + '</button>';
            }).join('');
            return '<span class="sb-tag"><span class="sb-tag-name">' + escapeHtml(s.name) + '</span>' + ipBtns + '</span>';
        }).join('');
        hosts = '<div class="sb-tags" data-key="' + escapeHtml(d.provider + '|' + d.domain)
            + '" role="button" tabindex="0" title="点击管理子域名与 IP（多选批量修改 / 删除 / 访问检查）">'
            + tags
            + '</div>';
    }

    return '<article class="sb-card ' + cls + '">'
        + '<div class="sb-card-head"><span class="sb-dot sb-dot-' + domainStatus(d) + '" title="'
        + escapeHtml({ ok: '有访问检查通过', fail: '访问检查均未通过', none: '尚未检查' }[domainStatus(d)]) + '"></span>'
        + '<a class="sb-domain" href="' + escapeHtml(httpLink(d.domain))
        + '" target="_blank" rel="noopener" title="打开 ' + escapeHtml(httpLink(d.domain)) + '">'
        + escapeHtml(d.domain) + '</a>'
        + '<button type="button" class="sb-copy-btn" data-domain-copy="' + escapeHtml(d.domain) + '" title="复制主域名">复制</button></div>'
        + '<div class="sb-stat-row">'
        + '<div class="sb-stat">' + daysCell + '<span>天后到期</span></div>'
        + '<div class="sb-stat sb-stat-link"><b class="sb-stat-num c-cyan">' + Number(d.subdomain_count || 0) + '</b><button type="button" class="sb-stat-btn" data-other-modal="' + zoneKey + '" title="点击查看非 A 记录（CNAME / MX / TXT 等）">子域名</button></div>'
        + '<div class="sb-stat sb-stat-link"><b class="sb-stat-num c-violet">' + Number(d.a_count || 0) + '</b><button type="button" class="sb-stat-btn" data-key="' + zoneKey + '" title="点击管理子域名与 A 记录（批量修改 / 删除 / 添加）">A 记录</button></div>'
        + '<div class="sb-stat"><b class="sb-stat-num ' + stColorCls + '">' + stLabel + '</b><span>访问状态' + (stMs != null ? ' · ' + Number(stMs) + 'ms' : '') + '</span></div>'
        + '</div>'
        + '<div class="sb-meta">' + chips.join('') + '</div>'
        + hosts
        + '</article>';
}

function renderGrid(items, dataOk) {
    var grid = $('sb-grid');
    var empty = $('sb-empty');
    if (!dataOk) {
        grid.innerHTML = '';
        empty.classList.remove('hidden');
        empty.textContent = '正在读取大屏缓存数据…';
        return;
    }
    if (!items.length) {
        grid.innerHTML = '';
        empty.classList.remove('hidden');
        empty.textContent = state.tab || state.q
            ? '当前筛选没有未过期域名'
            : '没有未过期的域名：请先在域名同步中拉取台账，或检查到期日期';
        return;
    }
    empty.classList.add('hidden');
    grid.innerHTML = items.map(cardHtml).join('');
}

function renderPager(total, view) {
    var showAll = state.size === 'all';
    $('sb-count').textContent = '共 ' + total + ' 个域名';
    if (showAll) {
        $('sb-page-info').textContent = '全部展示 ' + total + ' 项';
    } else {
        $('sb-page-info').textContent = '第 ' + view.page + ' / ' + view.pages + ' 页 · 每页 ' + state.size + ' 条';
    }
    $('sb-prev').disabled = showAll || view.page <= 1;
    $('sb-next').disabled = showAll || view.page >= view.pages;
}

function renderCacheTime() {
    var el = $('sb-cache');
    var ip = state.mode === 'ip';
    var data = ip ? state.ipsData : state.data;
    var localAt = ip ? state.ipsLocalAt : state.localAt;
    if (!data) { el.textContent = '读取中…'; return; }
    var savedAt = (data.cache && data.cache.saved_at) || data.generated_at || '';
    var ageMin = ageMinutes(savedAt);
    var prefix = localAt ? '本地缓存' : '数据缓存';
    var rel = '';
    if (ageMin !== null) {
        if (ageMin < 1) { rel = '（刚刚）'; }
        else if (ageMin < 60) { rel = '（' + ageMin + ' 分钟前）'; }
        else if (ageMin < 1440) { rel = '（' + Math.floor(ageMin / 60) + ' 小时前）'; }
        else { rel = '（' + Math.floor(ageMin / 1440) + ' 天前）'; }
    }
    el.textContent = prefix + '于 ' + savedAt + rel;
    // 缓存超过 1 小时提示可能过时；手动更新指定账号可立刻刷新。
    el.classList.toggle('ap-stale', ageMin !== null && ageMin >= 60);
}

function ageMinutes(savedAt) {
    var ts = Date.parse((savedAt || '').replace(/-/g, '/').replace(' ', 'T') + '+08:00');
    if (!isFinite(ts)) { return null; }
    return Math.max(0, Math.round((Date.now() - ts) / 60000));
}

function renderStats() {
    var el = $('sb-stats');
    var s = state.data && state.data.stats;
    if (!s) { el.textContent = ''; return; }
    var text = '共 ' + Number(s.domains || 0) + ' 个域名 · ' + Number(s.subdomains || 0) + ' 个子域名 · ' + Number(s.a_records || 0) + ' 条 A 记录';
    var h = s.hidden || {};
    var hidden = [];
    if (h.expired) { hidden.push('过期 ' + h.expired); }
    if (h.invalid) { hidden.push('失效 ' + h.invalid); }
    if (h.orphaned) { hidden.push('账号缺失 ' + h.orphaned); }
    if (hidden.length) { text += ' · 已隐藏：' + hidden.join(' / '); }
    el.textContent = text;
}

function shortTime(value) {
    var text = String(value || '');
    return text.length >= 16 ? text.slice(5, 16) : text; // MM-DD HH:mm
}

function fmtNow() {
    var d = new Date();
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
}

var toastTimer = null;
function toast(message, type, duration) {
    var el = $('sb-toast');
    el.textContent = message;
    el.className = 'sb-toast show ' + (type === 'error' ? 'sb-toast-error' : (type === 'info' ? 'sb-toast-info' : 'sb-toast-ok'));
    if (toastTimer) { clearTimeout(toastTimer); }
    toastTimer = setTimeout(function () { el.className = 'sb-toast'; }, duration || 5000);
}

function accountName(id) {
    var accounts = (state.data && state.data.accounts) || [];
    for (var i = 0; i < accounts.length; i++) {
        if (accounts[i].id === id) { return accounts[i].name || id; }
    }
    return id;
}

// ---- 手动更新指定账号 ----

function syncAccount() {
    var id = $('sb-sync-account').value;
    if (!id || state.syncing) { return; }
    state.syncing = true;
    var btn = $('sb-sync-btn');
    btn.disabled = true;
    btn.textContent = '更新中…';
    toast('开始更新「' + accountName(id) + '」：先同步域名台账，再刷新解析缓存，约需数十秒', 'info', 8000);
    fetchJson('/api/accounts/' + encodeURIComponent(id) + '/sync', { method: 'POST', body: { refresh_dns: true } })
        .then(function (r) {
            var dns = r.dns || { refreshed: 0, failed: 0, pending: [] };
            var msg = '已同步 ' + ((r.domains && r.domains.count) || 0) + ' 个域名，解析刷新 ' + (dns.refreshed || 0)
                + '、失败 ' + (dns.failed || 0);
            var partial = (dns.failed || 0) > 0 || (dns.pending && dns.pending.length);
            if (dns.pending && dns.pending.length) { msg += '，预算内未刷新 ' + dns.pending.length + ' 个'; }
            toast(msg, partial ? 'info' : 'success', 8000);
            loadData();
        })
        .catch(function (err) {
            toast((err && err.message) || '更新失败，请稍后重试', 'error', 8000);
        })
        .then(function () {
            state.syncing = false;
            btn.disabled = false;
            btn.textContent = '更新数据';
        });
}


function expireBadge(d) {
    if (d.days_left == null) { return '<span class="sb-badge sb-gray">到期未知</span>'; }
    if (d.days_left === 0) { return '<span class="sb-badge sb-red">今天到期</span>'; }
    if (d.days_left <= 30) { return '<span class="sb-badge sb-red">剩 ' + d.days_left + ' 天</span>'; }
    if (d.days_left <= 90) { return '<span class="sb-badge sb-amber">剩 ' + d.days_left + ' 天</span>'; }
    return '<span class="sb-badge sb-green">剩 ' + d.days_left + ' 天</span>';
}


// 前端检查：浏览器直连各主机（no-cors），结果缓存在本浏览器
function frontProbe(fqdn) {
    return new Promise(function (resolve) {
        var settled = false;
        var ctrl = new AbortController();
        var timer = setTimeout(function () {
            if (!settled) { settled = true; ctrl.abort(); resolve(false); }
        }, FRONT_CHECK_TIMEOUT_MS);
        var url = 'http://' + fqdn + '/?_awchk=' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
        fetch(url, { mode: 'no-cors', signal: ctrl.signal, cache: 'no-store' })
            .then(function () { if (!settled) { settled = true; clearTimeout(timer); resolve(true); } })
            .catch(function () { if (!settled) { settled = true; clearTimeout(timer); resolve(false); } });
    });
}


function bind() {
    $('sb-tabs').addEventListener('click', function (event) {
        var btn = event.target.closest('[data-tab]');
        if (!btn) { return; }
        state.tab = btn.getAttribute('data-tab');
        state.page = 1;
        render();
    });

    $('sb-grid').addEventListener('click', function (event) {
        if (event.target.closest('a')) { return; } // 域名标签链接交给浏览器
        var copyDomain = event.target.closest('[data-domain-copy]');
        if (copyDomain) { SB.copyText(copyDomain.getAttribute('data-domain-copy'), '主域名已复制'); return; }
        var statModal = event.target.closest('button[data-key]');
        if (statModal) { ScreenBoard.openModal(statModal.getAttribute('data-key') || ''); return; }
        var otherBtn = event.target.closest('[data-other-modal]');
        if (otherBtn) {
            var zoneKey = otherBtn.getAttribute('data-other-modal');
            var all = (state.data && state.data.domains) || [];
            for (var i = 0; i < all.length; i++) {
                if (all[i].provider + '|' + all[i].domain === zoneKey) { ScreenBoard.openOtherRecords(all[i]); return; }
            }
            return;
        }
        var ipGo = event.target.closest('[data-ip-goto]');
        if (ipGo) {
            var tagsBtn = ipGo.closest('.sb-tags');
            ScreenBoard.openModal(tagsBtn ? tagsBtn.getAttribute('data-key') || '' : '', null, ipGo.getAttribute('data-ip-goto'));
            return;
        }
        var tags = event.target.closest('.sb-tags');
        if (tags) { ScreenBoard.openModal(tags.getAttribute('data-key') || ''); }
    });


    var searchTimer = null;
    $('sb-q').addEventListener('input', function (event) {
        var value = event.target.value;
        if (searchTimer) { clearTimeout(searchTimer); }
        searchTimer = setTimeout(function () {
            state.q = value;
            state.page = 1;
            render();
        }, SEARCH_DELAY_MS);
    });

    $('sb-sort').addEventListener('change', function (event) {
        // 排序键按视图分别记忆。
        if (state.mode === 'ip') { state.ipSort = event.target.value; }
        else { state.sort = event.target.value; }
        state.page = 1;
        render();
    });
    $('sb-status').addEventListener('change', function (event) {
        if (state.mode === 'ip') { state.ipStatus = event.target.value; }
        else { state.domainStatus = event.target.value; }
        state.page = 1;
        render();
    });
    $('sb-domain-fecheck').addEventListener('click', function () { runDomainBatchCheck('front'); });
    $('sb-domain-becheck').addEventListener('click', function () { runDomainBatchCheck('back'); });
    document.querySelectorAll('.sb-mode-btn').forEach(function (btn) {
        btn.addEventListener('click', function () { setMode(btn.getAttribute('data-mode') === 'ip' ? 'ip' : 'domain'); });
    });
    $('sb-size').addEventListener('change', function (event) { state.size = event.target.value; state.page = 1; render(); });
    $('sb-prev').addEventListener('click', function () { state.page -= 1; render(); });
    $('sb-next').addEventListener('click', function () { state.page += 1; render(); });
    $('sb-size').addEventListener('change', function (event) { state.size = event.target.value; state.page = 1; render(); });
    $('sb-sync-btn').addEventListener('click', syncAccount);

    // 手动刷新：带 ?refresh=1 绕过快照 TTL 立即重建（不访问云商）。
    $('sb-refresh-btn').addEventListener('click', function () {
        var button = $('sb-refresh-btn');
        button.disabled = true;
        loadData(true).then(function () { toast('已重新读取缓存快照'); })
            .finally(function () { button.disabled = false; });
    });

    // 轮询按设置页配置执行（默认关闭）：弹窗打开或批量写入 / 检查执行期间自动暂停，保持数据稳定。
    function pollingAllowed() {
        return state.autoRefresh.enabled && !document.hidden && !state.busyWrite && !state.busyCheck
            && !$('sb-modal').classList.contains('hidden')
            && !document.querySelector('.sb-ipov'); // IP 关联域名弹窗打开时同样暂停
    }
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && pollingAllowed()) { loadData(); }
    });
    var pollTimer = null;
    function applyPolling(seconds) {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        if (!seconds || seconds < 60) { return; }
        pollTimer = setInterval(function () {
            if (pollingAllowed()) { loadData(); }
        }, seconds * 1000);
    }
    // 从设置页读取大屏刷新配置（读取失败按手动模式）。
    fetchJson('/api/settings').then(function (s) {
        var screen = s.screen || {};
        state.autoRefresh = { enabled: !!screen.auto_refresh, seconds: screen.refresh_seconds || 300 };
        applyPolling(state.autoRefresh.enabled ? state.autoRefresh.seconds : 0);
    }).catch(function () {
        state.autoRefresh = { enabled: false, seconds: 0 };
    });

    // hash-only 导航（前进 / 后退 / 手动改 URL）时跟随视图模式
    window.addEventListener('hashchange', function () {
        var m = (location.hash.match(/^#mode=(ip|domain)/) || [])[1];
        if (m && m !== state.mode) { setMode(m); }
    });
    setInterval(function () {
        $('sb-clock').textContent = new Date().toLocaleTimeString('zh-CN', { hour12: false });
    }, 1000);
    $('sb-clock').textContent = new Date().toLocaleTimeString('zh-CN', { hour12: false });
}

/* ---- 域名视图批量访问检查（前端浏览器直连 / 后端逐域名全量探测） ---- */

var domainCheckBusy = false;

function runDomainBatchCheck(mode) {
    if (domainCheckBusy) { return; }
    var items = state.lastView; // 当前筛选 + 分页后的域名行
    if (!items || !items.length) { toast('当前列表没有域名', 'info'); return; }
    if (mode === 'front' && items.length > 200) { items = items.slice(0, 200); toast('列表超过 200 个，本次检查前 200 个', 'info', 5000); }
    domainCheckBusy = true;
    ['sb-domain-fecheck', 'sb-domain-becheck', 'sb-ip-fecheck', 'sb-ip-becheck'].forEach(function (id) { $(id).disabled = true; });
    if (mode === 'front') {
        // 浏览器直连探测主域名（4 路并发），按 zone 收集后逐域名上报（快照行的 dns_revision 直接可用）
        toast('前端检查中：浏览器直连 ' + items.length + ' 个主域名…', 'info', 8000);
        var groups = {};
        items.forEach(function (d) {
            var key = d.provider + '|' + d.domain;
            if (!groups[key]) { groups[key] = { provider: d.provider, domain: d.domain, revision: d.dns_revision, results: {} }; }
            groups[key].target = d.domain;
        });
        var list = items.slice(), okCount = 0, cursor = 0;
        var worker = function () {
            if (cursor >= list.length) { return Promise.resolve(); }
            var d = list[cursor++];
            return frontProbe(d.domain).then(function (res) {
                if (res.ok) { okCount++; }
                var key = d.provider + '|' + d.domain;
                if (groups[key]) { groups[key].results[d.domain] = { ok: res.ok === true, ms: res.ms }; }
                return worker();
            });
        };
        var pool = [];
        for (var i = 0; i < Math.min(4, list.length); i++) { pool.push(worker()); }
        Promise.all(pool).then(function () {
            var groupList = Object.keys(groups).map(function (k) { return groups[k]; }).filter(function (g) {
                return Object.keys(g.results).length && typeof g.revision === 'string' && g.revision;
            });
            var chain = Promise.resolve();
            groupList.forEach(function (g) {
                chain = chain.then(function () {
                    return fetchJson('/api/domains/' + encodeURIComponent(g.provider) + '/' + encodeURIComponent(g.domain) + '/records/browser-check',
                        { method: 'POST', body: { revision: g.revision, results: g.results } })
                        .catch(function () { /* 单域名上报失败不影响其余 */ });
                });
            });
            chain.then(function () {
                domainCheckBusy = false;
                ['sb-domain-fecheck', 'sb-domain-becheck'].forEach(function (id) { $(id).disabled = false; });
                toast('前端检查完成：通过 ' + okCount + ' / ' + list.length + '（结果已全局缓存）', okCount ? 'success' : 'error', 9000);
                loadData(true);
            });
        });
        return;
    }
    // 后端：逐域名调用全量解析探测（每个域名一次请求，内部并发探测全部记录）
    toast('后端检查中：服务端逐域名探测 ' + items.length + ' 个域名的全部解析记录，可能需要数分钟…', 'info', 10000);
    var doneCount = 0, total = items.length;
    var zones = items.slice();
    var zChain = Promise.resolve();
    zones.forEach(function (d) {
        zChain = zChain.then(function () {
            return fetchJson('/api/domains/' + encodeURIComponent(d.provider) + '/' + encodeURIComponent(d.domain) + '/records/access-check', { method: 'POST', body: {} })
                .catch(function () { /* 单域名失败继续其余 */ })
                .then(function () { doneCount++; toast('后端检查进度：' + doneCount + ' / ' + total, 'info', 3000); });
        });
    });
    zChain.then(function () {
        domainCheckBusy = false;
        ['sb-domain-fecheck', 'sb-domain-becheck'].forEach(function (id) { $(id).disabled = false; });
        toast('后端检查完成：' + total + ' 个域名全部记录已探测并缓存', 'success', 9000);
        loadData(true);
    });
}

function init() {
    // 先画本地缓存的快照（秒开），再拉取最新数据；页码等视图状态从 localStorage 恢复。
    try {
        var saved = JSON.parse(localStorage.getItem(CACHE_KEY) || 'null');
        if (saved && saved.payload && Array.isArray(saved.payload.domains)) {
            state.data = saved.payload;
            state.localAt = saved.at;
        }
    } catch (e) { /* 缓存损坏则直接拉取 */ }
    try {
        var savedIps = JSON.parse(localStorage.getItem(IPS_CACHE_KEY) || 'null');
        if (savedIps && savedIps.payload && Array.isArray(savedIps.payload.ips)) {
            state.ipsData = savedIps.payload;
            state.ipsLocalAt = savedIps.at;
        }
    } catch (e) { /* 缓存损坏则直接拉取 */ }
    try { restoreView(); } catch (e) { toast('初始化异常：' + e.message, 'error'); }
    try { bind(); } catch (e) { toast('初始化异常：' + e.message, 'error'); }
    try { render(); } catch (e) { toast('渲染异常：' + e.message, 'error'); }
    loadData();
    if (state.mode === 'ip') { loadIpData(); }
}

init();
try { window.AdminPage.trackHeadHeight('.ap-head'); } catch (e) { /* 共享模块缺失时使用 CSS 回退高度 */ }

// screen-ip.js 在本文件之后加载；就绪后按当前视图重绘（刷新期间保存的 IP 视图）。
ScreenBoard.onIpModuleReady = function () {
    render();
};

})();
