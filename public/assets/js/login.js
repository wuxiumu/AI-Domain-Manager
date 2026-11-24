'use strict';

window.WorkspaceLogin = (() => {
    let active = false, busy = false, loading = false, generation = 0;
    let challenge = '', expiresAt = 0, retryAt = 0, timer;
    const storageKey = 'asset-workspace-remembered-login';
    const REMEMBER_HINT = '默认记住密码：登录成功后保存在此浏览器中，下次自动填入；取消勾选即清除，请勿在公用设备使用。';
    function forget() { try { localStorage.removeItem(storageKey); } catch (_) {} }
    /** 已保存且勾选时返回记住的密码，否则空串（失败回填用）。 */
    function savedPass() {
        if (!$('#login-remember').checked) return '';
        try {
            const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
            return saved && typeof saved.pass === 'string' ? saved.pass : '';
        } catch (_) { return ''; }
    }
    function restore() {
        const hintEl = $('#login-remember-hint');
        try {
            const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
            if (saved && typeof saved.user === 'string' && typeof saved.pass === 'string') {
                $('#login-user').value = saved.user; $('#login-pass').value = saved.pass;
                $('#login-remember').checked = true;
                hintEl.textContent = '已自动填入记住的账号和密码，输入验证码即可登录；取消勾选将清除保存。';
            } else {
                // 默认记住：登录成功后保存到本浏览器，下次免输账号密码。
                $('#login-remember').checked = true;
                hintEl.textContent = REMEMBER_HINT;
            }
        } catch (_) {
            $('#login-remember').checked = false;   // localStorage 不可用（如隐私模式）
            hintEl.textContent = REMEMBER_HINT;
        }
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
            if (error.code === 'setup_required') {
                // 工作台尚未初始化：登录表单是死路，引导回环境检查 + 创建管理员流程。
                busy = false;
                toast('工作台尚未初始化，请先完成环境检查并创建管理员');
                await window.WorkspaceSetup.check();
                return;
            }
            showLoginError(error.message);
            $('#login-pass').value = savedPass();   // 失败多为验证码/限流：记住过则回填密码，避免整段重输
            challenge = '';
            $('#login-captcha').value = '';
            $('#login-captcha-image').removeAttribute('src');
            if (error.status === 429) retryAt = Date.now() + (error.retryAfter || 60) * 1000;
            busy = false;
            await refresh();
            // 账号密码已回填：焦点直接落在验证码框，重输验证码即可再试。
            $('#login-captcha').focus();
        } finally {
            if (active && current === generation) { busy = false; update(); }
        }
    });
    return {load, leave, forget};
})();
