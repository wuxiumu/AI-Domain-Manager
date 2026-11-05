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
    function confirm(plans, independent = false, skipped = 0) {
        if (!plans.length) return Promise.resolve(false);
        return new Promise(resolve => {
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay dns-review-overlay';
            overlay.innerHTML = `<section class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="dns-review-title"><div class="modal-head"><h3 id="dns-review-title">确认 DNS 变更 · ${plans.length} 项</h3><button class="modal-close" aria-label="关闭变更预览">×</button></div><div class="modal-body"><p class="dns-review-note">提交前会逐条核对云端当前值；提交后会回读核实结果。</p>${plans.map(plan => `<section class="dns-review-item"><p class="muted">${escapeHtml(plan.target)}</p><h4>${escapeHtml(actions[plan.action])} · ${escapeHtml((plan.before || plan.after)?.name || '@')} <span class="muted">${escapeHtml((plan.before || plan.after)?.type || '')}</span></h4><div class="table-wrap"><table><thead><tr><th>字段</th><th>变更前</th><th>拟提交</th></tr></thead><tbody>${Object.entries(labels).map(([field, label]) => `<tr class="${shown(plan.before, field) !== shown(plan.after, field) ? 'dns-field-changed' : ''}"><th>${label}</th><td>${escapeHtml(shown(plan.before, field))}</td><td>${escapeHtml(shown(plan.after, field))}</td></tr>`).join('')}</tbody></table></div></section>`).join('')}<p class="muted">回读确认不代表全球 DNS 已传播。批量操作逐条执行，不会自动撤销已完成的变更；${independent ? '单条失败或待核实时继续其他记录，不会自动重试。' : '结果待核实时会停止后续提交。'}</p></div><div class="modal-foot"><button class="btn dns-review-cancel">返回修改</button><button class="btn ${plans.some(plan => plan.action === 'delete') ? 'btn-danger' : 'btn-primary'} dns-review-confirm">确认提交到云端</button></div></section>`;
            const previous = document.activeElement;
            if (skipped) {
                const notice = document.createElement('p');
                notice.className = 'error-text';
                notice.textContent = `${skipped} 条记录缺少有效版本，无法提交；其余记录可继续，详情将在报告中显示。`;
                overlay.querySelector('.modal-body').prepend(notice);
            }
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
    function parseBatchAdds(text) {
        const types = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];
        const entries = [];
        text.split(/\r?\n/).forEach((line, index) => {
            if (!line.trim()) return;
            const fields = line.split('\t').map(field => field.trim());
            if (fields.length < 3 || fields.length > 5) throw new Error(`第 ${index + 1} 行：请用 Tab 分隔 3–5 个字段`);
            const [type, name, value, ttl = '', priority = ''] = fields;
            const normalizedType = type.toUpperCase();
            if (!types.includes(normalizedType) || !value || name.length > 253 || value.length > 4096) throw new Error(`第 ${index + 1} 行：请检查记录类型、主机记录和记录值`);
            const integer = (input, fallback, min, max) => {
                if (!input) return fallback;
                if (!/^\d+$/.test(input)) return NaN;
                const n = Number(input);
                return Number.isSafeInteger(n) && n >= min && n <= max ? n : NaN;
            };
            const seconds = integer(ttl, 600, 1, 2147483647), rank = integer(priority, 0, 0, 65535);
            if (!Number.isFinite(seconds) || !Number.isFinite(rank)) throw new Error(`第 ${index + 1} 行：TTL 或优先级无效`);
            entries.push({type: normalizedType, name: name || '@', value, ttl: seconds, priority: rank});
        });
        if (!entries.length) throw new Error('请填写至少一条解析记录');
        return entries;
    }
    async function writeBatchAdds(plans, progress = () => {}) {
        let revision = plans[0]?.expected, completed = 0;
        for (const plan of plans) {
            progress(completed + 1, plans.length);
            try {
                const result = await write({...plan, expected: revision});
                if (!result.verified) return {completed, message: `第 ${completed + 1} 条结果待核实，后续 ${plans.length - completed - 1} 条未提交。请刷新云端后核对，避免重复添加。${result.message || ''}`};
                completed++;
                if (completed < plans.length && !/^[a-f0-9]{64}$/.test(result.revision || '')) return {completed, message: `未取得新版本，后续 ${plans.length - completed} 条未提交。请刷新云端后核对。`};
                revision = result.revision;
            } catch (error) {
                const unknown = !error.status || error.status >= 500;
                return {completed, message: `第 ${completed + 1} 条${unknown ? '结果待核实' : '提交失败'}，后续 ${plans.length - completed - 1} 条未提交：${error.message || '请求未完成'}。请刷新云端后核对，避免重复添加。`};
            }
        }
        return {completed, message: '全部提交并回读核实'};
    }
    async function batchValues(options, progress = () => {}) {
        const items = options.map(option => {
            try { return {option, plan: prepare(option)}; }
            catch (error) { return {option, status:'未提交', message:error.message}; }
        });
        const plans = items.filter(item => item.plan).map(item => item.plan);
        if (plans.length && !await confirm(plans, true, items.length - plans.length)) return null;
        let done = 0;
        for (const item of items) {
            if (!item.plan) { done++; progress(done, items.length); continue; }
            if (item.plan.owner && !item.plan.owner.isConnected) {
                item.status = '未提交'; item.message = '操作窗口已关闭';
            } else {
                try {
                    const result = await write(item.plan);
                    item.status = result.verified ? '已核实' : '待核实';
                    item.message = result.message || '';
                    item.changeId = result.change_id || '';
                } catch (error) {
                    item.status = !error.status || error.status >= 500 ? '待核实' : '失败';
                    item.message = (error.message || '请求未完成') + (error.status ? `（HTTP ${error.status}）` : '（请求结果未知，请核实）');
                }
            }
            done++; progress(done, items.length);
        }
        return items;
    }
    function showBatchReport(items, caption) {
        const counts = ['已核实','失败','待核实','未提交'].map(status => `${status} ${items.filter(item => item.status === status).length}`).join(' · ');
        const lines = [caption, counts, ''];
        items.forEach((item, index) => {
            const record = item.option.record || {};
            lines.push(`${index + 1}. ${item.status} | ${item.option.target} | ${record.name || '@'} ${record.type || ''} | 记录 ID: ${record.id ?? '未知'}`,
                `   ${record.value ?? '—'} → ${item.option.params?.value ?? '—'} | ${item.message || '—'}${item.changeId ? ' | 变更编号: ' + item.changeId : ''}`);
        });
        lines.push('', '待核实的记录请先刷新云端确认，不要重复提交。回读成功不代表全球 DNS 已传播。');
        const modal = document.createElement('div'); modal.className = 'modal-overlay dns-report-overlay';
        modal.innerHTML = `<section class="modal modal-wide" role="dialog" aria-modal="true" aria-label="批量修改结果"><div class="modal-head"><h3>批量修改结果</h3><button class="modal-close" aria-label="关闭结果">×</button></div><div class="modal-body"><p class="dns-report-summary"></p><label>详细报告，可选择复制<textarea class="dns-report-text" readonly rows="14" style="width:100%;resize:vertical"></textarea></label><p class="muted dns-report-message" role="status"></p></div><div class="modal-foot"><button class="btn dns-report-close">关闭</button><button class="btn btn-primary dns-report-copy">复制报告</button></div></section>`;
        (document.fullscreenElement || document.body).appendChild(modal);
        const textarea = modal.querySelector('.dns-report-text'); textarea.value = lines.join('\n');
        modal.querySelector('.dns-report-summary').textContent = counts;
        const close = () => modal.remove();
        modal.querySelector('.modal-close').onclick = close; modal.querySelector('.dns-report-close').onclick = close;
        modal.querySelector('.dns-report-copy').onclick = async () => {
            if (await copyText(textarea.value, '报告已复制')) return;
            textarea.focus(); textarea.select(); modal.querySelector('.dns-report-message').textContent = '请复制已选中的报告';
        };
        textarea.focus();
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
    return {prepare, confirm, write, perform, notify, parseBatchAdds, writeBatchAdds, batchValues, showBatchReport};
})();
