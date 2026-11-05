'use strict';

/* ---------- 登录 ---------- */
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
    window.WorkspaceCalendar?.leave();
    state.csrf = '';
    window.WorkspaceCache?.clear();
    window.WorkspaceCache?.setUser(null);
    $('#setup-view').classList.add('hidden');
    $('#login-view').classList.remove('hidden');
    window.WorkspaceLogin.load();
}

/* ---------- 视图 / Tab ---------- */
const TABS = ['dashboard', 'issues', 'trends', 'https', 'calendar', 'relations', 'domains', 'ips', 'settings'];

async function showApp() {
    window.WorkspaceLogin.leave();
    $('#setup-view').classList.add('hidden');
    $('#login-view').classList.add('hidden');
    $('#app-view').classList.remove('hidden');
    try {
        state.accounts = await api('/api/accounts');
        state.accountsLoaded = true;
        $('#current-user').textContent = '已登录';
        renderDomainFilterTabs();
        const accountsCard = $('#dash-accounts-count');
        if (accountsCard) accountsCard.textContent = `${state.accounts.filter(a => a.enabled !== false).length} 个账号`;
    } catch (error) {
        state.accounts = [];
        state.accountsLoaded = false;
        toast('云账号列表加载失败：' + (error?.message || '请稍后重试') + '，域名操作可能受限，可刷新重试', 'error', 5000);
    }
    const initial = location.hash.slice(1).split('?')[0];
    switchTab(TABS.includes(initial) ? initial : 'dashboard');
}

$$('.tab-btn').forEach((btn) => {
    btn.addEventListener('click', () => switchTab(btn.dataset.tab));
});

function updateCalendarClock() {
    const now = new Date();
    const pad = value => String(value).padStart(2, '0');
    const day = ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'][now.getDay()];
    const time = $('#calendar-clock-time');
    time.textContent = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())} ${day}`;
    time.dateTime = now.toISOString();
}
updateCalendarClock();
setInterval(updateCalendarClock, 1000);
$('#calendar-clock').addEventListener('click', () => switchTab('calendar'));

function switchTab(tab) {
    if (!TABS.includes(tab)) tab = 'dashboard';
    if (state.tab === 'settings' && tab !== 'settings') window.WorkspaceSettings?.leave();
    const changed = state.tab !== tab;
    state.tab = tab;
    if (changed) window.scrollTo(0, 0);
    if ($('#page-title')) $('#page-title').textContent = ({dashboard:'健康看板',issues:'问题中心',trends:'检查趋势',https:'HTTPS 跟进',calendar:'检查日历',relations:'资产关系',domains:'域名管理',ips:'IP 资产',settings:'产品设置'})[tab];
    if (location.hash.split('?')[0] !== '#' + tab) history.replaceState(null, '', '#' + tab);
    $$('.tab-btn').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
    $('#calendar-clock').classList.toggle('active', tab === 'calendar');
    $$('.tab-view').forEach((v) => v.classList.toggle('hidden', v.id !== 'view-' + tab));
    if (tab === 'dashboard') loadDashboard();
    if (tab === 'issues') window.HealthWorkbench?.load();
    if (tab === 'trends') window.WorkspaceTrends?.load();
    if (tab === 'https') window.HealthWorkbench?.loadHttpsReview();
    if (tab === 'calendar') window.WorkspaceCalendar?.open();
    if (tab === 'domains') {
        loadDomains();
        loadAccessSummary();
    }
    if (tab === 'relations') window.Relationships?.load();
    if (tab === 'ips') window.HealthWorkbench?.load();
    if (tab === 'settings') window.WorkspaceSettings?.load();
}
window.addEventListener('hashchange', () => switchTab(location.hash.slice(1).split('?')[0]));

/* 看板入口卡片等元素通过 data-goto-tab 跳转到对应页面；data-goto-panel 联动目标页内的子面板（如设置页的云账号）。 */
document.addEventListener('click', event => {
    const target = event.target.closest('[data-goto-tab]');
    if (!target) return;
    switchTab(target.dataset.gotoTab);
    if (target.dataset.gotoPanel) {
        const panel = document.querySelector('[data-settings-tab="' + target.dataset.gotoPanel + '"]');
        if (panel && panel.getAttribute('aria-selected') !== 'true') panel.click();
    }
});

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
