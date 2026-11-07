'use strict';

/* 提示词库：prompts/*.md 的只读展示。内容即文件——调整直接编辑 md，页面只负责渲染与复制。 */
window.WorkspacePrompts = (() => {
    let items = [];
    const visible = () => !$('#view-prompts').classList.contains('hidden');

    function error(message = '') {
        $('#prompts-error').textContent = message;
        $('#prompts-error').classList.toggle('hidden', !message);
    }

    async function load() {
        error();
        try {
            const data = await api('/api/prompts');
            items = data.items || [];
            render();
        } catch (failure) { if (visible()) error(failure.message); }
    }

    function render() {
        $('#prompts-count').textContent = `${items.length} 篇`;
        $('#prompts-list').innerHTML = items.length ? items.map(card).join('')
            : '<div class="hc-empty">提示词库为空。在项目 <code>prompts/</code> 目录放入 .md 文件即可出现在这里。</div>';
    }

    function card(item) {
        const preview = item.content.split(/\r?\n/).filter(line => line.trim() !== '' && !line.startsWith('# ')).slice(0, 2).join(' ');
        return `<div class="prompt-card" data-prompt-name="${escapeHtml(item.name)}">
            <div class="prompt-card-head"><button type="button" class="plan-title-btn" data-prompt-action="view">${escapeHtml(item.title)}</button>
            <button type="button" class="btn btn-sm" data-prompt-action="copy" title="复制 Markdown 原文">复制全文</button></div>
            <small class="hc-subline muted">${escapeHtml(item.file)} · 更新于 ${escapeHtml(item.updated_at)} · ${item.bytes} 字节</small>
            ${preview ? `<p class="prompt-preview muted">${escapeHtml(preview)}</p>` : ''}</div>`;
    }

    function openDetail(item) {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `<section class="modal plan-detail-modal" role="dialog" aria-modal="true" aria-labelledby="prompt-detail-title">
            <div class="modal-head"><h3 id="prompt-detail-title">${escapeHtml(item.title)}</h3><button type="button" class="modal-close" aria-label="关闭详情">×</button></div>
            <div class="modal-body"><p class="plan-meta muted">${escapeHtml(item.file)} · 更新于 ${escapeHtml(item.updated_at)}</p>
            <div class="plan-md">${renderMarkdown(item.content)}</div></div>
            <div class="modal-foot"><button type="button" class="btn btn-sm" data-prompt-copy>复制原文</button><button type="button" class="btn" data-prompt-close>关闭</button></div>
        </section>`;
        const returnFocus = document.activeElement;
        (document.fullscreenElement || document.body).appendChild(overlay);
        const close = () => { overlay.remove(); returnFocus?.focus(); };
        overlay.querySelector('.modal-close').onclick = close;
        overlay.querySelector('[data-prompt-close]').onclick = close;
        overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
        overlay.addEventListener('keydown', event => { if (event.key === 'Escape') { event.preventDefault(); close(); } });
        overlay.querySelector('[data-prompt-copy]').addEventListener('click', () => copyText(item.content, '提示词原文已复制'));
    }

    $('#prompts-list').addEventListener('click', event => {
        const button = event.target.closest('[data-prompt-action]');
        const cardEl = event.target.closest('[data-prompt-name]');
        const item = items.find(i => i.name === cardEl?.dataset.promptName);
        if (!item) return;
        const action = button ? button.dataset.promptAction : 'view';
        if (action === 'copy') { copyText(item.content, '提示词原文已复制'); return; }
        openDetail(item);
    });

    return { load };
})();
