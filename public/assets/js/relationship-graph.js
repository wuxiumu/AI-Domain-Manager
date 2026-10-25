'use strict';

/** Pure SVG rendering. Relationship data, filtering and event handling live in relations.js. */
window.RelationshipGraph = (() => {
    const h = escapeHtml;
    const colors = {healthy: '#42826a', unhealthy: '#b87956', stale: '#a88d46', unchecked: '#a7aea6'};
    const endpoint = asset => (asset.ip.includes(':') ? `[${asset.ip}]` : asset.ip) + ':' + asset.port;
    const widthFor = (width, minimum) => Math.max(minimum, Math.min(1800, Number(width) || minimum));

    function fitLabel(value, width, characterWidth) {
        const text = String(value || '');
        const capacity = Math.floor(width / characterWidth);
        let used = 0, result = '';
        for (const character of text) {
            used += character.codePointAt(0) > 0x2e7f ? 2 : 1;
            if (used > capacity) return result.slice(0, -1) + '…';
            result += character;
        }
        return result;
    }

    function node(asset, {x, y, width, caption, attribute, value}) {
        return `<g role="button" tabindex="0" aria-label="${h(asset.name)}" ${attribute}="${h(value)}" class="rel-node">
            <title>${h(asset.name)} · ${h(caption)}</title>
            <rect x="${x}" y="${y}" width="${width}" height="50" rx="9"/>
            <circle cx="${x + 15}" cy="${y + 17}" r="4" fill="${colors[asset.health?.status] || colors.unchecked}"/>
            <text x="${x + 28}" y="${y + 21}">${h(fitLabel(asset.name, width - 44, 7.2))}</text>
            <text x="${x + 15}" y="${y + 39}" class="rel-node-sub">${h(fitLabel(caption, width - 28, 5.8))}</text>
        </g>`;
    }

    function edge(x1, y1, x2, y2, confirmed, title = '', hierarchy = false) {
        const bend = Math.max(30, (x2 - x1) * .45);
        const stroke = hierarchy ? '#d0dcd2' : confirmed ? '#2d6b58' : '#aab9ae';
        return `<path d="M ${x1} ${y1} C ${x1 + bend} ${y1}, ${x2 - bend} ${y2}, ${x2} ${y2}" fill="none" stroke="${stroke}" stroke-width="${confirmed ? 2.5 : 1.5}" ${confirmed || hierarchy ? '' : 'stroke-dasharray="6 5"'}>${title ? `<title>${h(title)}</title>` : ''}</path>`;
    }

    function overview({domains, ips, width: availableWidth, compact = false}) {
        const width = widthFor(availableWidth, 1040);
        const row = compact ? 64 : 76;
        const nodeWidth = Math.min(380, Math.round(width * .32));
        const left = 28, right = width - nodeWidth - 28;
        const height = Math.max(domains.length, ips.length, 1) * row + 68;
        const paths = domains.flatMap((domain, i) => ips.map((ip, j) => {
            if (!(domain.linked_ips || []).includes(ip.id)) return '';
            const confirmed = domain.bound_ips.includes(ip.id);
            return edge(left + nodeWidth, 73 + i * row, right, 73 + j * row, confirmed,
                `${domain.name} → ${ip.ip} · ${confirmed ? '已确认绑定' : 'DNS 解析线索'}`);
        })).join('');
        const domainsSvg = domains.map((domain, i) => node(domain, {
            x: left, y: 48 + i * row, width: nodeWidth,
            caption: domain.bound_ips.length ? `已确认 ${domain.bound_ips.length} 个目标` : domain.linked_ips?.length ? 'DNS 线索，待确认' : '尚未关联 IP',
            attribute: 'data-rel-detail', value: domain.id,
        })).join('');
        const ipsSvg = ips.map((ip, i) => node(ip, {
            x: right, y: 48 + i * row, width: nodeWidth,
            caption: endpoint(ip) + ' · ' + (ip.server_provider || 'IP 检查目标'),
            attribute: 'data-rel-detail', value: ip.id,
        })).join('');
        return `<svg viewBox="0 0 ${width} ${height}" data-graph="overview" role="group" aria-label="当前页域名与IP关联"><text x="${left}" y="28" class="rel-column-title">域名 · ${domains.length}</text><text x="${right}" y="28" class="rel-column-title">IP / 服务器 · ${ips.length}</text>${paths}${domainsSvg}${ipsSvg}</svg>`;
    }

    function detail({domain, hosts, ips, labels, width: availableWidth, compact = false}) {
        const width = widthFor(availableWidth, 1100);
        const row = compact ? 64 : 76;
        const rootWidth = Math.min(280, Math.round(width * .19));
        const hostWidth = Math.min(380, Math.round(width * .28));
        const ipWidth = Math.min(360, Math.round(width * .27));
        const gap = (width - 48 - rootWidth - hostWidth - ipWidth) / 2;
        const rootX = 24, hostX = rootX + rootWidth + gap, ipX = hostX + hostWidth + gap;
        const height = Math.max(hosts.length, ips.length, 1) * row + 78;
        let paths = hosts.map((host, i) => edge(rootX + rootWidth, 76, hostX, 76 + i * row, false, '', true)).join('');
        paths += hosts.flatMap((host, i) => ips.map((ip, j) => host.ips.includes(ip.ip)
            ? edge(hostX + hostWidth, 76 + i * row, ipX, 76 + j * row, host.isRoot && domain.bound_ips.includes(ip.id)) : '')).join('');
        const rootSvg = node(domain, {x: rootX, y: 51, width: rootWidth, caption: '点击查看缓存明细', attribute: 'data-rel-graph-host', value: 'all'});
        const hostSvg = hosts.map((host, i) => node(host, {
            x: hostX, y: 51 + i * row, width: hostWidth,
            caption: host.isRoot ? '@ 主域名' : (labels[host.health?.status] || '未检查'),
            attribute: 'data-rel-graph-host', value: host.id,
        })).join('');
        const ipSvg = ips.map((ip, i) => node(ip, {
            x: ipX, y: 51 + i * row, width: ipWidth, caption: endpoint(ip),
            attribute: 'data-rel-graph-ip', value: ip.id,
        })).join('');
        return `<svg viewBox="0 0 ${width} ${height}" data-graph="detail" role="group" aria-label="主域名、子域名与服务器关系图"><text class="rel-column-title" x="${rootX}" y="28">主域名</text><text class="rel-column-title" x="${hostX}" y="28">网站主机 / 子域名 · ${hosts.length}</text><text class="rel-column-title" x="${ipX}" y="28">IP / 服务器 · ${ips.length}</text>${paths}${rootSvg}${hostSvg}${ipSvg}</svg>`;
    }

    return {overview, detail};
})();
