import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../public/assets/js/cache.js',import.meta.url),'utf8');
function setup(storage={}, clock=Date) {
 const memory=storage;
 const sessionStorage=new Proxy({getItem:k=>memory[k]??null,setItem:(k,v)=>{memory[k]=v;},removeItem:k=>{delete memory[k];}}, {ownKeys:()=>Object.keys(memory),getOwnPropertyDescriptor:()=>({enumerable:true,configurable:true})});
 const context=vm.createContext({window:{addEventListener(){},dispatchEvent(){}},sessionStorage,localStorage:{setItem(){}},CustomEvent:class{},Date:clock,structuredClone,URLSearchParams});
 vm.runInContext(source,context);return context.window.WorkspaceCache;
}
test('tab navigation and restored sessions use cached data; mutation clears it',async()=>{
 const storage={}, c=setup(storage);let calls=0;const fetcher=async()=>({count:++calls});c.setUser('admin');
 assert.equal((await c.read('/api/health',fetcher)).count,1);
 assert.equal((await c.read('/api/health',fetcher)).count,1);
 const restored=setup(storage);restored.setUser('admin');assert.equal((await restored.read('/api/health',fetcher)).count,1);
 restored.clear();assert.equal((await restored.read('/api/health',fetcher)).count,2);
 assert.equal((await restored.read('/api/health',fetcher,true)).count,3);
});
test('health and statistics expire after one minute while inventory keeps its longer cache',async()=>{
 let now=1000,calls=0;const cache=setup({}, {now:()=>now});cache.setUser('admin');
 const fetcher=async()=>({revision:++calls});
 await cache.read('/api/health',fetcher);await cache.read('/api/domains/stats',fetcher);await cache.read('/api/domains',fetcher);
 now+=60001;
 assert.equal((await cache.read('/api/health',fetcher)).revision,4);
 assert.equal((await cache.read('/api/domains/stats',fetcher)).revision,5);
 assert.equal((await cache.read('/api/domains',fetcher)).revision,3);
});
test('upgrading or logging out removes earlier application cache namespaces without clearing other storage',async()=>{
 const storage={'asset-workspace-v5:admin:/api/health':'private-old-snapshot','another-product':'keep'};
 const cache=setup(storage);assert.equal(storage['asset-workspace-v5:admin:/api/health'],undefined);assert.equal(storage['another-product'],'keep');
 storage['asset-workspace-v4:admin:/api/domains']='old';cache.clear();
 assert.equal(storage['asset-workspace-v4:admin:/api/domains'],undefined);assert.equal(storage['another-product'],'keep');
});
test('concurrent reads deduplicate, callers cannot mutate cache, users are isolated',async()=>{
 const c=setup();c.setUser('a');let calls=0;
 const fetcher=async()=>{calls++;await new Promise(resolve=>setTimeout(resolve,5));return {nested:{x:1}};};
 const [a]=await Promise.all([c.read('snapshot',fetcher),c.read('snapshot',fetcher)]);assert.equal(calls,1);
 a.nested.x=7;assert.equal((await c.read('snapshot',fetcher)).nested.x,1);
 c.setUser('b');await c.read('snapshot',fetcher);assert.equal(calls,2);
});
