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
                <div id="rec-batch-form" class="rec-form hidden">
                    <label for="rec-batch-input">批量添加解析记录</label> <button type="button" class="btn btn-sm" id="rec-batch-example">查看并复制示例</button>
                    <p class="muted">每行一条，字段用 Tab 分隔：类型、主机记录、记录值、TTL（可选，默认 600）、优先级（可选，默认 0）。可从表格复制粘贴。</p>
                    <textarea id="rec-batch-input" rows="5" style="width:100%;box-sizing:border-box;resize:vertical" placeholder="A&#9;www&#9;192.0.2.1&#9;600&#10;CNAME&#9;blog&#9;example.com"></textarea>
                    <div class="rec-form-actions"><button class="btn btn-sm" id="rec-batch-cancel">取消</button><button class="btn btn-sm btn-primary" id="rec-batch-save">预览批量添加</button></div>
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
                <button class="btn" id="rec-refresh-btn">刷新云端记录</button><button class="btn" id="rec-add-btn">＋ 添加记录</button><button class="btn" id="rec-batch-add-btn">批量添加</button>
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
    const target = `${domain} · ${providerInfo(provider).name} (${provider})`;
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

    /* IP 记录值（A / AAAA）→ 点击查看 IP 服务器详情弹窗；其余值保持站点链接。所有值超出省略，悬停看全文。 */
    const isIpValue = (value) => {
        const v = String(value || '').trim();
        return /^(\d{1,3}\.){3}\d{1,3}$/.test(v) || (/^[0-9a-fA-F:.]+$/.test(v) && v.includes(':') && v.length >= 2);
    };
    const valueCell = (value) => {
        const v = String(value || '').trim();
        const inner = isIpValue(v)
            ? `<button type="button" class="rec-link rec-ip" data-rec-ip="${escapeHtml(v)}" title="查看 IP ${escapeHtml(v)} 的服务器详情与台账关联">${escapeHtml(v)}</button>`
            : linkHtml(v, valueLink(v));
        return `<span class="rec-value-text" title="${escapeHtml(v)}">${inner}</span>`;
    };

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
                    <td class="rec-value">${valueCell(r.value)}</td>
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
            $('#rec-empty').classList.add('hidden');
            $('#rec-count').textContent = '读取失败';
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
        setBusy(true);
        try {
            const options = records.map(record => ({endpoint: endpoint + '/' + encodeURIComponent(record.id), action:'update', record,
                params:{value:input.trim()}, owner:overlay, target}));
            const results = await window.WorkspaceDns.batchValues(options, (done, total) => { if (overlay.isConnected) $('#rec-count').textContent = `处理 ${done} / ${total} 条`; });
            if (!results) return;
            if (overlay.isConnected) await loadRecords();
            window.WorkspaceDns.showBatchReport(results, '批量修改 DNS 记录值');
        } catch (error) { toast(error.message, 'error'); }
        finally { if (overlay.isConnected) setBusy(false); }
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
        $('#rec-batch-form').classList.add('hidden');
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
    $('#rec-batch-add-btn').addEventListener('click', () => {
        if (busy) return;
        hideForm();
        $('#rec-batch-form').classList.remove('hidden');
        $('#rec-batch-input').focus();
    });
    $('#rec-batch-example').addEventListener('click', () => {
        const example = document.createElement('div');
        example.className = 'modal-overlay';
        example.innerHTML = `<section class="modal" role="dialog" aria-modal="true" aria-labelledby="rec-example-title"><div class="modal-head"><h3 id="rec-example-title">批量添加格式示例</h3><button class="modal-close" type="button" aria-label="关闭示例">×</button></div><div class="modal-body"><p>每行一条记录，字段之间使用 Tab 分隔。依次填写：类型、主机记录、记录值、TTL、优先级。后两项可省略，分别默认为 600 和 0。</p><p class="muted">以下域名和 IP 仅供参考，提交前请替换成自己的记录。</p><label for="rec-example-text">可复制的示例</label><textarea id="rec-example-text" readonly rows="5" style="display:block;width:100%;box-sizing:border-box;resize:vertical"></textarea><p id="rec-example-status" class="muted" role="status"></p></div><div class="modal-foot"><button class="btn" type="button" id="rec-example-close">关闭</button><button class="btn btn-primary" type="button" id="rec-example-copy">复制示例</button></div></section>`;
        (document.fullscreenElement || document.body).appendChild(example);
        const text = example.querySelector('#rec-example-text');
        text.value = 'A\twww\t192.0.2.1\t600\t0\nCNAME\tblog\texample.com\t600\t0\nMX\t@\tmail.example.com\t600\t10\nTXT\t@\tv=spf1 -all\t600\t0';
        const close = () => { example.remove(); $('#rec-batch-example').focus(); };
        example.querySelector('.modal-close').addEventListener('click', close);
        example.querySelector('#rec-example-close').addEventListener('click', close);
        example.addEventListener('click', event => { if (event.target === example) close(); });
        example.addEventListener('keydown', event => { if (event.key === 'Escape') { event.stopPropagation(); close(); } });
        example.querySelector('#rec-example-copy').addEventListener('click', async () => {
            if (await copyText(text.value, '示例已复制')) return;
            text.focus(); text.select(); example.querySelector('#rec-example-status').textContent = '请复制已选中的示例';
        });
        example.querySelector('#rec-example-copy').focus();
    });
    $('#rec-batch-cancel').addEventListener('click', () => $('#rec-batch-form').classList.add('hidden'));
    $('#rec-batch-save').addEventListener('click', async () => {
        if (busy) return;
        let plans;
        try {
            const entries = window.WorkspaceDns.parseBatchAdds($('#rec-batch-input').value);
            plans = entries.map(params => plan('add', null, params));
        } catch (error) { toast(error.message, 'error'); return; }
        setBusy(true);
        try {
            if (!await window.WorkspaceDns.confirm(plans)) return;
            const result = await window.WorkspaceDns.writeBatchAdds(plans, (done, total) => {
                if (overlay.isConnected) $('#rec-count').textContent = `正在提交 ${done} / ${total}`;
            });
            if (!overlay.isConnected) return;
            if (result.completed === plans.length) {
                $('#rec-batch-input').value = '';
                $('#rec-batch-form').classList.add('hidden');
            }
            toast(`批量添加：已核实 ${result.completed} 条，${result.message}`, result.completed === plans.length ? 'success' : 'error', 10000);
            await loadRecords();
        } finally { if (overlay.isConnected) setBusy(false); }
    });
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
        const ipButton = e.target.closest('.rec-ip');
        if (ipButton) { openIpRecordDetails(ipButton.dataset.recIp); return; }
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

    /* ---------- IP 记录值 → IP 服务器详情弹窗（数据来自健康台账，可跳转 IP 资产页） ---------- */
    const healthLabels = { healthy: '正常', unhealthy: 'HTTP 异常', stale: '结果过时', unchecked: '未检查' };
    const statusPill = (status) => `<span class="hc-status" data-status="${escapeHtml(status || 'unchecked')}"><i aria-hidden="true"></i>${escapeHtml(healthLabels[status] || status || '未检查')}</span>`;
    const locationText = (loc) => loc ? ([loc.country, loc.region, loc.city].filter(Boolean).join(' · ') || '未提供位置') : '归属地未查询';

    function ipFactsHtml(ip, asset) {
        const health = asset?.health || { status: 'unchecked', message: '' };
        const checked = asset ? (asset.health?.checked_at ? `<span class="muted">${escapeHtml(asset.health.checked_at)}</span>` : '<span class="muted">尚未检查</span>') : '';
        const latency = health.latency_ms != null ? `<div><dt>延迟</dt><dd>${escapeHtml(String(health.latency_ms))} ms${health.http_code != null ? ' · HTTP ' + escapeHtml(String(health.http_code)) : ''}</dd></div>` : '';
        const domains = asset ? (asset.linked_domains || asset.bound_domains || []) : [];
        const facts = `<dl class="hc-facts">
            <div><dt>健康状态</dt><dd>${asset ? statusPill(health.status) + ' ' + checked : '<span class="muted">未在 IP 资产登记，尚未纳入检查</span>'}</dd></div>
            ${latency}
            ${health.message ? `<div><dt>检查消息</dt><dd>${escapeHtml(health.message)}</dd></div>` : ''}
            ${asset?.location ? `<div><dt>归属地</dt><dd>${escapeHtml(locationText(asset.location))}<br><small class="muted">近似位置，缓存于服务器；可手动重新查询</small></dd></div>` : ''}
            <div><dt>IP 版本</dt><dd>${escapeHtml(asset?.ip_version || (ip.includes(':') ? 'IPv6' : 'IPv4'))}</dd></div>
            ${asset ? `<div><dt>来源</dt><dd>${escapeHtml(asset.source === 'manual' ? '手工登记' : 'DNS 解析发现')} · ${escapeHtml(asset.server_provider || '供应商未填写')}</dd></div>` : ''}
            ${asset?.note ? `<div><dt>备注</dt><dd>${escapeHtml(asset.note)}</dd></div>` : ''}
            ${asset ? `<div><dt>关联域名</dt><dd>${escapeHtml(String(asset.domain_count ?? domains.length))} 个主域名${domains.length ? '<br>' + escapeHtml(domains.slice(0, 6).join('、')) + (domains.length > 6 ? ' 等' : '') : ''}</dd></div>` : ''}
        </dl>`;
        return `${asset ? '' : '<p class="hc-browser-probe" data-reachable="0"><span class="info-dot" aria-hidden="true">i</span> 该 IP 还没有登记到 IP 资产台账，未纳入健康检查；可在 IP 资产页手工登记，或检查域名以自动发现。</p>'}${facts}`;
    }

    async function openIpRecordDetails(ip) {
        const ipOverlay = document.createElement('div');
        ipOverlay.className = 'modal-overlay rec-ip-overlay';
        ipOverlay.innerHTML = `<section class="modal rec-ip-modal" role="dialog" aria-modal="true" aria-label="IP 服务器详情">
            <div class="modal-head"><div><span class="hc-eyebrow">IP SERVER</span><h3>${escapeHtml(ip)}</h3></div><button class="modal-close" aria-label="关闭详情">×</button></div>
            <div class="modal-body"><p class="muted" data-rec-ip-loading>正在读取 IP 台账…</p><div data-rec-ip-body class="hidden"></div></div>
            <div class="modal-foot"><button class="btn" data-rec-ip-close>关闭</button><a class="btn" href="http://${escapeHtml(ip.includes(':') ? '[' + ip + ']' : ip)}/" target="_blank" rel="noopener" title="直接访问该 IP">打开站点</a><button class="btn btn-primary" data-rec-ip-goto>${'前往 IP 资产'}</button></div>
        </section>`;
        (document.fullscreenElement || document.body).appendChild(ipOverlay);
        const closeIpOverlay = () => ipOverlay.remove();
        ipOverlay.addEventListener('click', (e) => { if (e.target === ipOverlay || e.target.closest('[data-rec-ip-close],.modal-close')) closeIpOverlay(); });
        ipOverlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeIpOverlay(); });
        const body = ipOverlay.querySelector('[data-rec-ip-body]');
        try {
            await window.HealthWorkbench?.load();
            const asset = window.HealthWorkbench?.findIp ? window.HealthWorkbench.findIp(ip) : null;
            ipOverlay.querySelector('[data-rec-ip-loading]')?.remove();
            body.classList.remove('hidden');
            body.innerHTML = ipFactsHtml(ip, asset);
            ipOverlay.querySelector('[data-rec-ip-goto]').textContent = asset ? '在 IP 资产中查看' : '前往 IP 资产登记';
        } catch (err) {
            const loading = ipOverlay.querySelector('[data-rec-ip-loading]');
            if (loading) loading.textContent = '健康台账读取失败：' + err.message;
        }
        ipOverlay.querySelector('[data-rec-ip-goto]').addEventListener('click', () => {
            closeIpOverlay();
            overlay.remove();
            ['ip-version', 'ip-status'].forEach((id) => { const el = document.querySelector('#' + id); if (el) el.value = ''; });
            const query = document.querySelector('#ip-query');
            if (query) { query.value = ip; query.dispatchEvent(new Event('input', { bubbles: true })); }
            switchTab('ips');
        });
    }

    loadRecords();
}
