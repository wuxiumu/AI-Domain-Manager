/**
 * 大屏 IP 视图（screen-ip.js）：公网 IP 卡片（DNS 自动发现 + 手工登记）。
 * 依赖 screen.js 暴露的 window.ScreenBoard（escapeHtml / pageSlice / fetchJson / toast / reload）；
 * 渲染由 screen.js 在 IP 模式下委托给 ScreenIp.render(ctx)。
 */
(function () {
'use strict';

var SB = window.ScreenBoard;
if (!SB || typeof document === 'undefined') { return; }
var esc = SB.escapeHtml;

function $(id) { return document.getElementById(id); }

// ---- 纯函数：筛选 / 排序 ----

function applyFilters(data, tab, q, status) {
    var list = (data && data.ips) || [];
    var kw = (q || '').trim().toLowerCase();
    if (!tab && !kw && !status) { return list.slice(); }
    return list.filter(function (r) {
        if (tab && (r.sources || []).indexOf(tab) === -1) { return false; }
        if (status) {
            // ok / fail / unchecked（null 或无 access 结果均视为未检查）
            var st = r.status === 'ok' || r.status === 'fail' ? r.status : 'unchecked';
            if (st !== status) { return false; }
        }
        if (!kw) { return true; }
        if (String(r.ip || '').indexOf(kw) !== -1) { return true; }
        if (String(r.name || '').toLowerCase().indexOf(kw) !== -1) { return true; }
        if (String(r.note || '').toLowerCase().indexOf(kw) !== -1) { return true; }
        var loc = r.location || {};
        if (String(loc.country || '').toLowerCase().indexOf(kw) !== -1) { return true; }
        if (String(loc.city || '').toLowerCase().indexOf(kw) !== -1) { return true; }
        if (String(loc.isp || '').toLowerCase().indexOf(kw) !== -1) { return true; }
        var ds = r.domains || [];
        for (var i = 0; i < ds.length; i++) {
            if (String(ds[i].fqdn || '').toLowerCase().indexOf(kw) !== -1) { return true; }
            if (String(ds[i].provider_name || '').toLowerCase().indexOf(kw) !== -1) { return true; }
        }
        return false;
    });
}

function cmpIp(a, b) { return String(a.ip || '').localeCompare(String(b.ip || '')); }

var SORTS = {
    domains: function (a, b) { return (b.domain_count || 0) - (a.domain_count || 0) || cmpIp(a, b); },
    records: function (a, b) { return (b.records || 0) - (a.records || 0) || cmpIp(a, b); },
    ip: cmpIp,
    country: function (a, b) {
        return String((a.location || {}).country || '').localeCompare(String((b.location || {}).country || ''))
            || (b.domain_count || 0) - (a.domain_count || 0) || cmpIp(a, b);
    },
};

function sortRows(list, sort) {
    var fn = SORTS[sort] || SORTS.domains;
    return list.slice().sort(fn);
}

/** 国家代码 → 旗帜 emoji（无效果时返回空串）。 */
function flagOf(cc) {
    cc = String(cc || '');
    if (!/^[A-Za-z]{2}$/.test(cc)) { return ''; }
    return String.fromCodePoint(
        cc.toUpperCase().charCodeAt(0) - 65 + 0x1f1e6,
        cc.toUpperCase().charCodeAt(1) - 65 + 0x1f1e6);
}

function shortTime(value) {
    var text = String(value || '');
    return text.length >= 16 ? text.slice(5, 16) : text; // MM-DD HH:mm
}

function siteLink(host) {
    return 'http://' + String(host || '').replace(/["<>\\]/g, '') + '/';
}

function fmtNowLocal() {
    var d = new Date();
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
}

// ---- 渲染 ----

function tabHtml(id, label, count, active) {
    return '<button type="button" class="sb-tab' + (active ? ' active' : '') + '" data-ip-tab="' + esc(id) + '">'
        + esc(label) + '<span class="sb-tab-count">' + Number(count || 0) + '</span></button>';
}

function renderTabs(ctx) {
    var dataOk = !!(ctx.data && Array.isArray(ctx.data.ips));
    var accounts = (ctx.data && ctx.data.accounts) || [];
    var searched = dataOk ? applyFilters(ctx.data, '', ctx.q, ctx.status) : [];
    var counts = {};
    for (var i = 0; i < searched.length; i++) {
        var sources = searched[i].sources || [];
        for (var j = 0; j < sources.length; j++) {
            counts[sources[j]] = (counts[sources[j]] || 0) + 1;
        }
    }
    var html = tabHtml('', '全部', searched.length, ctx.tab === '');
    for (var k = 0; k < accounts.length; k++) {
        var a = accounts[k];
        html += tabHtml(a.id, a.name || a.id, counts[a.id] || 0, ctx.tab === a.id);
    }
    $('sb-tabs').innerHTML = html;
}

function statusInfo(r) {
    var cls = r.status === 'ok' ? 'sb-dot-ok' : r.status === 'fail' ? 'sb-dot-fail' : 'sb-dot-none';
    var title = r.status === 'ok' ? '有访问检查通过' : r.status === 'fail' ? '存在访问失败的记录' : '尚未检查（未检查不等于正常）';
    return { cls: cls, title: title, label: r.status === 'ok' ? '正常' : r.status === 'fail' ? '异常' : '检查',
        colorCls: r.status === 'ok' ? 'c-green' : r.status === 'fail' ? 'c-red' : 'c-gray' };
}

function ipCardHtml(r, names) {
    var st = statusInfo(r);
    var loc = r.location;
    var locText = loc && (loc.country || loc.city)
        ? flagOf(loc.country_code) + ' ' + esc([loc.country, loc.city].filter(Boolean).join(' · '))
            + (loc.isp ? '<span class="sb-ip-isp">' + esc(loc.isp) + '</span>' : '')
        : '<span class="sb-ip-noloc">归属地未刷新</span>';

    var chips = [];
    (r.sources || []).forEach(function (id) {
        if (id === 'manual') { chips.push('<span class="sb-chip sb-chip-manual">手工登记</span>'); }
        else { chips.push('<span class="sb-chip">' + esc(names[id] || id) + '</span>'); }
    });
    if (r.port) { chips.push('<span class="sb-chip">端口 ' + Number(r.port) + '</span>'); }
    if (r.checked_at) { chips.push('<span class="sb-chip sb-dim">检查 ' + esc(shortTime(r.checked_at)) + '</span>'); }
    if (r.direct) {
        var directTip = 'IP 直连检查（' + (r.direct.source === 'probe' ? '服务端' : '浏览器') + '）' + (r.direct.checked_at || '');
        chips.push('<span class="sb-chip ' + (r.direct.ok ? 'sb-chip-manual' : 'sb-warn') + '" title="' + esc(directTip) + '">'
            + (r.direct.ok ? 'IP 直连 ✓' : 'IP 直连 ✗') + (r.direct.ms ? ' ' + Number(r.direct.ms) + 'ms' : '') + '</span>');
    }
    if (loc && loc.checked_at) { chips.push('<span class="sb-chip sb-dim">归属地 ' + esc(shortTime(loc.checked_at)) + '</span>'); }

    var ds = r.domains || [];
    var shown = ds.map(function (d) {
        var msTip = d.access_ms != null ? ' · ' + Number(d.access_ms) + 'ms' : '';
        var mark = d.access === 'ok' ? '<i class="sb-ipmark ok" title="HTTP ' + esc(d.http_code) + msTip + '"></i>'
            : d.access === 'fail' ? '<i class="sb-ipmark fail" title="访问失败"></i>' : '';
        // HTTPS 徽标：ok=证书有效可达（已上线运营）；仅 HTTP=可达但未启用 HTTPS；旧结果无 https 字段不标
        var scheme = d.https === 'ok' ? '<b class="sb-scheme ok" title="HTTPS 检查通过（证书有效）——已上线运营">HTTPS</b>'
            : (d.https === 'fail' && d.access === 'ok' ? '<b class="sb-scheme no" title="站点可达但未启用 HTTPS——尚未运营">仅HTTP</b>' : '');
        var paused = d.paused ? '<span class="sb-paused-mini" title="记录已暂停解析">⏸</span>' : '';
        return '<a class="sb-tag" href="' + esc(siteLink(d.fqdn)) + '" target="_blank" rel="noopener"'
            + ' title="新页面打开 ' + esc(d.fqdn) + (d.access_ms != null ? '（' + Number(d.access_ms) + 'ms）' : '') + '">'
            + '<span class="sb-tag-name' + (d.paused ? ' is-paused' : '') + '">' + mark + esc(d.fqdn) + paused + scheme + '</span></a>';
    }).join('');

    // 域名区：wrap 撑满卡片富余空间（少则正常展示、多则滚动），「更多」按钮固定区右下
    var domains = ds.length
        ? '<div class="sb-ip-domains-wrap">'
            + '<div class="sb-ip-domains">' + shown + '</div>'
            + '<button type="button" class="sb-dom-more" data-ip-domains="' + esc(r.ip) + '" title="弹窗查看全部关联域名（可复制）">更多 ›</button>'
            + '</div>'
        : '<div class="sb-hosts-empty">暂无域名 A 记录指向此 IP' + (r.manual ? '' : '（解析未缓存或已下线）') + '</div>';

    // 每个 IP 都可编辑：已登记走编辑（按 id），自动发现未登记走「登记手工 IP」（按 ip 预填）；仅已登记可删除
    var editAttr = (r.manual && r.id) ? String(r.id) : String(r.ip);
    var actions = '<div class="sb-ip-actions">'
        + '<button type="button" class="sb-mini" data-ip-loc="' + esc(r.ip) + '" title="刷新归属地（请求 ipwho.is）">归属地</button>'
        + '<button type="button" class="sb-mini" data-ip-fecheck="' + esc(r.ip) + '" title="浏览器直连探测 http://' + esc(r.ip) + '/">前端检查</button>'
        + '<button type="button" class="sb-mini" data-ip-becheck="' + esc(r.ip) + '" title="服务端探测 http://' + esc(r.ip) + '/（不跟随跳转）">后端检查</button>'
        + '<button type="button" class="sb-mini sb-mini-primary" data-ip-edit="' + esc(editAttr) + '"'
        + ' title="' + (r.manual ? '编辑名称 / 备注 / 端口' : '登记为手工 IP（名称 / 备注 / 端口），卡片将标注「手工登记」') + '">编辑</button>'
        + (r.manual
            ? '<button type="button" class="sb-mini sb-mini-danger" data-ip-del="' + esc(String(r.id || '')) + '">删除</button>'
            : '')
        + '</div>';

    return '<article class="sb-card sb-ipcard" data-ip="' + esc(r.ip) + '">'
        + '<div class="sb-card-head"><span class="sb-dot ' + st.cls + '" title="' + esc(st.title) + '"></span>'
        + '<span class="sb-ip-addr">' + esc(r.ip) + '</span>'
        + '<span class="sb-ip-ops">'
        + '<button type="button" class="sb-ip-act" data-ip-copy="' + esc(r.ip) + '" title="复制 IP">复制</button>'
        + '<a class="sb-ip-act" href="' + esc(siteLink(r.ip)) + '" target="_blank" rel="noopener" title="新页面打开 http://' + esc(r.ip) + '/">↗</a>'
        + '</span>'
        + (r.name ? '<span class="sb-ip-name">' + esc(r.name) + '</span>' : '')
        + '</div>'
        + '<div class="sb-ip-loc">' + locText + '</div>'
        + '<div class="sb-stat-row">'
        + '<div class="sb-stat sb-stat-link"><b class="sb-stat-num c-cyan">' + Number(r.domain_count || 0) + '</b><button type="button" class="sb-stat-btn" data-ip-domains="' + esc(r.ip) + '" title="点击查看此 IP 关联的全部域名">关联域名</button></div>'
        + '<div class="sb-stat"><b class="sb-stat-num c-violet">' + Number(r.records || 0) + '</b><span>A 记录</span></div>'
        + '<div class="sb-stat"><b class="sb-stat-num ' + st.colorCls + '">' + st.label + '</b><span>访问状态' + (r.access_ms != null ? ' · ' + Number(r.access_ms) + 'ms' : '') + '</span></div>'
        + '</div>'
        + '<div class="sb-meta">' + chips.join('') + '</div>'
        + domains
        + (r.note ? '<div class="sb-ip-note">' + esc(r.note) + '</div>' : '')
        + actions
        + '</article>';
}

// 最近一次渲染的行（供编辑 / 删除按 id 定位，弹窗按 ip 取完整域名清单）。
var lastRows = [];
var lastAllRows = [];
function findCurrent(id) {
    for (var i = 0; i < lastRows.length; i++) {
        if (lastRows[i].id === id) { return lastRows[i]; }
    }
    return null;
}

/** 「共 N 个域名」弹窗：该 IP 下的全部关联域名，支持多选批量操作（跨域名 / 跨云账号）。 */
var domSel = {}, domModal = null, domBusy = false;

function domSelectedCount() { return Object.keys(domSel).length; }

function domGroups() {
    // 选中项按 zone（provider|domain）分组：批量写以 zone 为单位拉取最新记录再逐条执行。
    var groups = {};
    ((domModal && domModal.row.domains) || []).forEach(function (d) {
        if (!domSel[d.fqdn]) { return; }
        var key = d.provider + '|' + d.domain;
        if (!groups[key]) { groups[key] = { provider: d.provider, domain: d.domain, items: [] }; }
        groups[key].items.push(d);
    });
    return Object.keys(groups).map(function (k) { return groups[k]; });
}

function syncDomButtons() {
    if (!domModal) { return; }
    var n = domSelectedCount();
    ['sb-ipdom-editip', 'sb-ipdom-del', 'sb-ipdom-pause', 'sb-ipdom-resume', 'sb-ipdom-fecheck', 'sb-ipdom-becheck'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.disabled = domBusy || !n; }
    });
    var pause = document.getElementById('sb-ipdom-pause');
    if (pause && n) { pause.textContent = '暂停解析（' + n + '）'; }
    var delCount = document.getElementById('sb-ipdom-delcount');
    if (delCount) { delCount.textContent = n; }
}

function setDomBusy(text) {
    domBusy = !!text;
    if (text) {
        var hint = document.getElementById('sb-ipdom-hint');
        if (hint) { hint.textContent = text; }
    }
    syncDomButtons();
}

/** 相对主机记录 → FQDN（与 ScreenService::fqdnOf 同规则）。 */
function fqdnOf(name, zone) {
    name = String(name || '').trim().toLowerCase();
    if (name === '' || name === '@' || name === zone) { return zone; }
    return name.slice(-(zone.length + 1)) === '.' + zone ? name : name + '.' + zone;
}

/**
 * 跨 zone 批量执行：先逐 zone 读取最新记录（拿 version 与定位记录），再逐条写。
 * 策略与域名管理弹窗一致：首个失败或待核实（202 / verified:false）后停止后续提交。
 */
function runDomBatch(action, newIp) {
    var groups = domGroups();
    if (!groups.length) { return Promise.resolve(); }
    var zoneCount = groups.length;
    var itemTotal = domSelectedCount();
    setDomBusy('准备中：读取 ' + zoneCount + ' 个域名的最新解析记录…');
    var jobs = [];
    var chain = Promise.resolve();
    groups.forEach(function (g) {
        chain = chain.then(function () {
            return SB.fetchJson('/api/domains/' + encodeURIComponent(g.provider) + '/' + encodeURIComponent(g.domain) + '/records')
                .then(function (snap) {
                    var want = {};
                    g.items.forEach(function (d) { want[d.fqdn] = true; });
                    (snap.records || []).forEach(function (rec) {
                        if (String(rec.type || '').toUpperCase() !== 'A') { return; }
                        if (!want[fqdnOf(rec.name, g.domain)]) { return; }
                        var base = '/api/domains/' + encodeURIComponent(g.provider) + '/' + encodeURIComponent(g.domain) + '/records/' + encodeURIComponent(rec.id);
                        if (action === 'update') {
                            jobs.push({ url: base, method: 'PUT', body: { value: newIp, expected_version: rec.version }, label: fqdnOf(rec.name, g.domain) + ' → ' + newIp });
                        } else if (action === 'delete') {
                            jobs.push({ url: base, method: 'DELETE', body: { expected_version: rec.version }, label: '删除 ' + fqdnOf(rec.name, g.domain) });
                        } else if (action === 'pause' || action === 'resume') {
                            jobs.push({ url: base + '/status', method: 'POST', body: { paused: action === 'pause', expected_version: rec.version }, label: (action === 'pause' ? '暂停 ' : '恢复 ') + fqdnOf(rec.name, g.domain) });
                        }
                    });
                })
                .catch(function (err) {
                    jobs.push({ failed: true, label: '读取 ' + g.domain + ' 失败：' + (err.message || '未知错误') });
                });
        });
    });
    return chain.then(function () {
        var ok = 0, fail = 0, pending = 0, firstErr = null, stopped = false;
        var step = function (i) {
            if (stopped || i >= jobs.length) {
                var label = { update: 'IP 修改', delete: '删除', pause: '暂停解析', resume: '恢复解析' }[action] || '批量操作';
                var type = fail ? (ok ? 'info' : 'error') : 'success';
                var msg = label + '完成：成功 ' + ok + ' · 失败 ' + fail + (pending ? ' · 待核实 ' + pending : '')
                    + ' / 共 ' + itemTotal + ' 个域名' + (firstErr ? '；首个错误：' + firstErr : '');
                if (pending && !fail) { msg += '；存在待核实结果，已停止后续提交，请刷新核实后继续'; }
                SB.toast(msg, type, 9000);
                setDomBusy('');
                domSel = {};
                syncDomList();
                syncDomButtons();
                SB.reload();
                return;
            }
            var job = jobs[i];
            if (job.failed) { fail++; firstErr = firstErr || job.label; stopped = true; return step(i + 1); }
            return SB.fetchJson(job.url, { method: job.method, body: job.body })
                .then(function (r) {
                    if (r && r.verified === false) { pending++; stopped = true; return; } // 待核实：不自动重试，停止后续
                    ok++;
                })
                .catch(function (err) { fail++; firstErr = firstErr || (job.label + '：' + (err.message || '未知错误')); stopped = true; })
                .then(function () { return step(i + 1); });
        };
        return step(0);
    });
}

/** 选中项前端检查：浏览器直连探测（结果缓存本浏览器），4 路并发。 */
function runDomFrontCheck() {
    var targets = Object.keys(domSel);
    if (!targets.length) { return; }
    setDomBusy('前端检查中（浏览器直连，最多约 ' + Math.ceil(targets.length / 4 * 6) + ' 秒）…');
    var okCount = 0, cursor = 0;
    var worker = function () {
        if (cursor >= targets.length) { return Promise.resolve(); }
        var fqdn = targets[cursor++];
        return SB.frontProbe(fqdn).then(function (res) {
            var ok = res.ok === true;
            if (ok) { okCount++; }
            SB.saveAccess(fqdn, 'fe', { ok: ok, ms: res.ms, at: fmtNowLocal() });
            return worker();
        });
    };
    var pool = [];
    for (var i = 0; i < Math.min(4, targets.length); i++) { pool.push(worker()); }
    Promise.all(pool).then(function () {
        SB.toast('前端检查完成：通过 ' + okCount + ' / ' + targets.length
            + (okCount < targets.length ? '；本页为 HTTPS 而目标不支持 HTTPS 时会误报，可用后端检查复核' : ''), okCount ? 'success' : 'error', 9000);
        reportDomFrontCheck(targets);
        setDomBusy('');
        SB.reload();
    });
}

/** 前端检查结果按 zone 上报服务端全局缓存（跨浏览器可见）。 */
function reportDomFrontCheck(targets) {
    // 选中 fqdn → 所属 zone 分组（来自当前弹窗行的域名清单）
    var groups = {};
    ((domModal && domModal.row.domains) || []).forEach(function (d) {
        if (targets.indexOf(d.fqdn) === -1) { return; }
        var key = d.provider + '|' + d.domain;
        if (!groups[key]) { groups[key] = { provider: d.provider, domain: d.domain, revision: null, results: {} }; }
        var cached = (window.localStorage && (function () {
            try { return JSON.parse(localStorage.getItem('asset-workspace-screen:access:v1') || '{}')[d.fqdn] || {}; } catch (e) { return {}; }
        })());
        if (cached.fe && cached.fe.ok === true) { // 只报成功：浏览器失败可能是环境限制
            groups[key].results[d.fqdn] = { ok: true, ms: typeof cached.fe.ms === 'number' ? cached.fe.ms : null };
        }
    });
    Object.keys(groups).forEach(function (key) {
        var g = groups[key];
        if (!Object.keys(g.results).length) { return; }
        // revision 从域名快照读取（弹窗数据来自快照行；缺失时服务端按缓存 revision 校验）
        SB.fetchJson('/api/domains/' + encodeURIComponent(g.provider) + '/' + encodeURIComponent(g.domain) + '/records')
            .then(function (snap) {
                return SB.fetchJson('/api/domains/' + encodeURIComponent(g.provider) + '/' + encodeURIComponent(g.domain) + '/records/browser-check',
                    { method: 'POST', body: { revision: snap.revision, results: g.results } });
            })
            .catch(function () { /* 上报失败不影响本地缓存使用 */ });
    });
}

/** 选中项后端检查：按 zone 调用服务端探测（API 为区域级，全量刷新该域名）。 */
function runDomBackCheck() {
    var groups = domGroups();
    if (!groups.length) { return; }
    setDomBusy('后端检查中：服务端探测 ' + groups.length + ' 个域名的全部解析记录…');
    var okSum = 0, failSum = 0, chain = Promise.resolve();
    groups.forEach(function (g) {
        chain = chain.then(function () {
            return SB.fetchJson('/api/domains/' + encodeURIComponent(g.provider) + '/' + encodeURIComponent(g.domain) + '/records/access-check', { method: 'POST', body: {} })
                .then(function (summary) { okSum += summary.ok || 0; failSum += summary.fail || 0; })
                .catch(function (err) { failSum++; SB.toast('检查 ' + g.domain + ' 失败：' + (err.message || '未知错误'), 'error', 6000); });
        });
    });
    chain.then(function () {
        SB.toast('后端检查完成：通过 ' + okSum + ' · 异常 ' + failSum, okSum ? 'success' : 'error', 9000);
        setDomBusy('');
        SB.reload();
    });
}

function syncDomList() {
    if (!domModal) { return; }
    domModal.ui.ov.querySelectorAll('.sb-ipdom-check').forEach(function (cb) {
        cb.checked = !!domSel[cb.getAttribute('data-fqdn')];
    });
    var pickall = document.getElementById('sb-ipdom-all');
    if (pickall) { pickall.checked = domSelectedCount() > 0 && domSelectedCount() === domModal.row.domains.length; }
}

function openDomainsModal(ip) {
    var row = null;
    for (var i = 0; i < lastAllRows.length; i++) {
        if (lastAllRows[i].ip === ip) { row = lastAllRows[i]; break; }
    }
    if (!row) { SB.toast('数据已刷新，请重试', 'error'); return; }
    var ds = row.domains || [];
    domSel = {}; domBusy = false;
    var ui = overlay('<h3>' + esc(ip) + ' · 关联域名（' + ds.length + ' 个）</h3>'
        + '<div class="sb-ipdom-tools">'
        + '<label class="sb-ipdom-pickall"><input type="checkbox" id="sb-ipdom-all"> 全选</label>'
        + '<button type="button" class="sb-mini sb-mini-primary" id="sb-ipdom-editip" disabled>修改 IP</button>'
        + '<button type="button" class="sb-mini sb-mini-danger" id="sb-ipdom-del" disabled>删除</button>'
        + '<button type="button" class="sb-mini" id="sb-ipdom-pause" disabled>暂停解析</button>'
        + '<button type="button" class="sb-mini" id="sb-ipdom-resume" disabled>恢复解析</button>'
        + '<span class="sb-tool-sep"></span>'
        + '<button type="button" class="sb-mini" id="sb-ipdom-fecheck" disabled>前端检查</button>'
        + '<button type="button" class="sb-mini" id="sb-ipdom-becheck" disabled>后端检查</button>'
        + '<span class="sb-tool-hint" id="sb-ipdom-hint">跨域名 / 跨云账号逐条写入并核实 · 暂停保留记录仅停止解析 · GoDaddy 不支持暂停</span>'
        + '</div>'
        + '<div class="sb-inline-bar hidden" id="sb-ipdom-ipedit">'
        + '<label>新 IP <input type="text" id="sb-ipdom-ipinput" placeholder="IPv4，如 192.0.2.10" autocomplete="off" spellcheck="false"></label>'
        + '<button type="button" class="sb-mini sb-mini-primary" id="sb-ipdom-ipok">应用到选中</button>'
        + '<button type="button" class="sb-mini" id="sb-ipdom-ipcancel">取消</button></div>'
        + '<div class="sb-inline-bar sb-inline-danger hidden" id="sb-ipdom-delconf">'
        + '<span>确认删除选中的 <b id="sb-ipdom-delcount">0</b> 组解析记录？跨域名逐条写入并回读核实。</span>'
        + '<button type="button" class="sb-mini sb-mini-danger" id="sb-ipdom-delyes">确认删除</button>'
        + '<button type="button" class="sb-mini" id="sb-ipdom-delno">取消</button></div>'
        + '<p class="sb-ipdom-hint">此 IP 当前被以下域名解析指向；点击域名行进入它的解析管理（批量改 IP / 删除 / 访问检查），↗ 新页面打开网站。</p>'
        + '<div class="sb-demo-foot" style="margin:0 0 10px;justify-content:flex-start"><button type="button" class="sb-mini" id="sb-ipdom-copy" title="复制全部关联域名，一行一个">复制全部域名</button></div>'
        + '<div class="sb-ipdom-list" id="sb-ipdom-list">'
        + (ds.length ? ds.map(function (d) {
            var mark = d.access === 'ok' ? '<i class="sb-ipmark ok" title="HTTP ' + esc(d.http_code) + '"></i>'
                : d.access === 'fail' ? '<i class="sb-ipmark fail" title="访问失败"></i>' : '';
            return '<div class="sb-ipdom-row">'
                + '<input type="checkbox" class="sb-ipdom-check" data-fqdn="' + esc(d.fqdn) + '">'
                + mark
                + '<button type="button" class="sb-ipdom-name" data-domain-key="' + esc(d.provider + '|' + d.domain) + '"'
                + ' data-src-fqdn="' + esc(d.fqdn) + '" title="进入「' + esc(d.domain) + '」的解析管理弹窗"'
                + (d.paused ? ' style="color:var(--muted);text-decoration:line-through"' : '') + '>' + esc(d.fqdn)
                + (d.paused ? ' <span class="sb-pausedmark" title="记录已暂停解析">已暂停</span>' : '') + '</button>'
                + '<span class="sb-chip">' + esc(d.provider_name) + '</span>'
                + '<a class="sb-tag-open" href="' + esc(siteLink(d.fqdn)) + '" target="_blank" rel="noopener" title="新页面打开网站">↗</a>'
                + '</div>';
        }).join('') : '<p class="sb-hosts-empty">暂无关联域名</p>')
        + '</div>');
    domModal = { ui: ui, ip: ip, row: row };
    var q = function (sel) { return ui.ov.querySelector(sel); };

    q('#sb-ipdom-copy').addEventListener('click', function () {
        var text = ds.map(function (d) { return d.fqdn; }).join('\n');
        SB.copyText(text, '已复制 ' + ds.length + ' 个域名（一行一个）');
    });

    q('#sb-ipdom-list').addEventListener('click', function (event) {
        if (event.target.closest('a')) { return; } // ↗ 交给浏览器
        if (event.target.closest('.sb-ipdom-check')) { return; } // 勾选由 change 处理
        var btn = event.target.closest('[data-domain-key]');
        if (!btn) { return; }
        ui.close();
        domModal = null;
        SB.openDomainModal(btn.getAttribute('data-domain-key'), {
            ip: ip,
            fqdn: btn.getAttribute('data-src-fqdn') || '',
        });
    });
    ui.ov.querySelector('.sb-ipdom-list').addEventListener('change', function (event) {
        var cb = event.target.closest('.sb-ipdom-check');
        if (!cb) { return; }
        var fqdn = cb.getAttribute('data-fqdn');
        if (cb.checked) { domSel[fqdn] = true; } else { delete domSel[fqdn]; }
        syncDomButtons();
    });
    q('#sb-ipdom-all').addEventListener('change', function (event) {
        var on = event.target.checked;
        domSel = {};
        if (on) { ds.forEach(function (d) { domSel[d.fqdn] = true; }); }
        syncDomList();
        syncDomButtons();
    });

    q('#sb-ipdom-editip').addEventListener('click', function () {
        if (!domSelectedCount()) { return; }
        q('#sb-ipdom-delconf').classList.add('hidden');
        q('#sb-ipdom-ipedit').classList.remove('hidden');
        q('#sb-ipdom-ipinput').focus();
    });
    q('#sb-ipdom-ipcancel').addEventListener('click', function () { q('#sb-ipdom-ipedit').classList.add('hidden'); });
    q('#sb-ipdom-ipok').addEventListener('click', function () {
        var value = q('#sb-ipdom-ipinput').value.trim();
        if (!/^\d{1,3}(\.\d{1,3}){3}$/.test(value)) { SB.toast('请输入有效的 IPv4 地址', 'error'); return; }
        q('#sb-ipdom-ipedit').classList.add('hidden');
        runDomBatch('update', value);
    });

    q('#sb-ipdom-del').addEventListener('click', function () {
        if (!domSelectedCount()) { return; }
        q('#sb-ipdom-ipedit').classList.add('hidden');
        q('#sb-ipdom-delconf').classList.remove('hidden');
    });
    q('#sb-ipdom-delno').addEventListener('click', function () { q('#sb-ipdom-delconf').classList.add('hidden'); });
    q('#sb-ipdom-delyes').addEventListener('click', function () {
        q('#sb-ipdom-delconf').classList.add('hidden');
        runDomBatch('delete');
    });

    q('#sb-ipdom-pause').addEventListener('click', function () { runDomBatch('pause'); });
    q('#sb-ipdom-resume').addEventListener('click', function () { runDomBatch('resume'); });
    q('#sb-ipdom-fecheck').addEventListener('click', runDomFrontCheck);
    q('#sb-ipdom-becheck').addEventListener('click', runDomBackCheck);
}

function render(ctx) {
    renderTabs(ctx);
    var names = {};
    ((ctx.data && ctx.data.accounts) || []).forEach(function (a) { names[a.id] = a.name || a.id; });

    var dataOk = !!(ctx.data && Array.isArray(ctx.data.ips));
    var filtered = dataOk ? applyFilters(ctx.data, ctx.tab, ctx.q, ctx.status) : [];
    var sorted = sortRows(filtered, ctx.sort);
    var view = SB.pageSlice(sorted, ctx.page, ctx.size === 'all' ? 1 : Number(ctx.size), ctx.size === 'all');
    lastAllRows = view.items;
    lastRows = view.items.filter(function (r) { return r.manual && r.id; });

    var grid = $('sb-grid'), empty = $('sb-empty');
    if (!dataOk) {
        grid.innerHTML = '';
        empty.classList.remove('hidden');
        empty.textContent = '正在读取 IP 数据…';
    } else if (!view.items.length) {
        grid.innerHTML = '';
        empty.classList.remove('hidden');
        empty.textContent = (ctx.tab || ctx.q)
            ? '当前筛选没有 IP'
            : '没有公网 IP：先「更新数据」拉取解析缓存，或点工具栏「＋ 添加 IP」手工登记';
    } else {
        empty.classList.add('hidden');
        grid.innerHTML = view.items.map(function (r) { return ipCardHtml(r, names); }).join('');
    }

    var showAll = ctx.size === 'all';
    $('sb-count').textContent = '共 ' + sorted.length + ' 个 IP';
    $('sb-page-info').textContent = showAll
        ? '全部展示 ' + sorted.length + ' 项'
        : '第 ' + view.page + ' / ' + view.pages + ' 页 · 每页 ' + ctx.size + ' 条';
    $('sb-prev').disabled = showAll || view.page <= 1;
    $('sb-next').disabled = showAll || view.page >= view.pages;

    var s = (ctx.data && ctx.data.stats) || {};
    $('sb-stats').textContent = dataOk
        ? ('共 ' + Number(s.ips || 0) + ' 个 IP（自动发现 ' + Number(s.auto || 0) + ' · 手工登记 ' + Number(s.manual || 0)
            + '）· 覆盖国家/地区 ' + Number(s.countries || 0) + ' · 关联 A 记录 ' + Number(s.records || 0))
        : '';
}

// ---- 操作：归属地刷新 / 编辑 / 删除 / 添加 ----

var busy = {};

function refreshLocation(ip) {
    if (busy['loc:' + ip]) { return; }
    busy['loc:' + ip] = true;
    SB.toast('正在刷新 ' + ip + ' 的归属地…', 'info', 4000);
    SB.fetchJson('/api/ips/' + encodeURIComponent(ip) + '/location', { method: 'POST' })
        .then(function (r) {
            var loc = r.location || {};
            SB.toast('归属地已更新：' + [loc.country, loc.city].filter(Boolean).join(' · ') + (loc.isp ? ' · ' + loc.isp : ''));
            SB.reload();
        })
        .catch(function (err) { SB.toast(err.message || '刷新失败，请稍后重试', 'error', 6000); })
        .then(function () { busy['loc:' + ip] = false; });
}

/** 轻量弹窗骨架（样式与 sb-modal 同源），返回 {box, close}。 */
function overlay(html) {
    var ov = document.createElement('div');
    ov.className = 'sb-ipov';
    ov.innerHTML = '<div class="sb-ipbox">' + html + '</div>';
    document.body.appendChild(ov);
    var close = function () { ov.remove(); };
    ov.addEventListener('click', function (e) { if (e.target === ov) { close(); } });
    ov.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
    return { ov: ov, close: close };
}

function confirmBox(title, message, okText, onOk) {
    var ui = overlay('<h3>' + esc(title) + '</h3><p>' + esc(message) + '</p>'
        + '<div class="sb-ipbox-foot"><button type="button" class="sb-mini" data-c>取消</button>'
        + '<button type="button" class="sb-mini sb-mini-danger" data-ok>' + esc(okText) + '</button></div>');
    ui.ov.querySelector('[data-c]').addEventListener('click', ui.close);
    ui.ov.querySelector('[data-ok]').addEventListener('click', function () { ui.close(); onOk(); });
    ui.ov.querySelector('[data-ok]').focus();
}

/** 添加 / 登记 / 编辑手工 IP 弹窗。row 为 null 是添加；row 有 id 是编辑；仅带 ip 是登记自动发现的 IP。 */
function openIpForm(row) {
    var isEdit = !!(row && row.id);
    var prefillIp = row && row.ip ? String(row.ip) : '';
    var title = isEdit ? '编辑手工 IP' : (prefillIp ? '登记手工 IP' : '添加手工 IP');
    var submitText = isEdit ? '保存修改' : (prefillIp ? '登记为手工 IP' : '添加 IP');
    var okMsg = isEdit ? '手工 IP 已保存' : (prefillIp ? '已登记为手工 IP，卡片已标注「手工登记」' : '手工 IP 已添加');
    var ui = overlay('<h3>' + title + '</h3>'
        + (prefillIp && !isEdit ? '<p class="sb-ipform-hint">该 IP 来自 DNS 解析自动发现；登记后即可在此维护名称 / 备注 / 端口，卡片将标注「手工登记」。</p>' : '')
        + '<form class="sb-ipform">'
        + '<label>IP 地址<input name="ip" required spellcheck="false" autocomplete="off" placeholder="公网 IPv4 / IPv6"' + (prefillIp ? ' value="' + esc(prefillIp) + '"' : '') + '></label>'
        + '<div class="sb-ipform-row">'
        + '<label>端口（选填）<input name="port" type="number" min="1" max="65535" placeholder="如 443"' + (row && row.port ? ' value="' + Number(row.port) + '"' : '') + '></label>'
        + '<label>名称（选填）<input name="name" maxlength="120" placeholder="如：生产服务器 · 上海"' + (row && row.name ? ' value="' + esc(row.name) + '"' : '') + '></label>'
        + '</div>'
        + '<label>备注（选填）<textarea name="note" rows="3" maxlength="2000" placeholder="用途、到期、供应商等">' + (row && row.note ? esc(row.note) : '') + '</textarea></label>'
        + '<p class="sb-ipform-err hidden"></p>'
        + '<div class="sb-ipbox-foot"><button type="button" class="sb-mini" data-c>取消</button>'
        + '<button type="submit" class="sb-mini sb-mini-primary">' + submitText + '</button></div>'
        + '</form>');
    var form = ui.ov.querySelector('form');
    ui.ov.querySelector('[data-c]').addEventListener('click', ui.close);
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var errEl = form.querySelector('.sb-ipform-err');
        errEl.classList.add('hidden');
        var body = {
            ip: form.elements.ip.value.trim(),
            port: form.elements.port.value ? Number(form.elements.port.value) : null,
            name: form.elements.name.value.trim(),
            note: form.elements.note.value.trim(),
        };
        SB.fetchJson('/api/ips' + (isEdit ? '/' + encodeURIComponent(row.id) : ''), { method: isEdit ? 'PUT' : 'POST', body: body })
            .then(function () {
                ui.close();
                SB.toast(okMsg);
                SB.reload();
            })
            .catch(function (err) {
                errEl.textContent = err.message || '保存失败';
                errEl.classList.remove('hidden');
            });
    });
    form.elements.ip.focus();
}

function deleteIp(row) {
    confirmBox('删除手工 IP', '删除 ' + row.ip + ' 的手工登记？DNS 自动发现的记录不受影响。', '确认删除', function () {
        SB.fetchJson('/api/ips/' + encodeURIComponent(row.id), { method: 'DELETE' })
            .then(function () { SB.toast('手工 IP 已删除'); SB.reload(); })
            .catch(function (err) { SB.toast(err.message || '删除失败', 'error', 6000); });
    });
}

// ---- 事件绑定（一次性；卡片行内动作走事件委托） ----

$('sb-tabs').addEventListener('click', function (event) {
    var btn = event.target.closest('[data-ip-tab]');
    if (btn && currentCtx && currentCtx.onTab) { currentCtx.onTab(btn.getAttribute('data-ip-tab')); }
});
$('sb-grid').addEventListener('click', function (event) {
    if (event.target.closest('a')) { return; } // ↗ 跳转链接交给浏览器
    if (window.__SCREEN_MODE__ !== 'ip') { return; } // 仅 IP 视图处理卡片交互（域名视图由 screen.js 处理）
    var loc = event.target.closest('[data-ip-loc]');
    if (loc) { refreshLocation(loc.getAttribute('data-ip-loc')); return; }
    var copyBtn = event.target.closest('[data-ip-copy]');
    if (copyBtn) { SB.copyText(copyBtn.getAttribute('data-ip-copy'), 'IP 已复制'); return; }
    var domBtn = event.target.closest('[data-ip-domains]');
    if (domBtn) { openDomainsModal(domBtn.getAttribute('data-ip-domains')); return; }
    var feOne = event.target.closest('[data-ip-fecheck]');
    if (feOne) { probeIps([feOne.getAttribute('data-ip-fecheck')], 'browser'); return; }
    var beOne = event.target.closest('[data-ip-becheck]');
    if (beOne) { probeIps([beOne.getAttribute('data-ip-becheck')], 'probe'); return; }
    var del = event.target.closest('[data-ip-del]');
    if (del) {
        var row = findCurrent(del.getAttribute('data-ip-del'));
        if (row) { deleteIp(row); }
        return;
    }
    var edit = event.target.closest('[data-ip-edit]');
    if (edit) {
        var key = edit.getAttribute('data-ip-edit');
        var row = findCurrent(key);
        // 已登记按 id 编辑；未登记（自动发现）按 ip 进入登记模式
        openIpForm(row || { ip: key });
        return;
    }
});

/* ---- IP 访问检查（前端浏览器直连 / 后端服务端探测），结果全局缓存 ---- */

var ipCheckBusy = false;

function probeIps(ips, mode) {
    if (!ips.length || ipCheckBusy) { return; }
    ipCheckBusy = true;
    if (mode === 'browser') {
        // 浏览器直连：4 路并发探测 http://IP/，完成后一次性上报
        SB.toast('前端检查中：浏览器直连 ' + ips.length + ' 个 IP…', 'info', 6000);
        var results = {}, okCount = 0, cursor = 0;
        var worker = function () {
            if (cursor >= ips.length) { return Promise.resolve(); }
            var ip = ips[cursor++];
            return SB.frontProbe(ip).then(function (res) {
                if (res.ok) { okCount++; results[ip] = { ok: true, ms: res.ms }; } // 失败不上报：浏览器直连失败可能是环境限制
                return worker();
            });
        };
        var pool = [];
        for (var i = 0; i < Math.min(4, ips.length); i++) { pool.push(worker()); }
        Promise.all(pool).then(function () {
            SB.fetchJson('/api/ips/browser-check', { method: 'POST', body: { results: results } })
                .then(function () { SB.toast('前端检查完成：通过 ' + okCount + ' / ' + ips.length + '（结果已全局缓存）', okCount ? 'success' : 'error', 9000); SB.reload(true); })
                .catch(function (err) { SB.toast('结果上报失败：' + (err.message || '未知错误'), 'error', 6000); SB.reload(); })
                .then(function () { ipCheckBusy = false; });
        });
        return;
    }
    // 服务端探测：单次请求全部 IP（上限 50）
    SB.toast('后端检查中：服务端探测 ' + ips.length + ' 个 IP（每个目标最多 4 秒）…', 'info', 8000);
    SB.fetchJson('/api/ips/check', { method: 'POST', body: { ips: ips.slice(0, 50) } })
        .then(function (r) {
            SB.toast('后端检查完成：通过 ' + r.ok + ' / ' + r.total + '（结果已全局缓存）', r.ok ? 'success' : 'error', 9000);
            SB.reload(true);
        })
        .catch(function (err) { SB.toast(err.message || '后端检查失败', 'error', 8000); })
        .then(function () { ipCheckBusy = false; });
}

// 工具栏：当前列表（筛选 + 分页后）全部 IP
$('sb-ip-fecheck').addEventListener('click', function () {
    var ips = lastAllRows.map(function (r) { return r.ip; });
    if (!ips.length) { SB.toast('当前列表没有 IP', 'info'); return; }
    probeIps(ips, 'browser');
});
$('sb-ip-becheck').addEventListener('click', function () {
    var ips = lastAllRows.map(function (r) { return r.ip; });
    if (!ips.length) { SB.toast('当前列表没有 IP', 'info'); return; }
    if (ips.length > 50) { ips = ips.slice(0, 50); SB.toast('列表超过 50 个，本次检查前 50 个', 'info', 5000); }
    probeIps(ips, 'probe');
});
$('sb-addip').addEventListener('click', function () { openIpForm(null); });

var currentCtx = null;
var baseRender = render;
render = function (ctx) {
    currentCtx = ctx;
    try { baseRender(ctx); }
    catch (e) { SB.toast('IP 视图渲染失败：' + e.message, 'error', 8000); }
};

window.ScreenIp = { render: render, applyFilters: applyFilters, sortRows: sortRows, flagOf: flagOf };
if (SB.onIpModuleReady) { SB.onIpModuleReady(); }

})();
