import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../public/assets/js/core.js', import.meta.url), 'utf8');
const apiSource = source.slice(source.indexOf('async function api('), source.indexOf('/* ---------- 工具'));
function client(response) {
    const context = vm.createContext({state:{csrf:'session-token'},fetch:async (path,opts) => {
        assert.equal(opts.headers['X-CSRF-Token'], 'session-token');
        return response;
    }});
    vm.runInContext(apiSource, context);
    return context.api;
}

test('HTTP 200 with business failure rejects instead of counting a DNS edit as successful', async () => {
    await assert.rejects(client({ok:true,status:200,json:async()=>({ok:false,error:'DNS write rejected'})})('/api/records'), /DNS write rejected/);
});
test('HTTP failure rejects even if body contains ok', async () => {
    await assert.rejects(client({ok:false,status:403,json:async()=>({ok:false,error:'csrf rejected'})})('/api/health'), /csrf rejected/);
});
test('malformed server response cannot be treated as success', async () => {
    await assert.rejects(client({ok:true,status:200,json:async()=>{throw new Error('html');}})('/api/records'), /无法读取/);
});
test('legacy numeric ok count of zero is data, not boolean failure', async () => {
    const result = await client({ok:true,status:200,json:async()=>({ok:0,fail:4})})('/api/access-check');
    assert.equal(result.ok,0);
});

test('rate limit metadata reaches the login UI', async () => {
    await assert.rejects(client({ok:false,status:429,headers:{get:()=> '12'},json:async()=>({ok:false,error:'wait',code:'rate_limited',retry_after:12})})('/api/login'), error => {
        assert.equal(error.status, 429);
        assert.equal(error.code, 'rate_limited');
        assert.equal(error.retryAfter, 12);
        return true;
    });
});

test('captcha fetches bypass the application response cache', async () => {
    let fetched = 0;
    const context = vm.createContext({state:{csrf:''},window:{WorkspaceCache:{read(){throw new Error('Captcha must not be cached');}}},
        fetch:async()=>({ok:true,status:200,json:async()=>({captcha_id:++fetched})}),
    });
    vm.runInContext(apiSource, context);
    assert.equal((await context.api('/api/login/captcha')).captcha_id, 1);
    assert.equal((await context.api('/api/login/captcha')).captcha_id, 2);
});
