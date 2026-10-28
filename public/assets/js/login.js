'use strict';

window.WorkspaceLogin = (() => {
    let active = false, busy = false, loading = false, generation = 0;
    let challenge = '', expiresAt = 0, retryAt = 0, timer;
    const storageKey = 'asset-workspace-remembered-login';
    function forget() { try { localStorage.removeItem(storageKey); } catch (_) {} }
    function restore() {
        try {
            const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
            if (saved && typeof saved.user === 'string' && typeof saved.pass === 'string') {
                $('#login-user').value = saved.user; $('#login-pass').value = saved.pass;
                $('#login-remember').checked = true;
            } else { $('#login-remember').checked = false; }
        } catch (_) { $('#login-remember').checked = false; }
    }
    const hint = () => $('#login-captcha-hint');
    function update() {
        const seconds = Math.max(0, Math.ceil((retryAt - Date.now()) / 1000));
        $('#login-btn').disabled = busy || loading || !challenge || seconds > 0 || Date.now() >= expiresAt;
        $('#login-captcha-refresh').disabled = busy || loading || seconds > 0;
        $('#login-btn').textContent = busy ? '正在登录…' : seconds ? `${seconds} 秒后重试` : '登录工作台';
        hint().textContent = seconds ? `请求过于频繁，请等待 ${seconds} 秒后重试。` : loading ? '正在获取验证码…' : challenge ? '不区分大小写，2 分钟有效；点击图片可更换。' : '验证码未加载，请点击图片重试。';
    }
    async function refresh() {
        if (!active || busy || retryAt > Date.now()) return;
        const current = ++generation;
        loading = true;
        challenge = '';
        $('#login-captcha').value = '';
        $('#login-captcha-image').removeAttribute('src');
        update();
        try {
            const data = await api('/api/login/captcha', {cache: 'no-store'});
            if (!active || current !== generation) return;
            challenge = data.captcha_id;
            expiresAt = Date.now() + data.expires_in * 1000;
            state.csrf = data.csrf_token;
            $('#login-captcha-image').src = data.image;
        } catch (error) {
            if (!active || current !== generation) return;
            if (error.status === 429) retryAt = Date.now() + (error.retryAfter || 60) * 1000;
            showLoginError(error.message);
        } finally {
            if (active && current === generation) { loading = false; update(); }
        }
    }
    function tick() {
        if (!active) return;
        if (!busy && !loading && ((retryAt && retryAt <= Date.now()) || (challenge && expiresAt <= Date.now()))) {
            retryAt = 0;
            refresh();
        }
        update();
        timer = setTimeout(tick, 1000);
    }
    function leave() {
        active = false;
        generation++;
        clearTimeout(timer);
        busy = loading = false;
        challenge = '';
        retryAt = expiresAt = 0;
        $('#login-pass').value = '';
        $('#login-captcha').value = '';
        $('#login-captcha-image').removeAttribute('src');
    }
    async function load() {
        leave();
        restore();
        active = true;
        $('#login-error').classList.add('hidden');
        tick();
        await refresh();
    }
    $('#login-captcha-refresh').addEventListener('click', () => refresh());
    $('#login-remember').addEventListener('change', () => { if (!$('#login-remember').checked) forget(); });
    $('#login-form').addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || loading || retryAt > Date.now()) return;
        if (!challenge || Date.now() >= expiresAt) { await refresh(); return; }
        const current = generation;
        busy = true;
        const user = $('#login-user').value, pass = $('#login-pass').value;
        $('#login-error').classList.add('hidden');
        update();
        try {
            const data = await api('/api/login', {method: 'POST', body: JSON.stringify({
                user, pass,
                captcha_id: challenge, captcha: $('#login-captcha').value,
            })});
            if (!active || current !== generation) return;
            let rememberFailed = false;
            if ($('#login-remember').checked) {
                try { localStorage.setItem(storageKey, JSON.stringify({user, pass})); }
                catch (_) { $('#login-remember').checked = false; forget(); rememberFailed = true; }
            } else { forget(); }
            state.csrf = data.csrf_token || '';
            window.WorkspaceCache?.setUser(data.user);
            showApp();
            if (rememberFailed) toast('登录成功，但浏览器不允许保存账号和密码', 'error');
        } catch (error) {
            if (!active || current !== generation) return;
            showLoginError(error.message);
            $('#login-pass').value = '';
            challenge = '';
            $('#login-captcha').value = '';
            $('#login-captcha-image').removeAttribute('src');
            if (error.status === 429) retryAt = Date.now() + (error.retryAfter || 60) * 1000;
            busy = false;
            await refresh();
        } finally {
            if (active && current === generation) { busy = false; update(); }
        }
    });
    return {load, leave, forget};
})();
