'use strict';

/* ---------- 测试 Key ---------- */
async function loadKeys() {
    const grid = $('#accounts-grid');
    grid.innerHTML = '<p class="muted">加载中…</p>';
    try {
        const accounts = await api('/api/accounts');
        state.accounts = accounts;
        renderDomainFilterTabs();
        renderCards(accounts);
    } catch (err) {
        grid.innerHTML = `<p class="error-text">加载账号失败：${err.message}</p>`;
    }
}

function renderCards(accounts) {
    const grid = $('#accounts-grid');
    grid.innerHTML = '';
    if (!accounts.length) {
        grid.innerHTML = '<div class="panel settings-empty"><strong>添加第一个云账号</strong><p>接入后可同步域名，或先到 IP 资产页手工登记服务器。</p><button class="btn btn-primary" data-account-new>添加云账号</button></div>';
        grid.querySelector('[data-account-new]').onclick = () => window.WorkspaceSettings.openAccount();
    }
    accounts.forEach((acc) => {
        const card = document.createElement('div');
        card.className = 'card';
        card.dataset.id = acc.id;
        const cls = badgeClass(acc.provider);
        card.innerHTML = `
            <div class="card-head">
                <span class="card-name">${escapeHtml(acc.name)}</span>
                <span class="badge ${acc.enabled === false ? 'b-gray' : cls}">${acc.enabled === false ? '已停用' : escapeHtml(acc.provider)}</span>
            </div>
            <div class="key-line">密钥 ${escapeHtml(acc.key_masked)}</div>
            ${acc.note ? `<p class="account-note">${escapeHtml(acc.note)}</p>` : ''}
            <div class="account-state"><span>启用云账号</span><button type="button" class="account-switch" role="switch" aria-checked="${acc.enabled !== false}" aria-label="启用 ${escapeHtml(acc.name)}" data-account-toggle="${escapeHtml(acc.id)}"><span></span></button></div>
            <div class="card-actions">
                <button class="btn btn-sm test-btn" ${acc.enabled === false ? 'disabled' : ''}>测试连接</button>
                <button class="btn btn-sm" data-account-edit="${escapeHtml(acc.id)}">编辑配置</button>
                <button class="btn btn-sm btn-link-danger" data-account-delete="${escapeHtml(acc.id)}">删除</button>
                <div class="status-line"><span class="pending">${acc.enabled === false ? '已停用' : '未测试'}</span></div>
            </div>`;
        card.querySelector('.test-btn').addEventListener('click', () => testAccount(acc.id, card));
        grid.appendChild(card);
    });
}

async function testAccount(id, card) {
    const statusEl = card.querySelector('.status-line');
    const btn = card.querySelector('.test-btn');
    btn.disabled = true;
    statusEl.innerHTML = '<span class="pending">测试中…</span>';
    try {
        const data = await api(`/api/accounts/${encodeURIComponent(id)}/test`, { method: 'POST' });
        statusEl.innerHTML = renderStatus(data);
        addResultItem(data);
    } catch (err) {
        statusEl.innerHTML = `<span class="fail">测试失败</span><span class="detail">${escapeHtml(err.message)}</span>`;
    } finally {
        btn.disabled = state.accounts.find(account => account.id === id)?.enabled === false;
    }
}

$('#test-all-btn').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.disabled = true;
    $$('#accounts-grid .card').forEach((c) => {
        if (state.accounts.find(account => account.id === c.dataset.id)?.enabled === false) return;
        c.querySelector('.status-line').innerHTML = '<span class="pending">测试中…</span>';
        c.querySelector('.test-btn').disabled = true;
    });
    try {
        const results = await api('/api/test-all', { method: 'POST' });
        results.forEach((r) => {
            const card = document.querySelector(`#accounts-grid .card[data-id="${r.id}"]`);
            if (card) {
                card.querySelector('.status-line').innerHTML = renderStatus(r);
                card.querySelector('.test-btn').disabled = state.accounts.find(account => account.id === r.id)?.enabled === false;
            }
            addResultItem(r);
        });
    } catch (err) {
        $$('#accounts-grid .card').forEach((c) => {
            c.querySelector('.status-line').innerHTML = `<span class="fail">测试失败：${escapeHtml(err.message)}</span>`;
            c.querySelector('.test-btn').disabled = state.accounts.find(account => account.id === c.dataset.id)?.enabled === false;
        });
    } finally {
        btn.disabled = false;
    }
});

function renderStatus(data) {
    if (!data.ok) {
        return `<span class="fail">✗ 失败</span><span class="detail">${escapeHtml(data.error || '未知错误')}</span>`;
    }
    const r = data.result || {};
    const samples = (r.samples && r.samples.length)
        ? '　样例: ' + escapeHtml(r.samples.join(', ')) : '';
    return `<span class="ok">✓ 成功 · ${r.count != null ? r.count + ' 个域名' : ''} · ${r.latency_ms != null ? r.latency_ms + 'ms' : ''}</span>${samples ? `<span class="detail">${samples}</span>` : ''}`;
}

function addResultItem(data) {
    const section = $('#result-section');
    section.classList.remove('hidden');
    const list = $('#result-list');
    const item = document.createElement('div');
    item.className = 'result-item';
    let body;
    if (data.ok) {
        const r = data.result || {};
        body = `✓ 成功 · 域名数 ${r.count ?? '-'} · 延迟 ${r.latency_ms ?? '-'}ms`
            + (r.samples && r.samples.length ? `\n样例: ${r.samples.join(', ')}` : '');
    } else {
        body = `✗ 失败\n${data.error || '未知错误'}`;
    }
    item.innerHTML = `
        <span class="icon">${data.ok ? '✅' : '❌'}</span>
        <div>
            <strong>${escapeHtml(data.name || data.account || '')}</strong>
            <span class="meta">${new Date().toLocaleTimeString()}</span>
            <pre>${escapeHtml(body)}</pre>
        </div>`;
    list.prepend(item);
}

$('#accounts-grid').addEventListener('click', async event => {
    const toggle = event.target.closest('[data-account-toggle]');
    const remove = event.target.closest('[data-account-delete]');
    const button = toggle || remove;
    if (!button || button.disabled) return;
    const account = state.accounts.find(item => item.id === (toggle?.dataset.accountToggle || remove?.dataset.accountDelete));
    if (!account) return;
    if (remove && !await confirmDialog({title: '删除云账号', message: `删除“${account.name}”的云账号配置？已有域名资产与缓存会保留，后续云端操作需要重新配置账号。`, confirmText: '删除账号', danger: true})) return;
    button.disabled = true;
    try {
        await api('/api/accounts/' + encodeURIComponent(account.id), toggle ? {method: 'PUT', body: JSON.stringify({enabled: account.enabled === false})} : {method: 'DELETE'});
        await loadKeys();
        toast(toggle ? (account.enabled === false ? '云账号已启用' : '云账号已停用') : '云账号已删除');
    } catch (error) { toast(error.message, 'error'); button.disabled = false; }
});
