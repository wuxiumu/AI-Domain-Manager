'use strict';

window.WorkspaceLogin = (() => {
    let active = false, busy = false, loading = false, generation = 0;
    let challenge = '', expiresAt = 0, retryAt = 0, timer;
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
        active = true;
        $('#login-error').classList.add('hidden');
        tick();
        await refresh();
    }
    $('#login-captcha-refresh').addEventListener('click', () => refresh());
    $('#login-form').addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || loading || retryAt > Date.now()) return;
        if (!challenge || Date.now() >= expiresAt) { await refresh(); return; }
        const current = generation;
        busy = true;
        $('#login-error').classList.add('hidden');
        update();
        try {
            const data = await api('/api/login', {method: 'POST', body: JSON.stringify({
                user: $('#login-user').value, pass: $('#login-pass').value,
                captcha_id: challenge, captcha: $('#login-captcha').value,
            })});
            if (!active || current !== generation) return;
            state.csrf = data.csrf_token || '';
            window.WorkspaceCache?.setUser(data.user);
            showApp();
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
    return {load, leave};
})();
