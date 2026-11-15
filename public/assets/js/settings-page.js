/**
 * 独立设置页（/settings）：云账号、API 密钥、修改记录与文档、通用设置。
 * 自包含模块；使用 AdminPage 共享工具。
 * 面板与子面板状态写入 URL hash（#tab-accounts、#tab-records/dns），刷新后保持。
 */
(function () {
'use strict';

var A = window.AdminPage;
var esc = A.escapeHtml, api = A.fetchJson, toast = A.toast, confirmDialog = A.confirmDialog, copyText = A.copyText;

function $(id) { return document.getElementById(id); }

// mermaid 图表：深色主题，手动触发（渲染后经 AdminPage.enhancePreview 调用）
if (window.mermaid) {
    try { window.mermaid.initialize({ startOnLoad: false, theme: 'dark', securityLevel: 'strict' }); } catch (e) { /* 重复初始化忽略 */ }
}

function errorFor(form, message) {
    var error = form.querySelector('.ap-error');
    if (!error) { return; }
    error.textContent = message || '';
    error.classList.toggle('hidden', !message);
}

var providerFields = {
    aliyun: [['access_key_id', 'AccessKey ID'], ['access_key_secret', 'AccessKey Secret']],
    tencent: [['secret_id', 'SecretId'], ['secret_key', 'SecretKey']],
    godaddy: [['api_key', 'API Key'], ['api_secret', 'API Secret']],
};
var providerNames = { aliyun: '阿里云', tencent: '腾讯云', godaddy: 'GoDaddy' };

var accounts = [];

/* ---- 面板标签：状态写入 URL hash，刷新后保持 ---- */

var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-sp-tab]'));
var recordsLoaded = false;
var loadedSubs = {};

function writeHash(name, sub) {
    var hash = '#tab-' + name + (sub ? '/' + sub : '');
    if (location.hash !== hash) { history.replaceState(null, '', hash); }
}

function readHash() {
    var match = location.hash.match(/^#tab-(\w+)(?:\/(\w+))?/);
    return match ? { tab: match[1], sub: match[2] || null } : null;
}

function selectTab(name, sub) {
    if (tabs.every(function (tab) { return tab.dataset.spTab !== name; })) { name = 'accounts'; }
    tabs.forEach(function (tab) { tab.setAttribute('aria-selected', String(tab.dataset.spTab === name)); });
    document.querySelectorAll('[data-sp-panel]').forEach(function (panel) {
        panel.hidden = panel.dataset.spPanel !== name;
    });
    if (name === 'accounts') { loadAccounts(); }
    if (name === 'general') { load(); }
    if (name === 'records') {
        if (!recordsLoaded) { loadRecords(); } // 首次进入拉取全部记录内容，之后子标签切换免请求
        selectSubTab(sub);
    }
    writeHash(name, name === 'records' ? sub : null);
}

tabs.forEach(function (tab) { tab.addEventListener('click', function () { selectTab(tab.dataset.spTab); }); });
// 前进 / 后退 / 手动改 URL 时跟随 hash（避免与 writeHash 循环：replaceState 不触发 hashchange）
window.addEventListener('hashchange', function () {
    var state = readHash();
    selectTab(state ? state.tab : 'accounts', state ? state.sub : null);
});

/* ---- 修改记录与文档面板：内部子标签（吸顶） ---- */

var SUBTABS = ['overview', 'changelog', 'dns', 'audit'];
var subTabs = Array.prototype.slice.call(document.querySelectorAll('[data-sp-sub]'));

function selectSubTab(sub) {
    if (!sub || SUBTABS.indexOf(sub) === -1) { sub = 'overview'; }
    subTabs.forEach(function (tab) { tab.setAttribute('aria-selected', String(tab.dataset.spSub === sub)); });
    document.querySelectorAll('[data-sp-subpanel]').forEach(function (panel) {
        panel.hidden = panel.dataset.spSubpanel !== sub;
    });
    writeHash('records', sub);
}

subTabs.forEach(function (tab) {
    tab.addEventListener('click', function () { selectSubTab(tab.dataset.spSub); });
});

function loadRecords() {
    recordsLoaded = true;
    // 系统概况（代码规模 / 数据目录 / 运行时内存）
    api('/api/stats').then(function (s) {
        var fmtKB = function (bytes) { return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB'; };
        var card = function (label, value, sub) {
            return '<div class="sp-stat"><strong>' + esc(value) + '</strong><span>' + esc(label) + '</span>' + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</div>';
        };
        var c = s.code || {};
        var sum = function (key, field) { return (c[key] || {})[field] || 0; };
        var keys = ['app', 'public', 'scripts', 'tests'];
        var totalFiles = keys.reduce(function (acc, k) { return acc + sum(k, 'files'); }, 0);
        var totalLines = keys.reduce(function (acc, k) { return acc + sum(k, 'lines'); }, 0);
        var totalBytes = keys.reduce(function (acc, k) { return acc + sum(k, 'bytes'); }, 0);
        var mb = s.memory || {};
        $('sp-stats').innerHTML =
            card('代码文件', totalFiles + ' 个', 'app / public / scripts / tests') +
            card('代码行数', Number(totalLines).toLocaleString() + ' 行', 'PHP · JS · CSS · Python') +
            card('代码体积', fmtKB(totalBytes), '磁盘占用（源码）') +
            card('数据目录', fmtKB((s.data || {}).bytes || 0), (s.data || {}).files + ' 个文件 · data/（0600）') +
            card('PHP 内存', fmtKB(mb.peak || 0), '本次请求峰值 · 当前 ' + fmtKB(mb.current || 0)) +
            card('PHP 版本', s.php || '-', '运行时环境');
    }).catch(function (error) {
        $('sp-stats').innerHTML = '<p class="ap-error">' + esc(error.message) + '</p>';
    });
    // DNS 变更记录
    api('/api/dns/changes').then(function (r) {
        var rows = r.changes || [];
        $('sp-dns-changes').innerHTML = rows.length ? rows.map(function (entry) {
            var outcome = outcomeLabel[entry.outcome] || entry.outcome;
            var detail = esc(diffSummary(entry));
            return '<tr><td>' + esc(shortTime(entry.started_at)) + '</td>' +
                '<td>' + esc(entry.domain) + (entry.record_id ? '<small style="display:block;color:var(--muted)">记录 ' + esc(String(entry.record_id).slice(0, 10)) + '…</small>' : '') + '</td>' +
                '<td><span class="ap-badge">' + esc(actionLabel[entry.action] || entry.action) + '</span>' +
                '<small style="display:block;color:var(--muted)">' + esc(outcome === '待核实' ? '⚠ ' + outcome : outcome) + '</small></td>' +
                '<td>' + esc(entry.actor || 'admin') + '<small style="display:block;color:var(--muted)">' + esc(entry.via || '') + '</small></td>' +
                '<td class="sp-diff">' + detail + '</td></tr>';
        }).join('') : '<tr><td colspan="5" class="ap-muted">暂无 DNS 变更记录。</td></tr>';
    }).catch(function (error) {
        $('sp-dns-changes').innerHTML = '<tr><td colspan="5" class="ap-error">' + esc(error.message) + '</td></tr>';
    });
    // 操作审计
    api('/api/audit').then(function (r) {
        var rows = (r.entries || []).slice().reverse();
        $('sp-audit').innerHTML = rows.length ? rows.map(function (entry) {
            var targets = Object.keys(entry.targets || {}).map(function (key) { return entry.targets[key]; }).filter(Boolean).join(' · ');
            var pending = entry.outcome === 'pending';
            return '<tr><td>' + esc(shortTime(entry.started_at)) + '</td>' +
                '<td>' + esc(entry.actor || '') + '<small style="display:block;color:var(--muted)">' + esc(entry.via || '') + '</small></td>' +
                '<td>' + esc(entry.method + ' ' + entry.route) + (targets ? '<small style="display:block;color:var(--muted)">' + esc(targets) + '</small>' : '') + '</td>' +
                '<td>' + (pending ? '<span class="ap-badge">处理中</span>' : '<span class="ap-badge ' + (entry.http_status < 400 ? 'b-green' : 'b-red') + '">' + esc(entry.http_status + ' · ' + entry.outcome) + '</span>') + '</td></tr>';
        }).join('') : '<tr><td colspan="4" class="ap-muted">暂无操作记录。</td></tr>';
    }).catch(function (error) {
        $('sp-audit').innerHTML = '<tr><td colspan="4" class="ap-error">' + esc(error.message) + '</td></tr>';
    });
    // 产品修改记录（CHANGELOG 原文）
    api('/api/docs/read?path=' + encodeURIComponent('CHANGELOG.md')).then(function (r) {
        var el = $('sp-changelog');
        el.innerHTML = A.renderMarkdown(r.content);
        A.enhancePreview(el); // hljs 高亮 + mermaid 图渲染
    }).catch(function (error) {
        $('sp-changelog').innerHTML = '<p class="ap-error">' + esc(error.message) + '</p>';
    });
}

var actionLabel = { add: '新增', update: '修改', delete: '删除', pause: '暂停', resume: '恢复' };
var outcomeLabel = { verified: '已核实', unchanged: '无变化', unconfirmed: '待核实', pending: '处理中', failed: '失败' };

function shortTime(value) {
    var text = String(value || '');
    return text.length >= 16 ? text.slice(5, 16) : text;
}

function diffSummary(entry) {
    var before = entry.before, requested = entry.requested, after = entry.after;
    if (entry.action === 'pause' || entry.action === 'resume') {
        return (before && before.name ? before.name : '') + '：' + ((before && String(before.status).toUpperCase() === 'DISABLE') ? '暂停' : '启用') + ' → ' + (entry.action === 'pause' ? '暂停' : '启用');
    }
    if (entry.action === 'delete') { return (before ? before.name + ' ' + before.value : '') + ' → 已删除'; }
    if (entry.action === 'add') { return '新增 ' + (requested ? requested.name + ' ' + requested.value : ''); }
    return (before ? before.value : '?') + ' → ' + (after ? after.value : (requested ? requested.value : '?'));
}

/* ---- 设置与密钥加载 ---- */

function renderTokens(tokens) {
    var list = $('sp-token-list');
    list.innerHTML = tokens.length ? tokens.map(function (token) {
        return '<div class="sp-token"><div><strong>' + esc(token.name) + '</strong>' +
            '<div class="sp-token-meta">' + esc(token.prefix) + '… · ' +
            (token.invalidated ? '登录信息已更新，此密钥已失效' : token.expired ? '已过期' : '有效至 ' + new Date(token.expires_at).toLocaleDateString()) +
            '</div><div class="sp-token-scopes">' + token.scopes.map(function (scope) { return '<span class="ap-badge">' + esc(scope) + '</span>'; }).join('') + '</div></div>' +
            '<button type="button" class="ap-btn ap-btn-sm ap-btn-danger" data-revoke-token="' + esc(token.id) + '">撤销</button></div>';
    }).join('') : '<div class="ap-empty"><strong>还没有 API 密钥</strong><p>创建后可交给 AI 工具读取资产；需要操作时再勾选对应权限。</p></div>';
}

function hideToken() {
    $('sp-token-value').textContent = '';
    $('sp-token-usage').textContent = '';
    $('sp-token-once').classList.add('hidden');
    $('sp-token-password').value = '';
}

function load() {
    Promise.all([api('/api/settings'), api('/api/tokens')]).then(function (results) {
        var result = results[0], authorization = results[1];
        $('sp-user').value = result.admin_user;
        $('sp-admin').textContent = result.admin_user;
        $('sp-timeout').value = result.http.timeout;
        $('sp-connect-timeout').value = result.http.connect_timeout;
        $('sp-proxy-state').textContent = result.http.proxy_configured ? '当前代理：' + result.http.proxy_label + '（凭据不回显）' : '当前使用直接连接';
        $('sp-scopes').innerHTML = Object.entries(authorization.scopes).map(function (entry) {
            var scope = entry[0], label = entry[1];
            return '<label class="ap-check"><input type="checkbox" name="api_scope" value="' + esc(scope) + '"' +
                (['assets:read', 'dns:read'].includes(scope) ? ' checked' : '') + '> <span>' + esc(label) +
                '<small style="display:block;color:var(--muted);font-size:11px">' + esc(scope) + '</small></span></label>';
        }).join('');
        renderTokens(authorization.tokens);
        $('sp-curl-example').textContent = 'curl "' + window.location.origin + '/api/domains/screen" \\\n  -H "Authorization: Bearer $WORKSPACE_API_TOKEN"';
        // 大屏刷新配置回填
        var screen = result.screen || {};
        $('sp-screen-auto').checked = !!screen.auto_refresh;
        $('sp-screen-seconds').value = screen.refresh_seconds || 300;
    }).catch(function (error) { toast(error.message, 'error'); });
}

/* ---- 大屏自动刷新 ---- */

$('sp-screen-form').addEventListener('submit', function (event) {
    event.preventDefault();
    var form = event.currentTarget, button = form.querySelector('button[type="submit"]');
    errorFor(form); button.disabled = true;
    api('/api/settings/screen', { method: 'PUT', body: {
        auto_refresh: $('sp-screen-auto').checked,
        refresh_seconds: Number($('sp-screen-seconds').value),
    } }).then(function (r) {
        toast('大屏刷新配置已保存（' + (r.screen.auto_refresh ? '自动 · ' + r.screen.refresh_seconds + ' 秒' : '手动') + '）');
    }).catch(function (error) { errorFor(form, error.message); })
      .finally(function () { button.disabled = false; });
});

/* ---- HTTP 与登录信息 ---- */

$('sp-http-form').addEventListener('submit', function (event) {
    event.preventDefault();
    var form = event.currentTarget, button = form.querySelector('button[type="submit"]');
    errorFor(form); button.disabled = true;
    api('/api/settings/http', { method: 'PUT', body: {
        proxy: $('sp-proxy').value.trim(),
        clear_proxy: $('sp-clear-proxy').checked,
        timeout: Number($('sp-timeout').value),
        connect_timeout: Number($('sp-connect-timeout').value),
    } }).then(function () {
        $('sp-proxy').value = ''; $('sp-clear-proxy').checked = false;
        toast('网络配置已保存'); load();
    }).catch(function (error) { errorFor(form, error.message); })
      .finally(function () { button.disabled = false; });
});

$('sp-auth-form').addEventListener('submit', function (event) {
    event.preventDefault();
    var form = event.currentTarget, button = form.querySelector('button[type="submit"]');
    errorFor(form);
    if ($('sp-new-password').value !== $('sp-confirm-password').value) { errorFor(form, '两次输入的新密码不一致'); return; }
    button.disabled = true;
    api('/api/auth/credentials', { method: 'PUT', body: {
        current_password: $('sp-current-password').value,
        user: $('sp-user').value.trim(),
        password: $('sp-new-password').value,
    } }).then(function () {
        toast('登录信息已更新，请使用新账号密码登录');
        window.location.href = '/'; // 服务端已注销会话，回登录页
    }).catch(function (error) { errorFor(form, error.message); button.disabled = false; });
});

/* ---- 清除本机记住的密码（公用设备）：与 login.js 的 storageKey 保持一致 ---- */

var REMEMBERED_KEY = 'asset-workspace-remembered-login';
$('sp-forget-remembered').addEventListener('click', function () {
    var saved = null;
    try { saved = localStorage.getItem(REMEMBERED_KEY); } catch (e) { /* localStorage 不可用视为未保存 */ }
    if (!saved) { toast('本机没有保存的账号密码'); return; }
    confirmDialog({
        title: '清除本机记住的密码',
        message: '将删除此浏览器自动填入的管理员账号与密码，下次登录需手动输入。云账号凭据与 API 密钥不受影响。',
        confirmText: '清除',
        danger: true,
    }).then(function (ok) {
        if (!ok) { return; }
        try { localStorage.removeItem(REMEMBERED_KEY); toast('已清除本机记住的密码'); }
        catch (e) { toast('浏览器不允许清除本地存储', 'error'); }
    });
});

/* ---- API 密钥 ---- */

$('sp-token-form').addEventListener('submit', function (event) {
    event.preventDefault();
    var form = event.currentTarget, button = form.querySelector('button[type="submit"]');
    var password = $('sp-token-password').value;
    errorFor(form); hideToken(); button.disabled = true;
    api('/api/tokens', { method: 'POST', body: {
        name: $('sp-token-name').value.trim(),
        current_password: password,
        expires_in_days: Number($('sp-token-days').value),
        scopes: Array.prototype.map.call(form.querySelectorAll('[name="api_scope"]:checked'), function (input) { return input.value; }),
    } }).then(function (result) {
        $('sp-token-value').textContent = result.token;
        $('sp-token-usage').textContent = formatTokenUsage(result.ai_usage || {});
        $('sp-token-once').classList.remove('hidden');
        api('/api/tokens').then(function (list) { renderTokens(list.tokens); });
        toast('API 密钥已创建，请保存本次显示的值');
    }).catch(function (error) { errorFor(form, error.message); })
      .finally(function () { button.disabled = false; $('sp-token-password').value = ''; });
});

/** 把签发响应的 ai_usage 组装成可整段复制的接入材料文本。 */
function formatTokenUsage(usage) {
    var lines = ['认证头：' + (usage.header || 'Authorization: Bearer <token>'),
        '自包含接入文档（一次请求完成接入）：' + (usage.bootstrap_url || '/api/ai/bootstrap.md'),
        '示例：'];
    return lines.concat(usage.examples || []).join('\n');
}

$('sp-token-copy').addEventListener('click', function () {
    if ($('sp-token-value').textContent) { copyText($('sp-token-value').textContent, '密钥已复制'); }
});
$('sp-token-copy-usage').addEventListener('click', function () {
    var text = $('sp-token-usage').textContent;
    if (text) { copyText(text, 'AI 接入材料已复制，可直接发给任何 AI'); }
});
$('sp-token-dismiss').addEventListener('click', hideToken);

$('sp-token-list').addEventListener('click', function (event) {
    var button = event.target.closest('[data-revoke-token]');
    if (!button) { return; }
    confirmDialog({ title: '撤销 API 密钥', message: '使用此密钥的 AI 工具将无法继续调用，可创建新密钥重新授权。', danger: true, confirmText: '撤销密钥' })
        .then(function (ok) {
            if (!ok) { return; }
            button.disabled = true;
            api('/api/tokens/' + encodeURIComponent(button.dataset.revokeToken), { method: 'DELETE' })
                .then(function () { hideToken(); load(); toast('密钥已撤销'); })
                .catch(function (error) { toast(error.message, 'error'); button.disabled = false; });
        });
});

/* ---- 云账号 ---- */

function renderStatus(data) {
    if (!data.ok) {
        return '<span class="is-fail">✗ 失败</span><span class="detail">' + esc(data.error || '未知错误') + '</span>';
    }
    var r = data.result || {};
    var samples = (r.samples && r.samples.length) ? '　样例: ' + esc(r.samples.join(', ')) : '';
    return '<span class="is-ok">✓ 成功 · ' + (r.count != null ? r.count + ' 个域名' : '') +
        ' · ' + (r.latency_ms != null ? r.latency_ms + 'ms' : '') + '</span>' + samples;
}

function addResultItem(data) {
    var section = $('sp-result-section'), list = $('sp-result-list');
    section.hidden = false;
    var body = data.ok
        ? '✓ 成功 · 域名数 ' + ((data.result || {}).count != null ? data.result.count : '-') + ' · 延迟 ' + ((data.result || {}).latency_ms != null ? data.result.latency_ms + 'ms' : '-') +
            ((data.result || {}).samples && data.result.samples.length ? '\n样例: ' + data.result.samples.join(', ') : '')
        : '✗ 失败\n' + (data.error || '未知错误');
    var item = document.createElement('div');
    item.className = 'sp-result';
    item.innerHTML = '<span>' + (data.ok ? '✅' : '❌') + '</span><div><strong>' + esc(data.name || data.account || '') + '</strong>' +
        '<span class="meta">' + new Date().toLocaleTimeString() + '</span><pre>' + esc(body) + '</pre></div>';
    list.prepend(item);
}

function renderCards() {
    var grid = $('sp-accounts');
    if (!accounts.length) {
        grid.innerHTML = '<div class="ap-empty"><strong>添加第一个云账号</strong><p>接入后可同步域名到大屏；服务器 IP 也可在大屏 IP 视图手工登记。</p></div>';
        return;
    }
    grid.innerHTML = accounts.map(function (acc) {
        var badgeClass = acc.enabled === false ? 'b-gray' : 'b-' + esc(acc.provider);
        return '<div class="sp-acc" data-id="' + esc(acc.id) + '">' +
            '<div class="sp-acc-head"><strong>' + esc(acc.name) + '</strong>' +
            '<span class="ap-badge ' + badgeClass + '">' + (acc.enabled === false ? '已停用' : esc(providerNames[acc.provider] || acc.provider)) + '</span></div>' +
            '<div class="sp-acc-key">密钥 ' + esc(acc.key_masked) + '</div>' +
            (acc.note ? '<div class="sp-acc-note">' + esc(acc.note) + '</div>' : '') +
            '<div class="sp-acc-switch"><span>启用此云账号</span><input type="checkbox" role="switch" data-account-toggle="' + esc(acc.id) + '"' + (acc.enabled === false ? '' : ' checked') + '></div>' +
            '<div class="sp-acc-actions">' +
            '<button type="button" class="ap-btn ap-btn-sm ap-btn-primary" data-account-test="' + esc(acc.id) + '"' + (acc.enabled === false ? ' disabled' : '') + '>测试连接</button>' +
            '<button type="button" class="ap-btn ap-btn-sm" data-account-edit="' + esc(acc.id) + '">编辑配置</button>' +
            '<button type="button" class="ap-btn ap-btn-sm ap-btn-danger" data-account-delete="' + esc(acc.id) + '">删除</button>' +
            '</div><div class="sp-acc-status"><span class="is-pending">' + (acc.enabled === false ? '已停用' : '未测试') + '</span></div></div>';
    }).join('');
}

function loadAccounts() {
    var grid = $('sp-accounts');
    grid.innerHTML = '<div class="ap-empty"><p>加载中…</p></div>';
    api('/api/accounts').then(function (list) {
        accounts = Array.isArray(list) ? list : [];
        renderCards();
    }).catch(function (error) { grid.innerHTML = '<div class="ap-error">' + esc(error.message) + '</div>'; });
}

function accountById(id) {
    return accounts.find(function (item) { return item.id === id; }) || null;
}

function testAccount(id) {
    var card = document.querySelector('.sp-acc[data-id="' + id + '"]');
    if (!card) { return; }
    var status = card.querySelector('.sp-acc-status');
    var button = card.querySelector('[data-account-test]');
    button.disabled = true;
    status.innerHTML = '<span class="is-pending">测试中…</span>';
    api('/api/accounts/' + encodeURIComponent(id) + '/test', { method: 'POST' })
        .then(function (data) {
            status.innerHTML = renderStatus(data);
            addResultItem(data);
            var acc = accountById(id);
            button.disabled = !!acc && acc.enabled === false;
        })
        .catch(function (error) {
            status.innerHTML = '<span class="is-fail">测试失败</span><span class="detail">' + esc(error.message) + '</span>';
            button.disabled = false;
        });
}

$('sp-test-all').addEventListener('click', function () {
    var button = $('sp-test-all');
    button.disabled = true;
    accounts.forEach(function (acc) {
        if (acc.enabled === false) { return; }
        var card = document.querySelector('.sp-acc[data-id="' + acc.id + '"]');
        if (card) {
            card.querySelector('.sp-acc-status').innerHTML = '<span class="is-pending">测试中…</span>';
            card.querySelector('[data-account-test]').disabled = true;
        }
    });
    api('/api/test-all', { method: 'POST' }).then(function (results) {
        results.forEach(function (r) {
            var card = document.querySelector('.sp-acc[data-id="' + r.id + '"]');
            if (card) {
                card.querySelector('.sp-acc-status').innerHTML = renderStatus(r);
                var acc = accountById(r.id);
                card.querySelector('[data-account-test]').disabled = !!acc && acc.enabled === false;
            }
            addResultItem(r);
        });
    }).catch(function (error) {
        toast('测试失败：' + error.message, 'error');
    }).finally(function () { button.disabled = false; });
});

$('sp-accounts').addEventListener('change', function (event) {
    var input = event.target.closest('[data-account-toggle]');
    if (!input) { return; }
    var acc = accountById(input.dataset.accountToggle);
    if (!acc) { return; }
    input.disabled = true;
    api('/api/accounts/' + encodeURIComponent(acc.id), { method: 'PUT', body: { enabled: acc.enabled === false } })
        .then(function () { return loadAccounts(); })
        .then(function () { toast(acc.enabled === false ? '云账号已启用' : '云账号已停用'); })
        .catch(function (error) { input.checked = acc.enabled !== false; toast(error.message, 'error'); input.disabled = false; });
});

$('sp-accounts').addEventListener('click', function (event) {
    var edit = event.target.closest('[data-account-edit]');
    var del = event.target.closest('[data-account-delete]');
    var test = event.target.closest('[data-account-test]');
    if (test) { testAccount(test.dataset.accountTest); }
    if (edit) { openAccount(edit.dataset.accountEdit); }
    if (del) {
        var acc = accountById(del.dataset.accountDelete);
        if (!acc) { return; }
        confirmDialog({ title: '删除云账号', message: '删除“' + acc.name + '”的云账号配置？已有域名资产与缓存会保留，后续云端操作需要重新配置账号。', confirmText: '删除账号', danger: true })
            .then(function (ok) {
                if (!ok) { return; }
                del.disabled = true;
                api('/api/accounts/' + encodeURIComponent(acc.id), { method: 'DELETE' })
                    .then(function () { return loadAccounts(); })
                    .then(function () { toast('云账号已删除'); })
                    .catch(function (error) { toast(error.message, 'error'); del.disabled = false; });
            });
    }
});

function openAccount(id) {
    var account = id ? accountById(id) : null;
    if (id && !account) { toast('云账号不存在，请刷新列表', 'error'); return; }
    var provider = (account && account.provider) || 'aliyun';
    var overlay = document.createElement('div');
    overlay.className = 'ap-overlay';
    overlay.innerHTML = '<section class="ap-modal" role="dialog" aria-modal="true" aria-label="' + (account ? '编辑云账号' : '添加云账号') + '">' +
        '<div class="ap-modal-head"><h3>' + (account ? '编辑云账号' : '添加云账号') + '</h3><button type="button" class="ap-modal-close" aria-label="关闭">✕</button></div>' +
        '<form class="ap-form"><label>账号名称<input name="name" required maxlength="100" value="' + esc(account ? account.name : '') + '" placeholder="如：阿里云 · 生产账号"></label>' +
        '<label style="margin-bottom:6px">云商类型</label><div class="ap-tabs" id="sp-provider-tabs">' +
        Object.entries(providerNames).map(function (entry) {
            return '<button type="button" data-provider="' + entry[0] + '" aria-pressed="' + String(entry[0] === provider) + '"' + (account ? ' disabled' : '') + '>' + entry[1] + '</button>';
        }).join('') + '</div><div id="sp-cred-fields" style="margin-top:12px"></div>' +
        '<p class="ap-help">' + (account ? '密钥留空会保留已保存的值。停用仅停止云端操作，资产台账与缓存会保留。' : '凭据保存到服务器的私有配置文件，后续仅显示配置状态。') + '</p>' +
        '<label>备注<textarea name="note" rows="3" maxlength="2000" placeholder="账号用途或其他说明">' + esc(account ? account.note || '' : '') + '</textarea></label>' +
        '<label class="ap-check"><input type="checkbox" name="enabled"' + (account && account.enabled === false ? '' : ' checked') + '> 启用此云账号</label>' +
        '<p class="ap-error hidden" role="alert"></p>' +
        '<div class="ap-modal-foot"><button type="button" class="ap-btn" data-cancel>取消</button><button type="submit" class="ap-btn ap-btn-primary">保存配置</button></div>' +
        '</form></section>';
    document.body.appendChild(overlay);
    var form = overlay.querySelector('form');

    function fields() {
        overlay.querySelector('#sp-cred-fields').innerHTML = providerFields[provider].map(function (field) {
            var key = field[0], label = field[1];
            var configured = account && account.credentials && account.credentials[key] && account.credentials[key].configured;
            return '<label>' + label + '<input name="' + key + '" type="password" autocomplete="new-password" spellcheck="false"' + (account ? '' : ' required') +
                ' placeholder="' + (configured ? '已配置，留空保留' : '输入 ' + label) + '"></label>';
        }).join('');
        if (provider === 'godaddy') {
            overlay.querySelector('#sp-cred-fields').insertAdjacentHTML('beforeend',
                '<label>API 地址<input name="api_base_url" type="url" maxlength="2048" value="' + esc((account && account.api_base_url) || 'https://api.godaddy.com') + '" placeholder="https://api.godaddy.com"></label>' +
                '<p class="ap-help">默认使用 GoDaddy 官方地址；可修改为可信的 HTTPS 转发地址。密钥会发送至该地址。</p>');
        }
        overlay.querySelectorAll('[data-provider]').forEach(function (button) {
            button.setAttribute('aria-pressed', String(button.dataset.provider === provider));
        });
    }
    fields();

    var close = function () { overlay.remove(); };
    // 表单弹窗只能显式关闭（✕ / 取消）：点遮罩与 Escape 极易误触，辛苦填写的凭据会全部丢失。
    overlay.querySelector('.ap-modal-close').addEventListener('click', close);
    overlay.querySelector('[data-cancel]').addEventListener('click', close);
    overlay.addEventListener('click', function (event) {
        var tab = event.target.closest('[data-provider]');
        if (tab && !account) { provider = tab.dataset.provider; fields(); }
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var button = form.querySelector('[type="submit"]');
        button.disabled = true; errorFor(form);
        var body = { provider: provider, name: form.elements.name.value.trim(), enabled: form.elements.enabled.checked, note: form.elements.note.value.trim() };
        providerFields[provider].forEach(function (field) { body[field[0]] = form.elements[field[0]].value; });
        if (provider === 'godaddy') { body.api_base_url = form.elements.api_base_url.value.trim(); }
        api('/api/accounts' + (account ? '/' + encodeURIComponent(account.id) : ''), { method: account ? 'PUT' : 'POST', body: body })
            .then(function () { close(); return loadAccounts(); })
            .then(function () { toast('云账号配置已保存，可测试连接或到大屏更新数据'); })
            .catch(function (error) { errorFor(form, error.message); button.disabled = false; });
    });
    form.elements.name.focus();
}

$('sp-account-add').addEventListener('click', function () { openAccount(null); });

/* ---- 初始化：从 hash 恢复面板状态 ---- */

A.trackHeadHeight('.ap-head'); // 子标签吸顶依赖头部实际高度
var initial = readHash();
selectTab(initial ? initial.tab : 'accounts', initial ? initial.sub : null);
load();
})();
