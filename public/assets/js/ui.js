'use strict';

/* ============ UI 组件：Toast 通知 / 确认弹窗 / 输入弹窗 ============ */

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
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
            <div class="modal modal-sm">
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

        const close = (val) => { overlay.remove(); resolve(val); };
        overlay.querySelector('.modal-close').addEventListener('click', () => close(false));
        overlay.querySelector('.btn-cancel').addEventListener('click', () => close(false));
        overlay.querySelector('.btn-confirm').addEventListener('click', () => close(true));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(false); });
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

        const input = overlay.querySelector('.dialog-input');
        input.focus();
        input.select();

        const close = (val) => { overlay.remove(); resolve(val); };
        overlay.querySelector('.modal-close').addEventListener('click', () => close(null));
        overlay.querySelector('.btn-cancel').addEventListener('click', () => close(null));
        overlay.querySelector('.btn-ok').addEventListener('click', () => close(input.value));
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') close(input.value); });
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(null); });
    });
}
