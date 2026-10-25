'use strict';

/** Display preferences and shared desktop interactions; never fetches asset data. */
window.WorkspaceUI = (() => {
    const root = document.documentElement;
    const storageKey = 'asset-workspace:display:v1';
    let preferences = {density: 'comfortable', sidebarCollapsed: false};
    try {
        const saved = JSON.parse(localStorage.getItem(storageKey) || '{}');
        preferences.density = saved.density === 'compact' ? 'compact' : 'comfortable';
        preferences.sidebarCollapsed = saved.sidebarCollapsed === true;
    } catch { /* Private browsing and disabled storage still support this session. */ }

    function applyPreferences() {
        root.dataset.density = preferences.density;
        root.dataset.sidebar = preferences.sidebarCollapsed ? 'collapsed' : 'expanded';
        document.querySelectorAll('[data-density]').forEach(button => {
            button.setAttribute('aria-pressed', String(button.dataset.density === preferences.density));
        });
        const toggle = document.getElementById('sidebar-toggle');
        const label = preferences.sidebarCollapsed ? '展开侧栏' : '收起侧栏';
        toggle.setAttribute('aria-expanded', String(!preferences.sidebarCollapsed));
        toggle.setAttribute('aria-label', label);
        toggle.title = label;
        document.querySelectorAll('.sidebar .tab-btn').forEach(button => {
            button.title = button.textContent.trim();
        });
        try { localStorage.setItem(storageKey, JSON.stringify(preferences)); } catch {}
        window.dispatchEvent(new CustomEvent('workspace-display-changed'));
    }

    document.querySelectorAll('[data-density]').forEach(button => {
        button.addEventListener('click', () => {
            preferences.density = button.dataset.density;
            applyPreferences();
        });
    });
    document.getElementById('sidebar-toggle').addEventListener('click', () => {
        preferences.sidebarCollapsed = !preferences.sidebarCollapsed;
        applyPreferences();
    });

    function clearSearch(input) {
        input.value = '';
        input.dispatchEvent(new Event('input', {bubbles: true}));
        input.focus();
    }

    function enhanceSearch(field) {
        if (field.querySelector('.search-clear')) return;
        const input = field.querySelector('input[type="search"]');
        if (!input) return;
        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'search-clear';
        clear.setAttribute('aria-label', '清除搜索');
        clear.title = '清除搜索（Esc）';
        clear.textContent = '×';
        const sync = () => { clear.hidden = input.value.length === 0; };
        input.addEventListener('input', sync);
        // These fields use a wrapping label; prevent the label's default focus action.
        clear.addEventListener('click', event => { event.preventDefault(); clearSearch(input); });
        field.appendChild(clear);
        sync();
    }

    function enhanceTable(wrapper) {
        if (wrapper.dataset.scrollRegion) return;
        wrapper.dataset.scrollRegion = 'true';
        wrapper.tabIndex = 0;
        wrapper.setAttribute('role', 'region');
        const panel = wrapper.closest('.asset-panel,.modal');
        const heading = panel?.querySelector('h2,h3');
        if (heading?.id) wrapper.setAttribute('aria-labelledby', heading.id);
        else wrapper.setAttribute('aria-label', '资产列表，可滚动查看');
    }

    function enhance(container = document) {
        if (container.matches?.('.search-field')) enhanceSearch(container);
        if (container.matches?.('.table-wrap')) enhanceTable(container);
        container.querySelectorAll('.search-field').forEach(enhanceSearch);
        container.querySelectorAll('.table-wrap').forEach(enhanceTable);
    }

    document.addEventListener('keydown', event => {
        if (event.defaultPrevented || event.isComposing || event.ctrlKey || event.metaKey || event.altKey) return;
        const editing = event.target.closest('input,textarea,select,[contenteditable="true"]');
        if (event.key === 'Escape' && event.target.matches('input[type="search"]') && event.target.value) {
            event.preventDefault();
            clearSearch(event.target);
        } else if (event.key === '/' && !editing && !document.querySelector('.modal-overlay')) {
            const search = document.querySelector('.tab-view:not(.hidden) input[type="search"]');
            if (search) { event.preventDefault(); search.focus(); search.select(); }
        }
    });

    document.addEventListener('click', () => queueMicrotask(() => {
        document.querySelectorAll('.search-field').forEach(field => {
            const clear = field.querySelector('.search-clear');
            if (clear) clear.hidden = !field.querySelector('input').value;
        });
    }));

    // Only inspect added subtrees, rather than rescanning every table after row updates.
    new MutationObserver(records => {
        records.forEach(record => record.addedNodes.forEach(node => {
            if (node.nodeType === Node.ELEMENT_NODE && !node.matches('tr,td,th,option,svg,path')) enhance(node);
        }));
    }).observe(document.body, {childList: true, subtree: true});

    applyPreferences();
    enhance();
    return {refresh: enhance};
})();
