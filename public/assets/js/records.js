'use strict';

/* ---------- DNS 解析记录管理 ---------- */
const RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

function openRecordsModal(domain, provider) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
        <div class="modal modal-wide">
            <div class="modal-head">
                <h3>解析记录 · ${escapeHtml(domain)} <span class="muted">（${escapeHtml(providerInfo(provider).name)}）</span> <span id="rec-count" class="muted" role="status" aria-live="polite"></span></h3>
                <button class="modal-close">×</button>
            </div>
            <div class="modal-body">
                <div id="rec-error" class="error-text hidden"></div>
                <div id="rec-loading" class="muted">加载中…</div>
                <div id="rec-form" class="rec-form hidden">
                    <div class="rec-form-grid">
                        <select id="rec-type">${RECORD_TYPES.map((t) => `<option>${t}</option>`).join('')}</select>
                        <input type="text" id="rec-name" placeholder="主机记录（@ 或 www）" value="@">
                        <input type="text" id="rec-value" placeholder="记录值，如 192.0.2.1">
                        <input type="number" id="rec-ttl" placeholder="TTL" value="600">
                        <input type="number" id="rec-priority" placeholder="优先级(MX)" value="0">
                        <div class="rec-form-actions">
                            <button class="btn btn-sm btn-cancel">取消</button>
                            <button class="btn btn-sm btn-primary" id="rec-save">预览变更</button>
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

    const $ = selector => overlay.querySelector(selector);
    const $$ = selector => overlay.querySelectorAll(selector);
    const endpoint = `/api/domains/${encodeURIComponent(provider)}/${encodeURIComponent(domain)}/records`;
    const recApi = (path, opts) => api(endpoint + path, opts);
    let busy = false, zoneRevision = '', editingRecord = null, loadGeneration = 0;
    const target = `${domain} · ${providerInfo(provider).name}`;
    const plan = (action, record, params) => window.WorkspaceDns.prepare({endpoint: endpoint + (record ? '/' + encodeURIComponent(record.id) : ''), action, record, params, revision: zoneRevision, owner: overlay, target});
    function setBusy(value) {
        busy = value;
        $$('button:not(.modal-close), input[type=checkbox]').forEach(control => { control.disabled = value; });
        if (!value) updateBatchUI();
    }
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
        const request = ++loadGeneration;
        const tbody = $('#rec-table tbody');
        tbody.innerHTML = '<tr><td colspan="8" class="muted">加载中…</td></tr>';
        try {
            const data = await recApi(refresh ? '?refresh=1' : '');
            if (request !== loadGeneration || !overlay.isConnected) return;
            if (!data.ok) throw new Error(data.error || '加载失败');
            zoneRevision = data.revision;
            $('#rec-loading').classList.add('hidden');
            $('#rec-empty').classList.toggle('hidden', data.records.length !== 0);
            $('#rec-count').textContent = `共 ${data.records.length} 条 · 快照 ${data.snapshot_at || '未知'}`;
            recordsById = Object.create(null);
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
            if (request !== loadGeneration || !overlay.isConnected) return;
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
        $('#rec-batch-ttl').disabled = busy || count === 0;
        $('#rec-batch-value').disabled = busy || count === 0;
        $('#rec-batch-del').disabled = busy || count === 0;
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

    async function runBatch(plans, caption) {
        if (busy || !plans.length) return;
        setBusy(true);
        try {
            if (!await window.WorkspaceDns.confirm(plans)) return;
            let verified = 0, unconfirmed = 0, attempted = 0, failure = '';
            for (const change of plans) {
                if (!overlay.isConnected) break;
                attempted++;
                $('#rec-count').textContent = `正在提交 ${attempted} / ${plans.length} · 已核实 ${verified} 条`;
                try {
                    const result = await window.WorkspaceDns.write(change);
                    if (result.verified) verified++;
                    else { unconfirmed++; failure = result.message; break; }
                } catch (error) { failure = error.message; break; }
            }
            toast(`${caption}：已核实 ${verified} 条，待核实 ${unconfirmed} 条，未执行 ${plans.length - attempted} 条${failure ? '；已停止后续提交：' + failure : ''}`, failure ? 'error' : 'success', 9000);
            await loadRecords();
        } finally { setBusy(false); }
    }

    $('#rec-batch-ttl').addEventListener('click', async () => {
        if (busy) return;
        const records = selectedIds().map(id => recordsById[id]).filter(Boolean);
        if (!records.length) return;
        const input = await promptDialog({title: '批量修改 TTL', message: `修改 ${records.length} 条记录的 TTL（秒），下一步预览差异：`, value: '600', okText: '预览差异'});
        if (input === null) return;
        const ttl = Number(input);
        if (!Number.isInteger(ttl) || ttl < 1 || ttl > 86400) return toast('TTL 需为 1–86400 的整数', 'error');
        try { await runBatch(records.map(record => plan('update', record, {ttl})), '批量修改 TTL'); }
        catch (error) { toast(error.message, 'error'); }
    });
    $('#rec-batch-value').addEventListener('click', async () => {
        if (busy) return;
        const records = selectedIds().map(id => recordsById[id]).filter(Boolean);
        if (!records.length) return;
        const input = await promptDialog({title: '批量修改记录值', message: `修改 ${records.length} 条记录，下一步预览差异：`, value: '', okText: '预览差异'});
        if (input === null) return;
        if (!input.trim()) return toast('记录值不能为空', 'error');
        try { await runBatch(records.map(record => plan('update', record, {value: input.trim()})), '批量修改记录值'); }
        catch (error) { toast(error.message, 'error'); }
    });
    $('#rec-batch-del').addEventListener('click', async () => {
        if (busy) return;
        try { await runBatch(selectedIds().map(id => recordsById[id]).filter(Boolean).map(record => plan('delete', record, null)), '批量删除'); }
        catch (error) { toast(error.message, 'error'); }
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
        if (busy) return;
        editingRecord = record ? {...record} : null;
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
        editingRecord = null;
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
        if (busy) return;
        const payload = {type: $('#rec-type').value, name: $('#rec-name').value.trim() || '@', value: $('#rec-value').value.trim(),
            ttl: Number($('#rec-ttl').value), priority: Number($('#rec-priority').value)};
        if (!payload.value || !Number.isInteger(payload.ttl) || payload.ttl < 1 || !Number.isInteger(payload.priority) || payload.priority < 0) return toast('请填写有效记录值、TTL 和优先级整数', 'error');
        setBusy(true);
        try {
            const change = plan(editingRecord ? 'update' : 'add', editingRecord, payload);
            if (!await window.WorkspaceDns.confirm([change])) return;
            const result = await window.WorkspaceDns.write(change);
            window.WorkspaceDns.notify(result);
            hideForm();
            await loadRecords();
        } catch (error) { toast('变更未完成：' + error.message, 'error', 7000); }
        finally { setBusy(false); }
    });

    $('#rec-table tbody').addEventListener('click', async (e) => {
        if (busy) return;
        const editBtn = e.target.closest('.rec-edit');
        if (editBtn) { const record = recordsById[editBtn.dataset.id]; if (record) showForm(record); return; }
        const deleteBtn = e.target.closest('.rec-del');
        if (!deleteBtn) return;
        const record = recordsById[deleteBtn.dataset.id];
        if (!record) return;
        setBusy(true);
        try {
            const result = await window.WorkspaceDns.perform({endpoint: endpoint + '/' + encodeURIComponent(record.id), action: 'delete', record, owner: overlay, target});
            if (result) { window.WorkspaceDns.notify(result); await loadRecords(); }
        } catch (error) { toast('删除未完成：' + error.message, 'error', 7000); }
        finally { setBusy(false); }
    });

    loadRecords();
}
