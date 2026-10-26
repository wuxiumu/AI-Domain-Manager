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

    let activeDialog = null;
    function closeDialog() { activeDialog?.(); }
    function openDialog({title, panels, onClose, onOpen, compact = false, anchor = null, align = 'start'}) {
        closeDialog();
        const returnFocus = document.activeElement;
        const overlay = document.createElement('div'); overlay.className = 'modal-overlay'; overlay.id = 'workspace-content-overlay';
        if (anchor) overlay.classList.add('workspace-anchored-overlay');
        overlay.innerHTML = `<section class="modal workspace-content-modal ${compact ? 'workspace-filter-modal' : ''}" role="dialog" aria-modal="true" aria-labelledby="workspace-dialog-title"><div class="modal-head"><h3 id="workspace-dialog-title">${escapeHtml(title)}</h3><button type="button" class="modal-close" aria-label="关闭弹窗">×</button></div><div class="modal-body">${panels.length > 1 ? '<div class="workspace-tabs" role="tablist" aria-label="统计内容"></div>' : ''}<div class="workspace-dialog-content"></div></div><div class="modal-foot"><button type="button" class="btn" data-workspace-close>关闭</button></div></section>`;
        const restored = [], sections = [], buttons = [];
        panels.forEach((panel, index) => {
            const section = document.createElement('div'); section.className = 'workspace-dialog-panel'; section.id = 'workspace-dialog-panel-' + index;
            section.classList.toggle('hidden', index !== 0);
            if (panels.length > 1) {
                const button = document.createElement('button'); button.type = 'button'; button.role = 'tab';
                button.id = 'workspace-dialog-tab-' + index; button.textContent = panel.label;
                button.setAttribute('aria-controls', section.id); button.setAttribute('aria-selected', String(index === 0)); button.tabIndex = index === 0 ? 0 : -1;
                section.role = 'tabpanel'; section.setAttribute('aria-labelledby', button.id);
                button.onclick = () => { sections.forEach((item, i) => item.classList.toggle('hidden', i !== index)); buttons.forEach((item, i) => { item.setAttribute('aria-selected', String(i === index)); item.tabIndex = i === index ? 0 : -1; }); };
                overlay.querySelector('.workspace-tabs').appendChild(button); buttons.push(button);
            }
            panel.nodes.forEach(id => {
                const node = document.getElementById(id); if (!node) return;
                const placeholder = document.createComment('dialog:' + id), hidden = node.classList.contains('hidden');
                node.replaceWith(placeholder); section.appendChild(node); node.classList.remove('hidden');
                restored.push(() => { node.classList.toggle('hidden', hidden); placeholder.replaceWith(node); });
            });
            overlay.querySelector('.workspace-dialog-content').appendChild(section); sections.push(section);
        });
        (document.fullscreenElement || document.body).appendChild(overlay);
        const modal = overlay.querySelector('.modal');
        const previousExpanded = anchor?.getAttribute('aria-expanded');
        const previousControls = anchor?.getAttribute('aria-controls');
        modal.id = 'workspace-dialog';
        anchor?.setAttribute('aria-expanded', 'true'); anchor?.setAttribute('aria-controls', modal.id);
        const position = () => {
            if (!anchor || document.fullscreenElement === modal || modal.classList.contains('rel-fallback-fullscreen')) return;
            const rect = anchor.getBoundingClientRect(), margin = 12, gap = 8;
            const boundary = anchor.closest('.inventory-page')?.getBoundingClientRect();
            const minLeft = Math.max(margin, boundary?.left ?? margin);
            const maxRight = Math.min(window.innerWidth - margin, boundary?.right ?? window.innerWidth - margin);
            modal.style.maxWidth = Math.max(0, maxRight - minLeft) + 'px';
            const width = modal.getBoundingClientRect().width;
            const left = align === 'end' ? rect.right - width : rect.left;
            modal.style.left = Math.max(minLeft, Math.min(left, maxRight - width)) + 'px';
            const below = window.innerHeight - rect.bottom - gap - margin;
            const above = rect.top - gap - margin;
            const flip = below < 180 && above > below;
            modal.style.maxHeight = Math.max(100, flip ? above : below) + 'px';
            modal.style.top = Math.max(margin, flip ? rect.top - gap - modal.getBoundingClientRect().height : rect.bottom + gap) + 'px';
        };
        const reposition = event => { if (!overlay.contains(event.target)) position(); };
        if (anchor) { window.addEventListener('resize', position); document.addEventListener('scroll', reposition, true); }
        const close = () => {
            activeDialog = null;
            window.removeEventListener('resize', position); document.removeEventListener('scroll', reposition, true);
            if (anchor) {
                if (previousExpanded === null) anchor.removeAttribute('aria-expanded'); else anchor.setAttribute('aria-expanded', previousExpanded);
                if (previousControls === null) anchor.removeAttribute('aria-controls'); else anchor.setAttribute('aria-controls', previousControls);
            }
            restored.forEach(restore => restore()); overlay.remove(); onClose?.(); if (returnFocus?.isConnected) returnFocus.focus();
        };
        activeDialog = close;
        overlay.querySelector('.modal-close').onclick = close; overlay.querySelector('[data-workspace-close]').onclick = close;
        overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
        overlay.addEventListener('keydown', event => {
            if (event.key === 'Escape') { event.preventDefault(); close(); }
            if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key) && buttons.includes(event.target)) {
                const current = buttons.indexOf(event.target), index = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (current + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length;
                event.preventDefault(); buttons[index].click(); buttons[index].focus();
            }
            if (event.key === 'Tab') {
                const items = [...overlay.querySelectorAll('button:not(:disabled),input,select,textarea,a[href],[tabindex="0"]')].filter(item => item.getClientRects().length);
                const first = items[0], last = items[items.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
            }
        });
        position(); overlay.querySelector('.modal-close').focus(); onOpen?.();
    }
    document.addEventListener('click', event => {
        const filter = event.target.closest('[data-open-filter]');
        if (filter) openDialog({title: '筛选资产', compact: true, anchor: filter, panels: [{nodes: [filter.dataset.openFilter]}]});
        const healthStats = event.target.closest('[data-open-health-stats]');
        if (healthStats) {
            openDialog({title: '资产统计', anchor: healthStats, align: 'end', panels: [{label: '健康统计', nodes: ['health-summary-panel']}, {label: '域名与同步', nodes: ['health-domains-panel']}]}); loadDashboard();
        }
        const domainStats = event.target.closest('[data-open-domain-stats]');
        if (domainStats) {
            openDialog({title: '域名统计', anchor: domainStats, align: 'end', panels: [{nodes: ['health-domains-panel']}]}); loadDashboard();
        }
    });
    function updateFilterCounts() {
        document.querySelectorAll('[data-open-filter]').forEach(button => {
            const panel = document.getElementById(button.dataset.openFilter);
            const count = [...panel.querySelectorAll('select,input[type="hidden"]')].filter(input => input.value && !(input.id === 'f-sort' && input.value === 'expire')).length
                + (panel.querySelector('[data-rel-tab][aria-selected="true"]:not([data-rel-tab="all"])') ? 1 : 0);
            button.textContent = count ? `筛选（${count}）` : '筛选';
        });
    }
    document.addEventListener('change', updateFilterCounts);
    document.addEventListener('click', () => queueMicrotask(updateFilterCounts));
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
    updateFilterCounts();
    return {refresh: enhance, openDialog, closeDialog};
})();
