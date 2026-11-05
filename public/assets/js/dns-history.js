'use strict';

/* DNS 变更记录弹窗：按域名筛选变更历史，差异可复制；数据来自 /api/dns/changes（管理员会话）。 */
window.DnsHistory = (() => {
    const h = escapeHtml;
    const actionLabels = {add: '新增', update: '修改', delete: '删除'};
    const outcomeLabels = {verified: '云端已核实', unchanged: '无需变更', pending: '处理中 / 待核实', unconfirmed: '待核实'};
    const dateTime = value => value ? new Date(value).toLocaleString('zh-CN', {hour12: false}) : '—';
    let overlay = null;

    const diffText = entry => JSON.stringify({原值: entry.before ?? null, 拟提交: entry.requested ?? null, 回读值: entry.after ?? null}, null, 2);

    function entryLogText(entry) {
        return [
            `# DNS 变更记录${entry.id ? ' ' + entry.id : ''}`,
            `- 时间：${dateTime(entry.started_at)}`,
            `- 目标：${entry.domain} · 账号 ${entry.account}`,
            `- 操作：${actionLabels[entry.action] || entry.action || '变更'} · 结果：${outcomeLabels[entry.outcome] || entry.outcome || '待核实'}`,
            `- 操作者：${entry.actor || '—'} · ${entry.via === 'api' ? 'API' : '浏览器'}`,
            '',
            diffText(entry),
        ].join('\n');
    }

    function renderRows(entries) {
        return entries.length ? entries.map(entry => {
            const ok = ['verified', 'unchanged'].includes(entry.outcome);
            return `<tr><td>${h(dateTime(entry.started_at))}<small class="hc-subline">${h(entry.actor || '')} · ${entry.via === 'api' ? 'API' : '浏览器'}</small></td><td>${h(actionLabels[entry.action] || entry.action || '变更')}<br><span class="badge ${ok ? 'b-green' : 'b-amber'}">${h(outcomeLabels[entry.outcome] || '待核实')}</span></td><td><details><summary>查看差异</summary><small>变更编号：${h(entry.id || '')}</small><pre class="dns-change-values">${h(diffText(entry))}</pre></details></td><td class="row-actions"><button class="btn btn-sm btn-link" data-dns-history-copy="${h(entry.id || '')}">复制</button></td></tr>`;
        }).join('') : '<tr><td colspan="4" class="muted">该域名还没有 DNS 变更记录。</td></tr>';
    }

    function close() {
        overlay?.remove();
        overlay = null;
    }

    async function open(filter = {}) {
        if (overlay) close();
        overlay = document.createElement('div');
        overlay.className = 'modal-overlay dns-history-overlay';
        overlay.innerHTML = `<section class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="dns-history-title"><div class="modal-head"><div><span class="hc-eyebrow">DNS HISTORY</span><h3 id="dns-history-title">DNS 变更记录${filter.domain ? ` · ${h(filter.domain)}` : ''}</h3><p class="muted">服务器保留最近 200 条；记录含私有 DNS 数据，仅管理员会话可读。</p></div><button class="modal-close" aria-label="关闭变更记录">×</button></div><div class="modal-body"><div class="table-wrap"><table><thead><tr><th>发起时间</th><th>操作与结果</th><th>前后值</th><th>操作</th></tr></thead><tbody id="dns-history-rows"><tr><td colspan="4" class="muted"><span class="loading-ring"></span>正在读取变更记录…</td></tr></tbody></table></div><p class="hc-footnote">待核实表示云端结果尚未确认，请先刷新云端记录核实，避免重复提交。</p></div><div class="modal-foot"><button class="btn" data-dns-history-close>关闭</button><button class="btn btn-primary" id="dns-history-copy-all">复制全部（文本）</button></div></section>`;
        (document.fullscreenElement || document.body).appendChild(overlay);
        const returnFocus = document.activeElement;
        overlay.addEventListener('click', event => { if (event.target === overlay || event.target.closest('[data-dns-history-close],.modal-close')) { close(); returnFocus?.focus?.(); } });
        overlay.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
        let entries = [];
        try {
            const data = await api('/api/dns/changes');
            entries = (data.changes || []).filter(entry => !filter.domain || entry.domain === filter.domain);
            overlay.querySelector('#dns-history-rows').innerHTML = renderRows(entries);
        } catch (err) {
            overlay.querySelector('#dns-history-rows').innerHTML = `<tr><td colspan="4" class="muted">读取失败：${h(err.message)}</td></tr>`;
            return;
        }
        overlay.addEventListener('click', event => {
            const button = event.target.closest('[data-dns-history-copy]');
            if (!button) return;
            const entry = entries.find(item => item.id === button.dataset.dnsHistoryCopy);
            if (entry) copyText(entryLogText(entry), '变更记录已复制，可直接粘贴给 AI 助手');
        });
        overlay.querySelector('#dns-history-copy-all').addEventListener('click', () => {
            if (!entries.length) { toast('没有可复制的变更记录', 'info'); return; }
            copyText(entries.map(entryLogText).join('\n\n'), `已复制 ${entries.length} 条变更记录`);
        });
        overlay.querySelector('.modal-close')?.focus();
    }

    return {open};
})();
