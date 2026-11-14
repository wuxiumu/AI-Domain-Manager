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

// IP 视图点击域名标签 → 打开该域名的子域名管理弹窗（支持前端 / 后端访问检查；弹窗标题可跳转网站）。
// source 携带来源 IP 卡片的 IP 与被点击的 FQDN，弹窗内高亮对应记录，解释“一个域名多条解析指向不同 IP”的视角差异。
// 域名快照尚未加载时先拉取再打开；域名不在未过期清单（已过期 / 未同步）时给出提示。
ScreenBoard.openDomainModal = function (key, source) {
    var open = function () {
        if (modalItemFor(key)) { openModal(key, source); }
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

function accessTitle(grp) {
    var cached = accessStore()[grp.fqdn] || {};
    var parts = [];
    if (cached.fe) { parts.push('前端检查' + (cached.fe.ok ? '通过' : '异常') + (cached.fe.ms ? ' ' + cached.fe.ms + 'ms' : '') + ' ' + cached.fe.at); }
    if (grp.browser_at) { parts.push('前端检查上报于 ' + grp.browser_at + (grp.browser_ms ? ' ' + grp.browser_ms + 'ms' : '')); }
    var be = null;
    (grp.records || []).some(function (r) { if (r.access) { be = r.access; return true; } return false; });
    if (be) { parts.push('后端检查' + (be.status === 'ok' ? '通过' : '异常') + ' HTTP ' + be.http_code + ' ' + be.checked_at); }
    return parts.join('；') || '尚未检查';
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

// ---- 弹窗：子域名多选 + 批量修改 / 删除 + 访问检查 ----

function modalItem() {
    if (!state.modalKey) { return null; }
    var all = (state.data && state.data.domains) || [];
    for (var i = 0; i < all.length; i++) {
        if (all[i].provider + '|' + all[i].domain === state.modalKey) { return all[i]; }
    }
    return null;
}

function openModal(key, source, locateIp) {
    if (!modalItemFor(key)) { return; }
    state.modalKey = key;
    // 来源上下文（从 IP 视图进入时携带）：高亮指向该 IP 的记录与被点击的域名。
    state.modalSource = source && source.ip ? { ip: String(source.ip), fqdn: String(source.fqdn || '') } : null;
    state.locateIp = locateIp ? String(locateIp) : null; // 点击卡片 IP：打开后勾选该组并滚动定位
    state.selected = {};
    hideInlineBars();
    ensureIpLocations(); // 归属地缓存就绪后重绘记录行
    renderModal();
    $('sb-modal').classList.remove('hidden');
}

function modalItemFor(key) {
    var all = (state.data && state.data.domains) || [];
    for (var i = 0; i < all.length; i++) {
        if (all[i].provider + '|' + all[i].domain === key) { return all[i]; }
    }
    return null;
}

function closeModal() {
    state.modalKey = null;
    state.modalSource = null;
    state.locateIp = null;
    state.selected = {};
    state.modalQ = '';
    state.modalStatus = '';
    $('sb-modal').classList.add('hidden');
}

// IP 归属地缓存（来自 /api/ips/screen 的服务端缓存，不触发探测）：弹窗记录行直接展示
var ipLocMap = null;
var ipLocLoading = false;
function ensureIpLocations() {
    if (ipLocMap || ipLocLoading) { return; }
    ipLocLoading = true;
    fetchJson('/api/ips/screen').then(function (snap) {
        var map = {};
        ((snap && snap.ips) || []).forEach(function (r) {
            if (r && r.ip && r.location && (r.location.country || r.location.city)) { map[r.ip] = r.location; }
        });
        ipLocMap = map;
    }).catch(function () {
        ipLocMap = {}; // 读取失败本会话不再重试，弹窗只少展示归属地
    }).then(function () {
        ipLocLoading = false;
        if (state.modalKey) { renderModal(); }
    });
}

/** 归属地展示：国旗 + 国家 · 城市；悬停提示 ISP 与缓存时间 */
function ipGeoHtml(loc) {
    var text = [loc.country, loc.city].filter(Boolean).join(' · ');
    if (!text) { return ''; }
    var flag = (window.ScreenIp && ScreenIp.flagOf) ? ScreenIp.flagOf(loc.country_code) : '';
    var tip = [loc.isp, loc.checked_at ? '归属地缓存于 ' + loc.checked_at : ''].filter(Boolean).join(' · ');
    return '<span class="sb-ip-geo"' + (tip ? ' title="' + escapeHtml(tip) + '"' : '') + '>' + flag + ' ' + escapeHtml(text) + '</span>';
}

function hideInlineBars() {
    $('sb-ipedit').classList.add('hidden');
    $('sb-delconf').classList.add('hidden');
    $('sb-addbar').classList.add('hidden');
}

function expireBadge(d) {
    if (d.days_left == null) { return '<span class="sb-badge sb-gray">到期未知</span>'; }
    if (d.days_left === 0) { return '<span class="sb-badge sb-red">今天到期</span>'; }
    if (d.days_left <= 30) { return '<span class="sb-badge sb-red">剩 ' + d.days_left + ' 天</span>'; }
    if (d.days_left <= 90) { return '<span class="sb-badge sb-amber">剩 ' + d.days_left + ' 天</span>'; }
    return '<span class="sb-badge sb-green">剩 ' + d.days_left + ' 天</span>';
}

function renderModal() {
    var item = modalItem();
    if (!item) { closeModal(); return; }

    var src = state.modalSource;
    // 弹窗展示整个域名的全部解析记录（DNS 写入按域名区域管理）；
    // 从 IP 卡片进入时标注视角差异：高亮指向来源 IP 的记录与被点击的域名。
    var sourceChip = src
        ? '<span class="sb-chip sb-chip-source" title="弹窗列出该域名的全部解析记录；一个域名下不同子域名可能指向不同服务器，绿色为指向来源 IP 的记录">来自 IP ' + escapeHtml(src.ip) + '</span>'
        : '';
    $('sb-modal-head').innerHTML = '<a class="sb-modal-domain" href="' + escapeHtml(httpLink(item.domain))
        + '" target="_blank" rel="noopener" title="打开 ' + escapeHtml(httpLink(item.domain)) + '">'
        + escapeHtml(item.domain) + '</a>'
        + '<div class="sb-modal-meta">' + expireBadge(item)
        + (item.provider_name ? '<span class="sb-chip">' + escapeHtml(item.provider_name) + '</span>' : '')
        + '<span class="sb-chip">A ' + Number(item.a_count || 0) + ' 条 · 其他 ' + Number(item.other_count || 0) + '</span>'
        + (item.dns_snapshot_at ? '<span class="sb-chip">解析快照 ' + escapeHtml(item.dns_snapshot_at) + '</span>' : '<span class="sb-chip sb-warn">解析未缓存</span>')
        + sourceChip
        + '</div>';

    // 可用性拆分：有解析快照即可批量添加（添加需要 revision）；修改 / 删除 / 检查还需存在记录。
    var canAdd = item.dns_cached && item.dns_revision != null;
    var hasRecords = (item.subdomains || []).some(function (s) { return (s.records || []).length; });
    $('sb-modal-tools').classList.toggle('hidden', !item.dns_cached);
    $('sb-batchadd').disabled = !canAdd || state.busyCheck || state.busyWrite;
    ['sb-editip', 'sb-del', 'sb-fecheck', 'sb-becheck', 'sb-invert'].forEach(function (id) {
        $(id).disabled = !hasRecords || state.busyCheck || state.busyWrite;
    });
    $('sb-tool-hint').textContent = hasRecords
        ? '前端 / 后端任一通过即通过 · 勾选后只检查选中项，未勾选检查全部 · 前端结果缓存于本浏览器 · 修改与删除直接写入云端 DNS'
        : '该域名暂无 A 记录：点「批量添加」创建第一条解析（每行一条：主机名 + IPv4）';

    // 弹窗内筛选：关键词（主机名 / IP）+ 访问状态 tab；筛选条件在弹窗会话内保留，关闭即重置
    var allRows = item.subdomains || [];
    var filterRow = $('sb-modal-filter');
    filterRow.classList.toggle('hidden', !allRows.length);
    $('sb-modal-q').value = state.modalQ || '';
    document.querySelectorAll('#sb-status-tabs .sb-status-tab').forEach(function (t) {
        t.classList.toggle('is-active', (t.getAttribute('data-mstatus') || '') === (state.modalStatus || ''));
    });
    var q = (state.modalQ || '').toLowerCase();
    var stFilter = state.modalStatus || '';
    var rows = allRows.filter(function (s) {
        if (stFilter && groupStatus(s) !== stFilter) { return false; }
        if (q) {
            var matched = s.fqdn.toLowerCase().indexOf(q) !== -1
                || (s.records || []).some(function (r) { return String(r.value || '').toLowerCase().indexOf(q) !== -1; });
            if (!matched) { return false; }
        }
        return true;
    });
    $('sb-modal-count').textContent = (q || stFilter) ? '匹配 ' + rows.length + ' / 共 ' + allRows.length + ' 行' : '';

    var rowsHtml = rows.map(function (s) {
        var st = groupStatus(s);
        var clicked = src && src.fqdn && s.fqdn === src.fqdn;
        // 表格化行：勾选 | 主机记录 | 访问状态 | TTL | 解析记录（固定列，多数据也对齐）
        var badge = '<span class="sb-host-badges">'
            + '<span class="sb-access sb-access-' + st + '" title="' + escapeHtml(accessTitle(s)) + '">'
            + (st === 'ok' ? '通过' : st === 'fail' ? '异常' : '未检查') + '</span>'
            + (clicked ? '<span class="sb-hitmark" title="你在 IP 卡片上点击的域名">所选域名</span>' : '')
            + '</span>';
        // 域名与 IP 均为可访问链接（新窗口打开）；通配符记录无法直接访问，保持纯文本。
        var hostCell = s.fqdn.indexOf('*') === -1
            ? '<a class="sb-hostname" href="' + escapeHtml(httpLink(s.fqdn)) + '" target="_blank" rel="noopener" title="' + escapeHtml(s.fqdn) + ' — 新页面打开 ' + escapeHtml(httpLink(s.fqdn)) + '">' + escapeHtml(s.fqdn) + '</a>'
            : '<span class="sb-hostname" title="' + escapeHtml(s.fqdn) + '">' + escapeHtml(s.fqdn) + '</span>';
        var hasHit = false;
        // 按记录逐条展示 IP（暂停态可精确到记录）；同值多线路会重复显示。每条记录固定一行，超出省略。
        var ips = (s.records || []).map(function (rec) {
            var paused = String(rec.status || '').toUpperCase() === 'DISABLE';
            var hit = src && rec.value === src.ip;
            if (hit) { hasHit = true; }
            var link = '<a class="sb-ip' + (hit ? ' sb-ip-hit' : '') + (paused ? ' sb-ip-paused' : '') + '" href="' + escapeHtml(httpLink(rec.value)) + '" target="_blank" rel="noopener" title="打开 ' + escapeHtml(httpLink(rec.value)) + '">' + escapeHtml(rec.value) + '</a>';
            var marks = '';
            if (paused) { marks += '<span class="sb-pausedmark" title="记录已暂停解析：该地址当前不再生效">已暂停</span>'; }
            if (hit) { marks += '<span class="sb-hitmark" title="该记录指向你来源的 IP 卡片">指向本 IP</span>'; }
            var ms = rec.access && rec.access.ms ? '<span class="sb-ms">' + Number(rec.access.ms) + 'ms</span>' : '';
            // 归属地（全局缓存，就绪后随重绘出现）
            var geo = ipLocMap && ipLocMap[rec.value] ? ipGeoHtml(ipLocMap[rec.value]) : '';
            // 单条记录「修改」按钮：打开编辑弹窗，可改主机记录 / 类型 / 记录值 / TTL / 线路 / 优先级
            var recEdit = (!paused && hasRecords)
                ? '<button type="button" class="sb-rec-edit-btn" data-rec-edit="' + escapeHtml(rec.id) + '" data-rec-type="' + escapeHtml(rec.type || 'A') + '" title="编辑这条解析记录（主机 / 类型 / 值 / TTL 等）">修改</button>'
                : '';
            return '<span class="sb-rec">'
                + '<span class="sb-rec-main">' + link + geo + ms + marks + '</span>'
                + recEdit
                + '</span>';
        }).join('');
        return '<div class="sb-host' + (clicked ? ' sb-host-clicked' : '') + (hasHit ? ' sb-host-hit' : '') + '">'
            + '<input type="checkbox" class="sb-check" data-fqdn="' + escapeHtml(s.fqdn) + '"' + (state.selected[s.fqdn] ? ' checked' : '') + '>'
            + hostCell
            + badge
            + '<span class="sb-host-ttl">' + (s.ttl ? 'TTL ' + Number(s.ttl) : '') + '</span>'
            + '<span class="sb-ips">' + ips + '</span>'
            + '</div>';
    }).join('');
    var headHtml = rowsHtml
        ? '<div class="sb-host-head"><span></span><span>主机记录</span><span>访问状态</span><span>TTL</span><span>解析记录</span></div>'
        : '';
    $('sb-modal-body').innerHTML = headHtml + (rowsHtml
        || (allRows.length
            ? '<div class="sb-hosts-empty">没有匹配的记录：调整关键词或访问状态筛选</div>'
            : '<div class="sb-hosts-empty">该域名暂无 A 记录 · 点上方「批量添加」创建第一条解析（每行一条：主机名 + IPv4）</div>'));

    // 点击卡片 IP 进入：勾选该子域名组、高亮并滚动定位到可见
    if (state.locateIp) {
        var targetRow = null;
        document.querySelectorAll('#sb-modal-body .sb-host').forEach(function (rowEl) {
            var ipsText = rowEl.querySelector('.sb-ips') ? rowEl.querySelector('.sb-ips').textContent : '';
            if (ipsText.indexOf(state.locateIp) !== -1) { targetRow = rowEl; }
        });
        if (targetRow) {
            var cb = targetRow.querySelector('.sb-check');
            if (cb) {
                var fqdn = cb.getAttribute('data-fqdn');
                cb.checked = true;
                if (fqdn) { state.selected[fqdn] = true; }
            }
            targetRow.classList.add('sb-host-locate');
            syncPickAll();
            syncButtons();
            setTimeout(function () { targetRow.scrollIntoView({ block: 'center', behavior: 'smooth' }); }, 60);
        }
        state.locateIp = null; // 一次性定位
    }

    syncPickAll();
    syncButtons();
}

function selectedGroups(item) {
    return (item.subdomains || []).filter(function (s) {
        return state.selected[s.fqdn] && (s.records || []).length;
    });
}

function selectedCount() { return Object.keys(state.selected).length; }

function syncPickAll() {
    var boxes = document.querySelectorAll('#sb-modal-body .sb-check');
    var all = boxes.length > 0;
    boxes.forEach(function (cb) { if (!cb.checked) { all = false; } });
    $('sb-pickall').checked = all;
}

function syncButtons() {
    var n = selectedCount();
    $('sb-editip').disabled = !n;
    $('sb-del').disabled = !n;
    $('sb-editip').textContent = n ? '修改 IP（' + n + '）' : '修改 IP';
    $('sb-del').textContent = n ? '删除（' + n + '）' : '删除';
    $('sb-delcount').textContent = n;
}

function setToolsBusy(text) {
    ['sb-editip', 'sb-del', 'sb-batchadd', 'sb-fecheck', 'sb-becheck', 'sb-invert'].forEach(function (id) { $(id).disabled = !!text; });
    if (text) { $('sb-tool-hint').textContent = text; }
    else { renderModal(); } // 忙碌结束后按当前数据恢复按钮可用性与提示文案
}

function reloadAfterWrite() {
    return loadData().then(function () {
        if (state.modalKey && modalItemFor(state.modalKey)) { openModal(state.modalKey, state.modalSource); }
    });
}

// 单条记录编辑弹窗：先读取云端记录缓存拿到完整字段（主机 / 类型 / 线路 / 优先级），再弹出表单
function openRecordEditModal(item, recId) {
    if (!item.dns_cached) { toast('该域名解析尚未缓存，请先「更新数据」再编辑', 'error'); return; }
    var base = '/api/domains/' + encodeURIComponent(item.provider) + '/' + encodeURIComponent(item.domain) + '/records';
    fetchJson(base).then(function (snap) {
        var rec = null;
        (snap.records || []).forEach(function (r) { if (String(r.id) === String(recId)) { rec = r; } });
        if (!rec) { toast('记录已不存在或已变化，请刷新后重试', 'error'); return; }
        openRecordEditForm(item, rec);
    }).catch(function (err) {
        toast('读取解析记录失败：' + ((err && err.message) || '未知错误'), 'error', 9000);
    });
}

// 编辑表单（与「非 A 记录」编辑同一套护栏：单次写入 + 回读核实 + 版本冲突提示）
function openRecordEditForm(item, rec) {
    var isGodaddy = String(item.provider || '').toLowerCase().indexOf('godaddy') !== -1;
    var formOv = document.createElement('div');
    formOv.className = 'sb-ipov';
    var typeOptions = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'].map(function (t) {
        return '<option value="' + t + '"' + (String(rec.type || 'A').toUpperCase() === t ? ' selected' : '') + '>' + t + '</option>';
    }).join('');
    var godaddyNote = isGodaddy
        ? '<p class="sb-demo-note sb-warn">GoDaddy 不支持修改已有记录的类型与主机名；如需变更，请删除该记录后重新添加。</p>'
        : '';
    formOv.innerHTML = '<div class="sb-ipbox sb-demobox"><h3>编辑解析记录 · ' + escapeHtml(item.domain) + '</h3>'
        + '<p class="sb-demo-note">' + escapeHtml(rec.name === '' || rec.name === '@' ? '@（主域名本身）' : rec.name) + ' · ' + escapeHtml(String(rec.type || '').toUpperCase())
        + ' · 只写这一条并回读核实；TTL / 线路 / 优先级留空则保持云端当前值。</p>'
        + godaddyNote
        + '<form class="ap-form dc-form">'
        + '<div class="ap-row">'
        + '<label>主机记录（@ 为主域名）<input name="name" required value="' + escapeHtml(rec.name || '@') + '"' + (isGodaddy ? ' disabled' : '') + ' spellcheck="false"></label>'
        + '<label>记录类型<select name="type"' + (isGodaddy ? ' disabled' : '') + '>' + typeOptions + '</select></label>'
        + '</div>'
        + '<label>记录值<input name="value" required value="' + escapeHtml(rec.value || '') + '" spellcheck="false"></label>'
        + '<div class="ap-row">'
        + '<label>TTL（秒）<input name="ttl" type="number" min="1" value="' + (rec.ttl ? Number(rec.ttl) : '') + '" placeholder="留空保持当前值"></label>'
        + '<label>线路（阿里云 / 腾讯云）<input name="line" value="' + escapeHtml(rec.line || '') + '" placeholder="default（留空保持当前值）" spellcheck="false"></label>'
        + '</div>'
        + '<label>优先级（仅 MX / SRV 生效）<input name="priority" type="number" min="0" max="65535" value="' + (rec.priority != null && rec.priority > 0 ? Number(rec.priority) : '') + '" placeholder="留空保持当前值"></label>'
        + '<p class="sb-demo-note">类型说明见弹窗工具栏「批量添加 → 解析示例」。A / AAAA 记录值必须是合法 IP 地址。</p>'
        + '<p class="dc-form-err hidden"></p>'
        + '<div class="sb-demo-foot"><button type="button" class="sb-mini" data-cancel>取消</button>'
        + '<button type="submit" class="sb-mini sb-mini-primary">保存修改（单次写入并回读核实）</button></div>'
        + '</form></div>';
    document.body.appendChild(formOv);
    var form = formOv.querySelector('form');
    var closeForm = function () { formOv.remove(); };
    formOv.querySelector('[data-cancel]').addEventListener('click', closeForm);
    formOv.addEventListener('click', function (e) { if (e.target === formOv) { closeForm(); } });
    formOv.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeForm(); } });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var errEl = formOv.querySelector('.dc-form-err');
        errEl.classList.add('hidden');
        var body = {
            type: form.elements.type.value.toUpperCase(),
            name: form.elements.name.value.trim().replace(/\.$/, '') || '@',
            value: form.elements.value.value.trim(),
            expected_version: rec.version
        };
        if (body.type === 'A' && !isIPv4(body.value)) { errEl.textContent = 'A 记录的记录值必须是合法 IPv4 地址（如 192.0.2.10）'; errEl.classList.remove('hidden'); return; }
        var isIPv6 = /^[0-9a-fA-F:]{2,45}$/.test(body.value) && body.value.indexOf(':') !== -1;
        if (body.type === 'AAAA' && !isIPv6) { errEl.textContent = 'AAAA 记录的记录值必须是合法 IPv6 地址'; errEl.classList.remove('hidden'); return; }
        if (body.value === '') { errEl.textContent = '记录值不能为空'; errEl.classList.remove('hidden'); return; }
        var ttlRaw = form.elements.ttl.value.trim();
        if (ttlRaw !== '') { body.ttl = Number(ttlRaw); }
        var lineRaw = form.elements.line.value.trim();
        if (lineRaw !== '') { body.line = lineRaw; }
        var priRaw = form.elements.priority.value.trim();
        if (priRaw !== '' && (body.type === 'MX' || body.type === 'SRV')) { body.priority = Number(priRaw); }
        var btn = form.querySelector('[type="submit"]');
        btn.disabled = true;
        fetchJson('/api/domains/' + encodeURIComponent(item.provider) + '/' + encodeURIComponent(item.domain) + '/records/' + encodeURIComponent(rec.id), { method: 'PUT', body: body })
            .then(function (r) {
                closeForm();
                toast(r.verified === false ? '已提交但未能回读核实，请稍后刷新核对' : '记录已修改并回读核实', r.verified === false ? 'info' : 'success', 9000);
                return reloadAfterWrite();
            })
            .catch(function (err) {
                btn.disabled = false;
                var msg = (err && err.message) || '保存失败';
                if (err && err.status === 409) { msg = '记录已在云端被修改（版本冲突），请关闭弹窗后重试'; closeForm(); reloadAfterWrite(); }
                errEl.textContent = msg;
                errEl.classList.remove('hidden');
            });
    });
}

function batchWrite(entries, action, ip) {
    if (!entries.length) { toast('选中项没有可操作的 A 记录', 'info'); return; }
    var item = modalItem();
    var base = '/api/domains/' + encodeURIComponent(item.provider) + '/' + encodeURIComponent(item.domain) + '/records/';
    state.busyWrite = true;
    hideInlineBars();
    setToolsBusy(action === 'update' ? '正在批量修改 IP（逐条写入并回读核实）…' : '正在批量删除（逐条写入并回读核实）…');
    var ok = 0, fail = 0, firstErr = null;
    function step(i) {
        if (i >= entries.length) {
            state.busyWrite = false;
            setToolsBusy('');
            var label = action === 'update' ? 'IP 修改' : '删除';
            var pass = (fail ? (ok ? 'info' : 'error') : 'success');
            toast(label + '完成：成功 ' + ok + ' · 失败 ' + fail + (firstErr ? '；首个错误：' + firstErr : ''), pass, 9000);
            hideInlineBars();
            reloadAfterWrite();
            return;
        }
        var grp = entries[i].grp, rec = entries[i].rec;
        var req = action === 'update'
            ? { method: 'PUT', body: { value: ip, expected_version: rec.version } }
            : { method: 'DELETE', body: { expected_version: rec.version } };
        fetchJson(base + encodeURIComponent(rec.id), req)
            .then(function () { ok++; })
            .catch(function (err) { fail++; firstErr = firstErr || (grp.fqdn + '：' + ((err && err.message) || '失败')); })
            .then(function () { step(i + 1); });
    }
    step(0);
}

function selectedRecords() {
    var item = modalItem();
    var entries = [];
    selectedGroups(item).forEach(function (g) {
        (g.records || []).forEach(function (r) { entries.push({ grp: g, rec: r }); });
    });
    return entries;
}

function applyBatchIp() {
    var ip = $('sb-ipinput').value.trim();
    if (!/^(\d{1,3}\.){3}\d{1,3}$/.test(ip) || ip.split('.').some(function (o) { return Number(o) > 255; })) {
        toast('请输入合法的 IPv4 地址（如 192.0.2.10）', 'error');
        return;
    }
    batchWrite(selectedRecords(), 'update', ip);
}

// ---- 批量添加解析：Markdown 多行（如 www 192.0.2.10），重复自动跳过 ----

function fqdnOfName(name, domain) {
    if (name === '@') { return domain; }
    var suffix = '.' + domain;
    return (name.length > suffix.length && name.slice(-suffix.length) === suffix) ? name : name + suffix;
}

var BATCH_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS'];

function isIPv4(v) {
    return /^(\d{1,3}\.){3}\d{1,3}$/.test(v) && v.split('.').every(function (o) { return Number(o) <= 255; });
}
function isIPv6(v) {
    return /^[0-9a-fA-F:]+$/.test(v) && v.indexOf(':') !== -1 && v.length <= 45;
}
function isDomainValue(v) {
    return /^[a-z0-9]([a-z0-9-*_]*[a-z0-9-*_])?(\.[a-z0-9-*_]+)*\.?$/.test(v.toLowerCase()) && v.indexOf('.') !== -1;
}

/** 批量添加解析：全类型行解析。
 *  简写：主机 值（IPv4→A、IPv6→AAAA、域名→CNAME）
 *  显式：主机 类型 值 [优先级]（MX 的数字 token 为优先级，TXT 值用引号包裹可含空格） */
function parseBatchLines(text) {
    var entries = [];
    var bad = [];
    var seen = {};
    String(text || '').split('\n').forEach(function (raw, index) {
        var line = raw.trim();
        if (!line || line.charAt(0) === '#' || line.charAt(0) === ';') { return; }
        // 容忍 Markdown 列表与加粗/行内代码标记
        line = line.replace(/^[-*+]\s+/, '').replace(/`/g, '').replace(/\*\*/g, '').trim();
        if (!line) { return; }
        var no = '第 ' + (index + 1) + ' 行';
        var parts = line.split(/[\s\t]+/).filter(Boolean);
        if (parts.length < 2) { bad.push(no + '格式不完整'); return; }
        var name = parts[0].toLowerCase().replace(/\.$/, '');
        if (name !== '@' && !/^[a-z0-9_\-*]+(\.[a-z0-9_\-*]+)*$/.test(name)) { bad.push(no + '主机名不合法'); return; }
        var rest = parts.slice(1);
        var type = '';
        if (BATCH_TYPES.indexOf(rest[0].toUpperCase()) !== -1) {
            type = rest[0].toUpperCase();
            rest = rest.slice(1);
        }
        var value = rest.join(' ').replace(/^"(.*)"$/, '$1').trim();
        var priority = null;
        if (type === 'MX') {
            var digits = rest.filter(function (t) { return /^\d{1,3}$/.test(t); });
            priority = digits.length ? Number(digits[0]) : 10;
            value = rest.filter(function (t) { return !/^\d{1,3}$/.test(t); }).join(' ').replace(/^"(.*)"$/, '$1').trim();
        }
        if (!type) {
            if (isIPv4(value)) { type = 'A'; }
            else if (isIPv6(value)) { type = 'AAAA'; }
            else if (isDomainValue(value)) { type = 'CNAME'; }
            else { bad.push(no + '无法识别记录值（应为 IPv4 / IPv6 / 域名，或在值前写明类型）'); return; }
        }
        if (type === 'A' && !isIPv4(value)) { bad.push(no + 'A 记录需要合法 IPv4 地址'); return; }
        if (type === 'AAAA' && !isIPv6(value)) { bad.push(no + 'AAAA 记录需要合法 IPv6 地址'); return; }
        if ((type === 'CNAME' || type === 'NS' || type === 'MX') && (value.indexOf(' ') !== -1 || !isDomainValue(value))) { bad.push(no + type + ' 记录值应为一个域名'); return; }
        if (type === 'TXT' && (value === '' || value.length > 512)) { bad.push(no + 'TXT 记录值需为 1–512 字符（含空格请用引号包裹）'); return; }
        var key = name + '|' + type + '|' + value + '|' + (priority || '');
        if (seen[key]) { return; } // 输入内重复：跳过
        seen[key] = true;
        var entry = { name: name, type: type, value: value };
        if (priority !== null) { entry.priority = priority; }
        entries.push(entry);
    });
    return { entries: entries, bad: bad };
}

var RECORD_DEMOS = [
    { type: 'A', label: 'A 记录 · 将主机指向 IPv4 地址', lines: ['www 192.0.2.10', 'blog A 192.0.2.11'],
        note: '最常用；简写「主机 IPv4」自动识别为 A；@ 表示主域名本身。' },
    { type: 'AAAA', label: 'AAAA 记录 · 将主机指向 IPv6 地址', lines: ['www 2001:db8::1'],
        note: '值含冒号自动识别为 AAAA。' },
    { type: 'CNAME', label: 'CNAME · 将主机指向另一个域名', lines: ['blog CNAME example.com', 'cdn cd.example.com'],
        note: '值是域名而非 IP；同一主机通常不能与 A 记录并存。' },
    { type: 'MX', label: 'MX · 邮件路由', lines: ['@ MX 10 mail.example.com', '@ MX 20 mail2.example.com'],
        note: '数字为优先级（越小越优先），缺省 10；收信服务器域名需另有 A/AAAA 记录。' },
    { type: 'TXT', label: 'TXT · 文本记录（SPF / DKIM / 站点验证）', lines: ['@ TXT "v=spf1 mx -all"', '_dmarc TXT "v=DMARC1; p=none"'],
        note: '值含空格用引号包裹；具体内容以邮件服务商 / 验证平台的说明为准。' },
    { type: 'NS', label: 'NS · 子域委派', lines: ['api NS ns1.example.com'],
        note: '将子域委派给其他 DNS 服务器；慎用，通常只在分域托管时配置。' },
];

function openRecordDemoModal() {
    var ov = document.createElement('div');
    ov.className = 'sb-ipov';
    var rows = RECORD_DEMOS.map(function (demo) {
        var lines = demo.lines.map(function (line) {
            return '<div class="sb-demo-line"><code>' + escapeHtml(line) + '</code>' +
                '<button type="button" class="sb-mini" data-demo-copy="' + escapeHtml(line) + '">复制</button></div>';
        }).join('');
        return '<div class="sb-demo-block"><div class="sb-demo-label"><span class="sb-chip">' + escapeHtml(demo.type) + '</span> ' + escapeHtml(demo.label) + '</div>'
            + lines + '<p class="sb-demo-note">' + escapeHtml(demo.note) + '</p></div>';
    }).join('');
    ov.innerHTML = '<div class="sb-ipbox sb-demobox"><h3>解析记录示例与常见规则</h3>'
        + '<p class="sb-demo-note">批量添加格式：<b>主机 [类型] 值</b>，一行一条；简写「主机 IPv4」即 A 记录；# 开头为注释；TTL 默认 600；已存在的相同记录自动跳过；逐条写入云端并回读核实。</p>'
        + rows
        + '<div class="sb-demo-foot"><button type="button" class="sb-mini" id="sb-demo-copyall">复制全部示例</button>'
        + '<button type="button" class="sb-mini sb-mini-primary" data-close-demo>知道了</button></div></div>';
    document.body.appendChild(ov);
    var close = function () { ov.remove(); };
    ov.querySelector('[data-close-demo]').addEventListener('click', close);
    ov.querySelector('#sb-demo-copyall').addEventListener('click', function () {
        var text = RECORD_DEMOS.map(function (demo) {
            return '# ' + demo.label + '\n' + demo.lines.join('\n');
        }).join('\n\n');
        if (window.AdminPage) { window.AdminPage.copyText(text, '示例已复制'); }
    });
    ov.addEventListener('click', function (e) { if (e.target === ov) { close(); } });
    ov.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
    ov.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-demo-copy]');
        if (btn && window.AdminPage) { window.AdminPage.copyText(btn.getAttribute('data-demo-copy'), '示例已复制'); }
    });
}

/** 非 A 记录弹窗：多选 / 全选 / 反选 + 编辑与添加（写操作与其他 DNS 变更同一套护栏）。 */
function openOtherRecordsModal(item) {
    var ov = document.createElement('div');
    ov.className = 'sb-ipov';
    ov.innerHTML = '<div class="sb-ipbox sb-demobox"><h3>' + escapeHtml(item.domain) + ' · 非 A 记录</h3>'
        + '<div class="sb-other-tools">'
        + '<label class="sb-check"><input type="checkbox" id="sb-other-all"> 全选</label>'
        + '<button type="button" class="sb-mini" id="sb-other-invert">反选</button>'
        + '<button type="button" class="sb-mini sb-mini-primary" id="sb-other-edit" disabled>编辑</button>'
        + '<button type="button" class="sb-mini sb-mini-primary" id="sb-other-add" disabled>添加</button>'
        + '<span class="sb-tool-hint" id="sb-other-status">正在读取解析记录…</span>'
        + '</div>'
        + '<div class="sb-other-list" id="sb-other-list"></div>'
        + '<div class="sb-demo-foot"><button type="button" class="sb-mini sb-mini-primary" data-close-other>关闭</button></div></div>';
    document.body.appendChild(ov);
    var close = function () { ov.remove(); };
    ov.querySelector('[data-close-other]').addEventListener('click', close);
    ov.addEventListener('click', function (e) { if (e.target === ov) { close(); } });
    ov.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });

    var zone = { provider: item.provider, domain: item.domain };
    var records = []; // 非 A 记录（含 version）

    function renderList() {
        var listEl = ov.querySelector('#sb-other-list');
        var statusEl = ov.querySelector('#sb-other-status');
        statusEl.textContent = '共 ' + records.length + ' 条非 A 记录 · 选中 ' + selectedCount() + ' 条';
        listEl.innerHTML = records.length
            ? '<table class="sb-other-table"><thead><tr><th></th><th>类型</th><th>主机记录</th><th>记录值</th><th>TTL</th></tr></thead><tbody>'
                + records.map(function (r, idx) {
                    var name = (r.name === '' || r.name === '@') ? '@' : r.name;
                    return '<tr><td><input type="checkbox" class="sb-other-check" data-idx="' + idx + '"></td>'
                        + '<td><span class="sb-chip">' + escapeHtml(r.type) + '</span></td>'
                        + '<td>' + escapeHtml(name) + '</td>'
                        + '<td class="sb-other-value">' + escapeHtml(r.value || '-') + '</td>'
                        + '<td>' + escapeHtml(String(r.ttl || '-')) + '</td></tr>';
                }).join('') + '</tbody></table>'
            : '<p class="sb-hosts-empty">该域名暂无非 A 记录。点「添加」创建第一条（CNAME / MX / TXT 等）。</p>';
    }

    function selectedIdx() {
        var out = [];
        ov.querySelectorAll('.sb-other-check:checked').forEach(function (cb) { out.push(Number(cb.dataset.idx)); });
        return out;
    }

    function syncTools() {
        var n = selectedIdx().length;
        ov.querySelector('#sb-other-edit').disabled = n !== 1; // 单条编辑（一次改一条，避免多记录冲突）
        ov.querySelector('#sb-other-add').disabled = records.length > 0 && selectedIdx().length > 0;
    }

    ov.querySelector('#sb-other-all').addEventListener('change', function (e) {
        var on = e.target.checked;
        ov.querySelectorAll('.sb-other-check').forEach(function (cb) { cb.checked = on; });
        syncTools();
    });
    ov.querySelector('#sb-other-invert').addEventListener('click', function () {
        ov.querySelectorAll('.sb-other-check').forEach(function (cb) { cb.checked = !cb.checked; });
        syncTools();
    });
    ov.querySelector('#sb-other-list').addEventListener('change', function (e) {
        if (e.target.closest('.sb-other-check')) { syncTools(); }
    });

    /** 编辑 / 添加共用表单弹窗；record 为 null 时是添加。 */
    function openForm(record, revision) {
        var isEdit = !!record;
        var formOv = document.createElement('div');
        formOv.className = 'sb-ipov';
        var typeOptions = ['AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'].map(function (t) {
            return '<option value="' + t + '"' + (isEdit && record.type === t ? ' selected' : '') + '>' + t + '</option>';
        }).join('');
        formOv.innerHTML = '<div class="sb-ipbox sb-demobox"><h3>' + (isEdit ? '编辑非 A 记录' : '添加非 A 记录') + '</h3>'
            + '<p class="sb-demo-note">' + escapeHtml(zone.domain) + ' · 类型说明见「批量添加 → 解析示例」；只写一次并回读核实。</p>'
            + '<form class="ap-form dc-form">'
            + '<div class="ap-row"><label>类型<select name="type"' + (isEdit ? '' : '') + '>' + typeOptions + '</select></label>'
            + '<label>主机记录（@ 为主域名）<input name="name" required value="' + escapeHtml(isEdit ? (record.name || '@') : '@') + '"></label></div>'
            + '<label>记录值<textarea name="value" rows="2" required placeholder="按类型填写：域名 / 文本（TXT 用引号包裹可含空格）">' + escapeHtml(isEdit ? record.value : '') + '</textarea></label>'
            + '<div class="ap-row"><label>TTL（秒，留空默认 600）<input name="ttl" type="number" min="1" value="' + (isEdit && record.ttl ? record.ttl : '') + '"></label>'
            + '<label>优先级（MX / SRV）<input name="priority" type="number" min="0" value="' + (isEdit && record.priority != null ? record.priority : '') + '"></label></div>'
            + '<p class="sb-demo-note">TTL / 优先级留空使用云商默认。</p>'
            + '<p class="dc-form-err hidden"></p>'
            + '<div class="sb-demo-foot"><button type="button" class="sb-mini" data-cancel>取消</button>'
            + '<button type="submit" class="sb-mini sb-mini-primary">' + (isEdit ? '保存修改' : '添加记录') + '</button></div></div></form></div>';
        document.body.appendChild(formOv);
        var form = formOv.querySelector('form');
        var closeForm = function () { formOv.remove(); };
        formOv.querySelector('[data-cancel]').addEventListener('click', closeForm);
        formOv.addEventListener('click', function (e) { if (e.target === formOv) { closeForm(); } });
        formOv.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeForm(); } });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var errEl = formOv.querySelector('.dc-form-err');
            errEl.classList.add('hidden');
            var ttlRaw = form.elements.ttl.value.trim();
            var priRaw = form.elements.priority.value.trim();
            var body = {
                type: form.elements.type.value,
                name: form.elements.name.value.trim().toLowerCase().replace(/\.$/, '') || '@',
                value: form.elements.value.value.trim(),
                expected_version: isEdit ? record.version : revision,
            };
            if (ttlRaw !== '') { body.ttl = Number(ttlRaw); }
            if (priRaw !== '' && (body.type === 'MX' || body.type === 'SRV')) { body.priority = Number(priRaw); }
            var url = '/api/domains/' + encodeURIComponent(zone.provider) + '/' + encodeURIComponent(zone.domain) + '/records'
                + (isEdit ? '/' + encodeURIComponent(record.id) : '');
            var btn = form.querySelector('[type="submit"]');
            btn.disabled = true;
            fetchJson(url, { method: isEdit ? 'PUT' : 'POST', body: body })
                .then(function (r) {
                    closeForm();
                    toast((isEdit ? '记录已保存' : '记录已添加') + (r.verified === false ? '（结果待核实）' : '（已回读核实）'), r.verified === false ? 'info' : 'success');
                    reload(); // 强制重建快照，状态即时生效
                })
                .catch(function (err) {
                    errEl.textContent = err.message || '保存失败';
                    errEl.classList.remove('hidden');
                    btn.disabled = false;
                });
        });
    }

    ov.querySelector('#sb-other-edit').addEventListener('click', function () {
        var idx = selectedIdx();
        if (idx.length !== 1) { return; }
        openForm(records[idx[0]], null);
    });
    ov.querySelector('#sb-other-add').addEventListener('click', function () {
        openForm(null, revision);
    });

    var revision = null;
    fetchJson('/api/domains/' + encodeURIComponent(zone.provider) + '/' + encodeURIComponent(zone.domain) + '/records')
        .then(function (snap) {
            records = (snap.records || []).filter(function (r) { return (r.type || '').toUpperCase() !== 'A'; });
            revision = snap.revision || null;
            renderList();
            syncTools();
        })
        .catch(function (err) {
            ov.querySelector('#sb-other-status').textContent = '读取失败：' + (err.message || '未知错误');
        });
}

function runBatchAdd() {
    var item = modalItem();
    if (!item) { return; }
    if (!item.dns_cached || item.dns_revision == null) { toast('该域名解析尚未缓存，请先「更新数据」后再批量添加', 'error'); return; }
    hideInlineBars();
    var parsed = parseBatchLines($('sb-addinput').value);
    if (!parsed.entries.length) {
        toast(parsed.bad.length ? '没有可添加的记录：' + parsed.bad[0] : '没有可添加的记录', 'error');
        return;
    }
    // 已存在的相同记录（名称 + 类型 + 值）直接跳过
    var existing = {};
    (item.subdomains || []).forEach(function (s) {
        (s.records || []).forEach(function (r) { existing[s.fqdn + '|' + (r.type || 'A') + '|' + r.value] = true; });
    });
    var revision = item.dns_revision;
    var base = '/api/domains/' + encodeURIComponent(item.provider) + '/' + encodeURIComponent(item.domain) + '/records';
    state.busyWrite = true;
    setToolsBusy('批量添加中（逐条写入云端并回读核实，重复自动跳过）…');
    var ok = 0, skipped = 0, fail = 0, stopMsg = null;
    function step(i) {
        if (stopMsg || i >= parsed.entries.length) {
            state.busyWrite = false;
            setToolsBusy('');
            hideInlineBars();
            var summary = '批量添加完成：新增 ' + ok + ' · 跳过重复 ' + skipped + ' · 失败 ' + fail
                + (parsed.bad.length ? ' · 未解析 ' + parsed.bad.length + ' 行（首个：' + parsed.bad[0] + '）' : '')
                + (stopMsg ? '；剩余未执行：' + stopMsg : '');
            toast(summary, fail || stopMsg ? 'info' : 'success', 10000);
            reloadAfterWrite();
            return;
        }
        var e = parsed.entries[i];
        var fqdn = fqdnOfName(e.name, item.domain);
        if (existing[fqdn + '|' + e.type + '|' + e.value]) { skipped++; step(i + 1); return; }
        var body = { type: e.type, name: e.name, value: e.value, expected_revision: revision };
        if (e.priority != null) { body.priority = e.priority; }
        fetchJson(base, { method: 'POST', body: body })
            .then(function (r) {
                ok++;
                existing[fqdn + '|' + e.type + '|' + e.value] = true;
                // 成功写入会改变区域版本；用回读核实后的新 revision 继续下一行。
                if (r.verified && r.revision) { revision = r.revision; }
                else { stopMsg = '第 ' + (i + 1) + ' 行结果未确认，为避免覆盖请刷新后重试剩余行'; }
            })
            .catch(function (err) {
                if (err && err.status === 409 && /相同记录/.test(err.message || '')) { skipped++; }
                else if (err && err.status === 409) { fail++; stopMsg = '解析版本已变化（可能他处同时修改），请刷新后重试剩余行'; }
                else { fail++; }
            })
            .then(function () { step(i + 1); });
    }
    step(0);
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

function runFrontCheck() {
    var item = modalItem();
    if (!item || state.busyCheck || state.busyWrite) { return; }
    var subs = item.subdomains || [];
    // 勾选了部分主机时只检查选中项（主域名与子域名一视同仁），未勾选检查全部可检查主机。
    var chosen = selectedGroups(item);
    var pool = chosen.length ? chosen : subs;
    var targets = pool.filter(function (s) { return s.fqdn && s.fqdn.indexOf('*') === -1; });
    if (!targets.length) {
        toast(chosen.length ? '选中的主机都是通配符记录，无法检查' : '该域名没有可检查的主机（通配符记录除外）', 'info');
        return;
    }
    state.busyCheck = true;
    setToolsBusy('前端检查中（浏览器直连，最多约 ' + Math.ceil(targets.length / 4 * 6) + ' 秒）…');
    var wildcard = pool.length - targets.length;
    var okCount = 0;
    var cursor = 0;
    function worker() {
        if (cursor >= targets.length) { return Promise.resolve(); }
        var grp = targets[cursor++];
        return frontProbe(grp.fqdn).then(function (res) {
            var ok = res.ok === true;
            if (ok) { okCount++; }
            saveAccess(grp.fqdn, 'fe', { ok: ok, ms: res.ms, at: fmtNow() });
            return worker();
        });
    }
    var pool2 = [];
    for (var i = 0; i < Math.min(4, targets.length); i++) { pool2.push(worker()); }
    Promise.all(pool2).then(function () {
        state.busyCheck = false;
        setToolsBusy('');
        renderModal();
        render(); // 卡片健康点同步刷新
        toast('前端检查完成（' + (chosen.length ? '选中 ' + targets.length : '全部 ' + targets.length) + ' 项）：通过 ' + okCount
            + (wildcard ? '，跳过通配符 ' + wildcard + ' 项' : '')
            + (okCount < targets.length ? '；本页为 HTTPS 而目标不支持 HTTPS 时会误报，可用后端检查复核' : ''),
            okCount ? 'success' : 'error', 9000);
        // 结果上报服务端全局缓存（含检查时间），跨浏览器可见；失败静默（本地缓存仍有效）。
        reportFrontCheck(item, targets);
    });
}

// 前端检查结果上报：与解析快照版本对齐后持久化到服务端（record_access.json）。
function reportFrontCheck(item, targets) {
    if (!item || item.dns_revision == null) { return; }
    var results = {};
    targets.forEach(function (s) {
        var cached = accessStore()[s.fqdn] || {};
        if (cached.fe && cached.fe.ok === true) { // 只报成功：浏览器失败可能是环境限制
            results[s.fqdn] = { ok: true, ms: typeof cached.fe.ms === 'number' ? cached.fe.ms : null };
        }
    });
    if (!Object.keys(results).length) { return; }
    fetchJson('/api/domains/' + encodeURIComponent(item.provider) + '/' + encodeURIComponent(item.domain) + '/records/browser-check',
        { method: 'POST', body: { revision: item.dns_revision, results: results } })
        .then(function () { loadData(); }) // 上报成功后刷新快照，让访问状态与检查时间在页面生效
        .catch(function () { /* 上报失败不影响本地缓存使用 */ });
}

// 后端检查：服务端探测解析记录并持久化，结果随后端快照返回。
// 勾选了部分主机时只探测选中记录（record_ids 过滤），未勾选探测该域名全部记录。
function runBackCheck() {
    var item = modalItem();
    if (!item || state.busyCheck || state.busyWrite) { return; }
    var chosen = selectedGroups(item);
    var recordIds = [];
    if (chosen.length) {
        chosen.forEach(function (s) { (s.records || []).forEach(function (r) { recordIds.push(r.id); }); });
    }
    state.busyCheck = true;
    setToolsBusy('后端检查中：先刷新云端解析，再由服务器探测' + (recordIds.length ? '选中的 ' + recordIds.length + ' 条记录' : '各主机') + '…');
    toast('后端检查「' + item.domain + '」：服务端探测中，约需数十秒', 'info', 8000);
    fetchJson('/api/domains/' + encodeURIComponent(item.provider) + '/' + encodeURIComponent(item.domain) + '/records/access-check',
        { method: 'POST', body: recordIds.length ? { record_ids: recordIds } : {} })
        .then(function (summary) {
            return loadData().then(function () {
                state.busyCheck = false;
                setToolsBusy('');
                if (state.modalKey && modalItemFor(state.modalKey)) { openModal(state.modalKey, state.modalSource); }
                toast('后端检查完成：通过 ' + (summary.ok || 0) + ' · 异常 ' + (summary.fail || 0)
                    + (summary.na ? ' · 不适用 ' + summary.na : ''), (summary.ok || 0) ? 'success' : 'error', 9000);
            });
        })
        .catch(function (err) {
            state.busyCheck = false;
            setToolsBusy('');
            toast((err && err.message) || '后端检查失败，请稍后重试', 'error', 8000);
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
        if (statModal) { openModal(statModal.getAttribute('data-key') || ''); return; }
        var otherBtn = event.target.closest('[data-other-modal]');
        if (otherBtn) {
            var zoneKey = otherBtn.getAttribute('data-other-modal');
            var all = (state.data && state.data.domains) || [];
            for (var i = 0; i < all.length; i++) {
                if (all[i].provider + '|' + all[i].domain === zoneKey) { openOtherRecordsModal(all[i]); return; }
            }
            return;
        }
        var ipGo = event.target.closest('[data-ip-goto]');
        if (ipGo) {
            var tagsBtn = ipGo.closest('.sb-tags');
            openModal(tagsBtn ? tagsBtn.getAttribute('data-key') || '' : '', null, ipGo.getAttribute('data-ip-goto'));
            return;
        }
        var tags = event.target.closest('.sb-tags');
        if (tags) { openModal(tags.getAttribute('data-key') || ''); }
    });

    $('sb-modal').addEventListener('click', function (event) {
        if (event.target.hasAttribute('data-close')) { closeModal(); }
    });
    $('sb-modal-close').addEventListener('click', closeModal);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !$('sb-modal').classList.contains('hidden')) { closeModal(); }
    });

    // 单条记录编辑：点击「修改」打开编辑弹窗（可改主机 / 类型 / 值 / TTL / 线路 / 优先级）
    $('sb-modal-body').addEventListener('click', function (event) {
        var editBtn = event.target.closest('[data-rec-edit]');
        if (!editBtn) { return; }
        var item = modalItem();
        if (!item) { return; }
        var recId = editBtn.getAttribute('data-rec-edit');
        openRecordEditModal(item, recId);
    });

    // 弹窗内勾选（事件委托，行内容会随数据刷新重建）
    $('sb-modal-body').addEventListener('change', function (event) {
        var cb = event.target.closest('.sb-check');
        if (!cb) { return; }
        var fqdn = cb.getAttribute('data-fqdn');
        if (cb.checked) { state.selected[fqdn] = true; }
        else { delete state.selected[fqdn]; }
        syncPickAll();
        syncButtons();
    });
    $('sb-pickall').addEventListener('change', function (event) {
        var on = event.target.checked;
        state.selected = {};
        document.querySelectorAll('#sb-modal-body .sb-check').forEach(function (cb) {
            cb.checked = on;
            if (on) { state.selected[cb.getAttribute('data-fqdn')] = true; }
        });
        syncButtons();
    });
    // 反选：可见行的勾选状态互换
    $('sb-invert').addEventListener('click', function () {
        document.querySelectorAll('#sb-modal-body .sb-check').forEach(function (cb) {
            var fqdn = cb.getAttribute('data-fqdn');
            cb.checked = !cb.checked;
            if (cb.checked) { state.selected[fqdn] = true; } else { delete state.selected[fqdn]; }
        });
        syncPickAll();
        syncButtons();
    });
    // 弹窗内关键词搜索：输入即过滤（渲染层过滤，不重新拉数据）
    $('sb-modal-q').addEventListener('input', function (event) {
        state.modalQ = event.target.value.trim();
        renderModal();
    });
    // 访问状态 tab：全部 / 通过 / 异常 / 未检查
    $('sb-status-tabs').addEventListener('click', function (event) {
        var t = event.target.closest('.sb-status-tab');
        if (!t) { return; }
        state.modalStatus = t.getAttribute('data-mstatus') || '';
        document.querySelectorAll('#sb-status-tabs .sb-status-tab').forEach(function (b) { b.classList.toggle('is-active', b === t); });
        renderModal();
    });

    $('sb-editip').addEventListener('click', function () {
        if (!selectedCount()) { toast('请先勾选要修改的子域名', 'info'); return; }
        hideInlineBars();
        $('sb-ipedit').classList.remove('hidden');
        $('sb-ipinput').focus();
    });
    $('sb-ipcancel').addEventListener('click', function () { $('sb-ipedit').classList.add('hidden'); });
    $('sb-ipok').addEventListener('click', applyBatchIp);
    $('sb-ipinput').addEventListener('keydown', function (event) {
        if (event.key === 'Enter') { applyBatchIp(); }
    });

    $('sb-del').addEventListener('click', function () {
        if (!selectedCount()) { toast('请先勾选要删除的子域名', 'info'); return; }
        hideInlineBars();
        $('sb-delconf').classList.remove('hidden');
    });
    $('sb-delno').addEventListener('click', function () { $('sb-delconf').classList.add('hidden'); });
    $('sb-delyes').addEventListener('click', function () { batchWrite(selectedRecords(), 'delete'); });

    $('sb-batchadd').addEventListener('click', function () {
        var item = modalItem();
        if (!item || !item.dns_cached || item.dns_revision == null) { toast('该域名解析尚未缓存，请先「更新数据」', 'error'); return; }
        hideInlineBars();
        $('sb-addbar').classList.remove('hidden');
        if (!$('sb-addinput').value.trim()) { $('sb-addinput').value = 'www 192.0.2.10'; } // 预填 A 记录参考 demo
        $('sb-addinput').focus();
    });
    $('sb-addcancel').addEventListener('click', function () { $('sb-addbar').classList.add('hidden'); });
    $('sb-record-demo').addEventListener('click', openRecordDemoModal);
    $('sb-addok').addEventListener('click', runBatchAdd);

    $('sb-fecheck').addEventListener('click', runFrontCheck);
    $('sb-becheck').addEventListener('click', runBackCheck);

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
