'use strict';

/* ---------- 仪表盘 ---------- */
async function loadDashboard() {
    window.HealthWorkbench?.load();
    try {
        const [stats, logs] = await Promise.all([api('/api/domains/stats'), api('/api/sync/logs?limit=8')]);
        renderStats(stats);
        renderProviderDist(stats);
        renderRecentSync(stats, logs);
    } catch (err) {
        $('#dash-sub').textContent = '加载失败: ' + err.message;
    }
}

function renderStats(s) {
    const cards = [
        { label: '总域名', value: s.total, cls: '', nav: {} },
        { label: '有效域名', value: s.valid, cls: '', nav: { valid: 1 } },
        { label: '30 天内到期', value: s.expiring_30, cls: 'v-red', nav: { expiring: '30' } },
        { label: '7 天内到期', value: s.expiring_7, cls: 'v-red', nav: { expiring: '7' } },
        { label: '已过期', value: s.expired, cls: 'v-red', nav: { expiring: 'expired' } },
        { label: '已失效', value: s.invalid, cls: 'v-gray', nav: { valid: 0 } },
    ];
    $('#stats-grid').innerHTML = cards.map((c) => `
        <div class="stat-card clickable" data-nav="${escapeHtml(JSON.stringify(c.nav))}">
            <div class="stat-value ${c.cls}">${c.value}</div>
            <div class="stat-label">${c.label} ↗</div>
        </div>`).join('');
    $('#dash-sub').textContent = s.last_sync
        ? `域名资料上次同步于 ${s.last_sync} · 健康结果以各项检测时间为准`
        : '尚未同步域名资料，可先同步域名或添加 IP。';
}

function renderProviderDist(s) {
    const el = $('#provider-dist');
    const entries = Object.entries(s.providers || {});
    if (!entries.length) {
        el.innerHTML = '<p class="muted">暂无数据</p>';
        return;
    }
    const max = Math.max(...entries.map(([, v]) => v));
    el.innerHTML = entries.map(([pid, count]) => {
        const info = providerInfo(pid);
        const pct = max ? Math.max(3, Math.round((count / max) * 100)) : 0;
        return `
            <div class="dist-row clickable" data-nav="${escapeHtml(JSON.stringify({ provider: pid }))}" title="查看该服务商域名">
                <span class="dist-name">${escapeHtml(info.name)}</span>
                <div class="dist-bar"><div class="dist-fill ${info.cls}" style="width:${pct}%"></div></div>
                <span class="dist-count">${count}</span>
            </div>`;
    }).join('');
}

/* 仪表盘 → 域名列表跳转 */
$('#stats-grid').addEventListener('click', (e) => {
    const el = e.target.closest('[data-nav]');
    if (el) navigateToDomains(JSON.parse(el.dataset.nav));
});
$('#provider-dist').addEventListener('click', (e) => {
    const el = e.target.closest('[data-nav]');
    if (el) navigateToDomains(JSON.parse(el.dataset.nav));
});

function navigateToDomains(filters) {
    $('#f-provider').value = filters.provider ?? '';
    $('#f-valid').value = filters.valid !== undefined ? String(filters.valid) : '';
    $('#f-access').value = filters.access ?? '';
    $('#f-q').value = filters.q ?? '';
    state.expiring = filters.expiring ?? '';
    if ($('#f-expiring')) $('#f-expiring').value = state.expiring;
    switchTab('domains');
}

function renderRecentSync(s, logs) {
    const el = $('#recent-sync');
    const syncLog = logs.find((l) => l.type === 'sync');
    if (!syncLog) {
        el.innerHTML = '<p class="muted">还没有同步记录</p>';
        return;
    }
    const d = syncLog.data || {};
    const items = (d.accounts || []).map((r) => `
        <div class="log-line">
            <span class="${r.ok ? 'ok' : 'fail'}">${r.ok ? '✓' : '✗'} ${escapeHtml(r.name)}</span>
            ${r.ok ? `<span class="muted">${r.count} 个 · ${r.latency_ms}ms</span>` : `<span class="detail">${escapeHtml(r.error || '')}</span>`}
        </div>`).join('');
    el.innerHTML = `
        <div class="log-line muted">${escapeHtml(syncLog.time)} · 共 ${d.total ?? '-'} 个域名，新增 ${d.new ?? '-'}，失效 ${d.stale ?? '-'}</div>
        ${items}`;
}

/* ---------- 同步 ---------- */
async function runSync(btn) {
    btn.disabled = true;
    const original = btn.textContent;
    btn.textContent = '同步中…';
    try {
        const res = await api('/api/sync', { method: 'POST' });
        const okCount = (res.results || []).filter((r) => r.ok).length;
        const fail = (res.results || []).filter((r) => !r.ok);
        let msg = `同步完成：${res.stats?.total ?? 0} 个域名（${okCount} 个账号成功，${fail.length} 个失败）`;
        if (fail.length) {
            msg += '\n失败：' + fail.map((f) => f.name + ' ' + f.error).join('\n');
        }
        toast(msg, fail.length ? 'error' : 'success');
        if (state.tab === 'dashboard') loadDashboard();
        else if (state.tab === 'domains') loadDomains();
        else window.HealthWorkbench?.load();
    } catch (err) {
        toast('同步失败：' + err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = original;
    }
}

$('#sync-btn').addEventListener('click', (e) => runSync(e.currentTarget));
$('#domains-sync-btn').addEventListener('click', (e) => runSync(e.currentTarget));
