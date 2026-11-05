'use strict';

/* 近 30 天检查趋势：读取 /api/trends 渲染为内联 SVG 堆叠柱状图，无外部依赖。 */
window.WorkspaceTrends = (() => {
    let loading = null;
    let data = null;
    const chart = $('#health-trend-chart');
    if (!chart) return { load: async () => null };

    async function load() {
        if (loading) return loading;
        loading = (async () => {
            try {
                data = await api('/api/trends');
                render();
                return data;
            } catch (err) {
                chart.setAttribute('role', 'img');
                chart.setAttribute('aria-label', '趋势图加载失败');
                chart.innerHTML = `<p class="muted">趋势暂不可用：${escapeHtml(err.message)}</p>`;
                return null;
            } finally {
                loading = null;
            }
        })();
        return loading;
    }

    function render() {
        if (!data) return;
        const daily = data.daily || [];
        const summary = data.summary || {};
        const max = Math.max(1, ...daily.map(d => d.checks));
        $('#trend-summary').textContent = `30 天共 ${summary.checks_30d || 0} 次检查（异常 ${summary.unhealthy_30d || 0}）· 完整检查 ${summary.full_runs_30d || 0} 轮 · 活跃 ${summary.active_days || 0} 天`
            + (summary.avg_latency_ms != null ? ` · 平均延迟 ${summary.avg_latency_ms} ms` : '');
        $('#dash-trend-checks').textContent = summary.checks_30d != null ? `${summary.checks_30d} 次检查` : '—';
        $('#dash-trend-summary').textContent = summary.checks_30d != null
            ? `异常 ${summary.unhealthy_30d || 0} 次 · 活跃 ${summary.active_days || 0} 天，点击查看完整趋势`
            : '正在加载趋势…';
        const width = 1080, height = 220, pad = 6, top = 14, bottom = 2;
        const slot = (width - pad * 2) / Math.max(1, daily.length);
        const barWidth = Math.max(4, Math.min(24, slot - Math.min(9, slot * 0.35)));
        const plotHeight = height - top - bottom;
        const bars = daily.map((d, i) => {
            const x = pad + i * slot + (slot - barWidth) / 2;
            const healthyH = d.healthy / max * plotHeight;
            const unhealthyH = d.unhealthy / max * plotHeight;
            const yHealthy = top + plotHeight - healthyH;
            const label = `${d.date}：检查 ${d.checks}（正常 ${d.healthy} / 异常 ${d.unhealthy}）${d.avg_latency_ms != null ? '，平均延迟 ' + d.avg_latency_ms + 'ms' : ''}`;
            return d.checks === 0
                ? `<rect x="${x}" y="${top + plotHeight - 2}" width="${barWidth}" height="2" fill="var(--line, #d8d8d2)"><title>${label}</title></rect>`
                : `<rect x="${x}" y="${yHealthy}" width="${barWidth}" height="${Math.max(2, healthyH)}" fill="var(--green)"><title>${label}</title></rect>`
                + (d.unhealthy ? `<rect x="${x}" y="${yHealthy - unhealthyH}" width="${barWidth}" height="${Math.max(2, unhealthyH)}" fill="var(--red)"><title>${label}</title></rect>` : '');
        }).join('');
        chart.setAttribute('aria-label', `最近 30 天共 ${summary.checks_30d || 0} 次检查，其中异常 ${summary.unhealthy_30d || 0} 次；高峰日最多 ${max} 项。`);
        /* 坐标轴刻度按柱体实际位置绝对定位，首尾刻度向内收避免溢出。 */
        const step = Math.max(1, Math.ceil(daily.length / 8));
        const tickIndexes = [];
        for (let i = 0; i < daily.length; i += step) tickIndexes.push(i);
        if (tickIndexes[tickIndexes.length - 1] !== daily.length - 1) {
            // 末尾刻度与最后一个固定刻度太近时会重叠，替换而不是追加。
            if (daily.length - 1 - tickIndexes[tickIndexes.length - 1] < Math.ceil(step / 2)) tickIndexes.pop();
            tickIndexes.push(daily.length - 1);
        }
        const ticks = tickIndexes.map((i, n) => {
            const left = (pad + i * slot + (slot - barWidth) / 2 + barWidth / 2) / width * 100;
            const shift = n === 0 ? '0%' : n === tickIndexes.length - 1 ? '-100%' : '-50%';
            return `<span style="left:${left}%;transform:translateX(${shift})">${escapeHtml(daily[i].date.slice(5))}</span>`;
        }).join('');
        chart.innerHTML = `<svg viewBox="0 0 ${width} ${height}" width="100%" height="${height}" preserveAspectRatio="none" style="display:block">${bars}</svg>`
            + `<div class="trend-axis" aria-hidden="true">${ticks}</div>`;
        const rows = [...daily].reverse().map(d => `<tr><td>${escapeHtml(d.date)}</td><td>${d.checks}</td><td>${d.healthy}</td><td>${d.unhealthy}</td><td>${d.full_runs}</td><td>${d.avg_latency_ms != null ? d.avg_latency_ms + ' ms' : '—'}</td></tr>`).join('');
        $('#trend-table tbody').innerHTML = rows || '<tr><td colspan="6">暂无趋势数据，执行检查后生成。</td></tr>';
    }

    $('#trend-refresh').addEventListener('click', load);
    $('#health-refresh').addEventListener('click', load);
    if (!$('#app-view').classList.contains('hidden')) load();
    return { load };
})();
