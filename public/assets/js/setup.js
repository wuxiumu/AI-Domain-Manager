'use strict';

window.WorkspaceSetup = (() => {
    async function check() {
        const status = await api('/api/setup/status', {refresh: true});
        if (!status.needs_setup) return false;
        state.csrf = status.csrf_token || '';
        $('#login-view').classList.add('hidden');
        $('#app-view').classList.add('hidden');
        $('#setup-view').classList.remove('hidden');
        $('#setup-key').focus();
        return true;
    }

    $('#setup-form').addEventListener('submit', async event => {
        event.preventDefault();
        const error = $('#setup-error'), button = $('#setup-submit');
        error.classList.add('hidden');
        if ($('#setup-password').value !== $('#setup-confirm').value) {
            error.textContent = '两次输入的密码不一致'; error.classList.remove('hidden'); return;
        }
        button.disabled = true;
        try {
            const result = await api('/api/setup', {method: 'POST', body: JSON.stringify({
                setup_key: $('#setup-key').value.trim(), user: $('#setup-user').value.trim(), password: $('#setup-password').value,
            })});
            state.csrf = result.csrf_token;
            window.WorkspaceCache?.setUser(result.user);
            $('#setup-form').reset();
            $('#setup-view').classList.add('hidden');
            history.replaceState(null, '', '#keys');
            toast('工作台已创建，接下来可以添加云账号或登记 IP');
            await showApp();
        } catch (err) { error.textContent = err.message; error.classList.remove('hidden'); }
        finally { button.disabled = false; }
    });
    return {check};
})();
