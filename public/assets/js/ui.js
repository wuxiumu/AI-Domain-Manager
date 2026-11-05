'use strict';

/* ============ UI 组件：Toast 通知 / 确认弹窗 / 输入弹窗 ============ */

/* 转义统一走 core.js 的 escapeHtml（补齐单引号），避免两套实现行为漂移。 */
function esc(s) {
    return escapeHtml(s);
}

/* ---------- Toast：自动消失、可堆叠、点击可提前关闭 ---------- */
let toastContainer = null;

function ensureToastContainer() {
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.className = 'toast-container';
        document.body.appendChild(toastContainer);
    }
    return toastContainer;
}

function toast(message, type = 'success', duration = 3500) {
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    const icon = type === 'success' ? '✓' : type === 'error' ? '✗' : 'ℹ';
    el.innerHTML = `<span class="toast-icon">${icon}</span><span class="toast-msg">${esc(message)}</span>`;
    ensureToastContainer().appendChild(el);

    requestAnimationFrame(() => el.classList.add('show'));

    const remove = () => {
        el.classList.remove('show');
        setTimeout(() => el.remove(), 260);
    };
    el.addEventListener('click', remove);
    setTimeout(remove, duration);
}

/* ---------- 复制文本：剪贴板 API 不可用时（HTTP 自托管）回退隐藏文本框 ---------- */
async function copyText(text, okMessage = '已复制到剪贴板') {
    try {
        await navigator.clipboard.writeText(text);
        toast(okMessage);
        return true;
    } catch {
        const helper = document.createElement('textarea');
        helper.value = text;
        helper.setAttribute('readonly', '');
        helper.style.position = 'fixed';
        helper.style.opacity = '0';
        document.body.appendChild(helper);
        helper.focus();
        helper.select();
        let copied = false;
        try { copied = document.execCommand('copy'); } catch { copied = false; }
        helper.remove();
        toast(copied ? okMessage : '浏览器拒绝了复制，请手动选择文本复制', copied ? 'success' : 'error', 5000);
        return copied;
    }
}

/* ---------- 对话框键盘与焦点：Esc 取消、Tab 圈闭、关闭后归还焦点 ---------- */
function bindDialogFocus(overlay, close) {
    const previous = document.activeElement;
    const focusables = () => Array.from(overlay.querySelectorAll('button, input, textarea, select, a[href]'))
        .filter((el) => !el.disabled && el.offsetParent !== null);
    overlay.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { e.preventDefault(); close(null); return; }
        if (e.key === 'Tab') {
            const items = focusables();
            if (!items.length) return;
            const first = items[0], last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });
    return () => { if (previous && typeof previous.focus === 'function') previous.focus(); };
}

/* ---------- 确认弹窗：返回 Promise<boolean> ---------- */
function confirmDialog(options = {}) {
    const {
        title = '确认操作',
        message = '',
        confirmText = '确定',
        danger = false,
    } = options;

    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal modal-sm" role="dialog" aria-modal="true">
                <div class="modal-head">
                    <h3>${esc(title)}</h3>
                    <button class="modal-close">×</button>
                </div>
                <div class="modal-body">
                    <p class="dialog-msg">${esc(message).replace(/\n/g, '<br>')}</p>
                </div>
                <div class="modal-foot">
                    <button class="btn btn-cancel">取消</button>
                    <button class="btn ${danger ? 'btn-danger' : 'btn-primary'} btn-confirm">${esc(confirmText)}</button>
                </div>
            </div>`;
        (document.fullscreenElement || document.body).appendChild(overlay);

        const close = (val) => { restoreFocus(); overlay.remove(); resolve(val); };
        const restoreFocus = bindDialogFocus(overlay, close);
        overlay.querySelector('.modal-close').addEventListener('click', () => close(false));
        overlay.querySelector('.btn-cancel').addEventListener('click', () => close(false));
        const confirmBtn = overlay.querySelector('.btn-confirm');
        confirmBtn.addEventListener('click', () => close(true));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(false); });
        confirmBtn.focus();
    });
}

/* ---------- 输入弹窗：返回 Promise<string|null> ---------- */
function promptDialog(options = {}) {
    const {
        title = '输入',
        message = '',
        value = '',
        placeholder = '',
        okText = '确定',
    } = options;

    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal modal-sm">
                <div class="modal-head">
                    <h3>${esc(title)}</h3>
                    <button class="modal-close">×</button>
                </div>
                <div class="modal-body">
                    ${message ? `<p class="dialog-msg">${esc(message).replace(/\n/g, '<br>')}</p>` : ''}
                    <input type="text" class="dialog-input" value="${esc(value)}" placeholder="${esc(placeholder)}">
                </div>
                <div class="modal-foot">
                    <button class="btn btn-cancel">取消</button>
                    <button class="btn btn-primary btn-ok">${esc(okText)}</button>
                </div>
            </div>`;
        (document.fullscreenElement || document.body).appendChild(overlay);

        const close = (val) => { restoreFocus(); overlay.remove(); resolve(val); };
        const restoreFocus = bindDialogFocus(overlay, close);
        const input = overlay.querySelector('.dialog-input');
        input.focus();
        input.select();

        overlay.querySelector('.modal-close').addEventListener('click', () => close(null));
        overlay.querySelector('.btn-cancel').addEventListener('click', () => close(null));
        overlay.querySelector('.btn-ok').addEventListener('click', () => close(input.value));
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') close(input.value); });
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(null); });
    });
}
