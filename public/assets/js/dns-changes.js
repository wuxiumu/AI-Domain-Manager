'use strict';

window.WorkspaceDns = (() => {
    const labels = {type: '类型', name: '主机记录', value: '记录值', ttl: 'TTL（秒）', priority: '优先级', line: '线路'};
    const actions = {add: '新增', update: '修改', delete: '删除'};
    const shown = (record, field) => record ? String(record[field] ?? (['ttl', 'priority'].includes(field) ? 0 : '')) || (field === 'line' ? '云商默认' : '—') : '—';
    function prepare({endpoint, action, record = null, params = null, revision = '', owner = null, target = ''}) {
        const before = record ? {...record} : null;
        const after = action === 'delete' ? null : {...(before || {}), ...(params || {})};
        const expected = action === 'add' ? revision : before?.version;
        if (!/^[a-f0-9]{64}$/.test(expected || '')) throw new Error('缺少 DNS 版本，请先刷新云端记录后重新编辑');
        return {endpoint, action, before, after, params: params ? {...params} : null, expected, owner, target};
    }
    function confirm(plans) {
        if (!plans.length) return Promise.resolve(false);
        return new Promise(resolve => {
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay dns-review-overlay';
            overlay.innerHTML = `<section class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="dns-review-title"><div class="modal-head"><h3 id="dns-review-title">确认 DNS 变更 · ${plans.length} 项</h3><button class="modal-close" aria-label="关闭变更预览">×</button></div><div class="modal-body"><p class="dns-review-note">提交前会核对云端当前值。其他人已修改时，本次操作会停止；提交后会回读核实结果。</p>${plans.map(plan => `<section class="dns-review-item"><p class="muted">${escapeHtml(plan.target)}</p><h4>${escapeHtml(actions[plan.action])} · ${escapeHtml((plan.before || plan.after)?.name || '@')} <span class="muted">${escapeHtml((plan.before || plan.after)?.type || '')}</span></h4><div class="table-wrap"><table><thead><tr><th>字段</th><th>变更前</th><th>拟提交</th></tr></thead><tbody>${Object.entries(labels).map(([field, label]) => `<tr class="${shown(plan.before, field) !== shown(plan.after, field) ? 'dns-field-changed' : ''}"><th>${label}</th><td>${escapeHtml(shown(plan.before, field))}</td><td>${escapeHtml(shown(plan.after, field))}</td></tr>`).join('')}</tbody></table></div></section>`).join('')}<p class="muted">回读确认不代表全球 DNS 已传播。批量操作逐条执行，不会自动撤销已完成的变更；结果待核实时会停止后续提交。</p></div><div class="modal-foot"><button class="btn dns-review-cancel">返回修改</button><button class="btn ${plans.some(plan => plan.action === 'delete') ? 'btn-danger' : 'btn-primary'} dns-review-confirm">确认提交到云端</button></div></section>`;
            const previous = document.activeElement;
            let closed = false;
            const close = value => {
                if (closed) return;
                closed = true; overlay.remove(); previous?.focus?.(); resolve(value);
            };
            overlay.querySelector('.modal-close').onclick = () => close(false);
            overlay.querySelector('.dns-review-cancel').onclick = () => close(false);
            overlay.querySelector('.dns-review-confirm').onclick = () => close(plans.every(plan => !plan.owner || plan.owner.isConnected));
            overlay.addEventListener('click', event => { if (event.target === overlay) close(false); });
            overlay.addEventListener('keydown', event => { if (event.key === 'Escape') { event.stopPropagation(); close(false); } });
            (document.fullscreenElement || document.body).appendChild(overlay);
            overlay.querySelector('.dns-review-cancel').focus();
        });
    }
    async function write(plan) {
        if (plan.owner && !plan.owner.isConnected) throw new Error('操作窗口已关闭，后续记录未提交');
        const body = {...(plan.params || {}), [plan.action === 'add' ? 'expected_revision' : 'expected_version']: plan.expected};
        const method = {add: 'POST', update: 'PUT', delete: 'DELETE'}[plan.action];
        return api(plan.endpoint, {method, body: JSON.stringify(body)});
    }
    async function perform(options) {
        const plan = prepare(options);
        if (!await confirm([plan])) return null;
        return write(plan);
    }
    function notify(result) {
        if (!result) return;
        toast(result.message || (result.verified ? '云端回读已核实' : '云端结果待核实，请刷新记录'), result.verified ? 'success' : 'error', result.verified ? 4500 : 10000);
    }
    return {prepare, confirm, write, perform, notify};
})();
