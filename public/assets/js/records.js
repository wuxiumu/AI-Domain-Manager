'use strict';

/* ---------- DNS 解析记录管理 ---------- */
const RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

function openRecordsModal(domain, provider) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
        <div class="modal modal-wide">
            <div class="modal-head">
                <h3>解析记录 · ${escapeHtml(domain)} <span class="muted">（${escapeHtml(providerInfo(provider).name)}）</span> <span id="rec-count" class="muted"></span></h3>
                <button class="modal-close">×</button>
            </div>
            <div class="modal-body">
                <div id="rec-error" class="error-text hidden"></div>
                <div id="rec-loading" class="muted">加载中…</div>
                <div id="rec-form" class="rec-form hidden">
                    <div class="rec-form-grid">
                        <select id="rec-type">${RECORD_TYPES.map((t) => `<option>${t}</option>`).join('')}</select>
                        <input type="text" id="rec-name" placeholder="主机记录（@ 或 www）" value="@">
                        <input type="text" id="rec-value" placeholder="记录值，如 1.2.3.4">
                        <input type="number" id="rec-ttl" placeholder="TTL" value="600">
                        <input type="number" id="rec-priority" placeholder="优先级(MX)" value="0">
                        <div class="rec-form-actions">
                            <button class="btn btn-sm btn-cancel">取消</button>
                            <button class="btn btn-sm btn-primary" id="rec-save">保存</button>
                        </div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table id="rec-table">
                        <thead><tr>
                            <th class="col-check"><input type="checkbox" id="rec-check-all" title="全选"></th>
                            <th>类型</th><th>主机记录</th><th>记录值</th><th>TTL</th><th>优先级</th><th>访问</th><th>操作</th>
                        </tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <p id="rec-empty" class="muted hidden">暂无解析记录</p>
            </div>
            <div class="modal-foot">
                <div class="batch-bar">
                    <span id="rec-sel-count" class="muted">未选择</span>
                    <button class="btn btn-sm" id="rec-batch-ttl" disabled>批量修改TTL</button>
                    <button class="btn btn-sm" id="rec-batch-value" disabled>批量修改记录值</button>
                    <button class="btn btn-sm btn-link-danger" id="rec-batch-del" disabled>批量删除</button>
                </div>
                <button class="btn" id="rec-refresh-btn">刷新云端记录</button><button class="btn" id="rec-add-btn">＋ 添加记录</button>
                <button class="btn" id="rec-access-btn" title="探测各记录指向的主机/IP 能否访问">🛰 检测访问</button>
            </div>
        </div>`;
    (document.fullscreenElement || document.body).appendChild(overlay);

    overlay.querySelector('.modal-close').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

    const recApi = (path, opts) => api(`/api/domains/${encodeURIComponent(provider)}/${encodeURIComponent(domain)}/records${path}`, opts);
    let editingId = null;
    let recordsById = {}; // recordId => 记录（供批量操作/编辑回填）

    /* 主机记录 → 站点链接 */
    const nameLink = (name) => {
        const n = name || '@';
        const host = (n === '@') ? domain : (n.startsWith('*') ? domain : n + '.' + domain);
        return 'https://' + host;
    };
    /* 记录值 → 站点链接（IP 直连 http://，域名 https://，其余不可点击） */
    const valueLink = (value) => {
        const v = String(value || '').trim();
        if (/^(\d{1,3}\.){3}\d{1,3}$/.test(v)) return 'http://' + v;              // IPv4
        if (/^[0-9a-fA-F:.]+$/.test(v) && v.includes(':')) return 'http://[' + v + ']'; // IPv6
        const host = v.replace(/\.$/, '');
        if (/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$/.test(host)) {
            return 'https://' + host;
        }
        return null;
    };
    const linkHtml = (text, href) => href
        ? `<a class="rec-link" href="${escapeHtml(href)}" target="_blank" rel="noopener" title="打开 ${escapeHtml(href)}">${escapeHtml(text)}</a>`
        : escapeHtml(text);

    async function loadRecords(refresh = false) {
        const tbody = $('#rec-table tbody');
        tbody.innerHTML = '<tr><td colspan="8" class="muted">加载中…</td></tr>';
        try {
            const data = await recApi(refresh ? '?refresh=1' : '');
            if (!data.ok) throw new Error(data.error || '加载失败');
            $('#rec-loading').classList.add('hidden');
            $('#rec-empty').classList.toggle('hidden', data.records.length !== 0);
            $('#rec-count').textContent = `共 ${data.records.length} 条 · 快照 ${data.snapshot_at || '未知'}`;
            recordsById = {};
            data.records.forEach((r) => { recordsById[r.id] = r; });
            tbody.innerHTML = data.records.map((r) => `
                <tr>
                    <td class="col-check"><input type="checkbox" class="rec-check" value="${escapeHtml(r.id)}"></td>
                    <td><span class="badge ${badgeClass('tencent')}">${escapeHtml(r.type)}</span></td>
                    <td>${linkHtml(r.name || '@', nameLink(r.name))}</td>
                    <td class="rec-value">${linkHtml(r.value, valueLink(r.value))}</td>
                    <td>${escapeHtml(String(r.ttl))}</td>
                    <td>${escapeHtml(String(r.priority || 0))}</td>
                    <td>${recAccessHtml(r)}</td>
                    <td class="row-actions">
                        <button class="btn btn-link btn-sm rec-edit" data-id="${escapeHtml(r.id)}">编辑</button>
                        <button class="btn btn-link-danger btn-sm rec-del" data-id="${escapeHtml(r.id)}">删除</button>
                    </td>
                </tr>`).join('');
            updateBatchUI();
        } catch (err) {
            $('#rec-loading').classList.add('hidden');
            $('#rec-table tbody').innerHTML = '<tr><td colspan="8" class="fail">' + escapeHtml(err.message) + '</td></tr>';
        }
    }

    /* ---------- 多选与批量操作 ---------- */
    function selectedIds() {
        return Array.from($$('#rec-table tbody .rec-check:checked')).map((c) => c.value);
    }

    function updateBatchUI() {
        const ids = selectedIds();
        const count = ids.length;
        $('#rec-sel-count').textContent = count ? `已选择 ${count} 条` : '未选择';
        $('#rec-batch-ttl').disabled = count === 0;
        $('#rec-batch-value').disabled = count === 0;
        $('#rec-batch-del').disabled = count === 0;
        const all = $$('#rec-table tbody .rec-check');
        $('#rec-check-all').checked = all.length > 0 && all.length === ids.length;
    }

    $('#rec-check-all').addEventListener('change', (e) => {
        $$('#rec-table tbody .rec-check').forEach((c) => { c.checked = e.target.checked; });
        updateBatchUI();
    });

    $('#rec-table tbody').addEventListener('change', (e) => {
        if (e.target.classList.contains('rec-check')) updateBatchUI();
    });

    $('#rec-batch-ttl').addEventListener('click', async () => {
        const ids = selectedIds();
        if (!ids.length) return;
        const input = await promptDialog({
            title: '批量修改 TTL',
            message: `将 ${ids.length} 条记录的 TTL 统一修改为（秒）：`,
            value: '600',
            placeholder: '1-86400',
            okText: '修改',
        });
        if (input === null) return;
        const ttl = parseInt(input, 10);
        if (!ttl || ttl < 1 || ttl > 86400) {
            toast('TTL 需为 1-86400 之间的整数', 'error');
            return;
        }
        const btn = $('#rec-batch-ttl');
        btn.disabled = true;
        let okCount = 0;
        const failed = [];
        for (const id of ids) {
            const rec = recordsById[id];
            if (!rec) continue;
            try {
                await recApi('/' + encodeURIComponent(id), {
                    method: 'PUT',
                    body: JSON.stringify({
                        type: rec.type, name: rec.name || '@', value: rec.value,
                        ttl, priority: rec.priority || 0,
                    }),
                });
                okCount++;
            } catch (err) {
                failed.push(`${rec.name || '@'} (${err.message})`);
            }
        }
        let msg = `批量修改 TTL 完成：成功 ${okCount} 条`;
        if (failed.length) msg += `，失败 ${failed.length} 条\n` + failed.slice(0, 5).join('\n');
        toast(msg, failed.length ? 'error' : 'success', 5000);
        loadRecords();
    });

    $('#rec-batch-value').addEventListener('click', async () => {
        const ids = selectedIds();
        if (!ids.length) return;
        const input = await promptDialog({
            title: '批量修改记录值',
            message: `将 ${ids.length} 条记录的记录值统一修改为：`,
            value: '',
            placeholder: '如 1.2.3.4 或 target.example.com',
            okText: '修改',
        });
        if (input === null) return;
        const newValue = input.trim();
        if (!newValue) {
            toast('记录值不能为空', 'error');
            return;
        }
        const btn = $('#rec-batch-value');
        btn.disabled = true;
        let okCount = 0;
        const failed = [];
        for (const id of ids) {
            const rec = recordsById[id];
            if (!rec) continue;
            try {
                await recApi('/' + encodeURIComponent(id), {
                    method: 'PUT',
                    body: JSON.stringify({
                        type: rec.type, name: rec.name || '@', value: newValue,
                        ttl: rec.ttl || 600, priority: rec.priority || 0,
                    }),
                });
                okCount++;
            } catch (err) {
                failed.push(`${rec.name || '@'} (${err.message})`);
            }
        }
        let msg = `批量修改记录值完成：成功 ${okCount} 条`;
        if (failed.length) msg += `，失败 ${failed.length} 条\n` + failed.slice(0, 5).join('\n');
        toast(msg, failed.length ? 'error' : 'success', 5000);
        loadRecords();
    });

    $('#rec-batch-del').addEventListener('click', async () => {
        const ids = selectedIds();
        if (!ids.length) return;
        const ok = await confirmDialog({
            title: '批量删除',
            message: `确定删除选中的 ${ids.length} 条解析记录吗？`,
            confirmText: '删除',
            danger: true,
        });
        if (!ok) return;
        const btn = $('#rec-batch-del');
        btn.disabled = true;
        let okCount = 0;
        const failed = [];
        for (const id of ids) {
            const rec = recordsById[id] || {};
            try {
                await recApi('/' + encodeURIComponent(id), { method: 'DELETE' });
                okCount++;
            } catch (err) {
                failed.push(`${rec.name || '@'} (${err.message})`);
            }
        }
        let msg = `批量删除完成：成功 ${okCount} 条`;
        if (failed.length) msg += `，失败 ${failed.length} 条\n` + failed.slice(0, 5).join('\n');
        toast(msg, failed.length ? 'error' : 'success', 5000);
        loadRecords();
    });

    /* 解析记录访问状态展示 */
    function recAccessHtml(r) {
        const a = r.access;
        if (!a || !a.status) return '<span class="muted">—</span>';
        if (a.status === 'na') return '<span class="muted">—</span>';
        if (a.status === 'ok') {
            return `<span class="badge b-green">ok</span> <span class="muted">${escapeHtml(String(a.ms))}ms</span>`;
        }
        const code = a.http_code ? escapeHtml(String(a.http_code)) : '不通';
        const err = a.error ? `<span class="detail muted" title="${escapeHtml(a.target || '')} ${escapeHtml(a.error)}">${escapeHtml((a.error || '').slice(0, 30))}</span>` : '';
        return `<span class="badge b-red">✗ ${code}</span>${err}`;
    }

    function showForm(record) {
        editingId = record ? record.id : null;
        $('#rec-form').classList.remove('hidden');
        $('#rec-add-btn').classList.add('hidden');
        if (record) {
            $('#rec-type').value = record.type;
            $('#rec-name').value = record.name || '@';
            $('#rec-value').value = record.value;
            $('#rec-ttl').value = record.ttl || 600;
            $('#rec-priority').value = record.priority || 0;
        } else {
            $('#rec-type').value = 'A';
            $('#rec-name').value = '@';
            $('#rec-value').value = '';
            $('#rec-ttl').value = 600;
            $('#rec-priority').value = 0;
        }
        $('#rec-value').focus();
    }

    function hideForm() {
        editingId = null;
        $('#rec-form').classList.add('hidden');
        $('#rec-add-btn').classList.remove('hidden');
    }

    $('#rec-refresh-btn').addEventListener('click', async (event) => { const button = event.currentTarget; button.disabled = true; try { await loadRecords(true); } finally { button.disabled = false; } });
    $('#rec-add-btn').addEventListener('click', () => showForm(null));
    $('#rec-form .btn-cancel').addEventListener('click', hideForm);

    $('#rec-access-btn').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        btn.disabled = true;
        btn.textContent = '检测中…';
        try {
            const res = await recApi('/access-check', { method: 'POST' });
            const na = res.na ? `，不适用 ${res.na}` : '';
            toast(`解析记录检测完成：共 ${res.total} 条，正常 ${Math.max(0, res.total - res.fail - (res.na || 0))} 个，异常 ${res.fail} 个${na}`, res.fail > 0 ? 'error' : 'success');
            loadRecords();
        } catch (err) {
            toast('检测失败：' + err.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = '🛰 检测访问';
        }
    });

    $('#rec-save').addEventListener('click', async () => {
        const payload = {
            type: $('#rec-type').value,
            name: $('#rec-name').value.trim() || '@',
            value: $('#rec-value').value.trim(),
            ttl: parseInt($('#rec-ttl').value, 10) || 600,
            priority: parseInt($('#rec-priority').value, 10) || 0,
        };
        if (!payload.value) {
            toast('记录值不能为空', 'error');
            return;
        }
        const btn = $('#rec-save');
        btn.disabled = true;
        try {
            if (editingId) {
                await recApi('/' + encodeURIComponent(editingId), { method: 'PUT', body: JSON.stringify(payload) });
                toast(`记录 ${payload.name || '@'} 已更新`);
            } else {
                await recApi('', { method: 'POST', body: JSON.stringify(payload) });
                toast(`记录 ${payload.name || '@'} 已添加`);
            }
            hideForm();
            loadRecords();
        } catch (err) {
            toast((editingId ? '修改' : '添加') + '失败：' + err.message, 'error');
        } finally {
            btn.disabled = false;
        }
    });

    $('#rec-table tbody').addEventListener('click', async (e) => {
        const editBtn = e.target.closest('.rec-edit');
        if (editBtn) {
            const rec = recordsById[editBtn.dataset.id];
            if (rec) {
                showForm({
                    id: rec.id,
                    type: rec.type,
                    name: rec.name || '@',
                    value: rec.value,
                    ttl: rec.ttl,
                    priority: rec.priority || 0,
                });
            }
            return;
        }
        const delBtn = e.target.closest('.rec-del');
        if (delBtn) {
            const rec = recordsById[delBtn.dataset.id] || {};
            const ok = await confirmDialog({
                title: '删除解析记录',
                message: `确定删除记录「${rec.type || ''} ${rec.name || '@'} → ${rec.value || ''}」吗？`,
                confirmText: '删除',
                danger: true,
            });
            if (!ok) return;
            try {
                await recApi('/' + encodeURIComponent(delBtn.dataset.id), { method: 'DELETE' });
                toast('记录已删除');
                loadRecords();
            } catch (err) {
                toast('删除失败：' + err.message, 'error');
            }
        }
    });

    loadRecords();
}
