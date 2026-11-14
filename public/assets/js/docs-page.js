/**
 * 独立文档页（/docs）：清单浏览、默认 Markdown 预览、编辑（带行号）、新建与删除。
 * 自包含模块；依赖 AdminPage（fetchJson / toast / confirmDialog / renderMarkdown）。
 * 保存与删除为会话写操作（X-CSRF-Token 由 AdminPage 统一携带）。
 */
(function () {
'use strict';

var A = window.AdminPage;
var esc = A.escapeHtml, api = A.fetchJson, toast = A.toast, confirmDialog = A.confirmDialog;

function $(id) { return document.getElementById(id); }

var ROOT_FILES = ['README.md', 'AGENTS.md', 'CHANGELOG.md'];
var current = null;   // { path, content, original, dirty }
var view = 'preview'; // preview | edit
var list = [];        // 服务端清单缓存

// mermaid 图表：深色主题，手动触发（渲染后经 AdminPage.enhancePreview 调用）
if (window.mermaid) {
    try { window.mermaid.initialize({ startOnLoad: false, theme: 'dark', securityLevel: 'strict' }); } catch (e) { /* 重复初始化忽略 */ }
}

/* ---- 清单 ---- */

function loadList(keepSelection) {
    api('/api/docs').then(function (r) {
        list = r.items || [];
        renderList();
        if (!keepSelection || !current) { return; }
        // 当前文件已不在清单（被删）：清空编辑区
        if (!list.some(function (doc) { return doc.path === current.path; })) { closeEditor(); }
    }).catch(function (error) {
        $('dc-list').innerHTML = '<p class="ap-error">' + esc(error.message) + '</p>';
    });
}

function renderList() {
    var el = $('dc-list');
    el.innerHTML = list.length ? list.map(function (doc) {
        var locked = ROOT_FILES.indexOf(doc.path) !== -1;
        return '<button type="button" class="dc-file' + (current && current.path === doc.path ? ' active' : '') + '" data-doc-path="' + esc(doc.path) + '">' +
            '<strong>' + esc(doc.title) + (locked ? '<span class="dc-locked" title="根目录白名单文档：可编辑，不可删除">🔒</span>' : '') + '</strong>' +
            '<small>' + esc(doc.path) + ' · ' + Math.round(doc.bytes / 1024) + ' KB · ' + esc(doc.updated_at || '') + '</small></button>';
    }).join('') : '<p class="ap-muted">docs/ 目录为空，点击「＋ 新建文档」创建第一篇。</p>';
}

$('dc-list').addEventListener('click', function (event) {
    var button = event.target.closest('[data-doc-path]');
    if (!button) { return; }
    if (current && current.path === button.dataset.docPath) { return; }
    openDoc(button.dataset.docPath);
});

$('dc-refresh').addEventListener('click', function () { loadList(true); toast('清单已刷新'); });

/* ---- 编辑器：预览 / 编辑双模式 ---- */

function setDirty(dirty) {
    if (current) { current.dirty = dirty; }
    $('dc-dirty').classList.toggle('hidden', !dirty);
}

function setView(mode) {
    view = mode;
    var editing = mode === 'edit';
    $('dc-code').hidden = !editing;
    $('dc-preview').hidden = editing;
    $('dc-view-toggle').textContent = editing ? '预览' : '编辑';
    if (!editing) { renderPreview(); }
    else { updateLines(); }
}

function renderPreview() {
    if (!current) { return; }
    $('dc-preview').innerHTML = A.renderMarkdown($('dc-textarea').value);
    A.enhancePreview($('dc-preview')); // hljs 高亮 + mermaid 图渲染
}

function updateLines() {
    var ta = $('dc-textarea');
    var lines = ta.value.split('\n').length;
    var el = $('dc-lines');
    if (el.dataset.n !== String(lines)) {
        el.dataset.n = String(lines);
        var out = [];
        for (var i = 1; i <= lines; i++) { out.push(i); }
        el.textContent = out.join('\n');
    }
    el.scrollTop = ta.scrollTop;
}

function openDoc(path) {
    api('/api/docs/read?path=' + encodeURIComponent(path)).then(function (doc) {
        current = { path: path, content: doc.content, original: doc.content, dirty: false };
        $('dc-path').textContent = path;
        $('dc-textarea').value = doc.content;
        $('dc-delete').disabled = ROOT_FILES.indexOf(path) !== -1;
        $('dc-editor').hidden = false;
        $('dc-empty').hidden = true;
        setDirty(false);
        setView('preview'); // 默认预览
        renderList();
    }).catch(function (error) { toast(error.message || '读取失败', 'error'); });
}

function closeEditor() {
    var proceed = function () {
        current = null;
        $('dc-editor').hidden = true;
        $('dc-empty').hidden = false;
        renderList();
    };
    guardDirty(proceed);
}

/** 有未保存修改时先确认。 */
function guardDirty(action) {
    if (!current || !current.dirty) { action(); return; }
    confirmDialog({ title: '未保存的修改', message: '当前文档有未保存的修改，继续将丢弃这些修改。', danger: true, confirmText: '丢弃并继续' })
        .then(function (ok) { if (ok) { action(); } });
}

$('dc-view-toggle').addEventListener('click', function () {
    if (!current) { return; }
    setView(view === 'edit' ? 'preview' : 'edit');
});

$('dc-textarea').addEventListener('input', function () {
    if (!current) { return; }
    current.content = this.value;
    setDirty(this.value !== current.original);
    updateLines();
});
$('dc-textarea').addEventListener('scroll', function () {
    $('dc-lines').scrollTop = this.scrollTop;
});

$('dc-save').addEventListener('click', function () {
    if (!current) { return; }
    var button = $('dc-save');
    button.disabled = true;
    api('/api/docs', { method: 'PUT', body: { path: current.path, content: current.content } })
        .then(function (r) {
            current.original = current.content;
            setDirty(false);
            toast('已保存：' + current.path + (r.updated_at ? ' · ' + r.updated_at : ''));
            loadList(true);
            if (view === 'preview') { renderPreview(); }
        })
        .catch(function (error) { toast(error.message || '保存失败', 'error', 6000); })
        .finally(function () { button.disabled = false; });
});

$('dc-delete').addEventListener('click', function () {
    if (!current) { return; }
    if (ROOT_FILES.indexOf(current.path) !== -1) { toast('根目录白名单文档受保护，不能删除', 'error'); return; }
    var path = current.path;
    confirmDialog({ title: '删除文档', message: '删除 ' + path + '？此操作不可恢复（操作会进入审计记录）。', danger: true, confirmText: '确认删除' })
        .then(function (ok) {
            if (!ok) { return; }
            api('/api/docs', { method: 'DELETE', body: { path: path } })
                .then(function () {
                    toast('文档已删除：' + path);
                    current = null;
                    $('dc-editor').hidden = true;
                    $('dc-empty').hidden = false;
                    loadList();
                })
                .catch(function (error) { toast(error.message || '删除失败', 'error', 6000); });
        });
});

/* ---- 新建 ---- */

$('dc-new').addEventListener('click', function () {
    guardDirty(function () {
        var ov = document.createElement('div');
        ov.className = 'ap-overlay';
        ov.innerHTML = '<section class="ap-modal" role="dialog" aria-modal="true" aria-label="新建文档">' +
            '<div class="ap-modal-head"><h3>新建文档</h3><button type="button" class="ap-modal-close" aria-label="关闭">✕</button></div>' +
            '<form class="dc-form"><label>文件路径（docs/ 内，以 .md 结尾）' +
            '<input name="path" required spellcheck="false" placeholder="如：docs/06-运维/巡检记录.md" value="docs/"></label>' +
            '<label>初始内容（Markdown，可留空）<textarea name="content" rows="6" placeholder="# 标题&#10;&#10;- 第一条"></textarea></label>' +
            '<p class="dc-form-err hidden"></p>' +
            '<div class="ap-modal-foot"><button type="button" class="ap-btn" data-cancel>取消</button>' +
            '<button type="submit" class="ap-btn ap-btn-primary">创建</button></div></form></section>';
        document.body.appendChild(ov);
        var form = ov.querySelector('form');
        var close = function () { ov.remove(); };
        ov.querySelector('.ap-modal-close').addEventListener('click', close);
        ov.querySelector('[data-cancel]').addEventListener('click', close);
        ov.addEventListener('click', function (e) { if (e.target === ov) { close(); } });
        ov.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var errEl = form.querySelector('.dc-form-err');
            errEl.classList.add('hidden');
            var path = form.elements.path.value.trim();
            api('/api/docs', { method: 'POST', body: { path: path, content: form.elements.content.value } })
                .then(function () {
                    close();
                    toast('文档已创建：' + path);
                    loadList();
                    openDoc(path);
                })
                .catch(function (error) {
                    errEl.textContent = error.message || '创建失败';
                    errEl.classList.remove('hidden');
                });
        });
        form.elements.path.focus();
    });
});

/* ---- 初始化 ---- */

loadList();
})();
