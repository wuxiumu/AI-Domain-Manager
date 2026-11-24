/**
 * 解析管理弹窗模块：渲染 / 关键词与状态筛选 / 勾选与批量工具 / 批量添加 / 单条记录编辑 / 解析示例 / 非 A 记录。
 * 依赖 screen.js 先行加载并暴露 ScreenBoard 桥接（共享 state、HTTP、提示、卡片渲染与检查工具）；
 * 模块向桥接回填 openModal / modalItemFor / openOtherRecords / closeModal 供视图与 IP 视图调用。
 */
(function () {
var SB = window.ScreenBoard;
if (!SB || !SB.state) { return; } // 桥接未就绪（加载顺序错误）时静默退出
var state = SB.state;
var $ = SB.$;
var fetchJson = SB.fetchJson;
var toast = SB.toast;
var escapeHtml = SB.escapeHtml;
var httpLink = SB.httpLink;
var httpsLock = SB.httpsLock;
var expireBadge = SB.expireBadge;
var groupStatus = SB.groupStatus;
var accessStore = SB.accessStore;
var saveAccess = SB.saveAccess;
var fmtNow = SB.fmtNow;
var frontProbe = SB.frontProbe;
var loadData = SB.loadData;
var render = SB.render;
var copyText = SB.copyText;

// ---- 弹窗分页：每页条数跨会话记忆（localStorage），页码在打开 / 换筛选 / 换条数时重置 ----
var MODAL_SIZE_KEY = 'asset-workspace-screen:modal-size:v1';
state.modalPage = 1;
state.modalSize = 'all';
try {
    var savedModalSize = localStorage.getItem(MODAL_SIZE_KEY);
    if (savedModalSize === '10' || savedModalSize === '20' || savedModalSize === 'all') { state.modalSize = savedModalSize; }
} catch (e) { /* localStorage 不可用时保持默认全部 */ }
function saveModalSize() { try { localStorage.setItem(MODAL_SIZE_KEY, state.modalSize); } catch (e) { /* 忽略 */ } }


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
    state.modalPage = 1; // 每次打开回到第一页（每页条数保持记忆值）
    // 来源上下文（从 IP 视图进入时携带）：高亮指向该 IP 的记录与被点击的域名。
    state.modalSource = source && source.ip ? { ip: String(source.ip), fqdn: String(source.fqdn || '') } : null;
    state.locateIp = locateIp ? String(locateIp) : null; // 点击卡片 IP：打开后勾选该组并滚动定位
    state.locateScroll = !!state.locateIp;
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
    state.locateScroll = false;
    state.selected = {};
    state.modalQ = '';
    state.modalStatus = '';
    state.modalPage = 1;
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
    ['sb-editip', 'sb-del', 'sb-fecheck', 'sb-becheck', 'sb-httpscheck', 'sb-invert'].forEach(function (id) {
        $(id).disabled = !hasRecords || state.busyCheck || state.busyWrite;
    });
    $('sb-tool-hint').textContent = hasRecords
        ? '前端 / 后端任一通过即通过 · HTTPS 检查仅覆盖已通过 HTTP 的记录 · 勾选后只检查选中项，未勾选检查全部 · 修改与删除直接写入云端 DNS'
        : '该域名暂无解析记录：点「批量添加」创建第一条（每行一条：主机 [类型] 值，支持 A / AAAA / CNAME / MX / TXT / NS / SRV / CAA，详见「解析示例」）';

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
        if (stFilter === 'https') { if (s.https !== 'ok') { return false; } }  // 有锁：HTTPS 通过
        else if (stFilter === 'http-only') { if (groupStatus(s) !== 'ok' || s.https === 'ok') { return false; } }  // 无锁：HTTP 通过但没有锁
        else if (stFilter && groupStatus(s) !== stFilter) { return false; }
        if (q) {
            var matched = s.fqdn.toLowerCase().indexOf(q) !== -1
                || (s.records || []).some(function (r) { return String(r.value || '').toLowerCase().indexOf(q) !== -1; });
            if (!matched) { return false; }
        }
        return true;
    });
    $('sb-modal-count').textContent = (q || stFilter) ? '匹配 ' + rows.length + ' / 共 ' + allRows.length + ' 行' : '';

    // 分页：过滤后按每页条数切片；点击卡片 IP 进入时先跳到目标行所在页再渲染
    var pageSize = state.modalSize === 'all' ? 0 : parseInt(state.modalSize, 10);
    var pageCount = pageSize ? Math.max(1, Math.ceil(rows.length / pageSize)) : 1;
    if (state.locateIp && pageSize) {
        for (var li = 0; li < rows.length; li++) {
            var recs = rows[li].records || [];
            for (var lj = 0; lj < recs.length; lj++) {
                if (String(recs[lj].value || '') === state.locateIp) { state.modalPage = Math.floor(li / pageSize) + 1; li = rows.length; break; }
            }
        }
    }
    if (state.modalPage > pageCount) { state.modalPage = pageCount; }
    if (state.modalPage < 1) { state.modalPage = 1; }
    var pageRows = pageSize ? rows.slice((state.modalPage - 1) * pageSize, state.modalPage * pageSize) : rows;

    var rowsHtml = pageRows.map(function (s) {
        var clicked = src && src.fqdn && s.fqdn === src.fqdn;
        // 域名与 IP 均为可访问链接（新窗口打开）；通配符记录无法直接访问，保持纯文本。
        // 主机名颜色 = 访问状态（绿=通过 / 红=异常 / 默认白=未检查）；🔒 HTTPS 通过才显示（锁放进链接内，紧跟名字）。
        var st = groupStatus(s);
        var stCls = st === 'ok' ? ' sb-st-ok' : st === 'fail' ? ' sb-st-fail' : '';
        var hostLock = s.https === 'ok' ? httpsLock(s.https_expires) : '';
        var firstChecked = (s.records || []).length && s.records[0].access ? s.records[0].access.checked_at : '';
        var hostTitle = s.fqdn + ' — 新页面打开 ' + httpLink(s.fqdn)
            + (firstChecked ? '（访问检查于 ' + firstChecked + '）' : '');
        var hostCell = s.fqdn.indexOf('*') === -1
            ? '<a class="sb-hostname' + stCls + '" href="' + escapeHtml(httpLink(s.fqdn)) + '" target="_blank" rel="noopener" title="' + escapeHtml(hostTitle) + '">' + escapeHtml(s.fqdn) + hostLock + '</a>'
            : '<span class="sb-hostname' + stCls + '" title="' + escapeHtml(hostTitle) + '">' + escapeHtml(s.fqdn) + hostLock + '</span>';
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
            // HTTP / HTTPS 访问耗时并列展示（一次检查两条协议各自的时间，方便对照）；旧结果无此字段不显示
            var times = '';
            if (rec.access && rec.access.http_ms != null) {
                times += '<span class="sb-time' + (rec.access.http_status === 'ok' ? ' ok' : '') + '" title="HTTP 访问耗时">HTTP ' + Number(rec.access.http_ms) + 'ms</span>';
            }
            if (rec.access && rec.access.https_ms != null) {
                times += '<span class="sb-time' + (rec.access.https_status === 'ok' ? ' ok' : '') + '" title="HTTPS 访问耗时">HTTPS ' + Number(rec.access.https_ms) + 'ms</span>';
            }
            // 归属地（全局缓存，就绪后随重绘出现）
            var geo = ipLocMap && ipLocMap[rec.value] ? ipGeoHtml(ipLocMap[rec.value]) : '';
            // 单条记录「修改」按钮：打开编辑弹窗，可改主机记录 / 类型 / 记录值 / TTL / 线路 / 优先级
            var recEdit = (!paused && hasRecords)
                ? '<button type="button" class="sb-rec-edit-btn" data-rec-edit="' + escapeHtml(rec.id) + '" data-rec-type="' + escapeHtml(rec.type || 'A') + '" title="编辑这条解析记录（主机 / 类型 / 值 / TTL 等）">修改</button>'
                : '';
            // 单条记录「删除」按钮：紧跟「修改」之后，二次弹窗确认后只写一次并回读核实（暂停态也能删）
            var recDel = (hasRecords && rec.id)
                ? '<button type="button" class="sb-rec-edit-btn sb-rec-del-btn" data-rec-del="' + escapeHtml(rec.id)
                    + '" data-rec-host="' + escapeHtml(s.fqdn) + '" title="删除这条解析记录（需二次确认）">删除</button>'
                : '';
            return '<span class="sb-rec">'
                + '<span class="sb-rec-main">' + link + geo + times + marks + '</span>'
                + recEdit + recDel
                + '</span>';
        }).join('');
        return '<div class="sb-host' + (clicked ? ' sb-host-clicked' : '') + (hasHit ? ' sb-host-hit' : '') + '">'
            + '<input type="checkbox" class="sb-check" data-fqdn="' + escapeHtml(s.fqdn) + '"' + (state.selected[s.fqdn] ? ' checked' : '') + '>'
            + '<span class="sb-host-cell">' + hostCell
            + (clicked ? '<span class="sb-hitmark" title="你在 IP 卡片上点击的域名">所选域名</span>' : '')
            + '</span>'
            + '<span class="sb-ips">' + ips + '</span>'
            + '</div>';
    }).join('');
    var headHtml = rowsHtml
        ? '<div class="sb-host-head"><span></span><span>主机记录</span><span>解析记录</span></div>'
        : '';
    $('sb-modal-body').innerHTML = headHtml + (rowsHtml
        || (allRows.length
            ? '<div class="sb-hosts-empty">没有匹配的记录：调整关键词或访问状态筛选</div>'
            : '<div class="sb-hosts-empty">该域名暂无解析记录 · 点上方「批量添加」创建第一条（每行一条：主机 [类型] 值，支持全部类型，详见「解析示例」）</div>'));
    renderPager(allRows.length, rows.length, pageCount);

    // 点击卡片 IP 进入：勾选该子域名组、高亮并滚动定位到可见。
    // locateIp 跨重渲染保留（异步归属地刷新 / 筛选重绘不再抹掉高亮），仅首屏滚动一次；closeModal 时复位。
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
            if (state.locateScroll) {
                setTimeout(function () { targetRow.scrollIntoView({ block: 'center', behavior: 'smooth' }); }, 60);
                state.locateScroll = false;
            }
        }
    }

    syncPickAll();
    syncButtons();
}

/** 弹窗底部分页栏：计数 + 每页条数（跨会话记忆） + 页码跳转 / 上下页；「全部」模式只显示计数与条数选择。 */
function renderPager(totalRows, matchedRows, pageCount) {
    var pager = $('sb-modal-pager');
    if (!totalRows) { pager.classList.add('hidden'); pager.innerHTML = ''; return; }
    pager.classList.remove('hidden');
    var sizeSel = '<label class="sb-pager-size">每页<select id="sb-modal-size" title="每页显示条数（选择会被记住）">'
        + ['all', '10', '20'].map(function (v) {
            return '<option value="' + v + '"' + (state.modalSize === v ? ' selected' : '') + '>' + (v === 'all' ? '全部' : v) + '</option>';
        }).join('')
        + '</select>条</label>';
    var nav = '';
    if (state.modalSize !== 'all') {
        var nums = pagerWindow(state.modalPage, pageCount).map(function (p) {
            if (p === '…') { return '<span class="sb-pager-ellipsis">…</span>'; }
            return '<button type="button" class="sb-pager-btn sb-pager-num' + (p === state.modalPage ? ' is-active' : '')
                + '" data-mpage-go="' + p + '" title="跳到第 ' + p + ' 页">' + p + '</button>';
        }).join('');
        nav = '<button type="button" class="sb-pager-btn" data-mpage="prev"' + (state.modalPage <= 1 ? ' disabled' : '') + '>‹</button>'
            + nums
            + '<button type="button" class="sb-pager-btn" data-mpage="next"' + (state.modalPage >= pageCount ? ' disabled' : '') + '>›</button>';
    }
    var sizeNum = parseInt(state.modalSize, 10);
    var countText = state.modalSize === 'all'
        ? '共 ' + totalRows + ' 行'
        : '第 ' + ((state.modalPage - 1) * sizeNum + 1) + '–' + Math.min(state.modalPage * sizeNum, totalRows) + ' 行 · 共 ' + totalRows + ' 行'
            + (matchedRows !== totalRows ? '（筛选后）' : '');
    pager.innerHTML = '<span class="sb-pager-count">' + escapeHtml(countText) + '</span>'
        + '<span class="sb-pager-nav">' + nav + sizeSel + '</span>';
}

/** 页码窗口：首尾 + 当前页邻域，中间以省略号折叠（总页数 ≤7 全展示）。 */
function pagerWindow(current, total) {
    if (total <= 7) {
        var all = [];
        for (var i = 1; i <= total; i++) { all.push(i); }
        return all;
    }
    var wanted = [1, current - 1, current, current + 1, total].filter(function (p) { return p >= 1 && p <= total; });
    var unique = [];
    wanted.forEach(function (p) { if (unique.indexOf(p) === -1) { unique.push(p); } });
    unique.sort(function (a, b) { return a - b; });
    var out = [];
    unique.forEach(function (p, i) {
        if (i && p - unique[i - 1] > 1) { out.push('…'); }
        out.push(p);
    });
    return out;
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
    ['sb-editip', 'sb-del', 'sb-batchadd', 'sb-fecheck', 'sb-becheck', 'sb-httpscheck', 'sb-invert'].forEach(function (id) { $(id).disabled = !!text; });
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
    var typeOptions = BATCH_TYPES.map(function (t) {
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
        + '<p class="sb-demo-note">类型说明见弹窗工具栏「批量添加 → 解析示例」。A / AAAA 记录值必须是合法 IP 地址，CNAME / MX / NS 记录值必须是一个域名。</p>'
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
        var valueErr = recordValueError(body.type, body.value);
        if (valueErr) { errEl.textContent = valueErr; errEl.classList.remove('hidden'); return; }
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

// ---- 删除单条 / 多条解析记录：二次确认弹窗 + 单次写入 + 回读核实 ----

/** 通用危险操作二次确认弹窗：rows 为 [标签, 值] 列表，点「确认」才执行 onOk。 */
function openConfirmDialog(opts, onOk) {
    var ov = document.createElement('div');
    ov.className = 'sb-ipov';
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.setAttribute('aria-label', opts.title || '确认操作');
    var rows = (opts.rows || []).map(function (row) {
        return '<div class="sb-confirm-row"><span>' + escapeHtml(row[0]) + '</span><b>' + escapeHtml(row[1]) + '</b></div>';
    }).join('');
    ov.innerHTML = '<div class="sb-ipbox sb-confirmbox"><h3>' + escapeHtml(opts.title || '确认操作') + '</h3>'
        + (rows ? '<div class="sb-confirm-rows">' + rows + '</div>' : '')
        + '<p class="sb-confirm-warn">' + escapeHtml(opts.warn || '') + '</p>'
        + '<div class="sb-demo-foot"><button type="button" class="sb-mini" data-confirm-cancel>取消</button>'
        + '<button type="button" class="sb-mini sb-mini-danger" data-confirm-ok>' + escapeHtml(opts.okText || '确认删除') + '</button></div></div>';
    document.body.appendChild(ov);
    var okBtn = ov.querySelector('[data-confirm-ok]');
    var cancelBtn = ov.querySelector('[data-confirm-cancel]');
    var close = function () { ov.remove(); };
    cancelBtn.addEventListener('click', close);
    ov.addEventListener('click', function (e) {
        if (e.target === okBtn) { close(); onOk(); return; }
        if (e.target === ov) { close(); } // 点遮罩只关闭，绝不触发删除
    });
    ov.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
    cancelBtn.focus(); // 默认焦点在「取消」，回车不会误删
}

/** 快照行数据按主机名 + 记录 id 找回（删除需要记录 version 做版本护栏）。 */
function findSnapshotRecord(item, fqdn, recId) {
    var found = null;
    (item.subdomains || []).forEach(function (s) {
        if (s.fqdn !== fqdn) { return; }
        (s.records || []).forEach(function (r) { if (String(r.id) === String(recId)) { found = r; } });
    });
    return found;
}

/** 逐条删除：单次写入 + 回读核实；版本冲突（409）立即停止剩余删除，绝不自动重试。 */
function deleteRecords(item, entries, done) {
    var base = '/api/domains/' + encodeURIComponent(item.provider) + '/' + encodeURIComponent(item.domain) + '/records/';
    var ok = 0, fail = 0, firstErr = null, stopped = false;
    function step(i) {
        if (stopped || i >= entries.length) { done(ok, fail, firstErr); return; }
        fetchJson(base + encodeURIComponent(entries[i].id), { method: 'DELETE', body: { expected_version: entries[i].version } })
            .then(function (r) {
                ok++;
                // 未回读核实即停：云端状态不明时继续删下一条有风险，绝不自动重试
                if (r.verified === false) { firstErr = firstErr || '云端结果未回读核实，请刷新核对后再继续'; stopped = true; }
            })
            .catch(function (err) {
                fail++;
                firstErr = firstErr || ((err && err.message) || '删除失败');
                if (err && err.status === 409) { stopped = true; }
            })
            .then(function () { step(i + 1); });
    }
    step(0);
}

/** 解析管理弹窗：单条记录删除（确认后走同一套写入护栏）。 */
function confirmDeleteRecord(item, rec, hostLabel) {
    openConfirmDialog({
        title: '确认删除这条解析记录？',
        rows: [['域名', item.domain], ['主机记录', hostLabel],
            ['类型', String(rec.type || 'A').toUpperCase()], ['记录值', rec.value || '-'],
            ['TTL', rec.ttl ? rec.ttl + ' 秒' : '云商默认']],
        warn: '删除只写一次并回读核实，不会自动重试；解析停止生效需等云端传播与 TTL 过期。',
        okText: '确认删除',
    }, function () {
        state.busyWrite = true;
        setToolsBusy('正在删除解析记录（写入并回读核实）…');
        deleteRecords(item, [rec], function (okCount, failCount, firstErr) {
            state.busyWrite = false;
            setToolsBusy('');
            toast(failCount ? '删除失败：' + firstErr
                : '记录已删除' + (firstErr ? '（' + firstErr + '）' : '并回读核实'),
                failCount ? 'error' : (firstErr ? 'info' : 'success'), 9000);
            reloadAfterWrite();
        });
    });
}

// ---- 批量添加解析：Markdown 多行（如 www 192.0.2.10），重复自动跳过 ----

function fqdnOfName(name, domain) {
    if (name === '@') { return domain; }
    var suffix = '.' + domain;
    return (name.length > suffix.length && name.slice(-suffix.length) === suffix) ? name : name + suffix;
}

// 与 DnsChangeService::params 的类型白名单一致：批量添加与单条添加 / 编辑共用同一套类型。
var BATCH_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

function isIPv4(v) {
    return /^(\d{1,3}\.){3}\d{1,3}$/.test(v) && v.split('.').every(function (o) { return Number(o) <= 255; });
}
function isIPv6(v) {
    return /^[0-9a-fA-F:]+$/.test(v) && v.indexOf(':') !== -1 && v.length <= 45;
}
function isDomainValue(v) {
    return /^[a-z0-9]([a-z0-9-*_]*[a-z0-9-*_])?(\.[a-z0-9-*_]+)*\.?$/.test(v.toLowerCase()) && v.indexOf('.') !== -1;
}

/** 按类型校验记录值（单条添加 / 编辑共用）：返回错误文案，合法时返回空串。 */
function recordValueError(type, value) {
    if (value === '') { return '记录值不能为空'; }
    if (type === 'A') { return isIPv4(value) ? '' : 'A 记录的记录值必须是合法 IPv4 地址（如 192.0.2.10）'; }
    if (type === 'AAAA') { return isIPv6(value) ? '' : 'AAAA 记录的记录值必须是合法 IPv6 地址（如 2001:db8::1）'; }
    if (type === 'CNAME' || type === 'NS' || type === 'MX') {
        return isDomainValue(value) ? '' : type + ' 记录的值应为一个域名（如 ' + (type === 'MX' ? 'mail.example.com' : 'example.com') + '）';
    }
    if (type === 'TXT') { return value.length > 512 ? 'TXT 记录值不应超过 512 字符' : ''; }
    // SRV / CAA 各云商填写格式不同（如「权重 端口 目标域名」「0 issue "letsencrypt.org"」），只要求非空，具体格式由云端核实。
    return '';
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
            else if (/^\d{1,3}(\.\d{1,3}){3}$/.test(value)) { bad.push(no + '疑似 IPv4 但地址不合法（每段需 ≤255）'); return; }
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
    { type: 'SRV', label: 'SRV · 服务定位', lines: ['_sip._tcp SRV 10 5060 sip.example.com'],
        note: '值按「权重 端口 目标域名」填写，各云商格式略有差异，以云商控制台说明为准。' },
    { type: 'CAA', label: 'CAA · 限制可签发证书的数字证书机构', lines: ['@ CAA 0 issue "letsencrypt.org"'],
        note: '值按「标志 标签 内容」填写；内容含空格用引号包裹，具体以证书服务商说明为准。' },
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
        + '<p class="sb-demo-note">批量添加格式：<b>主机 [类型] 值</b>，一行一条；简写「主机 IPv4」即 A 记录，类型可写 A / AAAA / CNAME / MX / TXT / NS / SRV / CAA；# 开头为注释；TTL 默认 600；已存在的相同记录自动跳过；逐条写入云端并回读核实。</p>'
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

/** 非 A 记录弹窗：多选 / 全选 / 反选 + 编辑与添加（写操作与其他 DNS 变更同一套护栏）。
 *  列表只列非 A 记录，但「添加 / 编辑」支持全部类型（含 A）：域名一条 A 都没有时也能在这里直接补上。 */
function openOtherRecordsModal(item) {
    var ov = document.createElement('div');
    ov.className = 'sb-ipov';
    ov.innerHTML = '<div class="sb-ipbox sb-demobox"><h3>' + escapeHtml(item.domain) + ' · 非 A 记录</h3>'
        + '<p class="sb-demo-note">列表只展示非 A 记录；「添加 / 编辑」支持全部类型（含 A / AAAA）。'
        + '在此添加的 A 记录请到卡片「A 记录」入口的解析管理中查看与修改。</p>'
        + '<div class="sb-other-tools">'
        + '<label class="sb-check"><input type="checkbox" id="sb-other-all"> 全选</label>'
        + '<button type="button" class="sb-mini" id="sb-other-invert">反选</button>'
        + '<button type="button" class="sb-mini sb-mini-primary" id="sb-other-edit" disabled>编辑</button>'
        + '<button type="button" class="sb-mini sb-mini-danger" id="sb-other-del" disabled>删除</button>'
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
    var recordsBase = '/api/domains/' + encodeURIComponent(zone.provider) + '/' + encodeURIComponent(zone.domain) + '/records';
    var records = []; // 非 A 记录（含 version）
    var revision = null; // 区域版本：添加记录用 expected_revision，写入成功后需刷新
    var otherBusy = false; // 写入期间锁住本弹窗工具（编辑 / 删除 / 添加 / 全选 / 反选）

    function renderList() {
        var listEl = ov.querySelector('#sb-other-list');
        var statusEl = ov.querySelector('#sb-other-status');
        ov.querySelector('#sb-other-all').checked = false; // 列表重建即勾选清空，全选框随之复位
        statusEl.textContent = '共 ' + records.length + ' 条非 A 记录 · 选中 ' + selectedIdx().length + ' 条';
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
            : '<p class="sb-hosts-empty">该域名暂无非 A 记录。点「添加」可创建任意类型解析（A / AAAA / CNAME / MX / TXT / NS / SRV / CAA）。</p>';
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

    /** 重新读取云端记录：刷新非 A 列表与区域 revision（写一次成功后版本会变，不刷新下一条必然 409）。 */
    function loadRecords(isInitial) {
        return fetchJson(recordsBase)
            .then(function (snap) {
                records = (snap.records || []).filter(function (r) { return (r.type || '').toUpperCase() !== 'A'; });
                revision = snap.revision || null;
                renderList();
                syncTools();
            })
            .catch(function (err) {
                ov.querySelector('#sb-other-status').textContent = (isInitial ? '读取失败：' : '记录列表刷新失败（写入已结束），关闭后重开可看最新记录：')
                    + ((err && err.message) || '未知错误');
            });
    }

    /** 编辑 / 添加共用表单弹窗；record 为 null 时是添加。类型覆盖全部（含 A / AAAA），写入走同一套护栏。 */
    function openForm(record) {
        var isEdit = !!record;
        var isGodaddy = String(zone.provider || '').toLowerCase().indexOf('godaddy') !== -1;
        var formOv = document.createElement('div');
        formOv.className = 'sb-ipov';
        var typeOptions = BATCH_TYPES.map(function (t) {
            return '<option value="' + t + '"' + (isEdit && String(record.type || 'A').toUpperCase() === t ? ' selected' : '') + '>' + t + '</option>';
        }).join('');
        // GoDaddy 不支持改类型与主机名（云端会拒绝），表单直接锁住这两项。
        var lockEdit = isEdit && isGodaddy;
        formOv.innerHTML = '<div class="sb-ipbox sb-demobox"><h3>' + (isEdit ? '编辑解析记录' : '添加解析记录') + ' · '
            + escapeHtml(zone.domain) + '</h3>'
            + '<p class="sb-demo-note">' + (isEdit ? '类型说明见「批量添加 → 解析示例」；只写一次并回读核实。'
                : '支持全部类型：A / AAAA / CNAME / MX / TXT / NS / SRV / CAA；只写一次并回读核实，已存在的相同记录不会重复添加。') + '</p>'
            + (lockEdit ? '<p class="sb-demo-note sb-warn">GoDaddy 不支持修改已有记录的类型与主机名；如需变更，请删除该记录后重新添加。</p>' : '')
            + '<form class="ap-form dc-form">'
            + '<div class="ap-row"><label>记录类型<select name="type"' + (lockEdit ? ' disabled' : '') + '>' + typeOptions + '</select></label>'
            + '<label>主机记录（@ 为主域名）<input name="name" required value="' + escapeHtml(isEdit ? (record.name || '@') : '@') + '"'
            + (lockEdit ? ' disabled' : '') + ' spellcheck="false"></label></div>'
            + '<label>记录值<textarea name="value" rows="2" required placeholder="A 填 IPv4（如 192.0.2.10）· AAAA 填 IPv6 · CNAME / MX / NS 填域名 · TXT 用引号包裹可含空格">' + escapeHtml(isEdit ? (record.value || '') : '') + '</textarea></label>'
            + '<div class="ap-row"><label>TTL（秒，留空默认 600）<input name="ttl" type="number" min="1" value="' + (isEdit && record.ttl ? record.ttl : '') + '"></label>'
            + '<label>优先级（MX / SRV）<input name="priority" type="number" min="0" max="65535" value="' + (isEdit && record.priority != null ? record.priority : '') + '"></label></div>'
            + '<p class="sb-demo-note">TTL / 优先级留空使用云商默认。' + (isEdit ? '' : '添加 A 记录后可在卡片「A 记录」入口的解析管理中修改与检查。') + '</p>'
            + '<p class="dc-form-err hidden"></p>'
            + '<div class="sb-demo-foot"><button type="button" class="sb-mini" data-cancel>取消</button>'
            + '<button type="submit" class="sb-mini sb-mini-primary">' + (isEdit ? '保存修改（单次写入并回读核实）' : '添加记录（单次写入并回读核实）') + '</button></div></div></form></div>';
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
            var fail = function (msg) { errEl.textContent = msg; errEl.classList.remove('hidden'); };
            var ttlRaw = form.elements.ttl.value.trim();
            var priRaw = form.elements.priority.value.trim();
            var body = {
                type: form.elements.type.value.toUpperCase(),
                name: form.elements.name.value.trim().toLowerCase().replace(/\.$/, '') || '@',
                value: form.elements.value.value.trim(),
            };
            var valueErr = recordValueError(body.type, body.value);
            if (valueErr) { fail(valueErr); return; }
            if (!isEdit) {
                if (revision == null) { fail('未取到解析区域版本，请关闭弹窗后重试'); return; }
                body.expected_revision = revision; // 添加用区域版本
            } else {
                body.expected_version = record.version; // 修改用单条记录版本
            }
            if (ttlRaw !== '') { body.ttl = Number(ttlRaw); }
            if (priRaw !== '' && (body.type === 'MX' || body.type === 'SRV')) { body.priority = Number(priRaw); }
            var btn = form.querySelector('[type="submit"]');
            btn.disabled = true;
            fetchJson(recordsBase + (isEdit ? '/' + encodeURIComponent(record.id) : ''), { method: isEdit ? 'PUT' : 'POST', body: body })
                .then(function (r) {
                    closeForm();
                    toast((isEdit ? '记录已保存' : body.type + ' 记录已添加')
                        + (r.verified === false ? '（结果待核实）' : '（已回读核实）'), r.verified === false ? 'info' : 'success', 9000);
                    SB.loadData(true); // 强制重建快照，状态即时生效
                    return loadRecords(false);
                })
                .catch(function (err) {
                    btn.disabled = false;
                    if (err && err.status === 409) {
                        fail(((err && err.message) || '记录已在云端被修改') + '；已重新读取最新版本，请确认后再提交');
                        loadRecords(false);
                        return;
                    }
                    fail((err && err.message) || '保存失败');
                });
        });
    }

    ov.querySelector('#sb-other-edit').addEventListener('click', function () {
        var idx = selectedIdx();
        if (idx.length !== 1) { return; }
        openForm(records[idx[0]]);
    });
    ov.querySelector('#sb-other-del').addEventListener('click', function () {
        var chosen = selectedIdx().map(function (i) { return records[i]; }).filter(Boolean);
        if (!chosen.length) { toast('请先勾选要删除的记录', 'info'); return; }
        var rows = [['域名', zone.domain]];
        chosen.slice(0, 6).forEach(function (r) {
            rows.push([(r.name === '' || r.name === '@') ? '@' : r.name,
                String(r.type || '').toUpperCase() + ' · ' + (r.value || '-')]);
        });
        if (chosen.length > 6) { rows.push(['…', '其余 ' + (chosen.length - 6) + ' 条']); }
        openConfirmDialog({
            title: '确认删除选中的 ' + chosen.length + ' 条解析记录？',
            rows: rows,
            warn: '删除只写一次并回读核实，不会自动重试；版本冲突会立即停止剩余删除。',
            okText: '确认删除 ' + chosen.length + ' 条',
        }, function () {
            otherBusy = true;
            syncTools();
            deleteRecords(item, chosen, function (okCount, failCount, firstErr) {
                otherBusy = false;
                toast('删除完成：成功 ' + okCount + ' · 失败 ' + failCount + (firstErr ? '；首个错误：' + firstErr : ''),
                    failCount ? (okCount ? 'info' : 'error') : 'success', 9000);
                SB.loadData(true); // 大屏快照同步重建
                loadRecords(false); // 重读列表与 revision，同时恢复工具可用
            });
        });
    });
    ov.querySelector('#sb-other-add').addEventListener('click', function () {
        openForm(null);
    });

    loadRecords(true);
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
            saveAccess(grp.fqdn, 'fe', { ok: ok, ms: res.ms, at: fmtNow(), scheme: ok ? res.scheme : null });
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
            results[s.fqdn] = { ok: true, ms: typeof cached.fe.ms === 'number' ? cached.fe.ms : null, scheme: cached.fe.scheme || undefined };
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

// HTTPS 专题检查：仅探测已通过 HTTP 访问检查记录的 https://主机名（严格 TLS + 证书到期），未通过 HTTP 的跳过。
function runHttpsCheck() {
    var item = modalItem();
    if (!item || state.busyCheck || state.busyWrite) { return; }
    var chosen = selectedGroups(item);
    var recordIds = [];
    if (chosen.length) {
        chosen.forEach(function (s) { (s.records || []).forEach(function (r) { recordIds.push(r.id); }); });
    }
    state.busyCheck = true;
    setToolsBusy('HTTPS 检查中：探测' + (recordIds.length ? '选中 ' + recordIds.length + ' 条' : '全部') + '已通过 HTTP 检查的记录…');
    toast('HTTPS 检查「' + item.domain + '」：仅覆盖已通过 HTTP 检查的记录', 'info', 8000);
    fetchJson('/api/domains/' + encodeURIComponent(item.provider) + '/' + encodeURIComponent(item.domain) + '/records/https-check',
        { method: 'POST', body: recordIds.length ? { record_ids: recordIds } : {} })
        .then(function (summary) {
            return loadData().then(function () {
                state.busyCheck = false;
                setToolsBusy('');
                if (state.modalKey && modalItemFor(state.modalKey)) { openModal(state.modalKey, state.modalSource); }
                if (summary.checked === 0) {
                    toast((summary.message || '没有可检查的记录'), 'info', 9000);
                    return;
                }
                toast('HTTPS 检查完成：通过 ' + (summary.https_ok || 0) + ' · 未启用/失败 ' + (summary.https_fail || 0)
                    + (summary.skipped_no_http ? ' · 跳过未过 HTTP 检查 ' + summary.skipped_no_http + ' 项' : ''),
                    (summary.https_ok || 0) ? 'success' : 'error', 9000);
            });
        })
        .catch(function (err) {
            state.busyCheck = false;
            setToolsBusy('');
            toast((err && err.message) || 'HTTPS 检查失败，请稍后重试', 'error', 8000);
        });
}

// ---- 弹窗级事件绑定（原 screen.js bind() 的弹窗部分；脚本在 body 末尾加载，元素已就绪） ----
    $('sb-modal').addEventListener('click', function (event) {
        if (event.target.hasAttribute('data-close')) { closeModal(); }
    });
    $('sb-modal-close').addEventListener('click', closeModal);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !$('sb-modal').classList.contains('hidden')) { closeModal(); }
    });

    // 单条记录「修改 / 删除」：修改打开编辑弹窗（先读云端补全字段），删除先二次确认再写入
    $('sb-modal-body').addEventListener('click', function (event) {
        var item = modalItem();
        if (!item) { return; }
        var delBtn = event.target.closest('[data-rec-del]');
        if (delBtn) {
            if (state.busyWrite || state.busyCheck) { toast('请等当前写入或检查完成后再删除', 'info'); return; }
            var host = delBtn.getAttribute('data-rec-host');
            var rec = findSnapshotRecord(item, host, delBtn.getAttribute('data-rec-del'));
            if (!rec || !rec.version) { toast('未取到该记录的云端版本，请关闭弹窗后重新打开再试', 'error'); return; }
            confirmDeleteRecord(item, rec, host);
            return;
        }
        var editBtn = event.target.closest('[data-rec-edit]');
        if (!editBtn) { return; }
        openRecordEditModal(item, editBtn.getAttribute('data-rec-edit'));
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
        state.modalPage = 1; // 换筛选回第一页
        renderModal();
    });
    // 访问状态 tab：全部 / 通过 / 异常 / HTTPS / 未检查
    $('sb-status-tabs').addEventListener('click', function (event) {
        var t = event.target.closest('.sb-status-tab');
        if (!t) { return; }
        state.modalStatus = t.getAttribute('data-mstatus') || '';
        state.modalPage = 1; // 换筛选回第一页
        document.querySelectorAll('#sb-status-tabs .sb-status-tab').forEach(function (b) { b.classList.toggle('is-active', b === t); });
        renderModal();
    });
    // 弹窗底部分页：页码跳转 + 上下页 + 每页条数（条数跨会话记忆，变更时回第一页）
    $('sb-modal-pager').addEventListener('click', function (event) {
        var goBtn = event.target.closest('[data-mpage-go]');
        if (goBtn) {
            state.modalPage = parseInt(goBtn.getAttribute('data-mpage-go'), 10) || 1;
            renderModal();
            return;
        }
        var btn = event.target.closest('[data-mpage]');
        if (!btn || btn.disabled) { return; }
        state.modalPage += btn.getAttribute('data-mpage') === 'prev' ? -1 : 1;
        renderModal();
    });
    $('sb-modal-pager').addEventListener('change', function (event) {
        var sel = event.target.closest('#sb-modal-size');
        if (!sel) { return; }
        state.modalSize = sel.value === '10' || sel.value === '20' ? sel.value : 'all';
        state.modalPage = 1;
        saveModalSize();
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
    $('sb-httpscheck').addEventListener('click', runHttpsCheck);

// 桥接回填：视图域与 IP 视图经此打开 / 关闭解析管理弹窗。
SB.openModal = openModal;
SB.modalItemFor = modalItemFor;
SB.openOtherRecords = openOtherRecordsModal;
SB.closeModal = closeModal;
})();
