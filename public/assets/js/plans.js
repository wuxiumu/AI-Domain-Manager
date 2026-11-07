'use strict';

/* 改进计划：功能点条目的提出、逐条审核与执行跟踪。数据与动作都走 /api/plans*（仅管理员会话）。
   列表行点击弹窗查看详情（标题 + Markdown 正文），弹窗内可直接进入编辑。 */
window.WorkspacePlans = (() => {
    let items = [];
    const visible = () => !$('#view-plans').classList.contains('hidden');

    const STATUS_LABELS = { proposed: '待审核', approved: '已通过', in_progress: '执行中', done: '已完成', rejected: '已驳回' };
    const STATUS_BADGES = { proposed: 'b-amber', approved: 'b-blue', in_progress: 'b-green', done: 'b-green', rejected: 'b-gray' };
    const PRIORITY_LABELS = { high: '高', medium: '中', low: '低' };
    const PRIORITY_BADGES = { high: 'b-red', medium: 'b-amber', low: 'b-gray' };
    const SOURCE_LABELS = { agent: 'AI 提出', admin: '手动新增' };

    function error(message = '') {
        $('#plans-error').textContent = message;
        $('#plans-error').classList.toggle('hidden', !message);
    }

    async function load() {
        error();
        try {
            const data = await api('/api/plans');
            items = data.items || [];
            render();
        } catch (failure) { if (visible()) error(failure.message); }
    }

    function render() {
        $('#plans-count').textContent = `${items.filter(i => i.status === 'proposed').length} 条待审核 · 共 ${items.length} 条`;
        $('#plans-empty').classList.toggle('hidden', items.length > 0);
        $('#plans-table tbody').innerHTML = items.length ? items.map(row).join('')
            : '<tr><td colspan="6"><div class="hc-empty">暂无计划条目。</div></td></tr>';
    }

    function row(item) {
        const preview = item.detail ? `<small class="hc-subline muted">${escapeHtml(firstLine(item.detail))}</small>` : '';
        const reviewNote = item.review_note ? `<small class="hc-subline">审核意见：${escapeHtml(item.review_note)}</small>` : '';
        const resultNote = item.result_note ? `<small class="hc-subline">执行结果：${escapeHtml(item.result_note)}</small>` : '';
        return `<tr data-plan-id="${escapeHtml(item.id)}" class="plan-row" title="点击查看详情">
            <td><button type="button" class="plan-title-btn" data-plan-action="view">${escapeHtml(item.title)}</button>${reviewNote}${resultNote}${preview}</td>
            <td><span class="badge ${PRIORITY_BADGES[item.priority] || 'b-gray'}">${PRIORITY_LABELS[item.priority] || escapeHtml(item.priority)}</span></td>
            <td><span class="muted">${SOURCE_LABELS[item.source] || escapeHtml(item.source)}</span></td>
            <td><span class="badge ${STATUS_BADGES[item.status] || 'b-gray'}">${STATUS_LABELS[item.status] || escapeHtml(item.status)}</span></td>
            <td class="muted">${escapeHtml(item.updated_at || '')}</td>
            <td class="row-actions">${actions(item)}</td></tr>`;
    }

    function firstLine(text) {
        const line = String(text).split(/\r?\n/).find(part => part.trim() !== '') || '';
        return line.length > 80 ? line.slice(0, 80) + '…' : line;
    }

    function actions(item) {
        const buttons = [];
        if (item.status === 'proposed' || item.status === 'rejected') {
            buttons.push(`<button class="btn btn-sm btn-primary" data-plan-action="approve">通过</button>`);
            buttons.push(`<button class="btn btn-sm btn-link-danger" data-plan-action="reject">驳回</button>`);
        }
        if (item.status === 'approved') buttons.push(`<button class="btn btn-sm" data-plan-action="start">开始执行</button>`);
        if (item.status === 'in_progress') buttons.push(`<button class="btn btn-sm btn-primary" data-plan-action="finish">标记完成</button>`);
        buttons.push(`<button class="btn btn-sm btn-link" data-plan-action="edit">编辑</button>`);
        buttons.push(`<button class="btn btn-sm btn-link-danger" data-plan-action="delete">删除</button>`);
        return buttons.join('');
    }

    /* ---------- 极简 Markdown：先整体转义再生成标签，杜绝注入；支持 ## 标题、**加粗**、`行内代码`、链接、- / 1. 列表 ---------- */
    function renderMarkdown(source) {
        const inline = text => text
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
        const blocks = [];
        let list = null;
        const flushList = () => {
            if (!list) return;
            blocks.push(`<${list.tag}>${list.items.map(item => `<li>${inline(escapeHtml(item))}</li>`).join('')}</${list.tag}>`);
            list = null;
        };
        for (const line of String(source || '').split(/\r?\n/)) {
            if (line.trim() === '') { flushList(); continue; }
            const heading = line.match(/^#{2,4}\s+(.*)$/);
            if (heading) { flushList(); blocks.push(`<h4>${inline(escapeHtml(heading[1]))}</h4>`); continue; }
            const unordered = line.match(/^\s*[-*]\s+(.*)$/);
            const ordered = line.match(/^\s*\d+[.、]\s+(.*)$/);
            if (unordered || ordered) {
                const tag = unordered ? 'ul' : 'ol';
                if (!list || list.tag !== tag) { flushList(); list = { tag, items: [] }; }
                list.items.push((unordered || ordered)[1]);
                continue;
            }
            flushList();
            blocks.push(`<p>${inline(escapeHtml(line))}</p>`);
        }
        flushList();
        return blocks.join('') || '<p class="muted">暂无详情，点击「编辑」补充。</p>';
    }

    /* ---------- 详情弹窗：点击行 / 标题打开，弹窗内编辑与状态动作 ---------- */
    function openView(item) {
        const { overlay, close } = openModal('plan-detail-template');
        overlay.querySelector('#plan-detail-title').textContent = item.title;
        overlay.querySelector('#plan-detail-meta').innerHTML =
            `<span class="badge ${STATUS_BADGES[item.status] || 'b-gray'}">${STATUS_LABELS[item.status] || ''}</span>` +
            `<span class="badge ${PRIORITY_BADGES[item.priority] || 'b-gray'}">${PRIORITY_LABELS[item.priority] || ''}优先级</span>` +
            `<span class="badge b-gray">${SOURCE_LABELS[item.source] || escapeHtml(item.source)}</span>` +
            `<span class="muted">更新于 ${escapeHtml(item.updated_at || '未知')}${item.reviewed_at ? ' · 审核于 ' + escapeHtml(item.reviewed_at) : ''}</span>`;
        overlay.querySelector('#plan-detail-content').innerHTML = renderMarkdown(item.detail);
        const review = overlay.querySelector('#plan-detail-review');
        review.textContent = item.review_note ? `审核意见：${item.review_note}` : '';
        review.classList.toggle('hidden', !item.review_note);
        const result = overlay.querySelector('#plan-detail-result');
        result.textContent = item.result_note ? `执行结果：${item.result_note}` : '';
        result.classList.toggle('hidden', !item.result_note);
        const foot = overlay.querySelector('#plan-detail-actions');
        foot.insertAdjacentHTML('afterbegin', actions(item));
        foot.addEventListener('click', event => {
            const button = event.target.closest('[data-plan-action]');
            if (!button) return;
            const action = button.dataset.planAction;
            close();
            runAction(item, action).catch(failure => toast(failure.message, 'error', 6000));
        });
        overlay.querySelector('[data-plan-close]').onclick = close;
    }

    /* ---------- 通用弹窗骨架（与 IP 创建弹窗同一套键盘与关闭行为） ---------- */
    function openModal(templateId) {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = $(`#${templateId}`).innerHTML;
        const returnFocus = document.activeElement;
        (document.fullscreenElement || document.body).appendChild(overlay);
        const close = () => { overlay.remove(); returnFocus?.focus(); };
        overlay.querySelector('.modal-close').onclick = close;
        const cancel = overlay.querySelector('[data-plan-cancel]');
        if (cancel) cancel.onclick = close;
        const plainClose = overlay.querySelector('[data-plan-close]');
        if (plainClose) plainClose.onclick = close;
        overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
        overlay.addEventListener('keydown', event => {
            if (event.key === 'Escape') { event.preventDefault(); close(); }
            if (event.key === 'Tab') {
                const focusable = [...overlay.querySelectorAll('button:not(:disabled),input,select,textarea')];
                const first = focusable[0], last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
            }
        });
        return { overlay, close };
    }

    function showFormError(overlay, id, message) {
        const el = overlay.querySelector(`#${id}`);
        el.textContent = message;
        el.classList.toggle('hidden', !message);
    }

    /* ---------- 新增 / 编辑 ---------- */
    $('#plan-open-create').addEventListener('click', () => openForm(null));

    function openForm(item) {
        const { overlay } = openModal('plan-form-template');
        overlay.querySelector('#plan-form-title').textContent = item ? '编辑计划点' : '新增计划点';
        overlay.querySelector('#plan-form-id').value = item?.id || '';
        overlay.querySelector('#plan-form-title-input').value = item?.title || '';
        overlay.querySelector('#plan-form-priority').value = item?.priority || 'medium';
        overlay.querySelector('#plan-form-detail').value = item?.detail || '';
        overlay.querySelector('#plan-form-title-input').focus();
        overlay.querySelector('#plan-form').addEventListener('submit', async event => {
            event.preventDefault();
            const body = {
                title: overlay.querySelector('#plan-form-title-input').value.trim(),
                priority: overlay.querySelector('#plan-form-priority').value,
                detail: overlay.querySelector('#plan-form-detail').value,
            };
            if (!body.title) { showFormError(overlay, 'plan-form-error', '请填写标题'); return; }
            const id = overlay.querySelector('#plan-form-id').value;
            const button = overlay.querySelector('#plan-form-submit');
            button.disabled = true;
            try {
                await api(id ? `/api/plans/${encodeURIComponent(id)}` : '/api/plans', { method: id ? 'PUT' : 'POST', body: JSON.stringify(body) });
                toast(id ? '计划点已更新' : '计划点已提交，等待审核');
                overlay.remove();
                await load();
            } catch (failure) { showFormError(overlay, 'plan-form-error', failure.message); button.disabled = false; }
        });
    }

    /* ---------- 审核：通过 / 驳回（驳回必填原因）；完成时复用同一弹窗填执行结果 ---------- */
    function openReview(item, decision) {
        const { overlay } = openModal('plan-review-template');
        const noteHint = overlay.querySelector('#plan-review-note-hint');
        const submit = overlay.querySelector('#plan-review-submit');
        let request;
        let successMessage;
        if (decision === 'finish') {
            overlay.querySelector('#plan-review-title').textContent = '标记完成';
            overlay.querySelector('#plan-review-target').textContent = `「${item.title}」`;
            noteHint.textContent = '选填，执行结果说明';
            submit.textContent = '确认完成';
            request = note => api(`/api/plans/${encodeURIComponent(item.id)}`, { method: 'PUT', body: JSON.stringify({ status: 'done', result_note: note }) });
            successMessage = '已完成';
        } else {
            overlay.querySelector('#plan-review-title').textContent = decision === 'approve' ? '通过计划点' : '驳回计划点';
            overlay.querySelector('#plan-review-target').textContent = `「${item.title}」`;
            if (decision !== 'approve') {
                noteHint.textContent = '必填，说明原因';
                submit.textContent = '确认驳回';
                submit.classList.add('btn-link-danger');
            }
            request = note => api(`/api/plans/${encodeURIComponent(item.id)}/review`, { method: 'POST', body: JSON.stringify({ decision, note }) });
            successMessage = decision === 'approve' ? '已通过，可开始执行' : '已驳回';
        }
        overlay.querySelector('#plan-review-note').focus();
        overlay.querySelector('#plan-review-form').addEventListener('submit', async event => {
            event.preventDefault();
            const note = overlay.querySelector('#plan-review-note').value.trim();
            if (decision === 'reject' && !note) { showFormError(overlay, 'plan-review-error', '驳回时请填写原因，便于修订后重新提交'); return; }
            submit.disabled = true;
            try {
                await request(note);
                toast(successMessage);
                overlay.remove();
                await load();
            } catch (failure) { showFormError(overlay, 'plan-review-error', failure.message); submit.disabled = false; }
        });
    }

    /* ---------- 动作分发：列表行内按钮与详情弹窗共用 ---------- */
    async function runAction(item, action) {
        if (action === 'view') { openView(item); return; }
        if (action === 'approve' || action === 'reject' || action === 'finish') { openReview(item, action); return; }
        if (action === 'edit') { openForm(item); return; }
        if (action === 'start') {
            await api(`/api/plans/${encodeURIComponent(item.id)}`, { method: 'PUT', body: JSON.stringify({ status: 'in_progress' }) });
            toast('已标记执行中');
        } else if (action === 'delete') {
            if (!await confirmDialog({ title: '删除计划点', message: `删除「${item.title}」？删除后不可恢复。`, confirmText: '删除', danger: true })) return;
            await api(`/api/plans/${encodeURIComponent(item.id)}`, { method: 'DELETE' });
            toast('已删除');
        }
        await load();
    }

    /* ---------- 列表交互：行内按钮 + 点击行打开详情 ---------- */
    $('#plans-table').addEventListener('click', async event => {
        const button = event.target.closest('[data-plan-action]');
        const tr = event.target.closest('tr[data-plan-id]');
        const item = items.find(i => i.id === tr?.dataset.planId);
        if (!item) return;
        // 点按钮走对应动作；点行内其它位置打开详情弹窗。
        const action = button ? button.dataset.planAction : 'view';
        try { await runAction(item, action); } catch (failure) { error(failure.message); }
    });

    return { load };
})();
