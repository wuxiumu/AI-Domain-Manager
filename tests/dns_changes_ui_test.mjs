import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

function fixture(respond = async()=>({ok:true,verified:true,message:'verified'})) {
    const calls = [], notifications = [], overlays = [];
    const document = {activeElement:{focus(){}},fullscreenElement:null,
        body:{appendChild(node){overlays.push(node);}},
        createElement(){
            const fields = new Map();
            return {innerHTML:'',className:'',removed:false,listeners:{},
                querySelector(selector){if(!fields.has(selector))fields.set(selector,{onclick:null,focus(){}});return fields.get(selector);},
                addEventListener(name, callback){this.listeners[name]=callback;},
                remove(){this.removed=true;},
            };
        },
    };
    const context = vm.createContext({document,window:{},toast:(...args)=>notifications.push(args),
        api:async(endpoint,options)=>{calls.push({endpoint,options});return respond(endpoint, options);},
        escapeHtml:value=>String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;'),
    });
    vm.runInContext(fs.readFileSync(new URL('../public/assets/js/dns-changes.js',import.meta.url),'utf8'),context);
    return {ui:context.window.WorkspaceDns,calls,notifications,overlays};
}
const record = {id:'r1',type:'A',name:'www',value:'192.0.2.1',ttl:600,priority:0,line:'custom',version:'a'.repeat(64)};

test('DNS changes require a known version and freeze the values shown for confirmation',()=>{
    const {ui}=fixture();
    assert.throws(()=>ui.prepare({action:'update',record:{...record,version:null}}),/刷新/);
    const params={value:'192.0.2.2'};
    const plan=ui.prepare({endpoint:'/api/records/r1',action:'update',record,params});
    params.value='192.0.2.99';
    assert.equal(plan.after.value,'192.0.2.2');
    assert.equal(plan.params.value,'192.0.2.2');
    assert.equal(plan.after.line,'custom');
});

test('preview displays escaped before and after values and cancel performs no write',async()=>{
    const f=fixture();
    const pending=f.ui.perform({endpoint:'/api/records/r1',action:'update',record:{...record,value:'<script>alert(1)</script>'},params:{value:'192.0.2.2'},target:'example.com · fixture'});
    const modal=f.overlays[0];
    assert.match(modal.innerHTML,/&lt;script&gt;/);
    assert.doesNotMatch(modal.innerHTML,/<script>/);
    assert.match(modal.innerHTML,/example.com · fixture/);
    assert.match(modal.innerHTML,/192.0.2.2/);
    modal.querySelector('.dns-review-cancel').onclick();
    assert.equal(await pending,null);
    assert.equal(f.calls.length,0);
});

test('confirmed update sends its old version while preserving omitted fields on the server',async()=>{
    const f=fixture();
    const pending=f.ui.perform({endpoint:'/api/records/r1',action:'update',record,params:{ttl:900}});
    f.overlays[0].querySelector('.dns-review-confirm').onclick();
    assert.equal((await pending).verified,true);
    assert.equal(f.calls[0].options.method,'PUT');
    assert.deepEqual(JSON.parse(f.calls[0].options.body),{ttl:900,expected_version:record.version});
});

test('delete carries its version and add carries the full zone revision',async()=>{
    const f=fixture();
    await f.ui.write(f.ui.prepare({endpoint:'/api/records/r1',action:'delete',record}));
    await f.ui.write(f.ui.prepare({endpoint:'/api/records',action:'add',revision:'b'.repeat(64),params:{type:'A',name:'www',value:'192.0.2.1'}}));
    assert.equal(f.calls[0].options.method,'DELETE');
    assert.equal(JSON.parse(f.calls[0].options.body).expected_version,record.version);
    assert.equal(JSON.parse(f.calls[1].options.body).expected_revision,'b'.repeat(64));
});

test('closing the owner or pressing Escape cancels the pending write',async()=>{
    const f=fixture(),owner={isConnected:true};
    const pending=f.ui.perform({endpoint:'/api/records/r1',action:'delete',record,owner});
    owner.isConnected=false;f.overlays[0].querySelector('.dns-review-confirm').onclick();
    assert.equal(await pending,null);assert.equal(f.calls.length,0);
    const another=f.ui.perform({endpoint:'/api/records/r1',action:'delete',record});
    f.overlays[1].listeners.keydown({key:'Escape',stopPropagation(){}});
    assert.equal(await another,null);assert.equal(f.calls.length,0);
});

test('unconfirmed outcomes are never shown as successful and writes are not retried',async()=>{
    const f=fixture();
    f.ui.notify({verified:false,message:'待核实'});
    assert.deepEqual(f.notifications[0],['待核实','error',10000]);
    assert.equal(f.calls.length,0);
    const owner={isConnected:false};
    await assert.rejects(f.ui.write(f.ui.prepare({endpoint:'/api/records/r1',action:'delete',record,owner})),/窗口已关闭/);
    assert.equal(f.calls.length,0);
});

test('batch value updates continue after failure and unconfirmed outcomes without retry',async()=>{
    const f=fixture(async endpoint=>{
        if(endpoint.endsWith('/1')) throw Object.assign(new Error('账号不存在'),{status:404});
        if(endpoint.endsWith('/2')) return {verified:false,message:'请核实',change_id:'change-2'};
        return {verified:true,message:'已核实'};
    });
    const pending=f.ui.batchValues([1,2,3].map(n=>({endpoint:'/api/records/'+n,action:'update',record:{...record,id:String(n)},params:{value:'192.0.2.2'},target:'example.com'})));
    f.overlays[0].querySelector('.dns-review-confirm').onclick();
    const result=await pending;
    assert.deepEqual(Array.from(result, item=>item.status),['失败','待核实','已核实']);
    assert.equal(result[1].changeId,'change-2');
    assert.equal(f.calls.length,3);
    f.ui.showBatchReport(result,'批量修改 DNS 记录值');
    const report=f.overlays[1].querySelector('.dns-report-text').value;
    assert.match(report,/失败 1 · 待核实 1/);
    assert.match(report,/账号不存在（HTTP 404）/);
    assert.match(report,/变更编号: change-2/);
    assert.match(report,/192\.0\.2\.1 → 192\.0\.2\.2/);
});

test('batch additions parse tab-separated records and reject malformed input before preview',()=>{
    const {ui}=fixture();
    const items=ui.parseBatchAdds('a\twww\t192.0.2.1\nTXT\t@\ttext with spaces\t300\t10');
    assert.equal(items.length,2);
    assert.equal(items[0].type,'A'); assert.equal(items[0].ttl,600);
    assert.equal(items[1].value,'text with spaces'); assert.equal(items[1].priority,10);
    assert.throws(()=>ui.parseBatchAdds('A www 192.0.2.1'),/第 1 行/);
    assert.throws(()=>ui.parseBatchAdds('A\twww\t192.0.2.1\t0'),/TTL/);
});

test('batch additions use each verified revision and stop on an uncertain result',async()=>{
    let n=0;
    const f=fixture(async()=>++n===1 ? {verified:true,revision:'c'.repeat(64)} : {verified:false,message:'未确认'});
    const plans=[1,2,3].map(i=>f.ui.prepare({endpoint:'/records',action:'add',revision:'b'.repeat(64),params:{type:'A',name:String(i),value:'192.0.2.1'}}));
    const result=await f.ui.writeBatchAdds(plans);
    assert.equal(result.completed,1); assert.match(result.message,/待核实/);
    assert.equal(f.calls.length,2);
    assert.equal(JSON.parse(f.calls[0].options.body).expected_revision,'b'.repeat(64));
    assert.equal(JSON.parse(f.calls[1].options.body).expected_revision,'c'.repeat(64));
});
