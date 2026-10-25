import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const context = vm.createContext({window: {}});
// Use the production escaping helper along with the pure renderer.
const core = fs.readFileSync(new URL('../public/assets/js/core.js', import.meta.url), 'utf8');
vm.runInContext(core.slice(core.indexOf('function escapeHtml('), core.indexOf('function providerInfo(')), context);
vm.runInContext(fs.readFileSync(new URL('../public/assets/js/relationship-graph.js', import.meta.url), 'utf8'), context);
const graph = context.window.RelationshipGraph;
const ips = [
    {id: 'ip4', name: '生产服务器', ip: '203.0.113.8', port: 443, health: {status: 'healthy'}},
    {id: 'ip6', name: 'IPv6 入口', ip: '2001:db8::8', port: 443, health: {status: 'unchecked'}},
];
const domain = {id: 'domain1', name: 'example.com', linked_ips: ['ip4', 'ip6'], bound_ips: ['ip4'], health: {status: 'healthy'}};
const hosts = [
    {...domain, ips: ['203.0.113.8'], isRoot: true},
    {id: 'host1', name: 'www.example.com', ips: ['2001:db8::8'], health: {status: 'unchecked'}},
];

function verifyCanvas(svg) {
    const [, width, height] = svg.match(/viewBox="0 0 ([\d.]+) ([\d.]+)"/).map(Number);
    const nodes = [...svg.matchAll(/<rect x="([\d.]+)" y="([\d.]+)" width="([\d.]+)" height="([\d.]+)"/g)];
    assert.ok(nodes.length);
    for (const [, x, y, w, h] of nodes) {
        assert.ok(+x >= 0 && +y >= 0 && +x + +w <= width && +y + +h <= height, 'node stays inside the canvas');
    }
    return nodes.length;
}

test('resizing and density changes retain every clickable asset and both binding types', () => {
    for (const width of [390, 1000, 1155, 1758, 2280]) {
        for (const compact of [false, true]) {
            const overview = graph.overview({domains: [domain], ips, width, compact});
            assert.equal(verifyCanvas(overview), 3);
            for (const asset of [domain, ...ips]) assert.ok(overview.includes(`data-rel-detail="${asset.id}"`));
            assert.match(overview, /已确认绑定/);
            assert.match(overview, /DNS 解析线索/);
            assert.match(overview, /\[2001:db8::8\]:443/);
            const detail = graph.detail({domain, hosts, ips, labels: {}, width, compact});
            assert.equal(verifyCanvas(detail), 5);
            assert.match(detail, /data-rel-graph-host="host1"/);
            assert.match(detail, /data-rel-graph-ip="ip6"/);
        }
    }
});

test('long or unsafe display values stay text and retain their full tooltip', () => {
    const name = '<script>alert("asset")</script>' + 'long-domain.'.repeat(12);
    const svg = graph.overview({domains: [{...domain, name}], ips, width: 1040});
    assert.ok(!svg.includes('<script>'));
    assert.ok(svg.includes('&lt;script&gt;'));
    assert.ok(svg.includes('…'));
    assert.ok(svg.includes(name.replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;')));
});
