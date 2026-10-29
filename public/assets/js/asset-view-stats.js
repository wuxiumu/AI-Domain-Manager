'use strict';
window.AssetViewStats = (() => {
    const statuses = ['healthy', 'unhealthy', 'stale', 'unchecked'];
    const names = {healthy:'正常', unhealthy:'异常', stale:'过期', unchecked:'未检查'};
    function calculate(assets, statusFor = asset => asset.health?.status) {
        const result = {total: assets.length, healthy: 0, unhealthy: 0, stale: 0, unchecked: 0};
        for (const asset of assets) {
            const status = statusFor(asset);
            result[statuses.includes(status) ? status : 'unchecked']++;
        }
        return result;
    }
    function render(assets, kind, statusFor) {
        const counts = calculate(assets, statusFor), title = kind === 'domain' ? '主域名' : 'IP / 服务器检查目标';
        return `<div class="asset-progress-header"><div><span class="asset-progress-label">${title}</span><strong>${counts.total}</strong><small>全部</small></div><span class="asset-progress-caption">最近缓存结果 · 不含当前筛选条件</span></div><div class="asset-progress-track" role="img" aria-label="全部 ${counts.total}，正常 ${counts.healthy}，异常 ${counts.unhealthy}，过期 ${counts.stale}，未检查 ${counts.unchecked}">${statuses.map(status => `<span class="asset-progress-${status}" style="width:${counts.total ? counts[status] / counts.total * 100 : 0}%"></span>`).join('')}</div><div class="asset-progress-legend">${statuses.map(status => `<span><i class="asset-progress-${status}"></i>${names[status]} <b>${counts[status]}</b></span>`).join('')}</div>`;
    }
    return {calculate, render};
})();
