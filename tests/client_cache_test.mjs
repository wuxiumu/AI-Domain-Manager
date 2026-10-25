import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../public/assets/js/cache.js',import.meta.url),'utf8');
function setup(storage={}) {
 const memory=storage;
 const sessionStorage=new Proxy({getItem:k=>memory[k]??null,setItem:(k,v)=>{memory[k]=v;},removeItem:k=>{delete memory[k];}}, {ownKeys:()=>Object.keys(memory),getOwnPropertyDescriptor:()=>({enumerable:true,configurable:true})});
 const context=vm.createContext({window:{addEventListener(){},dispatchEvent(){}},sessionStorage,localStorage:{setItem(){}},CustomEvent:class{},Date,structuredClone,URLSearchParams});
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
test('concurrent reads deduplicate, callers cannot mutate cache, users are isolated',async()=>{
 const c=setup();c.setUser('a');let calls=0;
 const fetcher=async()=>{calls++;await new Promise(resolve=>setTimeout(resolve,5));return {nested:{x:1}};};
 const [a]=await Promise.all([c.read('snapshot',fetcher),c.read('snapshot',fetcher)]);assert.equal(calls,1);
 a.nested.x=7;assert.equal((await c.read('snapshot',fetcher)).nested.x,1);
 c.setUser('b');await c.read('snapshot',fetcher);assert.equal(calls,2);
});
