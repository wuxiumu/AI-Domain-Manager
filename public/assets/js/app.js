'use strict';

/* 登录壳控制器：初始化 → 登录 → 跳转 /screen。管理能力在独立页面（大屏/设置/日历）。 */

function showLoginError(msg) {
    const el = $('#login-error');
    el.textContent = msg;
    el.classList.remove('hidden');
}

// 登录 / 首次初始化成功后的落点：大屏。
function showApp() {
    window.WorkspaceLogin.leave();
    location.href = '/screen';
}

function showLogin() {
    state.csrf = '';
    window.WorkspaceCache?.clear();
    window.WorkspaceCache?.setUser(null);
    $('#setup-view')?.classList.add('hidden');
    $('#login-view')?.classList.remove('hidden');
    window.WorkspaceLogin.load();
}

/* ---------- 初始化 ---------- */
(async function init() {
    try {
        if (await window.WorkspaceSetup.check()) return;
        const data = await api('/api/me');
        state.csrf = data.csrf_token || '';
        window.WorkspaceCache?.setUser(data.user);
        if (data.user) { location.href = '/screen'; return; }
        showLogin();
    } catch (error) {
        showLogin();
        showLoginError('工作台初始化失败：' + error.message);
    }
})();
