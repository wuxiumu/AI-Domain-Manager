import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const context = vm.createContext({window:{addEventListener(){}}, sessionStorage:{}, localStorage:{}, Date, URLSearchParams});
vm.runInContext(fs.readFileSync(new URL('../public/assets/js/cache.js', import.meta.url), 'utf8'), context);
const data = context.window.WorkspaceData;
const rows = [
    {domain:'Example.com',provider:'first',valid:true,health:{status:'healthy'}},
    {domain:'example.com.',provider:'second',valid:true,health:{status:'unhealthy'}},
    {domain:'example.com',provider:'first',valid:true,health:{status:'healthy'}},
    {domain:'another.example.com',provider:'first',valid:true,health:{status:'unchecked'}},
];

test('same domain becomes one row while preserving distinct account operations', () => {
    const groups = data.groupDomains(rows);
    assert.equal(groups.length, 2);
    assert.equal(groups[0].domain, 'example.com');
    assert.deepEqual(Array.from(groups[0].sources, item => item.provider), ['first', 'second']);
});

test('account and health filters apply before grouping', () => {
    const params = new URLSearchParams({provider:'second', access:'unhealthy'});
    const groups = data.groupDomains(data.filterDomains({list:rows}, params).list);
    assert.equal(groups.length, 1);
    assert.equal(groups[0].sources.length, 1);
    assert.equal(groups[0].sources[0].provider, 'second');
});
