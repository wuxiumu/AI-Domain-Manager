/**
 * 独立管理页共享工具（/settings、/calendar）：escapeHtml、fetchJson、toast、确认弹窗、复制。
 * 自包含基础模块；会话写操作统一携带 X-CSRF-Token，401 时跳回登录页。
 */
(function () {
'use strict';

var boot = window.__ADMIN__ || {};

function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function fetchJson(url, opts) {
    opts = opts || {};
    return window.fetch(url, {
        method: opts.method || 'GET',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': boot.csrf || '' },
        body: opts.body ? JSON.stringify(opts.body) : null,
        credentials: 'same-origin'
    }).then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
            if (res.status === 401) { window.location.href = '/'; }
            if (!res.ok || data.ok === false) {
                var err = new Error(data.error || ('请求失败 HTTP ' + res.status));
                err.status = res.status;
                throw err;
            }
            return data;
        });
    });
}

var toastTimer = null;
function toast(message, type) {
    var el = document.getElementById('ap-toast');
    if (!el) { return; }
    el.textContent = message;
    el.classList.toggle('is-error', type === 'error');
    el.classList.add('is-show');
    if (toastTimer) { window.clearTimeout(toastTimer); }
    toastTimer = window.setTimeout(function () { el.classList.remove('is-show'); }, 2600);
}

/** 确认弹窗：resolve(true/false)。danger 时确认按钮为红色。 */
function confirmDialog(options) {
    options = options || {};
    return new Promise(function (resolve) {
        var overlay = document.createElement('div');
        overlay.className = 'ap-overlay';
        overlay.innerHTML =
            '<section class="ap-modal" role="dialog" aria-modal="true" aria-label="' + escapeHtml(options.title || '确认') + '">' +
            '<div class="ap-modal-head"><h3>' + escapeHtml(options.title || '确认操作') + '</h3>' +
            '<button type="button" class="ap-modal-close" aria-label="关闭">✕</button></div>' +
            '<p style="color:var(--muted);font-size:13px">' + escapeHtml(options.message || '') + '</p>' +
            '<div class="ap-modal-foot">' +
            '<button type="button" class="ap-btn" data-ap-cancel>取消</button>' +
            '<button type="button" class="ap-btn ' + (options.danger ? 'ap-btn-danger' : 'ap-btn-primary') + '" data-ap-ok>' + escapeHtml(options.confirmText || '确认') + '</button>' +
            '</div></section>';
        document.body.appendChild(overlay);
        var done = function (result) { overlay.remove(); resolve(result); };
        overlay.querySelector('[data-ap-ok]').addEventListener('click', function () { done(true); });
        overlay.querySelector('[data-ap-cancel]').addEventListener('click', function () { done(false); });
        overlay.querySelector('.ap-modal-close').addEventListener('click', function () { done(false); });
        overlay.addEventListener('click', function (event) { if (event.target === overlay) { done(false); } });
        overlay.addEventListener('keydown', function (event) { if (event.key === 'Escape') { done(false); } });
        overlay.querySelector('[data-ap-ok]').focus();
    });
}

function copyText(text, okMessage) {
    var done = function () { toast(okMessage || '已复制'); };
    var fallback = function () {
        var input = document.createElement('textarea');
        input.value = text;
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        try { document.execCommand('copy'); done(); } catch (e) { toast('复制失败，请手动复制', 'error'); }
        input.remove();
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        var settled = false;
        navigator.clipboard.writeText(text).then(function () {
            if (!settled) { settled = true; done(); }
        }, function () {
            if (!settled) { settled = true; fallback(); }
        });
        // 部分环境 clipboard 权限请求会挂起：超时走 execCommand 兜底
        setTimeout(function () { if (!settled) { settled = true; fallback(); } }, 400);
    } else { fallback(); }
}

/**
 * Markdown 渲染：优先使用本地自托管的开源库管线（marked 解析 + DOMPurify 清洗），
 * 代码高亮与 mermaid 图由 enhancePreview() 在渲染后增强；vendor 缺失时回退极简渲染。
 */
function renderMarkdown(md) {
    if (window.marked && window.DOMPurify) {
        try {
            var html = window.marked.parse(String(md || ''), { gfm: true, async: false });
            return window.DOMPurify.sanitize(html, { ADD_ATTR: ['target'] });
        } catch (e) { /* 解析失败回退 */ }
    }
    return fallbackMarkdown(md);
}

/** 渲染后增强：highlight.js 代码高亮 + mermaid 图表渲染（容器内生效）。 */
function enhancePreview(container) {
    if (!container) { return; }
    // mermaid 块：marked 输出 <pre><code class="language-mermaid">，替换为 div.mermaid 供 mermaid.run 渲染
    var mermaidBlocks = container.querySelectorAll('pre > code.language-mermaid');
    var nodes = [];
    mermaidBlocks.forEach(function (codeEl) {
        var pre = codeEl.parentElement;
        var div = document.createElement('div');
        div.className = 'mermaid';
        div.textContent = codeEl.textContent;
        pre.replaceWith(div);
        nodes.push(div);
    });
    if (window.hljs) {
        container.querySelectorAll('pre code:not(.language-mermaid)').forEach(function (el) {
            try { window.hljs.highlightElement(el); } catch (e) { /* 未知语言保持原文 */ }
        });
    }
    if (window.mermaid && nodes.length) {
        try { window.mermaid.run({ nodes: nodes }); } catch (e) { /* 图表语法错误保持源文本 */ }
    }
}

/** vendor 缺失时的极简回退渲染（标题 / 列表 / 行内标记 / 代码块纯文本）。 */
function fallbackMarkdown(md) {
    var lines = String(md || '').split(/\r?\n/);
    var html = [];
    var inList = false;
    var inCode = false;
    var codeBuf = [];
    var inline = function (text) {
        return text
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (match, label, url) {
                if (/^(https?:\/\/|\/|#|\.\/)/i.test(url)) { return '<a href="' + url + '" target="_blank" rel="noopener">' + label + '</a>'; }
                return label;
            });
    };
    var closeList = function () { if (inList) { html.push('</ul>'); inList = false; } };
    lines.forEach(function (line) {
        if (inCode) {
            if (/^```/.test(line)) { html.push('<pre><code>' + escapeHtml(codeBuf.join('\n')) + '</code></pre>'); codeBuf = []; inCode = false; }
            else { codeBuf.push(line); }
            return;
        }
        if (/^```/.test(line)) { closeList(); inCode = true; codeBuf = []; return; }
        var esc = escapeHtml(line);
        if (/^##\s/.test(line)) { closeList(); html.push('<h3>' + inline(esc.slice(3)) + '</h3>'); }
        else if (/^#\s/.test(line)) { closeList(); html.push('<h2>' + inline(esc.slice(2)) + '</h2>'); }
        else if (/^\s{0,4}[-*]\s/.test(line)) { if (!inList) { html.push('<ul>'); inList = true; } html.push('<li>' + inline(esc.replace(/^\s{0,4}[-*]\s/, '')) + '</li>'); }
        else if (esc.trim() === '') { closeList(); }
        else { closeList(); html.push('<p>' + inline(esc) + '</p>'); }
    });
    if (inCode) { html.push('<pre><code>' + escapeHtml(codeBuf.join('\n')) + '</code></pre>'); }
    closeList();
    return html.join('\n');
}

// 全站统一退出（导航里的 ap-logout）：会话写操作经 fetchJson 携带 CSRF。
var logoutBtn = document.getElementById('ap-logout');
if (logoutBtn) {
    logoutBtn.addEventListener('click', function () {
        fetchJson('/api/logout', { method: 'POST' }).catch(function () { /* 会话已失效也照常回登录页 */ })
            .then(function () { window.location.href = '/'; });
    });
}

// 大屏工具栏吸顶需要头部实际高度（flex-wrap 会随宽度变化）：
// 每页由具体脚本调用 AdminPage.trackHeadHeight() 建立测量。
window.AdminPage = { boot: boot, escapeHtml: escapeHtml, fetchJson: fetchJson, toast: toast, confirmDialog: confirmDialog, copyText: copyText, renderMarkdown: renderMarkdown, enhancePreview: enhancePreview, trackHeadHeight: function (selector) {
    var head = document.querySelector(selector || '.ap-head');
    if (!head) { return; }
    var update = function () { document.documentElement.style.setProperty('--sb-head-h', head.offsetHeight + 'px'); };
    update();
    window.addEventListener('resize', update);
    if (window.ResizeObserver) { new ResizeObserver(update).observe(head); }
} };
})();
