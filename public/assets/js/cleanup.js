'use strict';

/* 数据清理：账号不可用的遗留记录 + 四类日志按维度删除。数据与动作都走 /api/maintenance/cleanup*。 */
window.WorkspaceCleanup = (() => {
    let snapshot = null;
    const visible = () => !$('#view-settings').classList.contains('hidden');

    function error(message = '') {
        $('#cleanup-error').textContent = message;
        $('#cleanup-error').classList.toggle('hidden', !message);
    }

    async function load() {
        error();
        try {
            const data = await api('/api/maintenance/cleanup');
            snapshot = data;
            renderOrphans(data.groups || []);
            renderLogs(data.logs || {});
        } catch (failure) { if (visible()) error(failure.message); }
    }

    function leave() { snapshot = null; }

    /* ---------- 账号不可用的记录 ---------- */
    function renderOrphans(groups) {
        const box = $('#cleanup-orphans');
        const meta = $('#cleanup-orphans-meta');
        const total = groups.reduce((n, g) => n + g.domains.length, 0);
        if (meta) meta.textContent = groups.length ? `${groups.length} 个账号 · ${total} 条记录` : '没有发现残留';
        if (!groups.length) {
            box.innerHTML = '<div class="settings-empty"><strong>没有账号不可用的遗留记录</strong><p>所有域名都挂在可用账号或手工资产下。</p></div>';
            return;
        }
        box.innerHTML = groups.map(group => {
            const rows = group.domains.map(d => `<tr><td><input type="checkbox" data-cleanup-domain="${escapeHtml(d.domain)}" data-cleanup-group="${escapeHtml(group.provider)}" aria-label="选择 ${escapeHtml(d.domain)}"></td><td>${escapeHtml(d.domain)}${d.valid ? '' : ' <span class="badge b-gray">已失效</span>'}</td><td>${escapeHtml(d.expire_at || '—')}</td><td>${escapeHtml(d.note || '—')}</td></tr>`).join('');
            return `<div class="cleanup-group"><div class="cleanup-group-head"><strong>${escapeHtml(group.label)}</strong>${group.disabled ? '<span class="badge b-amber">已停用</span>' : '<span class="badge b-gray">账号已删除</span>'}<small class="muted">${escapeHtml(group.provider)}</small><div class="maintenance-actions"><button class="btn btn-sm" data-cleanup-action="manual" data-cleanup-provider="${escapeHtml(group.provider)}">转为手工</button><button class="btn btn-sm btn-link-danger" data-cleanup-action="delete" data-cleanup-provider="${escapeHtml(group.provider)}">删除所选 / 整组</button></div></div><div class="table-wrap"><table><thead><tr><th></th><th>域名</th><th>到期</th><th>备注</th></tr></thead><tbody>${rows}</tbody></table></div></div>`;
        }).join('');
    }

    $('#cleanup-orphans').addEventListener('click', async event => {
        const button = event.target.closest('[data-cleanup-action]');
        if (!button) return;
        const provider = button.dataset.cleanupProvider;
        const action = button.dataset.cleanupAction;
        const group = (snapshot?.groups || []).find(item => item.provider === provider);
        if (!group) return;
        const selected = [...document.querySelectorAll('#cleanup-orphans input[data-cleanup-group]:checked')]
            .filter(input => input.dataset.cleanupGroup === provider)
            .map(input => input.dataset.cleanupDomain);
        const domains = selected.length ? selected : group.domains.map(d => d.domain);
        const verb = action === 'delete' ? '删除' : '转为手工';
        const scope = selected.length ? `所选 ${domains.length} 条` : `该账号全部 ${domains.length} 条`;
        if (!await confirmDialog({
            title: `${verb}不可用账号的记录`,
            message: `对「${group.label}」的${scope}记录执行「${verb}」。` + (action === 'delete'
                ? '域名台账行会被删除，无法恢复。'
                : '域名会保留为手工资产，可重新编辑账号；原有健康历史与绑定会清零，需重新检查。')
                + 'DNS 缓存、健康观测与遗留检测数据都会一并清除。',
            confirmText: verb, danger: true,
        })) return;
        button.disabled = true;
        try {
            const result = await api('/api/maintenance/cleanup/orphans', { method: 'POST', body: JSON.stringify({ provider, action, domains }) });
            $('#cleanup-message').textContent = `已${verb} ${result.domains} 条记录：DNS 缓存 ${result.caches_removed} 份 · 健康观测 ${result.observations_removed} 条 · 检查事件 ${result.events_removed} 条 · 绑定 ${result.bindings_removed} 组 · 遗留检测 ${result.legacy_checks_removed + result.record_access_removed} 条。`;
            toast(`已${verb} ${result.domains} 条记录`);
            window.WorkspaceCache?.clear();
            window.HealthWorkbench?.load();
            await load();
        } catch (failure) { error(failure.message); button.disabled = false; }
    });

    /* ---------- 日志清理 ---------- */
    const LOG_ROWS = [
        { key: 'check_events', label: '检查历史', dims: ['domain', 'before', 'all'] },
        { key: 'dns_changes', label: 'DNS 变更记录', dims: ['domain', 'all'] },
        { key: 'audit', label: '操作记录', dims: ['before', 'all'] },
        { key: 'sync_log', label: '同步日志', dims: ['type', 'before', 'all'] },
    ];
    const dimInput = (name, placeholder) => `<input type="${name === 'before' ? 'date' : 'text'}" class="cleanup-input" data-cleanup-input="${name}" placeholder="${escapeHtml(placeholder)}" aria-label="${escapeHtml(placeholder)}">`;

    function renderLogs(logs) {
        const box = $('#cleanup-logs');
        box.innerHTML = LOG_ROWS.map(row => {
            const stat = logs[row.key] || {};
            const count = stat.total ?? 0;
            const sub = [`${count} 条`, stat.oldest ? `最早 ${escapeHtml(stat.oldest)}` : null,
                row.key === 'check_events' && stat.history_entries != null ? `资产历史 ${stat.history_entries} 条` : null].filter(Boolean).join(' · ');
            const dims = row.dims.map(name => {
                if (name === 'domain') return dimInput('domain', '按域名删除，如 example.com');
                if (name === 'before') return dimInput('before', '此日期之前');
                if (name === 'type') {
                    const types = Object.entries(stat.types || {});
                    const options = types.map(([type, n]) => `<option value="${escapeHtml(type)}">${escapeHtml(type)}（${n}）</option>`).join('');
                    return `<select class="cleanup-input" data-cleanup-input="type" aria-label="按类型删除"><option value="">按类型…</option>${options}</select>`;
                }
                return `<label class="cleanup-all"><input type="checkbox" data-cleanup-input="all"> 清空全部</label>`;
            }).join('');
            return `<div class="cleanup-log-row"><div class="cleanup-log-info"><strong>${row.label}</strong><span class="muted">${sub}</span></div><div class="cleanup-log-dims">${dims}</div><button class="btn btn-sm btn-link-danger" data-cleanup-log="${row.key}" ${count ? '' : 'disabled'}>删除</button></div>`;
        }).join('') || '<p class="muted">暂无日志数据。</p>';
    }

    $('#cleanup-logs').addEventListener('click', async event => {
        const button = event.target.closest('[data-cleanup-log]');
        if (!button || button.disabled) return;
        const store = button.dataset.cleanupLog;
        const row = button.closest('.cleanup-log-row');
        const value = name => row.querySelector(`[data-cleanup-input="${name}"]`)?.value.trim() || '';
        const body = { store };
        if (store === 'check_events' || store === 'dns_changes') {
            const domain = value('domain');
            if (domain) body.domain = domain;
        }
        if (store === 'sync_log') {
            const type = value('type');
            if (type) body.type = type;
        }
        const before = value('before');
        if (before) body.before = before;
        const clearAll = row.querySelector('[data-cleanup-input="all"]')?.checked;
        if (clearAll) {
            body.all = true;
            delete body.domain; delete body.type; delete body.before;
        }
        const label = (LOG_ROWS.find(item => item.key === store) || {}).label || store;
        const parts = [];
        if (body.all) parts.push('全部条目');
        if (body.domain) parts.push(`域名 ${body.domain}`);
        if (body.type) parts.push(`类型 ${body.type}`);
        if (body.before) parts.push(`${body.before} 之前`);
        if (!parts.length) { toast('请选择删除维度，或勾选“清空全部”', 'info'); return; }
        if (!await confirmDialog({
            title: `删除${label}`,
            message: `即将删除${label}：${parts.join(' + ')}，删除不可恢复。` + (store === 'check_events' ? '各资产最近一次健康状态会保留。' : ''),
            confirmText: '删除', danger: true,
        })) return;
        button.disabled = true;
        try {
            const result = await api('/api/maintenance/cleanup/logs', { method: 'POST', body: JSON.stringify(body) });
            $('#cleanup-message').textContent = `已删除 ${result.removed ?? 0} 条${label}记录。`;
            toast(`已删除 ${result.removed ?? 0} 条${label}记录`);
            window.WorkspaceCache?.clear();
            await load();
        } catch (failure) { error(failure.message); button.disabled = false; }
    });

    return { load, leave };
})();
