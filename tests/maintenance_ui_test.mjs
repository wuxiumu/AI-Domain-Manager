import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

function fixture(fetcher) {
    const elements = new Map();
    const element = selector => {
        if (elements.has(selector)) return elements.get(selector);
        const classes = new Set(selector === '#maintenance-preview' ? ['hidden'] : []);
        const item = {value:'', textContent:'', innerHTML:'', disabled:false, files:[], listeners:{},
            classList:{add:name=>classes.add(name),remove:name=>classes.delete(name),contains:name=>classes.has(name),
                toggle(name,on){on?classes.add(name):classes.delete(name);}},
            addEventListener(type,handler){this.listeners[type]=handler;},
            querySelector:child=>element(selector+' '+child), focus(){},reset(){
                element('#maintenance-password').value='';element('#maintenance-confirmation').value='';
            },
        };
        elements.set(selector,item); return item;
    };
    const context = vm.createContext({window:{},$:element,$$:()=>[],api:fetcher,confirm:()=>true,setTimeout,
        document:{createElement(){throw new Error('No download expected');}}});
    const core = fs.readFileSync(new URL('../public/assets/js/core.js', import.meta.url),'utf8');
    vm.runInContext(core.slice(core.indexOf('function escapeHtml('),core.indexOf('function providerInfo(')),context);
    vm.runInContext(fs.readFileSync(new URL('../public/assets/js/maintenance.js', import.meta.url),'utf8'),context);
    return {element,ui:context.window.WorkspaceMaintenance};
}
const summary = {version:'1.1.0',backups:[],checks:[],audit:[{started_at:'2026-01-01',actor:'<img src=x onerror=alert(1)>',
    via:'api',method:'PUT',route:'/api/domains',targets:[],outcome:'success'}]};
const preview = {token:'fixture-token',expires_at:'2030-01-01',sha256:'fixture-sha',before:{domains:1,ips:1,bindings:1},after:{domains:1,ips:1,bindings:1},converted_domains:0};

test('private DNS before and after values render as escaped text and uncertain results stay distinct',async()=>{
    const f=fixture(async()=>({...summary,dns_changes:[{id:'fixture-change',started_at:'2026-01-01',actor:'admin',via:'browser',account:'fixture',domain:'example.com',action:'update',outcome:'unconfirmed',before:{value:'<img src=x onerror=alert(1)>'},requested:{value:'</pre><script>bad</script>'},after:null}]}));
    await f.ui.load();
    const html=f.element('#maintenance-dns-changes').innerHTML;
    assert.match(html,/待核实/);assert.match(html,/&lt;img/);assert.match(html,/&lt;script&gt;/);assert.doesNotMatch(html,/<img|<script>/);
});

test('operation identities render as text; leaving clears password, confirmation and selected file', async()=>{
    const f=fixture(async()=>summary);await f.ui.load();
    assert.match(f.element('#maintenance-audit').innerHTML,/&lt;img/);
    assert.doesNotMatch(f.element('#maintenance-audit').innerHTML,/<img/);
    f.element('#maintenance-password').value='temporary-password';f.element('#maintenance-confirmation').value='恢复资产';f.element('#maintenance-file').value='private-file.json';
    f.ui.leave();
    assert.equal(f.element('#maintenance-password').value,'');assert.equal(f.element('#maintenance-confirmation').value,'');assert.equal(f.element('#maintenance-file').value,'');
});

test('late management response cannot render after leaving settings',async()=>{
    let resolve; const f=fixture(()=>new Promise(done=>{resolve=done;}));
    const pending=f.ui.load();f.ui.leave();resolve(summary);await pending;
    assert.equal(f.element('#maintenance-audit').innerHTML,'');
    assert.equal(f.element('#maintenance-version').textContent,'');
});

test('failed restore clears password and shows the server error without claiming success',async()=>{
    const f=fixture(async path=>{
        if(path.endsWith('/preview'))return {preview};
        if(path.endsWith('/restore'))throw new Error('资产已变化，请重新预览');
        return summary;
    });
    f.element('#maintenance-file').files=[{size:20,text:async()=>'fixture'}];
    await f.element('#maintenance-file-preview').listeners.click({currentTarget:f.element('#maintenance-file-preview')});
    assert.equal(f.element('#maintenance-preview').classList.contains('hidden'),false);
    f.element('#maintenance-password').value='temporary-password';f.element('#maintenance-confirmation').value='恢复资产';
    f.element('#maintenance-restore-form').listeners.submit({preventDefault(){},currentTarget:f.element('#maintenance-restore-form')});
    await new Promise(done=>setImmediate(done));
    assert.equal(f.element('#maintenance-password').value,'');
    assert.match(f.element('#maintenance-error').textContent,/资产已变化/);
    assert.equal(f.element('#maintenance-message').textContent,'');
});

test('a cancelled or replaced file invalidates an in-flight preview',async()=>{
    let resolve;const f=fixture(async path=>path.endsWith('/preview')?new Promise(done=>{resolve=done;}):summary);
    f.element('#maintenance-file').files=[{size:20,text:async()=>'fixture'}];
    const pending=f.element('#maintenance-file-preview').listeners.click({currentTarget:f.element('#maintenance-file-preview')});
    await new Promise(done=>setImmediate(done));f.element('#maintenance-file').listeners.change();resolve({preview});await pending;
    assert.equal(f.element('#maintenance-preview').classList.contains('hidden'),true);
});
