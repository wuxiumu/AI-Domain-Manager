'use strict';

/* ---------- 登录 ---------- */
$('#login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('#login-btn');
    btn.disabled = true;
    $('#login-error').classList.add('hidden');
    try {
        const data = await api('/api/login', {
            method: 'POST',
            body: JSON.stringify({ user: $('#login-user').value, pass: $('#login-pass').value }),
        });
        if (data.ok) { state.csrf = data.csrf_token || ''; window.WorkspaceCache?.setUser(data.user); showApp(); }
        else showLoginError(data.error || '登录失败');
    } catch (err) {
        showLoginError(err.message);
    } finally {
        btn.disabled = false;
    }
});

function showLoginError(msg) {
    const el = $('#login-error');
    el.textContent = msg;
    el.classList.remove('hidden');
}

$('#logout-btn').addEventListener('click', async () => {
    await api('/api/logout', { method: 'POST' }).catch(() => {});
    showLogin();
});

function showLogin() {
    $('#app-view').classList.add('hidden');
    $$('.modal-overlay').forEach(overlay => {
        const close = overlay.querySelector('.modal-close');
        if (close) close.click(); else overlay.remove();
    });
    window.WorkspaceSettings?.leave();
    state.csrf = '';
    window.WorkspaceCache?.clear();
    window.WorkspaceCache?.setUser(null);
    $('#setup-view').classList.add('hidden');
    $('#login-view').classList.remove('hidden');
    $('#login-pass').value = '';
}

/* ---------- 视图 / Tab ---------- */
async function showApp() {
    $('#setup-view').classList.add('hidden');
    $('#login-view').classList.add('hidden');
    $('#app-view').classList.remove('hidden');
    try {
        state.accounts = await api('/api/accounts');
        $('#current-user').textContent = '已登录';
        renderDomainFilterTabs();
    } catch {
        state.accounts = [];
    }
    const initial = location.hash.slice(1).split('?')[0];
    switchTab(['dashboard', 'relations', 'domains', 'ips', 'keys', 'settings'].includes(initial) ? initial : 'dashboard');
}

$$('.tab-btn').forEach((btn) => {
    btn.addEventListener('click', () => switchTab(btn.dataset.tab));
});

function switchTab(tab) {
    if (!['dashboard', 'relations', 'domains', 'ips', 'keys', 'settings'].includes(tab)) tab = 'dashboard';
    if (state.tab === 'settings' && tab !== 'settings') window.WorkspaceSettings?.leave();
    const changed = state.tab !== tab;
    state.tab = tab;
    if (changed) window.scrollTo(0, 0);
    if ($('#page-title')) $('#page-title').textContent = ({dashboard:'健康看板',relations:'资产关系',domains:'域名管理',ips:'IP 资产',keys:'云账号',settings:'产品设置'})[tab];
    if (location.hash.split('?')[0] !== '#' + tab) history.replaceState(null, '', '#' + tab);
    $$('.tab-btn').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
    $$('.tab-view').forEach((v) => v.classList.toggle('hidden', v.id !== 'view-' + tab));
    if (tab === 'dashboard') loadDashboard();
    if (tab === 'domains') {
        loadDomains();
        loadAccessSummary();
    }
    if (tab === 'keys') loadKeys();
    if (tab === 'relations') window.Relationships?.load();
    if (tab === 'ips') window.HealthWorkbench?.load();
    if (tab === 'settings') window.WorkspaceSettings?.load();
}
window.addEventListener('hashchange', () => switchTab(location.hash.slice(1).split('?')[0]));

/* ---------- 初始化 ---------- */
(async function init() {
    try {
        if (await window.WorkspaceSetup.check()) return;
        const data = await api('/api/me');
        state.csrf = data.csrf_token || '';
        window.WorkspaceCache?.setUser(data.user);
        if (data.user) showApp();
        else showLogin();
    } catch (error) {
        showLogin();
        showLoginError('工作台初始化失败：' + error.message);
    }
})();
