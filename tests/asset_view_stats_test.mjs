import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const context = vm.createContext({window:{}});
vm.runInContext(fs.readFileSync(new URL('../public/assets/js/asset-view-stats.js', import.meta.url), 'utf8'), context);
const stats = context.window.AssetViewStats;
test('statistics keep untested and stale separate from normal and abnormal', () => {
    const assets = ['healthy', 'healthy', 'unhealthy', 'stale', 'unchecked', undefined].map(status => ({health:{status}}));
    assert.deepEqual(JSON.parse(JSON.stringify(stats.calculate(assets))), {total:6, healthy:2, unhealthy:1, stale:1, unchecked:2});
    const html = stats.render(assets, 'domain');
    assert.match(html, /全部 6，正常 2，异常 1，过期 1，未检查 2/);
});
test('empty groups produce zero counts and no invalid progress widths', () => {
    const html = stats.render([], 'ip');
    assert.match(html, /全部 0，正常 0，异常 0，过期 0，未检查 0/);
    assert.doesNotMatch(html, /NaN|Infinity/);
});
