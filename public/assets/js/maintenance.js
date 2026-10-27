'use strict';

window.WorkspaceMaintenance = (() => {
    let generation = 0;
    let previewGeneration = 0;
    let preview = null;
    let snapshot = null;
    let busy = false;
    const labels = {success: '已完成', failed: '未完成', partial: '部分未完成', skipped: '未执行', pending: '处理中 / 待核实', unconfirmed: '待核实'};
    const routeNames = {
        '/api/domains': '域名资料', '/api/health/ips': '添加 IP', '/api/health/ips/{id}': 'IP 资料',
        '/api/health/domains/{id}/bindings': '域名与 IP 关联', '/api/health/check': '资产检查',
        '/api/accounts': '云账号', '/api/accounts/{id}': '云账号配置', '/api/accounts/{id}/test': '测试云账号',
        '/api/sync': '同步域名', '/api/test-all': '测试全部云账号', '/api/settings/http': '网络配置',
        '/api/auth/credentials': '管理员登录信息', '/api/tokens': '创建 API 密钥', '/api/tokens/{id}': '撤销 API 密钥',
        '/api/maintenance/backups': '创建资产备份', '/api/maintenance/backups/{id}': '删除备份',
        '/api/maintenance/preview': '恢复预览', '/api/maintenance/restore': '恢复资产', '/api/logout': '退出登录',
    };
    const date = value => value ? new Date(value).toLocaleString('zh-CN', {hour12: false}) : '—';
    const size = bytes => bytes < 1024 ? bytes + ' B' : (bytes / 1024).toFixed(1) + ' KB';
    const visible = () => !$('#view-settings').classList.contains('hidden');

    function error(message = '') {
        $('#maintenance-error').textContent = message;
        $('#maintenance-error').classList.toggle('hidden', !message);
    }

    function resetPreview() {
        previewGeneration++;
        preview = null;
        $('#maintenance-preview').classList.add('hidden');
        $('#maintenance-restore-form').reset();
        const field = $('#maintenance-restore-form .error-text');
        field.textContent = ''; field.classList.add('hidden');
    }

    async function load() {
        const request = ++generation;
        error();
        try {
            const data = await api('/api/maintenance');
            if (request !== generation || !visible()) return;
            snapshot = data;
            $('#maintenance-version').textContent = 'v' + data.version;
            $('#maintenance-backups').innerHTML = data.backups.length ? data.backups.map(backup => {
                const id = escapeHtml(backup.id);
                if (backup.corrupt) return `<tr><td>文件损坏</td><td colspan="4">请检查服务器存储</td><td><button class="btn btn-sm btn-link-danger" data-backup-delete="${id}">删除</button></td></tr>`;
                return `<tr><td>${escapeHtml(date(backup.created_at))}${backup.reason === 'before-restore' ? '<small>恢复前自动留底</small>' : ''}</td><td>${backup.counts.domains}</td><td>${backup.counts.ips}</td><td>${backup.counts.bindings}</td><td>${size(backup.bytes)}</td><td><div class="maintenance-actions"><button class="btn btn-sm" data-backup-download="${id}">下载</button><button class="btn btn-sm" data-backup-preview="${id}">恢复预览</button><button class="btn btn-sm btn-link-danger" data-backup-delete="${id}">删除</button></div></td></tr>`;
            }).join('') : '<tr><td colspan="6" class="muted">暂无服务器备份，先创建一份再进行资料整理或版本升级。</td></tr>';
            const passed = data.checks.filter(check => check.status === 'ok').length;
            $('#maintenance-check-count').textContent = `${passed} / ${data.checks.length} 通过`;
            $('#maintenance-checks').innerHTML = data.checks.map(check => `<div class="maintenance-check"><span class="badge ${check.status === 'ok' ? 'b-green' : check.status === 'fail' ? 'b-red' : 'b-amber'}">${check.status === 'ok' ? '通过' : check.status === 'fail' ? '需修复' : '待确认'}</span><div><strong>${escapeHtml(check.name)}</strong><p>${escapeHtml(check.detail)}</p></div></div>`).join('');
            $('#maintenance-audit').innerHTML = data.audit.length ? data.audit.map(entry => `<tr><td>${escapeHtml(date(entry.started_at))}</td><td>${escapeHtml(entry.actor)}<small>${entry.via === 'api' ? 'API' : '浏览器'}</small></td><td>${escapeHtml(routeNames[entry.route] || (entry.route.includes('/records') ? 'DNS 操作' : entry.route))}<small>${escapeHtml(entry.method)} ${escapeHtml(entry.targets.join(' · '))}</small></td><td><span class="badge ${entry.outcome === 'success' ? 'b-green' : entry.outcome === 'failed' ? 'b-red' : 'b-amber'}">${labels[entry.outcome] || '待核实'}</span></td></tr>`).join('') : '<tr><td colspan="4" class="muted">暂无操作记录，后续通过权限校验的写入操作会记录在这里。</td></tr>';
            const dnsLabels = {verified: '云端已核实', unchanged: '无需变更', pending: '处理中 / 待核实', unconfirmed: '待核实'};
            $('#maintenance-dns-changes').innerHTML = (data.dns_changes || []).map(entry => `<tr><td>${escapeHtml(date(entry.started_at))}<small>${escapeHtml(entry.actor)} · ${entry.via === 'api' ? 'API' : '浏览器'}</small></td><td>${escapeHtml(entry.domain)}<small>${escapeHtml(entry.account)}</small></td><td>${({add:'新增',update:'修改',delete:'删除'})[entry.action] || '变更'}<br><span class="badge ${['verified','unchanged'].includes(entry.outcome) ? 'b-green' : 'b-amber'}">${dnsLabels[entry.outcome] || '待核实'}</span></td><td><details><summary>查看差异</summary><small>变更编号：${escapeHtml(entry.id)}</small><pre class="dns-change-values">${escapeHtml(JSON.stringify({原值:entry.before,拟提交:entry.requested,回读值:entry.after},null,2))}</pre></details></td></tr>`).join('') || '<tr><td colspan="4" class="muted">暂无 DNS 变更记录。</td></tr>';
        } catch (failure) { if (request === generation && visible()) error(failure.message); }
    }

    function download(file, type = 'application/json;charset=utf-8') {
        const url = URL.createObjectURL(new Blob([file.content], {type}));
        const link = document.createElement('a'); link.href = url; link.download = file.filename;
        document.body.append(link); link.click(); link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        $('#maintenance-message').textContent = '文件已生成，请妥善保存。SHA-256：' + file.sha256;
    }

    async function action(button, work) {
        if (busy) return;
        busy = true; button.disabled = true; error();
        $$('[data-settings-panel="maintenance"] button, #maintenance-file').forEach(control => { control.disabled = true; });
        try { await work(); }
        catch (failure) { if (visible()) error(failure.message); }
        finally { busy = false; button.disabled = false; $$('[data-settings-panel="maintenance"] button, #maintenance-file').forEach(control => { control.disabled = false; }); }
    }

    async function showPreview(body) {
        resetPreview();
        const request = generation;
        const previewRequest = previewGeneration;
        const result = await api('/api/maintenance/preview', {method: 'POST', body: JSON.stringify(body)});
        if (request !== generation || previewRequest !== previewGeneration || !visible()) return;
        preview = result.preview;
        const names = {domains: '域名', ips: '手工 IP', bindings: '人工关联'};
        $('#maintenance-preview-summary').innerHTML = '<p>此操作会替换全部当前资产，不会修改云端 DNS。当前 → 恢复后：</p><div class="maintenance-counts">' + Object.entries(names).map(([key, name]) => `<div><span>${name}</span><strong>${preview.before[key]} <span>→</span> ${preview.after[key]}</strong></div>`).join('') + '</div>';
        $('#maintenance-preview-warning').textContent = [
            preview.converted_domains ? `${preview.converted_domains} 个域名的原云账号未配置，将转为手工资产。` : '',
            preview.orphaned_bindings ? `${preview.orphaned_bindings} 条已删除域名的残留关联将忽略。` : '',
            '检查结果会清空；恢复前自动创建一份备份。',
        ].filter(Boolean).join(' ');
        $('#maintenance-preview-hash').textContent = `预览有效至 ${date(preview.expires_at)} · SHA-256：${preview.sha256}`;
        $('#maintenance-preview').classList.remove('hidden');
        $('#maintenance-preview').focus();
    }

    $('[data-settings-tab="maintenance"]').addEventListener('click', load);
    $('#maintenance-refresh').addEventListener('click', load);
    $('#maintenance-cancel').addEventListener('click', resetPreview);
    $('#maintenance-backup').addEventListener('click', event => action(event.currentTarget, async () => {
        await api('/api/maintenance/backups', {method: 'POST', body: '{}'});
        $('#maintenance-message').textContent = '服务器备份已创建。建议同时下载到独立位置保存。';
        await load();
    }));
    $$('[data-asset-export]').forEach(button => button.addEventListener('click', () => action(button, async () => {
        const format = button.dataset.assetExport;
        const file = await api('/api/maintenance/export?format=' + format);
        download(file, format === 'csv' ? 'text/csv;charset=utf-8' : 'application/json;charset=utf-8');
    })));
    $('#maintenance-backups').addEventListener('click', event => {
        const button = event.target.closest('button'); if (!button) return;
        action(button, async () => {
            if (button.dataset.backupDownload) download(await api('/api/maintenance/backups/' + button.dataset.backupDownload));
            if (button.dataset.backupPreview) await showPreview({backup_id: button.dataset.backupPreview});
            if (button.dataset.backupDelete && confirm('删除这份服务器备份？请先确认已下载需要保留的资料。')) {
                await api('/api/maintenance/backups/' + button.dataset.backupDelete, {method: 'DELETE'}); await load();
            }
        });
    });
    $('#maintenance-file').addEventListener('change', resetPreview);
    $('#maintenance-file-preview').addEventListener('click', event => action(event.currentTarget, async () => {
        const file = $('#maintenance-file').files[0];
        if (!file) throw new Error('请先选择 JSON 资产文件');
        if (file.size > 5 * 1024 * 1024) throw new Error('文件不能超过 5 MB');
        await showPreview({content: await file.text()});
    }));
    $('#maintenance-restore-form').addEventListener('submit', event => {
        event.preventDefault(); const form = event.currentTarget;
        action(form.querySelector('[type="submit"]'), async () => {
            if (!preview) throw new Error('请重新预览资产文件');
            try {
                await api('/api/maintenance/restore', {method: 'POST', body: JSON.stringify({token: preview.token,
                    password: $('#maintenance-password').value, confirmation: $('#maintenance-confirmation').value})});
                resetPreview(); $('#maintenance-file').value = '';
                $('#maintenance-message').textContent = '资产已恢复，恢复前备份已保留。请重新检查资产状态。';
                await load();
            } finally { $('#maintenance-password').value = ''; }
        });
    });
    $('#maintenance-report').addEventListener('click', () => {
        if (!snapshot) return error('请先加载交付自检');
        const last = snapshot.backups.find(backup => !backup.corrupt);
        const content = `# 资产工作台交付摘要\n\n生成时间：${new Date().toISOString()}\n版本：${snapshot.version}\n\n域名：${snapshot.counts.domains}；手工 IP：${snapshot.counts.ips}；人工关联：${snapshot.counts.bindings}\n\n## 环境自检\n\n` + snapshot.checks.map(check => `- [${check.status === 'ok' ? 'x' : ' '}] ${check.name}：${check.detail}`).join('\n') + `\n\n## 资产备份\n\n${last ? '最近备份：' + last.created_at + '\nSHA-256：' + last.sha256 : '尚未创建备份'}\n\n资产备份不含云凭据、登录配置和 API 密钥；检查缓存不恢复。完整换机还需重新配置账号与凭据。\n\n## 人工验收\n\n- [ ] 已在隔离实例完成恢复演练并核对备注、网站链接及关联\n- [ ] 已核对云账号权限和同步数量\n- [ ] 已确认 HTTPS、生产 Web 服务和文件访问控制\n- [ ] 已明确软件授权、交付范围和维护时间\n- [ ] 客户已完成一次搜索、编辑、检查及资料导出\n`;
        const url = URL.createObjectURL(new Blob([content], {type: 'text/markdown;charset=utf-8'}));
        const link = document.createElement('a'); link.href = url; link.download = 'delivery-summary.md'; document.body.append(link); link.click(); link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });

    function leave() { generation++; resetPreview(); snapshot = null; $('#maintenance-file').value = ''; }
    return {load, leave};
})();
