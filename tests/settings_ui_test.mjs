import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

function fixture(rejectCreate = false) {
    const elements = new Map(), calls = [];
    const element = selector => {
        if (elements.has(selector)) return elements.get(selector);
        const classes = new Set(selector === '#api-token-once' ? ['hidden'] : []);
        const result = {value: '', textContent: '', innerHTML: '', disabled: false, listeners: {},
            classList: {add: name => classes.add(name), remove: name => classes.delete(name),
                contains: name => classes.has(name), toggle(name, on) { on ? classes.add(name) : classes.delete(name); }},
            addEventListener(type, handler) { this.listeners[type] = handler; },
            querySelector: child => element(selector + ' ' + child),
            querySelectorAll: child => child === '[name="api_scope"]:checked' ? [{value: 'assets:read'}, {value: 'dns:read'}] : [],
            reset() {},
        };
        elements.set(selector, result);
        return result;
    };
    const context = vm.createContext({window: {addEventListener() {}}, document: {querySelectorAll: () => [], addEventListener() {}},
        $: element, toast() {}, location: {origin: 'https://workspace.example'}, navigator: {},
        api: async (url, options) => {
            calls.push({url, options});
            if (options?.method === 'POST') {
                if (rejectCreate) throw new Error('当前管理员密码不正确');
                return {token: 'aw_fake-one-time-test-value'};
            }
            return {tokens: []};
        },
    });
    const core = fs.readFileSync(new URL('../public/assets/js/core.js', import.meta.url), 'utf8');
    vm.runInContext(core.slice(core.indexOf('function escapeHtml('), core.indexOf('function providerInfo(')), context);
    vm.runInContext(fs.readFileSync(new URL('../public/assets/js/settings.js', import.meta.url), 'utf8'), context);
    element('#api-token-name').value = 'AI test';
    element('#api-token-days').value = '30';
    element('#api-token-password').value = 'temporary-test-password';
    const submit = () => element('#settings-token-form').listeners.submit({preventDefault() {}, currentTarget: element('#settings-token-form')});
    return {element, calls, submit, settings: context.window.WorkspaceSettings};
}

test('one-time token creation sends current password then clears it; leaving removes the token', async () => {
    const ui = fixture();
    await ui.submit();
    const body = JSON.parse(ui.calls[0].options.body);
    assert.equal(body.current_password, 'temporary-test-password');
    assert.deepEqual(body.scopes, ['assets:read', 'dns:read']);
    assert.equal(body.expires_in_days, 30);
    assert.equal(ui.element('#api-token-password').value, '');
    assert.equal(ui.element('#api-token-value').textContent, 'aw_fake-one-time-test-value');
    assert.equal(ui.element('#api-token-once').classList.contains('hidden'), false);
    ui.settings.leave();
    assert.equal(ui.element('#api-token-value').textContent, '');
    assert.equal(ui.element('#api-token-once').classList.contains('hidden'), true);
});

test('rejected token creation shows an error and removes previous secret material', async () => {
    const ui = fixture(true);
    ui.element('#api-token-value').textContent = 'old-token';
    await ui.submit();
    assert.equal(ui.element('#api-token-value').textContent, '');
    assert.equal(ui.element('#api-token-password').value, '');
    assert.equal(ui.element('#api-token-once').classList.contains('hidden'), true);
    assert.equal(ui.element('#settings-token-form .error-text').textContent, '当前管理员密码不正确');
    assert.equal(ui.element('#settings-token-form button[type="submit"]').disabled, false);
});
