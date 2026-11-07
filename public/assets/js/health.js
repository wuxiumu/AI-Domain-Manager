'use strict';

/* 健康看板只读取后端证据；没有检测数据时不推断资产健康。 */
window.HealthWorkbench = (() => {
    let snapshot = null;
    let issues = null;
    let issueFilter = 'all';
    let loading = null;
    let running = false;
    let stopped = false;
    let detailId = null;
    let detailReturnFocus = null;
    let detailFocused = false;
    /* 浏览器侧探测：走管理员本机网络（含系统代理），与检查机直连视角互补。不写入平台证据链。 */
    const browserProbeResults = new Map();
    const browserProbing = new Set();
    /* 按资产历史：列表出口不内嵌，详情/复制日志按需读取并缓存；每次 load() 失效。 */
    const historyCache = new Map();
    async function fetchHistory(id) {
        if (!historyCache.has(id)) {
            try {
                const data = await api('/api/health/history?asset_id=' + encodeURIComponent(id));
                historyCache.set(id, data.history || []);
            } catch {
                historyCache.set(id, []);
            }
        }
        return historyCache.get(id);
    }
    async function browserProbeFetch(url, timeoutMs = 8000) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs);
        const started = performance.now();
        try {
            await fetch(url + (url.includes('?') ? '&' : '?') + '_aw_probe=' + Date.now(), {mode: 'no-cors', cache: 'no-store', signal: controller.signal});
            return {reachable: true, ms: Math.round(performance.now() - started)};
        } catch {
            return {reachable: false, ms: Math.round(performance.now() - started)};
        } finally {
            clearTimeout(timer);
        }
    }
    async function runBrowserProbe(asset) {
        const url = 'http://' + (asset.ip.includes(':') ? '[' + asset.ip + ']' : asset.ip) + '/';
        browserProbing.add(asset.id);
        if (detailId === asset.id) renderDetail(asset.id);
        const result = await browserProbeFetch(url);
        browserProbing.delete(asset.id);
        const entry = {...result, at: new Date().toLocaleString('sv-SE').replace('T', ' ')};
        if (result.reachable) {
            // 前端可达也计入平台记录：服务端标注来源 browser，状态翻为正常并落盘。
            try {
                await api('/api/health/ips/' + encodeURIComponent(asset.id) + '/browser-probe', {method: 'POST', body: JSON.stringify({reachable: true, ms: result.ms})});
                entry.persisted = true;
                await load();
            } catch (err) {
                entry.persistError = err.message;
                toast('浏览器探测结果保存失败：' + err.message, 'error', 8000);
            }
        }
        browserProbeResults.set(asset.id, entry);
        if (detailId === asset.id) renderDetail(asset.id);
    }
    function browserProbeHtml(asset) {
        if (browserProbing.has(asset.id)) return '<p class="hc-browser-probe">浏览器检测中…（最长 8 秒）</p>';
        const r = browserProbeResults.get(asset.id);
        if (!r) return '';
        const state = r.reachable ? (r.persisted ? '浏览器网络可达，已计入平台记录' : '浏览器网络可达，有响应') : '浏览器网络也超时（检查机与浏览器均不可达）';
        const tail = r.persisted ? '来源已标注 browser，检查机直连证据保留为历史' : '走你本机网络/系统代理，与检查机直连视角可能不同';
        const warn = r.persistError ? ` · 保存失败：${h(r.persistError)}` : '';
        return `<p class="hc-browser-probe" data-reachable="${r.reachable ? '1' : '0'}"><span class="info-dot" aria-hidden="true">i</span> ${h(state)} · ${r.ms} ms · ${h(r.at)} · ${h(tail)}${warn}</p>`;
    }
    const selectedDomains = new Set();
    const labels = { healthy: '正常', unhealthy: 'HTTP 异常', stale: '结果过时', unchecked: '未检查' };
    const issueLabels = { unhealthy: 'HTTP 异常', stale: '结果过时', expiring: '即将到期', cert_expiring: '证书临期' };
    const severityBadges = { P0: 'b-red', P1: 'b-amber', P2: '' };
    const httpsStateLabels = { fail: 'HTTPS 异常', unknown: 'HTTPS 未确认', stale: '证据过时', pending: 'DNS 已更新待复检', expiring: '证书临期' };
    const h = value => escapeHtml(value ?? '');
    const getHealth = asset => {
        const health = asset.health || {status:'unchecked'};
        if (health.checked_at && Date.now() - Date.parse(health.checked_at.replace(' ','T') + '+08:00') >= 86400000) return {...health,status:'stale'};
        return health;
    };
    const pill = status => `<span class="hc-status" data-status="${h(status)}"><i aria-hidden="true"></i>${h(labels[status] || status || '未检查')}</span>`;
    const timeText = time => time ? h(time) : '尚未检查';
    const locationText = loc => loc ? [loc.country, loc.region, loc.city].filter(Boolean).join(' · ') || '未提供位置' : '归属地未查询';
    const sourceText = asset => asset.kind === 'domain' ? (asset.provider_name || '域名资产') : asset.source === 'manual' ? '手工登记' : 'DNS 解析发现';
    const domainNames = asset => [...new Set([...(asset.linked_domains || []).map(item => typeof item === 'string' ? item : item.domain || item.name || ''), ...(asset.bound_domains || []).map(id => snapshot?.assets.find(a => a.id === id)?.name || '')])].filter(Boolean);
    const evidenceLabel = c => labels[c.status] || ({ok: '正常', fail: '失败', skipped: '未执行', warning: '需关注'})[c.status] || c.status || '未知';

    /* 把资产的问题与检查证据编译成纯文本日志，粘贴给 AI 即可继续排查。 */
    function assetLogText(asset, issue = null) {
        const health = getHealth(asset);
        const lines = ['# 资产检查日志'];
        lines.push(`- 资产：${asset.name}${asset.kind === 'ip' ? `（${asset.ip}）` : ''}`);
        lines.push(`- 类型与来源：${asset.kind === 'domain' ? '域名' : 'IP / 服务器'} · ${sourceText(asset)}`);
        lines.push(`- 当前状态：${labels[health.status] || health.status || '未检查'} · 检查时间：${health.checked_at || '尚未检查'}`);
        if (issue) lines.push(`- 问题级别：${issue.severity}（${issueLabels[issue.type] || issue.type}）`, `- 问题摘要：${issue.summary}`);
        lines.push(`- 检查目标：${asset.kind === 'domain' ? 'http:// 与 https://' + asset.name : 'http://' + (asset.ip.includes(':') ? '[' + asset.ip + ']' : asset.ip) + '/'}`);
        if (asset.expires_at) lines.push(`- 到期日期：${asset.expires_at}`);
        if (health.latency_ms != null) lines.push(`- 延迟：${health.latency_ms} ms`);
        if (health.http_code != null) lines.push(`- HTTP 状态码：${health.http_code}`);
        if ((health.resolved_ips || []).length) lines.push(`- 解析 IP：${health.resolved_ips.join('、')}（可能是 CDN）`);
        if (health.message) lines.push(`- 检查消息：${health.message}`);
        if ((health.checks || []).length) {
            lines.push('', '## 检查证据');
            for (const c of health.checks) lines.push(`- [${evidenceLabel(c)}] ${c.label || c.name || c.type || '检查项'}：${c.message || c.error || '—'}`);
        }
        const history = historyCache.get(asset.id) ?? asset.history ?? health.history ?? [];
        if (history.length) {
            lines.push('', '## 最近检查记录');
            for (const item of history.slice(0, 10)) lines.push(`- ${item.checked_at || '时间未知'} · ${labels[item.status || item.last_status] || '未检查'} · ${item.message || ''}${item.latency_ms != null ? `（${item.latency_ms} ms${item.http_code != null ? ' · HTTP ' + item.http_code : ''}）` : ''}`);
        }
        lines.push('', `未检查与结果超过 24 小时的资产不计为正常。生成时间：${new Date().toLocaleString('sv-SE').replace('T', ' ')}`);
        return lines.join('\n');
    }

    async function load(force = false) {
        if (loading) return loading;
        loading = (async () => {
            try {
                const [snap, issueData, httpsData] = await Promise.all([
                    api('/api/health', {refresh: force === true}),
                    api('/api/issues').catch(() => null),
                    api('/api/https-review').catch(() => null),
                ]);
                snapshot = snap;
                historyCache.clear();
                if (issueData) issues = issueData;
                if (httpsData) { httpsReview = httpsData; renderHttpsReview(); }
                render();
                return snapshot;
            } catch (err) {
                const row = `<tr><td colspan="7"><div class="hc-empty"><strong>健康数据暂时不可用</strong><p>${h(err.message)}</p><button class="btn btn-sm" data-health-action="refresh">重试</button></div></td></tr>`;
                $('#health-table tbody').innerHTML = row;
                $('#ip-table tbody').innerHTML = row;
                $('#health-count').textContent = '加载失败';
                $('#health-empty').classList.add('hidden');
                if (!snapshot) $('#health-stats').innerHTML = '<div class="hc-error">未取得数据，暂不显示资产统计。</div>';
                return null;
            } finally {
                loading = null;
            }
        })();
        return loading;
    }

    /* 问题中心：与 AI 共用的分级问题清单，一眼看到全部待处理事项。 */
    /* 每个问题按类型给出直达操作：异常域名直达解析，到期域名直达台账，日志可复制给 AI 协助排查。 */
    function issueJumpActions(issue) {
        if (issue.kind !== 'domain') return '';
        const dns = issue.type === 'unhealthy' && issue.provider && issue.provider !== 'manual'
            ? `<button class="btn btn-sm btn-link" data-issue-dns data-domain="${h(issue.name)}" data-provider="${h(issue.provider)}" title="打开该域名的解析记录管理">管理解析</button>` : '';
        const gotoDomain = issue.type === 'expiring'
            ? `<button class="btn btn-sm btn-link" data-issue-goto-domain data-domain="${h(issue.name)}" title="在域名管理中筛选该域名">去域名管理</button>` : '';
        return dns + gotoDomain;
    }
    function renderProblemStrip() {
        const strip = $('#dash-problem-strip');
        if (!strip) return;
        const counts = issues?.counts || {};
        const list = issues?.issues || [];
        if (!counts.total || !list.length) {
            strip.classList.add('hidden');
            strip.innerHTML = '';
            return;
        }
        strip.classList.remove('hidden');
        const top = list.slice(0, 5);
        strip.innerHTML = `<div class="dash-problem-head"><span class="dash-problem-title"><svg class="icon" aria-hidden="true"><use href="#icon-alert"/></svg><strong>${counts.total} 项待处理</strong></span><span class="muted">P0 异常 ×${counts.unhealthy || 0} · P1 过时 ×${counts.stale || 0} · P2 到期 ×${counts.expiring || 0} · 看板每分钟自动刷新</span><button type="button" class="btn btn-sm" data-goto-tab="issues">进入问题中心 →</button></div>`
            + top.map(issue => `<div class="dash-problem-item"><span class="badge ${severityBadges[issue.severity] || ''}">${issue.severity}</span><small class="hc-subline">${issueLabels[issue.type] || issue.type}</small><button class="hc-asset-link" data-health-action="detail" data-id="${h(issue.asset_id)}">${h(issue.name)}</button><span class="hc-message" title="${h(issue.summary)}">${h(issue.summary)}</span><span class="dash-problem-actions"><button class="btn btn-sm" data-health-action="check" data-id="${h(issue.asset_id)}" ${running ? 'disabled' : ''}>检查</button>${issueJumpActions(issue)}<button class="btn btn-sm btn-link" data-issue-copy data-id="${h(issue.asset_id)}" title="复制问题与检查日志，粘贴给 AI 协助排查">复制日志</button></span></div>`).join('')
            + (list.length > top.length ? `<div class="dash-problem-more muted">还有 ${list.length - top.length} 项，请在问题中心查看 →</div>` : '');
    }
    function renderIssues() {
        if (!issues) return;
        const counts = issues.counts || {};
        document.title = counts.total ? `(${counts.total}) 资产工作台` : '资产工作台 · Asset Workspace';
        $('#issues-count').textContent = counts.total ? `${counts.total} 项待处理` : '全部正常';
        $('#issues-sub').textContent = counts.total
            ? `P0 立即处理 ×${counts.unhealthy} · P1 尽快处理 ×${counts.stale} · P2 择期处理 ×${counts.expiring}${issues.truncated ? '（清单已截断）' : ''}`
            : '没有 HTTP 异常、结果过时或即将到期的资产。HTTPS 证书跟进见「HTTPS 跟进」页。';
        for (const key of ['total', 'unhealthy', 'stale', 'expiring']) {
            const el = $('#issues-' + key);
            if (el) el.textContent = counts[key] || 0;
        }
        const dashCount = $('#dash-issues-count');
        if (dashCount) dashCount.textContent = counts.total ? `${counts.total} 项待处理` : '全部正常';
        const dashSub = $('#dash-issues-sub');
        if (dashSub) dashSub.textContent = counts.total
            ? `P0 HTTP 异常 ×${counts.unhealthy} · P1 过时 ×${counts.stale} · P2 到期 ×${counts.expiring}`
            : '没有待处理问题，继续保持。';
        renderProblemStrip();
        document.querySelectorAll('[data-issue-filter]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.issueFilter === issueFilter)));
        const list = (issues.issues || []).filter(i => issueFilter === 'all' || i.type === issueFilter);
        $('#issues-list').innerHTML = list.length ? `<table><thead><tr><th>级别</th><th>问题</th><th>资产</th><th>时间</th><th>操作</th></tr></thead><tbody>${list.map(issue => {
            const badge = `<span class="badge ${severityBadges[issue.severity] || ''}">${issue.severity}</span><small class="hc-subline">${issueLabels[issue.type] || issue.type}</small>`;
            const time = issue.due ? `到期 ${h(issue.due)}` : timeText(issue.since);
            const actions = (issue.actions || []).map(action => action.type === 'check'
                ? `<button class="btn btn-sm" data-health-action="check" data-id="${h(issue.asset_id)}" ${running ? 'disabled' : ''}>${h(action.label)}</button>`
                : `<button class="btn btn-sm btn-link" data-health-action="detail" data-id="${h(issue.asset_id)}">${h(action.label)}</button>`).join('') + issueJumpActions(issue)
                + `<button class="btn btn-sm btn-link" data-issue-copy data-id="${h(issue.asset_id)}" title="复制问题与检查日志，粘贴给 AI 协助排查">复制日志</button>`;
            const dns = issue.dns_path ? `<small class="hc-subline">${h((issue.ips || []).join(' · '))}</small>` : '';
            const url = issue.url ? `<a class="hc-url" href="${h(issue.url)}" target="_blank" rel="noopener noreferrer" title="打开问题页面核对">${h(issue.url)}</a>` : '';
            return `<tr><td>${badge}</td><td><span class="hc-message" title="${h(issue.summary)}">${h(issue.summary)}</span>${url}${dns}</td><td><button class="hc-asset-link" data-health-action="detail" data-id="${h(issue.asset_id)}">${h(issue.name)}</button><small class="hc-subline">${issue.kind === 'domain' ? '域名' : 'IP / 服务器'}</small></td><td class="hc-time">${time}</td><td class="row-actions">${actions}</td></tr>`;
        }).join('')}</tbody></table>` : '<div class="hc-empty">没有待处理问题，全部正常。<button class="btn btn-link" data-issue-filter-reset>查看全部类型</button></div>';
    }
    /* 问题行内的跳转与复制：域名异常 → 解析管理，到期 → 域名台账，日志 → 剪贴板。 */
    document.addEventListener('click', async event => {
        const dns = event.target.closest('[data-issue-dns]');
        if (dns) { window.WorkspaceUI?.closeDialog(); openRecordsModal(dns.dataset.domain, dns.dataset.provider); return; }
        const gotoDomain = event.target.closest('[data-issue-goto-domain]');
        if (gotoDomain) { navigateToDomains({q: gotoDomain.dataset.domain}); return; }
        const copyIssue = event.target.closest('[data-issue-copy]');
        if (copyIssue) {
            const issue = (issues?.issues || []).find(i => i.asset_id === copyIssue.dataset.id);
            const asset = (snapshot?.assets || []).find(a => a.id === copyIssue.dataset.id);
            if (asset) copyText(assetLogText(asset, issue), '问题日志已复制，可直接粘贴给 AI 助手');
            return;
        }
        const copyHistory = event.target.closest('[data-copy-history]');
        if (copyHistory) {
            const asset = (snapshot?.assets || []).find(a => a.id === copyHistory.dataset.copyHistory);
            if (asset) {
                await fetchHistory(asset.id);
                copyText(assetLogText(asset), '检查日志已复制，可直接粘贴给 AI 助手');
            }
        }
    });

    $('#issue-filters').addEventListener('click', event => {
        const button = event.target.closest('[data-issue-filter]');
        if (!button) return;
        issueFilter = issueFilter === button.dataset.issueFilter ? 'all' : button.dataset.issueFilter;
        renderIssues();
    });
    document.addEventListener('click', event => { if (event.target.closest('[data-issue-filter-reset]')) { issueFilter = 'all'; renderIssues(); } });
    $('#issues-check-all').addEventListener('click', () => {
        const ids = [...new Set((issues?.issues || []).filter(i => issueFilter === 'all' || i.type === issueFilter).map(i => i.asset_id))];
        if (!ids.length) { toast('没有待处理问题', 'info'); return; }
        check(ids);
    });
    $('#issues-refresh').addEventListener('click', () => load(true));

    /* HTTPS 跟进：证书与 HTTPS 可达性专项清单，不影响可达性判定。 */
    let httpsReview = null;
    let httpsFilter = 'all';
    async function loadHttpsReview() {
        try {
            httpsReview = await api('/api/https-review');
        } catch (err) {
            httpsReview = { ok: false, error: err.message, counts: {}, items: [] };
        }
        renderHttpsReview();
        return httpsReview;
    }
    function renderHttpsReview() {
        const counts = httpsReview?.counts || {};
        $('#https-count').textContent = counts.total ? `${counts.total} 项待跟进` : '无需跟进';
        const dashHttps = $('#dash-https-count');
        if (dashHttps) dashHttps.textContent = counts.total ? `${counts.total} 项待跟进` : '无需跟进';
        const dashHttpsSub = $('#dash-https-sub');
        if (dashHttpsSub) dashHttpsSub.textContent = counts.total
            ? `HTTPS 异常 ×${counts.fail || 0} · 未确认 ×${counts.unknown || 0} · 待复检 ×${counts.pending || 0}`
            : '证书与 HTTPS 可达性均正常';
        for (const key of ['total', 'fail', 'unknown', 'stale', 'pending', 'expiring']) {
            const el = $('#https-' + key);
            if (el) el.textContent = counts[key] || 0;
        }
        document.querySelectorAll('[data-https-filter]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.httpsFilter === httpsFilter)));
        const list = (httpsReview?.items || []).filter(i => httpsFilter === 'all' || i.state === httpsFilter);
        const certDaysLeft = expiresAt => expiresAt ? Math.ceil((new Date(expiresAt + 'T23:59:59') - Date.now()) / 86400000) : null;
        $('#https-list').innerHTML = list.length ? `<table><thead><tr><th>域名</th><th>跟进状态</th><th>证据</th><th>HTTP</th><th>检查时间</th><th>操作</th></tr></thead><tbody>${list.map(item => {
            const stateBadge = `<span class="badge ${item.state === 'fail' ? 'b-red' : item.state === 'stale' || item.state === 'expiring' ? 'b-amber' : ''}">${httpsStateLabels[item.state] || item.state}</span>`;
            const http = item.http_status === 'healthy' ? '<span class="badge b-green">HTTP 正常</span>' : item.http_status === 'unhealthy' ? '<span class="badge b-red">HTTP 异常</span>' : '<span class="muted">—</span>';
            const url = item.url ? `<a class="hc-url" href="${h(item.url)}" target="_blank" rel="noopener noreferrer" title="打开页面核对 HTTPS">${h(item.url)}</a>` : '';
            const cert = item.cert_expires_at
                ? `<small class="hc-subline">证书到期：${h(item.cert_expires_at)} · 剩余 ${Math.max(0, certDaysLeft(item.cert_expires_at))} 天${item.cert_issuer ? ' · ' + h(item.cert_issuer) : ''}</small>`
                : '';
            return `<tr><td><button class="hc-asset-link" data-health-action="detail" data-id="${h(item.asset_id)}">${h(item.name)}</button><small class="hc-subline">${h(item.provider || '')}</small></td><td>${stateBadge}</td><td><span class="hc-message" title="${h(item.https_message || '')}">${h(item.https_message || '尚无 HTTPS 证据')}</span>${url}${cert}</td><td>${http}</td><td class="hc-time">${timeText(item.checked_at)}</td><td class="row-actions"><button class="btn btn-sm" data-https-check="${h(item.asset_id)}" ${running ? 'disabled' : ''}>重新检查</button></td></tr>`;
        }).join('')}</tbody></table>` : `<div class="hc-empty">没有需要跟进的 HTTPS 事项，全部确认正常。<button class="btn btn-link" data-https-filter-reset>查看全部状态</button></div>`;
    }
    $('#https-filters').addEventListener('click', event => {
        const button = event.target.closest('[data-https-filter]');
        if (!button) return;
        httpsFilter = httpsFilter === button.dataset.httpsFilter ? 'all' : button.dataset.httpsFilter;
        renderHttpsReview();
    });
    document.addEventListener('click', event => { if (event.target.closest('[data-https-filter-reset]')) { httpsFilter = 'all'; renderHttpsReview(); } });
    $('#https-refresh').addEventListener('click', () => loadHttpsReview());
    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-https-check]');
        if (!button || running) return;
        button.disabled = true;
        try { await check([button.dataset.httpsCheck]); } finally { await loadHttpsReview(); }
    });

    function render() {
        if (!snapshot) return;
        renderIssues();
        const assets = snapshot.assets || [];
        const kind = $('#health-kind').value || 'domain';
        const selected = assets.filter(a => a.kind === kind);
        const summary = window.AssetViewStats.calculate(selected, a => getHealth(a).status);
        $('#health-type-domain-count').textContent = assets.filter(a => a.kind === 'domain').length;
        $('#health-type-ip-count').textContent = assets.filter(a => a.kind === 'ip').length;
        document.querySelectorAll('[data-health-type]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.healthType === kind)));
        $('#health-dns-toolbar').classList.toggle('hidden', kind !== 'domain');
        $('#health-overview').innerHTML = window.AssetViewStats.render(selected, kind, a => getHealth(a).status);
        const cards = [
            {label:kind === 'domain' ? '主域名总数' : 'IP 检查目标', value:summary.total, filter:'', category:'status', text:'当前资产类型全部目标'},
            ...['healthy','unhealthy','stale','unchecked'].map(status => ({label:labels[status], value:summary[status], filter:status, category:'status', text:'最近一次检查结果'})),
        ];
        $('#health-stats').innerHTML = cards.map(c => `<button type="button" class="hc-stat-card" data-filter-category="${c.category}" data-filter="${c.filter}" data-status="${c.filter}"><span class="hc-stat-label">${c.label}<span aria-hidden="true">↗</span></span><strong class="hc-stat-value">${c.value}</strong><span class="hc-stat-note">${c.text}</span></button>`).join('');
        const statuses = ['healthy', 'unhealthy', 'stale', 'unchecked'];
        $('#health-status-bar').innerHTML = `<div class="hc-bar" aria-label="健康状态分布">${statuses.map(status => `<span data-status="${status}" style="width:${summary.total ? summary[status] / summary.total * 100 : 0}%" title="${labels[status]} ${summary[status]}"></span>`).join('')}</div><div class="hc-legend">${statuses.map(status => `<button type="button" data-filter-category="status" data-filter="${status}">${pill(status)}<b>${summary[status]}</b></button>`).join('')}<span class="muted">仅统计当前选择的资产类型</span></div>`;
        window.DomainDetails?.renderSummary(snapshot);
        const availableIds = new Set(assets.filter(a=>a.kind==='domain').map(a=>a.id));
        for (const id of selectedDomains) if(!availableIds.has(id)) selectedDomains.delete(id);
        renderRows();
        renderIPs();
        if (detailId) renderDetail(detailId);
        updateBusy();
    }

    function filteredAssets() {
        const query = $('#health-query').value.trim().toLowerCase();
        const kind = $('#health-kind').value;
        const status = $('#health-status').value;
        return (snapshot?.assets || []).filter(a => (!kind || a.kind === kind) && (!status || getHealth(a).status === status) && (!query || [a.name, a.ip, a.provider_name, ...domainNames(a), ...(getHealth(a).resolved_ips || []), a.note].join(' ').toLowerCase().includes(query)));
    }

    function renderRows() {
        window.ChoiceTabs?.sync();
        const rows = filteredAssets();
        renderSelection();
        const order = { unhealthy: 0, stale: 1, unchecked: 2, healthy: 3 };
        rows.sort((a, b) => (order[getHealth(a).status] ?? 4) - (order[getHealth(b).status] ?? 4) || a.name.localeCompare(b.name));
        $('#health-count').textContent = `${rows.length} / ${(snapshot?.assets || []).filter(a => a.kind === $('#health-kind').value).length} 项 ${$('#health-kind').value === 'domain' ? '主域名' : 'IP / 服务器'}`;
        $('#health-empty').classList.add('hidden');
        $('#health-table tbody').innerHTML = rows.length ? rows.map(a => {
            const health = getHealth(a);
            return `<tr><td>${a.kind === 'domain' ? `<input type="checkbox" data-health-select="${h(a.id)}" aria-label="选择域名 ${h(a.name)}" ${selectedDomains.has(a.id)?'checked':''}>` : ''}</td><td><button class="hc-asset-link" data-health-action="detail" data-id="${h(a.id)}">${h(a.name)}</button><small class="hc-subline">${a.kind === 'ip' ? h(a.ip) : (health.resolved_ips || []).map(h).join(' · ') || '解析地址待检查'}</small></td><td><span class="hc-type">${a.kind === 'domain' ? '域名' : (a.ip_version || (a.ip.includes(':')?'IPv6':'IPv4'))}</span>${a.kind === 'domain' ? `<small class="hc-subline">${a.subdomain_count == null ? '子域名待缓存' : a.subdomain_count+' 个子域名'}</small>` : ''}<small class="hc-subline">${h(sourceText(a))}</small></td><td>${pill(health.status)}</td><td><span class="hc-message" title="${h(health.message)}">${h(health.message || '尚无检查证据')}</span>${health.latency_ms != null ? `<small class="hc-subline">${h(health.latency_ms)} ms</small>` : ''}</td><td class="hc-time">${timeText(health.checked_at)}</td><td class="row-actions"><button class="btn btn-sm" data-health-action="detail" data-id="${h(a.id)}">详情</button><button class="btn btn-sm btn-link" data-health-action="check" data-id="${h(a.id)}" ${running ? 'disabled' : ''}>检查</button></td></tr>`;
        }).join('') : '<tr><td colspan="7"><div class="hc-empty">没有符合条件的资产。<button class="btn btn-link" data-health-action="clear">清除筛选</button></div></td></tr>';
    }

    function renderSelection() {
        const count=selectedDomains.size;
        $('#health-selected-count').closest('.health-selection-bar').classList.toggle('hidden', count === 0 || $('#health-kind').value !== 'domain');
        $('#health-selected-count').textContent=count ? `已选择 ${count} 个域名` : '未选择域名';
        $('#health-to-relations').disabled=count===0;
        const visible=filteredAssets().filter(a=>a.kind==='domain');
        const selected=visible.filter(a=>selectedDomains.has(a.id)).length;
        $('#health-select-all').checked=visible.length>0 && selected===visible.length;
        $('#health-select-all').indeterminate=selected>0 && selected<visible.length;
        $('#health-select-all').disabled=visible.length===0;
        $('#health-select-all').classList.toggle('hidden', $('#health-kind').value !== 'domain');
    }
    document.addEventListener('change',event=>{
        const target=event.target.closest('[data-health-select]');
        if(target){if(target.checked)selectedDomains.add(target.dataset.healthSelect);else selectedDomains.delete(target.dataset.healthSelect);renderSelection();}
    });
    $('#health-select-all').onchange=event=>{filteredAssets().filter(a=>a.kind==='domain').forEach(a=>event.target.checked?selectedDomains.add(a.id):selectedDomains.delete(a.id));renderRows();};
    $('#health-selection-clear').onclick=()=>{selectedDomains.clear();renderRows();};
    $('#health-to-relations').onclick=()=>window.Relationships.showDomains([...selectedDomains]);

    function renderIPs() {
        const query = $('#ip-query').value.trim().toLowerCase();
        const all = (snapshot?.assets || []).filter(a => a.kind === 'ip');
        const rows = all.filter(a => (!$('#ip-version').value || (a.ip_version || (a.ip.includes(':')?'IPv6':'IPv4')) === $('#ip-version').value) && (!$('#ip-status').value || getHealth(a).status === $('#ip-status').value) && (!query || [a.name, a.ip, a.note, ...(a.links || []).flatMap(link => [link.name, link.url]), ...domainNames(a)].join(' ').toLowerCase().includes(query)));
        document.querySelectorAll('[data-ip-filter]').forEach(button => button.setAttribute('aria-pressed', String($('#' + button.dataset.ipFilter).value === button.dataset.value)));
        $('#ip-count').textContent = `${rows.length} 个检查目标 · ${new Set(all.map(a => a.ip)).size} 个 IP`;
        $('#ip-empty').classList.add('hidden');
        $('#ip-table tbody').innerHTML = rows.length ? rows.map(a => `<tr><td><button class="hc-asset-link" data-health-action="detail" data-id="${h(a.id)}">${h(a.name)}</button><small class="hc-subline">${h(a.ip)} · ${h(a.ip_version || (a.ip.includes(':') ? 'IPv6' : 'IPv4'))} · ${h(sourceText(a))}</small><small class="hc-subline">${h(a.server_provider || '供应商未填写')} · 到期：${h(a.expires_at || '未填写')}</small><small class="hc-subline">归属地：${h(locationText(a.location))}${a.location?.checked_at ? ' · ' + h(a.location.checked_at) : ''}</small></td><td><div class="asset-site-links">${window.AssetLinks.render(a.links) || '<span class="muted">—</span>'}</div></td><td class="asset-note">${h(a.note) || '<span class="muted">—</span>'}<small class="hc-subline">配置：${h(a.configuration || '未填写')}</small></td><td><span class="hc-message">${a.domain_count ?? domainNames(a).length} 个主域名 · ${a.subdomain_count ?? 0} 个子域名</span><small class="hc-subline">${domainNames(a).slice(0,3).map(h).join('、') || '暂无解析关联'}</small><button class="btn btn-link btn-sm" data-domain-ip="${h(a.id)}">查看 / 修改解析</button></td><td><button type="button" class="hc-status-button" data-health-action="check" data-id="${h(a.id)}" title="点击检查 HTTP 访问状态" aria-label="${h(labels[getHealth(a).status])}，点击检查 ${h(a.name)} 的 HTTP 访问状态" ${running ? 'disabled' : ''}>${pill(getHealth(a).status)}</button><small class="hc-subline">${h(getHealth(a).message || '尚未检查')}</small></td><td class="hc-time">${timeText(getHealth(a).checked_at)}</td><td class="row-actions"><button class="btn btn-sm" data-health-action="check" data-id="${h(a.id)}" ${running ? 'disabled' : ''}>检查</button><button class="btn btn-sm btn-link" data-health-action="detail" data-id="${h(a.id)}">详情</button><button class="btn btn-sm btn-link" data-location-refresh="${h(a.id)}">${a.location ? '刷新归属地' : '查询归属地'}</button><button class="btn btn-sm btn-link" data-rel-edit="${h(a.id)}">编辑</button>${a.source === 'manual' ? `<button class="btn btn-sm btn-link-danger" data-health-action="delete" data-id="${h(a.id)}" ${running ? 'disabled' : ''}>移除</button>` : ''}</td></tr>`).join('') : '<tr><td colspan="7"><div class="hc-empty">暂无 IP 检查目标。可点击“添加 IP 资产”登记公网 IP，或检查域名以发现解析地址。</div></td></tr>';
    }

    function closeDetail() {
        $('#health-detail-overlay')?.remove();
        detailId = null;
        detailFocused = false;
        detailReturnFocus?.focus();
    }

    function renderDetail(id) {
        const asset = (snapshot?.assets || []).find(a => a.id === id);
        if (!asset) { closeDetail(); return; }
        if (asset.kind === 'domain' && window.DomainDetails) { closeDetail(); window.DomainDetails.open(id); return; }
        detailId = id;
        let overlay = $('#health-detail-overlay');
        if (!overlay) {
            detailReturnFocus = document.activeElement;
            overlay = document.createElement('div');
            overlay.id = 'health-detail-overlay';
            overlay.className = 'modal-overlay hc-detail-overlay';
            (document.fullscreenElement || document.body).appendChild(overlay);
            overlay.addEventListener('click', event => { if (event.target === overlay || event.target.closest('[data-close-detail]')) closeDetail(); });
            overlay.addEventListener('keydown', event => {
                if (event.key === 'Escape') closeDetail();
                if (event.key === 'Tab') {
                    const focusable = Array.from(overlay.querySelectorAll('button:not(:disabled), a[href]'));
                    const first = focusable[0], last = focusable[focusable.length - 1];
                    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
                    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
                }
            });
        }
        const health = getHealth(asset);
        const history = historyCache.get(asset.id) ?? asset.history ?? health.history ?? [];
        const checks = health.checks || [];
        const ipTargetUrl = 'http://' + (asset.ip.includes(':') ? '[' + asset.ip + ']' : asset.ip) + '/';
        const targetFact = asset.kind === 'domain'
            ? 'http:// 和 https://' + h(asset.name)
            : `<a class="hc-url" href="${h(ipTargetUrl)}" target="_blank" rel="noopener noreferrer" title="打开页面核对">${h(ipTargetUrl)}</a>`;
        overlay.innerHTML = `<section class="hc-detail modal" role="dialog" aria-modal="true" aria-labelledby="hc-detail-title"><div class="modal-head"><div><span class="hc-eyebrow">${asset.kind === 'domain' ? '域名健康详情' : 'IP 访问详情'}</span><h3 id="hc-detail-title">${h(asset.name)}</h3></div><button class="modal-close" data-close-detail aria-label="关闭详情">×</button></div><div class="modal-body"><div class="hc-detail-summary"><span class="hc-detail-status">${pill(health.status)}<span class="muted">${timeText(health.checked_at)}</span></span>${asset.kind === 'ip' ? `<span class="hc-detail-actions"><button class="btn btn-sm" data-health-action="check" data-id="${h(asset.id)}" ${running ? 'disabled' : ''}>${running ? '检测中…' : '检测 HTTP 访问'}</button><button class="btn btn-sm" data-browser-probe="${h(asset.id)}" ${browserProbing.has(asset.id) ? 'disabled' : ''} title="从你当前浏览器（含系统代理）发起探测，与检查机直连视角互补">${browserProbing.has(asset.id) ? '检测中…' : '浏览器检测'}</button></span>` : ''}</div>${asset.kind === 'ip' && browserProbeHtml(asset) ? browserProbeHtml(asset) : ''}<p class="hc-detail-message">${h(health.message || '执行一次检查，获取当前状态。')}</p><dl class="hc-facts"><div><dt>来源</dt><dd>${h(sourceText(asset))}</dd></div><div><dt>检查目标</dt><dd>${targetFact}</dd></div>${asset.expires_at ? `<div><dt>到期日期</dt><dd>${h(asset.expires_at)}</dd></div>` : ''}<div><dt>解析 IP</dt><dd>${(health.resolved_ips || []).map(h).join('、') || (asset.kind === 'ip' ? h(asset.ip) : '暂无数据')}</dd></div>${health.cert ? `<div><dt>证书到期</dt><dd>${h(health.cert.expires_at)} · 剩余 ${Math.max(0, Math.ceil((new Date(health.cert.expires_at + 'T23:59:59') - Date.now()) / 86400000))} 天${health.cert.issuer ? ' · 签发 ' + h(health.cert.issuer) : ''}</dd></div>` : ''}${asset.kind === 'ip' ? `<div><dt>关联域名</dt><dd>${asset.domain_count ?? domainNames(asset).length} 个主域名 · ${asset.subdomain_count ?? 0} 个子域名<br>${domainNames(asset).map(h).join('、') || '暂无解析关联'}</dd></div><div><dt>服务器供应商</dt><dd>${h(asset.server_provider || '未填写')}</dd></div><div><dt>服务器配置</dt><dd>${h(asset.configuration || '未填写')}</dd></div>` : ''}</dl>${asset.kind === 'ip' ? `<h4>用途与备注</h4><p class="asset-note">${h(asset.note) || '暂无备注'}</p><h4>网站链接</h4><div class="asset-site-links">${window.AssetLinks.render(asset.links) || '<span class="muted">暂无网站链接</span>'}</div>` : ''}<h4>检查证据</h4><ul class="hc-check-list">${checks.length ? checks.map(c => `<li data-status="${h(c.status)}"><div><strong>${h(c.label || c.name || c.type || '检查项')}</strong><span>${h(labels[c.status] || ({ok:'正常',fail:'失败',skipped:'未执行',warning:'需关注'})[c.status] || c.status || '')}</span></div><p>${h(c.message || c.error || '—')}</p></li>`).join('') : '<li class="muted">尚无分层检测记录。旧 HTTP 结果仅作为历史参考。</li>'}</ul><h4>最近检查</h4><div class="hc-history">${history.length ? history.slice(0, 10).map(item => `<div><span>${timeText(item.checked_at)}</span>${pill(item.status || item.last_status)}<small>${h(item.message || '')}</small></div>`).join('') : '<p class="muted">执行检查后保存最近 10 次记录。</p>'}</div><p class="hc-footnote">${asset.kind === 'ip' ? 'IP 检查访问 http://IP/，以 HTTP 2xx / 3xx 为正常，不跟随重定向。结果和时间保存在服务器。' : '可达性以 HTTP 为准：HTTP 2xx / 3xx 即计为正常。HTTPS 证书验证与 HTTPS 连接单独记录，异常时在「HTTPS 跟进」页处理，不改变可达性判定。结果超过 24 小时标记为过时。'}</p></div><div class="modal-foot"><button class="btn" data-close-detail>关闭</button><button class="btn" data-copy-history="${h(asset.id)}" title="把检查证据与最近记录复制为文本，粘贴给 AI 协助排查">复制检查历史</button><button class="btn" data-rel-edit="${h(asset.id)}">编辑${asset.kind === 'domain' ? '域名' : '服务器'}</button>${asset.kind === 'domain' ? `<button class="btn" data-rel-bind="${h(asset.id)}">管理绑定</button>${asset.provider !== 'manual' ? `<button class="btn" data-rel-dns="${h(asset.id)}">管理 DNS</button>` : ''}` : ''}<button class="btn btn-primary" data-health-action="check" data-id="${h(asset.id)}" ${running ? 'disabled' : ''}>${asset.kind === 'ip' ? (running ? '检测中…' : '检测 HTTP 访问') : (running ? '检查中…' : '重新检查')}</button></div></section>`;
        if (!detailFocused) {
            overlay.querySelector('.modal-close')?.focus();
            detailFocused = true;
        }
    }

    function updateBusy() {
        ['health-check-all', 'health-check-domains-all', 'ip-check-all'].forEach(id => { if ($('#' + id)) $('#' + id).disabled = running; });
        $$('[data-health-action="check"], [data-health-action="delete"]').forEach(button => { button.disabled = running; });
        $('#ip-open-create').disabled = running;
        if ($('#ip-add-btn')) $('#ip-add-btn').disabled = running;
    }

    async function check(ids, discover = false) {
        if (running) { toast('已有检查正在进行', 'info'); return; }
        const unique = [...new Set(ids)];
        if (!unique.length) { toast('没有可检查的资产', 'info'); return; }
        running = true;
        stopped = false;
        updateBusy();
        const progress = $('#health-progress');
        const slot = state.tab === 'ips' ? $('#ip-progress-slot')
            : state.tab === 'issues' ? $('#issues-progress-slot')
            : $('#health-progress-slot');
        slot.after(progress);
        progress.classList.remove('hidden');
        let done = 0, abnormal = 0, failed = 0, lastError = '';
        let discovered = false;
        const kinds = new Map();
        for (const asset of snapshot?.assets || []) { kinds.set(asset.id, asset.kind); for (const host of asset.subdomains || []) if (host.checkable) kinds.set(host.id, 'subdomain'); }
        const counts = Object.fromEntries(['domain','ip','subdomain'].map(kind => [kind, {healthy:0, unhealthy:0}]));
        const line = $('#health-progress-text');
        let stop = $('#health-stop');
        if (!stop) {
            stop = document.createElement('button');
            stop.id = 'health-stop'; stop.className = 'btn btn-sm'; stop.type = 'button'; stop.textContent = '停止后续检查';
            stop.addEventListener('click', () => { stopped = true; stop.disabled = true; stop.textContent = '当前批次完成后停止'; });
            progress.appendChild(stop);
        }
        stop.disabled = false; stop.textContent = '停止后续检查';
        try {
            for (let i = 0; i < unique.length && !stopped; i += 2) {
                line.textContent = `正在检查 ${Math.min(i + 1, unique.length)}–${Math.min(i + 2, unique.length)} / ${unique.length} 项，请保持页面打开`;
                $('#health-progress-fill').style.width = `${done / unique.length * 100}%`;
                const batch = unique.slice(i, i + 2);
                try {
                    const response = await api('/api/health/check', { method: 'POST', body: JSON.stringify({ asset_ids: batch }) });
                    const results = response.results || [];
                    if (results.length !== batch.length || results.some(r => !kinds.has(r.id) || !['healthy','unhealthy'].includes(r.health?.status))) throw new Error('检查结果不完整');
                    abnormal += results.filter(r => r.health.status === 'unhealthy').length;
                    for (const result of results) counts[kinds.get(result.id)][result.health.status]++;
                    // 检查产生新历史：失效对应缓存，详情与复制日志下次按需重取。
                    for (const result of results) historyCache.delete(result.id);
                } catch (err) {
                    failed += batch.length;
                    lastError = err.message;
                    toast('本批检查未完成：' + err.message, 'error', 6000);
                    // 网络/认证/存储失败时不继续发送余下任务，也不伪造健康结果。
                    stopped = true;
                }
                done += batch.length;
                // 全量刷新按批节流：每 5 批（约 10 项）重建一次表格，批内不重绘，末批必刷。
                if (i + 2 >= unique.length || (done / 2) % 5 === 0) await load();
                if (discover && !discovered && i + 2 >= unique.length && !stopped) {
                    discovered = true;
                    const additions = (snapshot?.assets || []).filter(a => a.kind === 'ip' && !unique.includes(a.id));
                    for (const asset of additions) kinds.set(asset.id, asset.kind);
                    unique.push(...additions.map(a => a.id));
                }
            }
            if (discover && !stopped && !failed && done === unique.length) {
                try { await api('/api/calendar/runs', {method:'POST', body:JSON.stringify({counts})}); }
                catch (err) { toast('检查已完成，但日历记录保存失败：' + err.message, 'error', 8000); }
            }
            $('#health-progress-fill').style.width = `${done / unique.length * 100}%`;
            line.textContent = `${stopped ? '检查已停止' : '检查完成'} · 已处理 ${done}/${unique.length} 项 · 检查异常 ${abnormal} 项${failed ? ` · 未完成 ${failed} 项 · ${lastError}` : ''}`;
            stop.classList.add('hidden');
        } finally {
            running = false;
            updateBusy();
            stop.classList.remove('hidden');
            stop.disabled = true;
            stop.textContent = '本次检查结束';
            // 检查中的最后一次渲染发生在复位前，弹窗按钮会停留在「检测中…」，这里补一次终态渲染。
            if (detailId) renderDetail(detailId);
        }
    }

    async function checkKind(kind) {
        if (!await load()) return;
        if (kind !== 'ip' && state.tab !== 'dashboard') switchTab('dashboard');
        $('#health-kind').value = kind;
        $('#health-status').value = '';
        $('#health-query').value = '';
        render();
        return check(snapshot.assets.filter(a => !kind || a.kind === kind).map(a => a.id));
    }

    document.addEventListener('click', event => {
        const probeButton = event.target.closest('[data-browser-probe]');
        if (probeButton && !browserProbing.has(probeButton.dataset.browserProbe)) {
            const asset = (snapshot?.assets || []).find(a => a.id === probeButton.dataset.browserProbe);
            if (asset?.kind === 'ip') runBrowserProbe(asset);
        }
    });

    document.addEventListener('click', async event => {
        const filter = event.target.closest('[data-filter-category]');
        if (filter) {
            window.WorkspaceUI?.closeDialog();
            $('#health-query').value = '';
            if (filter.dataset.filterCategory === 'kind') $('#health-kind').value = filter.dataset.filter;
            $('#health-status').value = filter.dataset.filterCategory === 'status' ? filter.dataset.filter : '';
            renderRows();
            $('#health-table').scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
        const button = event.target.closest('[data-health-action]');
        if (!button) return;
        const id = button.dataset.id;
        switch (button.dataset.healthAction) {
            case 'detail': void (async () => { renderDetail(id); await fetchHistory(id); if (detailId === id) renderDetail(id); })(); break;
            case 'check': await check([id]); break;
            case 'refresh': await load(); break;
            case 'clear': ['health-query', 'health-status'].forEach(key => { $('#' + key).value = ''; }); renderRows(); break;
            case 'delete': {
                const asset = snapshot.assets.find(a => a.id === id);
                if (!asset || !await confirmDialog({title:'移除 IP 检查目标',message:`移除 ${asset.ip} 的手工登记？已有 DNS 解析关联和历史证据仍会保留。`,confirmText:'移除',danger:true})) break;
                try { await api('/api/health/ips/' + encodeURIComponent(id), { method:'DELETE' }); toast('已移除手工检查目标'); await load(); }
                catch (err) { toast(err.message, 'error'); }
                break;
            }
        }
    });

    document.querySelectorAll('[data-health-type]').forEach(button => button.addEventListener('click', () => {
        $('#health-kind').value = button.dataset.healthType;
        $('#health-status').value = '';
        render();
    }));
    /* 搜索输入防抖：大列表击键全量重建 tbody 是卡顿源头，与域名管理页保持同一节奏。 */
    const debounce = (fn, wait) => { let timer = null; return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), wait); }; };
    const debouncedRenderRows = debounce(renderRows, 300);
    const debouncedRenderIPs = debounce(renderIPs, 300);
    ['health-query', 'health-kind', 'health-status'].forEach(id => $('#' + id).addEventListener(id === 'health-query' ? 'input' : 'change', debouncedRenderRows));
    $('#ip-query').addEventListener('input', debouncedRenderIPs);
    document.querySelectorAll('[data-ip-filter]').forEach(button => button.addEventListener('click', () => {
        const input = $('#' + button.dataset.ipFilter);
        input.value = input.value === button.dataset.value ? '' : button.dataset.value;
        renderIPs();
    }));
    $('#health-refresh').addEventListener('click', () => { window.WorkspaceCache?.clear(); load(true); loadDashboard(); });
    /* 转后台执行：入队后由服务器 cron 的 worker 认领，关闭浏览器也会继续。 */
    $('#health-background-btn')?.addEventListener('click', async () => {
        const btn = $('#health-background-btn');
        btn.disabled = true;
        try {
            await api('/api/tasks', { method: 'POST', body: JSON.stringify({ type: 'health-check' }) });
            toast('已转入后台：全量检查将在服务器继续（不含子域名），完成后看板自动刷新。\n需服务器已配置 cron：php scripts/task-worker.php', 'success', 8000);
            window.BackgroundTasks?.refresh?.();
        } catch (err) {
            toast('转入后台失败：' + err.message, 'error', 6000);
        } finally {
            btn.disabled = false;
        }
    });
    $('#health-check-all').addEventListener('click', async () => {
        if (running) return;
        if (await load(true)) await check(snapshot.assets.map(a => a.id), true);
    });
    $('#health-check-domains-all').addEventListener('click', async () => {
        if (running) return;
        const button = $('#health-check-domains-all'); button.disabled = true;
        try {
            const cached = await window.DomainDetails.cacheMissing();
            if (cached.failed) { toast(`有 ${cached.failed} 个域名的 DNS 缓存读取失败，请检查云账号后重试`, 'error', 7000); return; }
            if (await load(true)) await check(snapshot.assets.filter(a => a.kind === 'domain').flatMap(a => [a.id, ...(a.subdomains || []).filter(host => host.checkable).map(host => host.id)]));
        } catch (err) { toast('准备 DNS 缓存失败：' + err.message, 'error', 7000); }
        finally { if (!running) button.disabled = false; }
    });
    let locating = false;
    async function locate(ids) {
        if (locating || !ids.length) { if (!ids.length) toast('没有未缓存的 IP', 'info'); return; }
        locating = true;
        const bulk = $('#ip-location-missing'); bulk.disabled = true;
        let done = 0;
        try {
            for (const id of ids) {
                bulk.textContent = `查询归属地 ${done + 1}/${ids.length}`;
                await api('/api/health/ips/' + encodeURIComponent(id) + '/location', {method:'POST'});
                done++;
            }
            toast(`已查询 ${done} 个 IP 的归属地`);
        } catch (err) { toast(`已查询 ${done} 个 IP，后续查询停止：${err.message}`, 'error', 6000); }
        finally { locating = false; bulk.disabled = false; bulk.textContent = '查询未缓存归属地'; await load(true); }
    }
    $('#ip-table').addEventListener('click', event => { const button = event.target.closest('[data-location-refresh]'); if (button) locate([button.dataset.locationRefresh]); });
    $('#ip-location-missing').addEventListener('click', () => locate((snapshot?.assets || []).filter(a => a.kind === 'ip' && !a.location).map(a => a.id)));
    $('#ip-check-all').addEventListener('click', () => checkKind('ip'));
    function openIpCreate() {
        if (running || $('#ip-create-overlay')) return;
        const overlay = document.createElement('div');
        overlay.id = 'ip-create-overlay'; overlay.className = 'modal-overlay';
        overlay.innerHTML = $('#ip-create-template').innerHTML;
        const returnFocus = document.activeElement;
        (document.fullscreenElement || document.body).appendChild(overlay);
        const ipLinks = window.AssetLinks.editor($('#ip-links-editor'));
        let saving = false;
        const close = () => { if (!saving) { overlay.remove(); returnFocus?.focus(); } };
        overlay.querySelector('.modal-close').onclick = close;
        overlay.querySelector('[data-ip-cancel]').onclick = close;
        overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
        overlay.addEventListener('keydown', event => {
            if (event.key === 'Escape') { event.preventDefault(); close(); }
            if (event.key === 'Tab') {
                const items = [...overlay.querySelectorAll('button:not(:disabled),input,textarea')];
                const first = items[0], last = items[items.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
            }
        });
        $('#ip-address').focus();
        $('#ip-add-form').addEventListener('submit', async event => {
            event.preventDefault();
            if (saving) return;
            saving = true;
            const error = $('#ip-add-error');
            error.classList.add('hidden');
            $('#ip-add-btn').disabled = true;
            try {
                await api('/api/health/ips', { method:'POST', body: JSON.stringify({name:$('#ip-name').value.trim(),ip:$('#ip-address').value.trim(),note:$('#ip-note').value.trim(),links:ipLinks.read()}) });
                saving = false;
                close();
                toast('IP 已登记，可检查 HTTP 访问状态');
                await load();
            } catch (err) {
                error.textContent = err.message;
                error.classList.remove('hidden');
            } finally { saving = false; if ($('#ip-add-btn')) $('#ip-add-btn').disabled = false; }
        });
    }
    $('#ip-open-create').addEventListener('click', openIpCreate);
    window.addEventListener('beforeunload', event => { if (running) { event.preventDefault(); event.returnValue = ''; } });

    /* AI 协同：把缓存证据编译成一份可直接粘贴给 AI 助手的 Markdown 报告。 */
    let aiReportOverlay = null;
    async function openAiReport() {
        if (aiReportOverlay) { aiReportOverlay.remove(); aiReportOverlay = null; }
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `<section class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="ai-report-title"><div class="modal-head"><div><span class="hc-eyebrow">AI 协同</span><h3 id="ai-report-title">AI 分析报告</h3></div><button class="modal-close" data-ai-report-close aria-label="关闭报告">×</button></div><div class="modal-body"><p class="muted" id="ai-report-summary">正在基于缓存证据生成报告…</p><p class="muted">报告包含检查异常域名、HTTPS 未确认域名、异常 IP 与到期提醒，不含任何密钥。「复制提示词 + 报告」会连同一份完整的分析要求一起复制，粘贴给任意 AI 助手即可直接开始分析。</p><label>Markdown 报告，可选择复制或下载<textarea id="ai-report-text" readonly rows="18" style="width:100%;resize:vertical"></textarea></label><p class="muted" id="ai-report-message" role="status"></p></div><div class="modal-foot"><button class="btn" data-ai-report-close>关闭</button><button class="btn" id="ai-report-download">下载 .md</button><button class="btn" id="ai-report-copy">复制报告</button><button class="btn btn-primary" id="ai-report-prompt">复制提示词 + 报告</button></div></section>`;
        (document.fullscreenElement || document.body).appendChild(overlay);
        aiReportOverlay = overlay;
        const close = () => { overlay.remove(); aiReportOverlay = null; };
        overlay.addEventListener('click', event => { if (event.target === overlay || event.target.closest('[data-ai-report-close]')) close(); });
        overlay.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
        const textarea = overlay.querySelector('#ai-report-text');
        const message = overlay.querySelector('#ai-report-message');
        let markdown = '';
        let fullPrompt = '';
        try {
            const report = await api('/api/health/report');
            markdown = report.markdown || '';
            fullPrompt = report.prompt || '';
            textarea.value = markdown;
            const summary = report.summary || {};
            overlay.querySelector('#ai-report-summary').textContent = `生成时间 ${report.generated_at || '未知'} · 主域名 ${summary.domains ?? 0} · IP ${summary.ips ?? 0} · 异常 ${summary.unhealthy ?? 0} · 过时 ${summary.stale ?? 0} · 未检查 ${summary.unchecked ?? 0}`;
        } catch (err) {
            overlay.querySelector('#ai-report-summary').textContent = '报告生成失败';
            message.textContent = err.message;
        }
        overlay.querySelector('#ai-report-copy').onclick = async () => {
            if (!markdown) return;
            if (await copyText(markdown, '报告已复制，可粘贴给 AI 助手')) return;
            textarea.focus(); textarea.select(); message.textContent = '请复制已选中的报告';
        };
        overlay.querySelector('#ai-report-prompt').onclick = async () => {
            if (!fullPrompt) return;
            if (await copyText(fullPrompt, '提示词与报告已一起复制，直接粘贴给 AI 助手即可')) return;
            textarea.value = fullPrompt; textarea.focus(); textarea.select(); message.textContent = '请复制已选中的全文';
        };
        overlay.querySelector('#ai-report-download').onclick = () => {
            if (!markdown) return;
            const blob = new Blob([markdown], {type: 'text/markdown'});
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `health-report-${(snapshot?.generated_at || new Date().toISOString().slice(0, 19)).replace(/[: ]/g, '-')}.md`;
            link.click();
            URL.revokeObjectURL(url);
            message.textContent = '已开始下载 .md 文件';
        };
        overlay.querySelector('.modal-close')?.focus();
    }
    $('#health-report').addEventListener('click', openAiReport);

    /* 从域名台账等外部列表直达域名操作中枢（DNS、检查、绑定都在详情弹窗内）。 */
    async function openDomain(provider, name) {
        await load();
        const assets = snapshot?.assets || [];
        const asset = assets.find(a => a.kind === 'domain' && a.name === name && a.provider === provider)
            || assets.find(a => a.kind === 'domain' && a.name === name);
        if (!asset) { toast('健康台账中未找到该域名，可能已被同步移除', 'info'); return false; }
        if (window.DomainDetails) window.DomainDetails.open(asset.id);
        return true;
    }

    /* 人机协同：看板 / 问题中心 / IP 页每分钟自动刷新快照与问题清单，只读缓存证据，不发起新的探测。 */
    setInterval(() => {
        if (document.hidden || running || loading) return;
        if (!['dashboard', 'issues', 'ips'].includes(state.tab)) return;
        load(true);
    }, 60000);
    if (!$('#app-view').classList.contains('hidden')) load();
    /* 供解析记录等外部模块按 IP 值查找台账资产（需先 await load()）。 */
    const findIp = ip => (snapshot?.assets || []).find(a => a.kind === 'ip' && a.ip === ip) || null;
    return { load, loadHttpsReview, closeDetail, checkIds: check, openDetail: async id => { await load(); renderDetail(id); await fetchHistory(id); if (detailId === id) renderDetail(id); }, checkDomains: () => checkKind('domain'), openDomain, findIp };
})();
