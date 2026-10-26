import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

function fixture() {
    const fields = new Map(), calls = [], responses = [], timers = new Map();
    let now = 1000, nextTimer = 0, shown = false, cachedUser;
    const state = {csrf: ''};
    function $(name) {
        if (!fields.has(name)) {
            const classes = new Set();
            fields.set(name, {value: '', textContent: '', disabled: false, src: '', listeners: {},
                classList: {add: v => classes.add(v), remove: v => classes.delete(v), contains: v => classes.has(v)},
                addEventListener(event, callback) { this.listeners[event] = callback; },
                removeAttribute(name) { this[name] = ''; },
            });
        }
        return fields.get(name);
    }
    const context = vm.createContext({$, state, Date: {now: () => now},
        setTimeout(callback) { const id = ++nextTimer; timers.set(id, callback); return id; },
        clearTimeout(id) { timers.delete(id); },
        window: {WorkspaceCache: {setUser(user) { cachedUser = user; }}},
        showLoginError(message) { $('#login-error').textContent = message; $('#login-error').classList.remove('hidden'); },
        showApp() { shown = true; context.window.WorkspaceLogin.leave(); },
        async api(path, options) {
            calls.push({path, options});
            const response = responses.shift();
            if (response instanceof Error) throw response;
            if (response) return response;
            return path === '/api/login/captcha' ? {captcha_id: `id-${calls.length}`, image: 'data:image/svg+xml;base64,fixture', expires_in: 120, csrf_token: `csrf-${calls.length}`} : {ok: true, user: 'admin', csrf_token: 'logged-in-csrf'};
        },
    });
    vm.runInContext(fs.readFileSync(new URL('../public/assets/js/login.js', import.meta.url), 'utf8'), context);
    return {$, calls, responses, state, login: context.window.WorkspaceLogin,
        submit: () => $('#login-form').listeners.submit({preventDefault() {}}),
        async advance(ms) {
            now += ms;
            const pending = [...timers.values()]; timers.clear(); pending.forEach(callback => callback());
            await Promise.resolve(); await Promise.resolve();
        },
        result: () => ({shown, cachedUser}), timers,
    };
}

test('login loads a fresh challenge without cache and refresh clears the previous answer', async () => {
    const ui = fixture();
    await ui.login.load();
    assert.equal(ui.calls[0].options.cache, 'no-store');
    assert.equal(ui.state.csrf, 'csrf-1');
    assert.equal(ui.$('#login-btn').disabled, false);
    assert.ok(ui.$('#login-captcha-image').src.startsWith('data:image/svg+xml;'));
    ui.$('#login-captcha').value = 'old';
    await ui.$('#login-captcha-refresh').listeners.click();
    assert.equal(ui.state.csrf, 'csrf-2');
    assert.equal(ui.$('#login-captcha').value, '');
});

test('failed login refreshes the consumed challenge and clears password and answer', async () => {
    const ui = fixture(); await ui.login.load();
    ui.$('#login-user').value = 'admin'; ui.$('#login-pass').value = 'secret'; ui.$('#login-captcha').value = 'AC234';
    ui.responses.push(Object.assign(new Error('用户名或密码错误'), {status: 401}));
    await ui.submit();
    assert.deepEqual(JSON.parse(ui.calls[1].options.body), {user: 'admin', pass: 'secret', captcha_id: 'id-1', captcha: 'AC234'});
    assert.equal(ui.calls[2].path, '/api/login/captcha');
    assert.equal(ui.$('#login-user').value, 'admin');
    assert.equal(ui.$('#login-pass').value, '');
    assert.equal(ui.$('#login-captcha').value, '');
    assert.equal(ui.$('#login-error').textContent, '用户名或密码错误');
    assert.equal(ui.$('#login-btn').disabled, false);
});

test('rate limiting disables login and refresh until the server wait has elapsed', async () => {
    const ui = fixture(); await ui.login.load();
    ui.responses.push(Object.assign(new Error('请求过于频繁'), {status: 429, retryAfter: 3}));
    await ui.submit();
    assert.equal(ui.calls.length, 2);
    assert.equal(ui.$('#login-btn').disabled, true);
    assert.equal(ui.$('#login-captcha-refresh').disabled, true);
    await ui.$('#login-captcha-refresh').listeners.click();
    await ui.submit();
    assert.equal(ui.calls.length, 2);
    await ui.advance(2000);
    assert.equal(ui.$('#login-btn').textContent, '1 秒后重试');
    await ui.advance(1000);
    assert.equal(ui.calls.length, 3);
    assert.equal(ui.$('#login-btn').disabled, false);
});

test('success enters the app, rotates CSRF and clears login secrets and timers', async () => {
    const ui = fixture(); await ui.login.load();
    ui.$('#login-pass').value = 'secret'; ui.$('#login-captcha').value = 'AC234';
    await ui.submit();
    assert.deepEqual(ui.result(), {shown: true, cachedUser: 'admin'});
    assert.equal(ui.state.csrf, 'logged-in-csrf');
    assert.equal(ui.$('#login-pass').value, '');
    assert.equal(ui.$('#login-captcha-image').src, '');
    assert.equal(ui.timers.size, 0);
});

test('duplicate submits are ignored and late captcha responses cannot revive a closed login view', async () => {
    const ui = fixture(); await ui.login.load();
    let resolve;
    ui.responses.push(new Promise(done => { resolve = done; }));
    const pending = ui.submit();
    await ui.submit();
    assert.equal(ui.calls.length, 2);
    resolve({ok: true, user: 'admin', csrf_token: 'rotated'}); await pending;
    ui.responses.push(new Promise(done => { resolve = done; }));
    const loading = ui.login.load();
    ui.login.leave();
    resolve({captcha_id: 'late', csrf_token: 'late', image: 'late', expires_in: 120});
    await loading;
    assert.equal(ui.state.csrf, 'rotated');
    assert.equal(ui.$('#login-captcha-image').src, '');
    assert.equal(ui.timers.size, 0);
});

test('expired challenges refresh automatically before another attempt', async () => {
    const ui = fixture(); await ui.login.load();
    await ui.advance(120000);
    assert.equal(ui.calls.length, 2);
    assert.equal(ui.calls[1].path, '/api/login/captcha');
    assert.equal(ui.$('#login-btn').disabled, false);
});
