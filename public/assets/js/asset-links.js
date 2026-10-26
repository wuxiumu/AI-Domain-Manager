'use strict';

window.AssetLinks = (() => {
    const h = escapeHtml;
    function render(links = []) {
        return links.filter(link => /^https?:\/\//i.test(link.url || '')).map(link => `<a class="asset-site-link" href="${h(link.url)}" target="_blank" rel="noopener noreferrer" title="${h(link.url)}">${h(link.name)} ↗</a>`).join('');
    }
    function editor(root, links = []) {
        root.innerHTML = '<div class="asset-link-rows"></div><button type="button" class="btn btn-sm" data-link-add>添加网站链接</button>';
        const rows = root.querySelector('.asset-link-rows');
        const addButton = root.querySelector('[data-link-add]');
        function add(link = {}) {
            const row = document.createElement('div'); row.className = 'asset-link-row';
            row.innerHTML = `<label>网站名称<input data-link-name maxlength="120" required value="${h(link.name || '')}" placeholder="如：管理后台"></label><label>网站 URL<input data-link-url type="url" maxlength="2048" required value="${h(link.url || '')}" placeholder="https://example.com"></label><button type="button" class="btn btn-sm btn-link-danger" aria-label="删除此网站链接">删除</button>`;
            row.querySelector('button').onclick = () => { row.remove(); addButton.disabled = false; };
            rows.appendChild(row);
            addButton.disabled = rows.children.length >= 30;
            return row;
        }
        links.forEach(add);
        addButton.onclick = () => { if (rows.children.length < 30) add().querySelector('input').focus(); };
        return {read: () => [...rows.children].map(row => ({name: row.querySelector('[data-link-name]').value.trim(), url: row.querySelector('[data-link-url]').value.trim()})),
            reset: () => { rows.innerHTML = ''; addButton.disabled = false; }};
    }
    return {render, editor};
})();
