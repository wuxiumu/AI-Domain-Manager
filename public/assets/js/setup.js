'use strict';

window.WorkspaceSetup = (() => {
    /** 渲染环境检查清单：ok / fail 徽标 + 修复提示。 */
    function renderChecks(checks) {
        $('#setup-env-list').innerHTML = checks.map(item => `
            <li class="setup-env-item ${item.status === 'ok' ? 'is-ok' : 'is-fail'}">
                <span class="setup-env-mark" aria-hidden="true">${item.status === 'ok' ? '✓' : '✗'}</span>
                <span class="setup-env-name">${escapeHtml(item.name || '')}</span>
                <span class="setup-env-detail">${escapeHtml(item.detail || '')}</span>
            </li>`).join('');
    }

    /** 阶段一：环境检查。全部通过才出现「开始创建管理员」，任一失败给出修复提示。 */
    async function runEnvCheck() {
        const error = $('#setup-env-error');
        error.classList.add('hidden');
        $('#setup-form-step').classList.add('hidden');
        $('#setup-env-continue').classList.add('hidden');
        $('#setup-env-retry').disabled = true;
        $('#setup-env-list').innerHTML = '<li class="setup-env-item is-loading"><span class="setup-env-mark" aria-hidden="true">…</span><span class="setup-env-name">正在检查运行环境</span></li>';
        try {
            const data = await api('/api/env-check', {cache: 'no-store'});
            const checks = Array.isArray(data.checks) ? data.checks : [];
            renderChecks(checks);
            if (checks.length > 0 && checks.every(item => item.status === 'ok')) {
                $('#setup-env-continue').classList.remove('hidden');
                $('#setup-env-continue').focus();
            } else {
                error.textContent = '环境检查未全部通过，请按上方说明处理服务器环境后点击「重新检查」。';
                error.classList.remove('hidden');
            }
        } catch (err) {
            $('#setup-env-list').innerHTML = '';
            error.textContent = '环境检查失败：' + err.message;
            error.classList.remove('hidden');
        } finally {
            $('#setup-env-retry').disabled = false;
        }
    }

    /** 阶段二：注册表单（仅环境全部通过后可见）。 */
    function revealForm() {
        $('#setup-env-step').classList.add('hidden');
        $('#setup-form-step').classList.remove('hidden');
        $('#setup-user').focus();
    }

    async function check() {
        const status = await api('/api/setup/status', {refresh: true});
        if (!status.needs_setup) return false;
        state.csrf = status.csrf_token || '';
        $('#login-view').classList.add('hidden');
        $('#app-view').classList.add('hidden');
        $('#setup-view').classList.remove('hidden');
        await runEnvCheck();
        return true;
    }

    $('#setup-env-retry').addEventListener('click', () => runEnvCheck());
    $('#setup-env-continue').addEventListener('click', () => revealForm());

    $('#setup-form').addEventListener('submit', async event => {
        event.preventDefault();
        const error = $('#setup-error'), button = $('#setup-submit');
        error.classList.add('hidden');
        button.disabled = true;
        try {
            const result = await api('/api/setup', {method: 'POST', body: JSON.stringify({
                user: $('#setup-user').value.trim(), password: $('#setup-password').value,
            })});
            state.csrf = result.csrf_token;
            window.WorkspaceCache?.setUser(result.user);
            $('#setup-form').reset();
            $('#setup-view').classList.add('hidden');
            history.replaceState(null, '', '#dashboard');
            toast('工作台已创建，接下来可以添加云账号或登记 IP');
            await showApp();
        } catch (err) { error.textContent = err.message; error.classList.remove('hidden'); }
        finally { button.disabled = false; }
    });
    return {check};
})();
