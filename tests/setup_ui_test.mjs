import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

function fixture({configured = false, fail = false} = {}) {
    const elements = new Map(), calls = [], state = {};
    let focused, reset = false, shown = false, cachedUser;
    for (const selector of ['#login-view', '#app-view', '#setup-view', '#setup-user', '#setup-password', '#setup-form', '#setup-error', '#setup-submit']) {
        const classes = new Set(['#setup-view', '#setup-error'].includes(selector) ? ['hidden'] : []);
        elements.set(selector, {
            value: '', disabled: false, textContent: '', listeners: {},
            classList: {add: name => classes.add(name), remove: name => classes.delete(name), contains: name => classes.has(name)},
            focus() { focused = selector; },
            reset() { reset = true; },
            addEventListener(type, listener) { this.listeners[type] = listener; },
        });
    }
    const element = selector => {
        assert.ok(elements.has(selector), `Unexpected form field: ${selector}`);
        return elements.get(selector);
    };
    const context = vm.createContext({
        window: {WorkspaceCache: {setUser(user) { cachedUser = user; }}},
        state, $: element, history: {replaceState() {}}, toast() {},
        async showApp() { shown = true; },
        async api(url, options) {
            calls.push({url, options});
            if (url === '/api/setup/status') return {needs_setup: !configured, csrf_token: configured ? null : 'setup-csrf'};
            if (fail) throw new Error('新密码长度需为 12–72 字节');
            return {user: 'admin', csrf_token: 'admin-csrf'};
        },
    });
    vm.runInContext(fs.readFileSync(new URL('../public/assets/js/setup.js', import.meta.url), 'utf8'), context);
    return {element, calls, state, setup: context.window.WorkspaceSetup,
        submit: () => element('#setup-form').listeners.submit({preventDefault() {}}),
        result: () => ({focused, reset, shown, cachedUser}),
    };
}

test('fresh installation opens the administrator form and focuses the account', async () => {
    const ui = fixture();
    assert.equal(await ui.setup.check(), true);
    assert.equal(ui.state.csrf, 'setup-csrf');
    assert.equal(ui.element('#setup-view').classList.contains('hidden'), false);
    assert.equal(ui.element('#login-view').classList.contains('hidden'), true);
    assert.equal(ui.result().focused, '#setup-user');
});

test('setup submits only account and password and enters the logged-in workspace', async () => {
    const ui = fixture();
    await ui.setup.check();
    ui.element('#setup-user').value = ' admin ';
    ui.element('#setup-password').value = 'test-password-1234';
    await ui.submit();
    assert.deepEqual(JSON.parse(ui.calls[1].options.body), {user: 'admin', password: 'test-password-1234'});
    assert.equal(ui.state.csrf, 'admin-csrf');
    assert.equal(ui.element('#setup-view').classList.contains('hidden'), true);
    assert.deepEqual(ui.result(), {focused: '#setup-user', reset: true, shown: true, cachedUser: 'admin'});
    assert.equal(ui.element('#setup-submit').disabled, false);
});

test('failed setup displays the validation error and allows retry', async () => {
    const ui = fixture({fail: true});
    await ui.setup.check();
    await ui.submit();
    assert.equal(ui.element('#setup-error').textContent, '新密码长度需为 12–72 字节');
    assert.equal(ui.element('#setup-error').classList.contains('hidden'), false);
    assert.equal(ui.element('#setup-submit').disabled, false);
    assert.equal(ui.result().shown, false);
});

test('existing installation skips administrator creation', async () => {
    const ui = fixture({configured: true});
    assert.equal(await ui.setup.check(), false);
    assert.equal(ui.element('#setup-view').classList.contains('hidden'), true);
    assert.equal(ui.result().focused, undefined);
});
