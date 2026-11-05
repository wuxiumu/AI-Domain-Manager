'use strict';

/* ---------- 域名列表 ---------- */
let domainsTimer = null;
let domainLoadRevision = 0;
['f-provider', 'f-valid', 'f-access', 'f-sort', 'f-expiring'].forEach((id) => {
    $('#' + id)?.addEventListener('change', () => {
        state.expiring = $('#f-expiring')?.value || '';
        loadDomains();
    });
});
$('#f-q').addEventListener('input', () => {
    clearTimeout(domainsTimer);
    domainsTimer = setTimeout(loadDomains, 300);
});

async function loadDomains() {
    const revision = ++domainLoadRevision;
    renderDomainFilterTabs();
    const params = new URLSearchParams();
    const provider = $('#f-provider').value;
    const valid = $('#f-valid').value;
    const access = $('#f-access').value;
    const sort = $('#f-sort').value;
    const q = $('#f-q').value.trim();
    if (provider) params.set('provider', provider);
    if (valid !== '') params.set('valid', valid);
    if (access !== '') params.set('access', access);
    if (sort) params.set('sort', sort);
    if (q) params.set('q', q);
    if (state.expiring) params.set('expiring', state.expiring);

    const tbody = $('#domains-table tbody');
    tbody.innerHTML = '<tr><td colspan="8" class="muted">加载中…</td></tr>';
    try {
        const allDomains = await api('/api/domains?all=1');
        if (revision !== domainLoadRevision) return;
        const data = window.WorkspaceData.filterDomains(allDomains, params);
        domainListData=allDomains.list;visibleDomainRows=window.WorkspaceData.groupDomains(data.list);
        const eligible=new Set(window.WorkspaceData.groupDomains(domainListData.filter(d=>d.valid!==false)).map(domainRowKey));
        for(const key of selectedDomainRows.keys())if(!eligible.has(key))selectedDomainRows.delete(key);
        renderDomainFilterTabs();renderDomainSelection();
        $('#domains-sub').textContent = `共 ${visibleDomainRows.length} 个域名${state.expiring ? '（到期过滤已开启）' : ''}${access ? '（健康状态筛选）' : ''}`;
        $('#domains-empty').classList.toggle('hidden', visibleDomainRows.length !== 0);

        if (!visibleDomainRows.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="muted">无匹配域名</td></tr>';
            return;
        }
        cacheDomainsForModal(data.list);
        tbody.innerHTML = visibleDomainRows.map((group) => {
            const accountAvailable = d => d.provider !== 'manual' && (!state.accountsLoaded || state.accounts.some(a => a.id === d.provider && a.enabled !== false));
            const sources = [...group.sources].sort((a, b) => Number(accountAvailable(b)) - Number(accountAvailable(a)));
            const invalid = sources.every(d => d.valid === false);
            const sourceLabel = d => escapeHtml(providerInfo(d.provider).name + (d.provider !== 'manual' && !accountAvailable(d) ? '（账号不可用）' : ''));
            const perSource = render => sources.map(d => `<div class="domain-source-item">${sources.length > 1 ? `<small class="hc-subline">${sourceLabel(d)}</small>` : ''}${render(d)}</div>`).join('');
            return `<tr class="${invalid ? 'row-invalid' : ''}">
                <td><input type="checkbox" data-domain-select="${escapeHtml(domainRowKey(group))}" aria-label="选择域名 ${escapeHtml(group.domain)}" ${selectedDomainRows.has(domainRowKey(group))?'checked':''} ${invalid?'disabled title="失效域名不参与关系图"':''}></td>
                <td class="domain-name"><button type="button" class="hc-asset-link act-detail" data-domain="${escapeHtml(group.domain)}" data-provider="${escapeHtml(sources[0]?.provider || '')}" title="打开域名操作中枢：解析、检查、绑定与变更记录">${escapeHtml(group.domain)}</button>${sources.length > 1 ? `<small class="hc-subline">${sources.length} 个账号</small>` : ''}${invalid ? ' <span class="badge b-gray">已失效</span>' : ''}</td>
                <td>${sources.map(d => { const info = providerInfo(d.provider); return `<div class="domain-source-item"><span class="badge ${info.cls}">${escapeHtml(info.name)}</span>${d.provider !== 'manual' && !accountAvailable(d) ? `<small class="hc-subline">账号已移除或停用 <button type="button" class="btn btn-link btn-sm act-cleanup" data-goto-tab="settings" data-goto-panel="cleanup">清理</button></small>` : ''}</div>`; }).join('')}</td>
                <td>${perSource(d => `<span class="domain-registration-status" title="${escapeHtml(d.status || '—')}">${escapeHtml(d.status || '—')}${d.valid === false ? '（已失效）' : ''}</span>`)}</td>
                <td>${perSource(d => `${escapeHtml(d.expire_at || '—')}${d.expire_at ? '<br>' + expireBadge(d.expire_at) : ''}`)}</td>
                <td>${perSource(d => accessHtml(d.health || d.access))}</td>
                <td class="note-cell">${perSource(d => `<span title="${escapeHtml(d.note || '')}">${d.note ? escapeHtml(d.note) : '<span class="muted">—</span>'}</span>`)}</td>
                <td class="row-actions">${perSource(d => `<div><button class="btn btn-link btn-sm act-relation" data-domain="${escapeHtml(d.domain)}" data-provider="${escapeHtml(d.provider)}">关联</button><button class="btn btn-link btn-sm act-records" data-domain="${escapeHtml(d.domain)}" data-provider="${escapeHtml(d.provider)}" ${!accountAvailable(d) ? `disabled title="${d.provider === 'manual' ? '手工域名没有 DNS 托管账号' : '账号已移除或停用，请使用其他可用账号'}"` : ''}>解析</button><button class="btn btn-link btn-sm act-edit" data-domain="${escapeHtml(d.domain)}" data-provider="${escapeHtml(d.provider)}">编辑</button><button class="btn btn-link-danger btn-sm act-del" data-domain="${escapeHtml(d.domain)}" data-provider="${escapeHtml(d.provider)}">删除</button></div>`)}</td>
            </tr>`;
        }).join('');
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="8" class="fail">加载失败: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function accessHtml(a) {
    if (!a || !a.status) return '<span class="muted">未检查</span>';
    const healthLabels = {healthy:'正常',unhealthy:'检查异常',stale:'结果过时',unchecked:'未检查'};
    if (healthLabels[a.status]) return `<span class="hc-status" data-status="${escapeHtml(a.status)}" title="${escapeHtml(a.message || '')}"><i aria-hidden="true"></i>${healthLabels[a.status]}</span><small class="hc-subline">${escapeHtml(a.checked_at || '')}</small>`;
    const checked = Date.parse(String(a.checked_at || '').replace(' ', 'T') + '+08:00');
    if (!checked || Date.now() - checked > 86400000) {
        return `<span class="badge b-amber">结果过时</span><span class="detail muted">${escapeHtml(a.checked_at || '时间未知')}</span>`;
    }
    if (a.status === 'ok') {
        return `<span class="badge b-green">ok</span> <span class="muted">${escapeHtml(String(a.ms))}ms</span>`;
    }
    const code = a.http_code || '×';
    const err = a.error ? ` (${escapeHtml(a.error)})` : '';
    return `<span class="badge b-red">✗ ${escapeHtml(String(code))}</span><span class="detail muted">${err}</span>`;
}

/* ---------- 访问检测 ---------- */
async function loadAccessSummary() {
    const el = $('#access-summary');
    try {
        const data = await api('/api/access-check');
        const failLink = data.fail > 0
            ? `<a class="rec-link" id="access-fail-link" href="javascript:void(0)">异常 ${data.fail} 个</a>`
            : '异常 0 个';
        el.innerHTML = `历史 HTTP 检测：共 ${data.total ?? 0} 项 / ${failLink} · ${escapeHtml(data.checked_at)} · 当前健康请查看看板`;
        $('#access-fail-link')?.addEventListener('click', () => {
            $('#f-access').value = 'unhealthy';
            loadDomains();
        });
    } catch (err) {
        // 首次使用时接口以 ok:false 表示「尚未检测过」（HTTP 200），与访问检测弹窗口径一致。
        el.innerHTML = err?.status === 200
            ? '尚未执行访问检测，点击"🛰 访问检测"探测全部域名'
            : '<span class="muted">访问检测记录加载失败，请稍后重试。</span>';
    }
}

/* ---------- 访问检测弹窗：一键探测全部域名能否访问，展示并留存状态与时间 ---------- */
const accessModal = { results: [], filter: 'all', running: false };

function accessRowHtml(r) {
    const info = providerInfo(r.provider);
    const ok = r.status === 'ok';
    const status = ok
        ? `<span class="badge b-green">ok${r.http_code ? ' ' + escapeHtml(String(r.http_code)) : ''}</span>`
        : `<span class="badge b-red">✗ ${escapeHtml(String(r.http_code || '失败'))}</span>`;
    const detail = ok ? `${escapeHtml(String(r.ms))} ms` : `<span class="detail">${escapeHtml(r.error || '无法访问')}</span>`;
    return `<tr class="${ok ? '' : 'row-invalid'}">
        <td class="domain-name">${escapeHtml(r.domain)}</td>
        <td><span class="badge ${info.cls}">${escapeHtml(info.name)}</span></td>
        <td>${status}<small class="hc-subline">${detail}</small></td>
        <td class="hc-time">${escapeHtml(r.ms != null ? r.ms + ' ms' : '—')}</td>
        <td class="hc-time">${escapeHtml(r.checked_at || '—')}</td>
    </tr>`;
}

function renderAccessModal() {
    const rows = accessModal.results.filter(r => accessModal.filter === 'all' || r.status === accessModal.filter);
    $('#access-modal-table tbody').innerHTML = rows.length
        ? rows.map(accessRowHtml).join('')
        : '<tr><td colspan="5" class="muted">没有符合筛选的结果</td></tr>';
    document.querySelectorAll('[data-access-filter]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.accessFilter === accessModal.filter)));
}

async function openAccessCheckModal() {
    if ($('#access-check-overlay')) return;
    const overlay = document.createElement('div');
    overlay.id = 'access-check-overlay';
    overlay.className = 'modal-overlay';
    const returnFocus = document.activeElement;
    overlay.innerHTML = `
        <section class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="access-check-title">
            <div class="modal-head">
                <h3 id="access-check-title">域名访问检测 <span id="access-modal-meta" class="muted" role="status" aria-live="polite"></span></h3>
                <button class="modal-close" aria-label="关闭访问检测">×</button>
            </div>
            <div class="modal-body">
                <p id="access-modal-summary" class="muted">正在读取上次检测结果…</p>
                <div class="access-filters" role="group" aria-label="访问检测结果筛选">
                    <button type="button" class="domain-filter-tab" data-access-filter="all" aria-pressed="true">全部</button>
                    <button type="button" class="domain-filter-tab" data-access-filter="ok" aria-pressed="false">可访问</button>
                    <button type="button" class="domain-filter-tab" data-access-filter="fail" aria-pressed="false">异常</button>
                </div>
                <div class="table-wrap">
                    <table id="access-modal-table">
                        <thead><tr><th>域名</th><th>云商</th><th>访问状态</th><th>耗时</th><th>检查时间</th></tr></thead>
                        <tbody><tr><td colspan="5" class="muted">正在加载…</td></tr></tbody>
                    </table>
                </div>
                <p class="panel-caption"><span class="info-dot">i</span> 检测并发探测全部有效域名的 HTTP 访问（4 秒超时，结果与时间保存在服务器）；HTTPS 证书与健康检查请查看看板「检查主域名与 IP」。</p>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn" data-access-close>关闭</button>
                <button type="button" class="btn btn-primary" id="access-modal-run">一键检测</button>
            </div>
        </section>`;
    (document.fullscreenElement || document.body).appendChild(overlay);
    const close = () => { overlay.remove(); returnFocus?.focus(); };
    overlay.querySelector('.modal-close').onclick = close;
    overlay.querySelector('[data-access-close]').onclick = close;
    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
    overlay.addEventListener('keydown', e => { if (e.key === 'Escape') { e.preventDefault(); close(); } });
    overlay.querySelector('.modal-close')?.focus();

    const summary = $('#access-modal-summary');
    const meta = $('#access-modal-meta');
    const renderSummary = d => {
        meta.textContent = d.checked_at ? `上次检测 ${d.checked_at}` : '尚未检测过';
        summary.textContent = d.total != null && d.total > 0
            ? `共检测 ${d.total} 个域名：可访问 ${d.ok} · 异常 ${d.fail}。结果已保存，本页「健康状态」列与首页问题中心同源使用。`
            : '尚未执行过访问检测，点击「一键检测」探测全部域名能否访问。';
    };
    try {
        const data = await api('/api/access-check');
        accessModal.results = data.results || [];
        renderSummary(data);
        renderAccessModal();
    } catch (err) {
        // 首次使用时接口以 ok:false 表示「尚未检测过」，属正常状态而非错误。
        accessModal.results = [];
        if (err.status === 200) {
            meta.textContent = '尚未检测过';
            summary.textContent = '尚未执行过访问检测，点击「一键检测」探测全部域名能否访问。';
        } else {
            summary.textContent = '读取上次结果失败：' + err.message;
        }
        $('#access-modal-table tbody').innerHTML = '<tr><td colspan="5" class="muted">暂无结果</td></tr>';
    }

    overlay.addEventListener('click', e => {
        const tab = e.target.closest('[data-access-filter]');
        if (tab) { accessModal.filter = tab.dataset.accessFilter; renderAccessModal(); }
    });
    $('#access-modal-run').addEventListener('click', async () => {
        if (accessModal.running) return;
        accessModal.running = true;
        const btn = $('#access-modal-run');
        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = '检测中…';
        summary.textContent = '正在并发探测全部域名（4 秒超时），请稍候…';
        try {
            const data = await api('/api/access-check', { method: 'POST' });
            accessModal.results = data.results || [];
            renderSummary(data);
            renderAccessModal();
            toast(`访问检测完成：共 ${data.total ?? 0} 项，异常 ${data.fail ?? 0} 项，结果与时间已保存`);
            loadAccessSummary();
        } catch (err) {
            summary.textContent = '检测失败：' + err.message;
            toast('访问检测失败：' + err.message, 'error');
        } finally {
            accessModal.running = false;
            btn.disabled = false;
            btn.textContent = original;
        }
    });
}

$('#access-check-btn').addEventListener('click', openAccessCheckModal);

/* 操作列事件（事件委托） */
$('#domains-table tbody').addEventListener('click', async (e) => {
    const nameBtn = e.target.closest('.act-detail');
    if (nameBtn) { window.HealthWorkbench?.openDomain(nameBtn.dataset.provider, nameBtn.dataset.domain); return; }
    const relBtn = e.target.closest('.act-relation');
    if (relBtn) { window.Relationships?.openDomain(relBtn.dataset.provider, relBtn.dataset.domain); return; }
    const recBtn = e.target.closest('.act-records');
    if (recBtn) {
        openRecordsModal(recBtn.dataset.domain, recBtn.dataset.provider);
        return;
    }
    const editBtn = e.target.closest('.act-edit');
    if (editBtn) {
        openDomainModal('edit', {
            domain: editBtn.dataset.domain,
            provider: editBtn.dataset.provider,
        });
        return;
    }
    const delBtn = e.target.closest('.act-del');
    if (delBtn) {
        const domain = delBtn.dataset.domain;
        const ok = await confirmDialog({
            title: '删除域名',
            message: `确定删除「${providerInfo(delBtn.dataset.provider).name}」下的域名「${domain}」吗？\n（仅删除该账号的本地记录，不影响云商侧域名）`,
            confirmText: '删除',
            danger: true,
        });
        if (!ok) return;
        try {
            await api('/api/domains', {
                method: 'DELETE',
                body: JSON.stringify({ domain, provider: delBtn.dataset.provider }),
            });
            toast(`已删除域名「${domain}」`);
            loadDomains();
        } catch (err) {
            toast('删除失败：' + err.message, 'error');
        }
    }
});

$('#domain-add-btn').addEventListener('click', () => openDomainModal('create'));

/* ---------- 域名新增 / 编辑模态框 ---------- */
function openDomainModal(mode, target) {
    const isEdit = mode === 'edit';
    const missingProvider = isEdit && !state.accounts.some(a => a.id === target.provider) && target.provider !== 'manual' ? `<option value="${escapeHtml(target.provider)}">${escapeHtml(providerInfo(target.provider).name)}</option>` : '';
    const providerOptions = missingProvider + '<option value="manual">手动添加</option>'
        + state.accounts.map((a) => `<option value="${escapeHtml(a.id)}">${escapeHtml(a.name)}</option>`).join('');

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
        <div class="modal">
            <div class="modal-head">
                <h3>${isEdit ? '编辑域名' : '新增域名'}</h3>
                <button class="modal-close">×</button>
            </div>
            <div class="modal-body">
                <div class="form-grid">
                    <label>域名 *
                        <input type="text" id="dm-domain" ${isEdit ? 'readonly' : ''} placeholder="example.com" required>
                    </label>
                    <label>服务商
                        <select id="dm-provider" ${isEdit ? 'disabled' : ''}>${providerOptions}</select>
                    </label>
                    <label>状态
                        <input type="text" id="dm-status" placeholder="如：正常">
                    </label>
                    <label>到期时间
                        <input type="date" id="dm-expire">
                    </label>
                    <label>注册日期
                        <input type="date" id="dm-regdate">
                    </label>
                    <label>注册商
                        <input type="text" id="dm-registrar" placeholder="如：阿里云">
                    </label>
                    <label class="span-2">备注
                        <textarea id="dm-note" rows="2" placeholder="域名用途、关联项目等"></textarea>
                    </label>
                    ${isEdit ? '<label class="span-2 check-line"><input type="checkbox" id="dm-valid" checked> 该域名有效</label>' : ''}
                </div>
            </div>
            <div class="modal-foot">
                <p id="dm-error" class="error-text hidden"></p>
                <button class="btn btn-cancel">取消</button>
                <button class="btn btn-primary" id="dm-save">${isEdit ? '保存' : '添加'}</button>
            </div>
        </div>`;
    (document.fullscreenElement || document.body).appendChild(overlay);
    const returnFocus = document.activeElement;
    const closeModal = () => { returnFocus?.focus?.(); overlay.remove(); };

    if (isEdit) {
        $('#dm-domain').value = target.domain;
        $('#dm-provider').value = target.provider;
        const store = modalContextStore[target.provider + '|' + target.domain];
        if (store) {
            $('#dm-status').value = store.status || '';
            $('#dm-expire').value = store.expire_at || '';
            $('#dm-regdate').value = store.registration_date || '';
            $('#dm-registrar').value = store.registrar || '';
            $('#dm-note').value = store.note || '';
            $('#dm-valid').checked = store.valid !== false;
        }
    }

    overlay.querySelector('.modal-close').addEventListener('click', closeModal);
    overlay.querySelector('.btn-cancel').addEventListener('click', closeModal);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); });
    overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.preventDefault(); closeModal(); } });

    $('#dm-save').addEventListener('click', async () => {
        const payload = {
            domain: $('#dm-domain').value.trim(),
            provider: isEdit ? target.provider : $('#dm-provider').value,
            status: $('#dm-status').value.trim(),
            expire_at: $('#dm-expire').value,
            registration_date: $('#dm-regdate').value,
            registrar: $('#dm-registrar').value.trim(),
            note: $('#dm-note').value.trim(),
        };
        if (isEdit) {
            payload.valid = $('#dm-valid').checked;
        }
        if (!payload.domain) {
            showModalError('域名不能为空');
            return;
        }
        const btn = $('#dm-save');
        btn.disabled = true;
        try {
            await api('/api/domains', {
                method: isEdit ? 'PUT' : 'POST',
                body: JSON.stringify(payload),
            });
            toast(isEdit ? `已保存域名「${payload.domain}」` : `已添加域名「${payload.domain}」`);
            closeModal();
            loadDomains();
        } catch (err) {
            showModalError(err.message);
            btn.disabled = false;
        }
    });

    function showModalError(msg) {
        const el = $('#dm-error');
        el.textContent = msg;
        el.classList.remove('hidden');
    }
}

/* 编辑时用到的最近一次列表数据缓存（避免额外请求） */
let modalContextStore = {};
function cacheDomainsForModal(list) {
    modalContextStore = {};
    list.forEach((d) => { modalContextStore[d.provider + '|' + d.domain] = d; });
}

/* Domain click-tabs and bulk graph navigation; data stays in the shared cache. */
const selectedDomainRows=new Map();
let domainListData=[],visibleDomainRows=[];
function domainRowKey(d){return String(d.domain || '').trim().replace(/\.$/, '').toLowerCase();}
function renderDomainFilterTabs(){
    const providerMap=new Map(domainListData.map(d=>[d.provider,{id:d.provider,name:d.provider_name||d.provider}]));
    state.accounts.forEach(a=>providerMap.set(a.id,a));
    const options={
        'f-provider':[['','全部账号','neutral'],...[...providerMap.values()].map(a=>[a.id,a.name,a.provider|| (a.id.startsWith('aliyun')?'aliyun':a.id.startsWith('tencent')?'tencent':a.id.startsWith('godaddy')?'godaddy':'neutral')])],
        'f-valid':[['','全部','neutral'],['1','有效','healthy'],['0','失效','unhealthy']],
        'f-expiring':[['','全部','neutral'],['7','7 天内','unhealthy'],['15','15 天内','stale'],['30','30 天内','tencent'],['expired','已过期','unhealthy']],
        'f-access':[['','全部','neutral'],['healthy','正常','healthy'],['unhealthy','检查异常','unhealthy'],['stale','结果过时','stale'],['unchecked','未检查','neutral']],
        'f-sort':[['expire','到期时间 ↑','neutral'],['expire_desc','到期时间 ↓','neutral'],['domain','域名 A–Z','neutral'],['synced','最近同步','neutral']]
    };
    for(const [id,items] of Object.entries(options)){
        const el=document.querySelector(`[data-filter-tabs="${id}"]`);if(!el)continue;
        el.innerHTML=items.map(([value,label,color])=>`<button type="button" class="domain-filter-tab" data-domain-filter="${id}" data-filter-value="${escapeHtml(value)}" data-color="${escapeHtml(color)}" aria-pressed="${$('#'+id).value===value}">${escapeHtml(label)}</button>`).join('');
    }
}
function renderDomainSelection(){
    $('#domain-selected-count').closest('.health-selection-bar').classList.toggle('hidden', selectedDomainRows.size === 0);
    $('#domain-selected-count').textContent=selectedDomainRows.size?`已选择 ${selectedDomainRows.size} 个域名（筛选切换时保留）`:'未选择域名';
    $('#domains-to-relations').disabled=selectedDomainRows.size===0;
    $('#domains-batch-ip').disabled=selectedDomainRows.size===0;
    const rows=visibleDomainRows.filter(d=>d.sources.some(source=>source.valid!==false)),n=rows.filter(d=>selectedDomainRows.has(domainRowKey(d))).length;
    $('#domain-select-all').checked=rows.length>0&&n===rows.length;
    $('#domain-select-all').indeterminate=n>0&&n<rows.length;$('#domain-select-all').disabled=!rows.length;
}
document.addEventListener('click',event=>{
    const tab=event.target.closest('[data-domain-filter]');if(!tab)return;
    const control=$('#'+tab.dataset.domainFilter);control.value=control.value===tab.dataset.filterValue && tab.dataset.domainFilter!=='f-sort'?'':tab.dataset.filterValue;control.dispatchEvent(new Event('change',{bubbles:true}));
});
document.addEventListener('change',event=>{
    const check=event.target.closest('[data-domain-select]');if(!check)return;
    const key=check.dataset.domainSelect,row=visibleDomainRows.find(d=>domainRowKey(d)===key);
    if(check.checked&&row)selectedDomainRows.set(key,row);else selectedDomainRows.delete(key);renderDomainSelection();
});
/* 全选/清空只改本地勾选态与已渲染的复选框，不重新请求列表。 */
$('#domain-select-all').onchange=event=>{
    const eligible=visibleDomainRows.filter(d=>d.sources.some(source=>source.valid!==false));
    const keys=new Set(eligible.map(domainRowKey));
    eligible.forEach(d=>event.target.checked?selectedDomainRows.set(domainRowKey(d),d):selectedDomainRows.delete(domainRowKey(d)));
    document.querySelectorAll('input[data-domain-select]').forEach(cb=>{if(keys.has(cb.dataset.domainSelect))cb.checked=event.target.checked;});
    renderDomainSelection();
};
$('#domain-selection-clear').onclick=()=>{selectedDomainRows.clear();document.querySelectorAll('input[data-domain-select]').forEach(cb=>{cb.checked=false;});renderDomainSelection();};
$('#domains-batch-ip').onclick=async event=>{
    const button=event.currentTarget;button.disabled=true;
    try{
        const groups=[...selectedDomainRows.values()];
        const dnsManaged=d=>d.provider!=='manual'&&(!state.accountsLoaded||state.accounts.some(a=>a.id===d.provider&&a.enabled!==false));
        // 同一域名多账号时取一个可用托管账号；手工或账号不可用的域名进入跳过清单。
        const candidates=[],skipped=[];
        for(const group of groups){
            const best=(group.sources||[]).find(d=>d.valid!==false&&dnsManaged(d));
            if(best)candidates.push(best);
            else skipped.push({target:group.domain,reason:'手工域名或 DNS 托管账号不可用，无法批量修改'});
        }
        if(!candidates.length){toast('所选域名均没有可用的 DNS 托管账号','error');return;}
        const ip=(await promptDialog({title:'批量修改解析 IP',
            message:`已选择 ${candidates.length} 个域名的可用 DNS 托管账号。将把每个域名根记录（@）的 A 记录改为新 IP；没有 A 记录的域名会跳过并在报告中说明。`,
            value:'',okText:'下一步'}));
        if(ip===null)return;
        const value=ip.trim();
        const isIpv4=/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/.test(value)&&value.split('.').every(n=>Number(n)>=0&&Number(n)<=255);
        const isIpv6=/^[0-9A-Fa-f:]{2,45}$/.test(value)&&value.includes(':');
        if(!isIpv4&&!isIpv6){toast('请输入有效的 IPv4 或 IPv6 地址','error');return;}
        // 逐域名读取缓存解析（含版本号），构造跨域名独立提交计划。
        const progress=document.createElement('div');progress.className='modal-overlay';progress.id='batch-ip-progress';
        progress.innerHTML='<section class="modal" role="status" aria-live="polite"><div class="modal-body"><p class="muted" id="batch-ip-progress-text">正在读取解析记录…</p></div></section>';
        (document.fullscreenElement||document.body).appendChild(progress);
        const progressText=$('#batch-ip-progress-text');
        const options=[];
        for(const d of candidates){
            const target=`${d.domain}（${escapeHtml(providerInfo(d.provider).name)}）`;
            try{
                const data=await api(`/api/domains/${encodeURIComponent(d.provider)}/${encodeURIComponent(d.domain)}/records`);
                const aRecords=(data.records||[]).filter(r=>(r.type||'').toUpperCase()==='A'&&['@','',d.domain.toLowerCase()].includes((r.name||'').toLowerCase()));
                if(!aRecords.length){skipped.push({target,reason:'根记录没有 A 记录，请先在解析页新增或走 AI 提案'});continue;}
                for(const record of aRecords){
                    options.push({endpoint:`/api/domains/${encodeURIComponent(d.provider)}/${encodeURIComponent(d.domain)}/records/${encodeURIComponent(record.id)}`,
                        action:'update',record,params:{value},target,owner:progress});
                }
            }catch(err){skipped.push({target,reason:'读取解析失败：'+err.message});}
        }
        if(!options.length){progress.remove();toast('所选域名中没有任何可修改的 @ A 记录','error');return;}
        progressText.textContent=`共 ${options.length} 条 A 记录待提交，请在确认弹窗中核对差异。`;
        const items=await window.WorkspaceDns.batchValues(options,(done,total)=>{progressText.textContent=`正在提交 ${done} / ${total} 条（逐条写入并回读核实，不自动重试）…`;});
        progress.remove();
        if(!items)return;
        if(skipped.length)items.push(...skipped.map(s=>({status:'未提交',option:{target:s.target},message:s.reason})));
        window.WorkspaceDns.showBatchReport(items,`批量修改解析 IP → ${value}`);
        loadDomains();
    }catch(error){toast(error.message,'error');}
    finally{renderDomainSelection();}
};
$('#domains-to-relations').onclick=async event=>{
    const button=event.currentTarget;button.disabled=true;
    try{const snapshot=await api('/api/health');const ids=snapshot.assets.filter(a=>a.kind==='domain'&&[...selectedDomainRows.values()].some(group=>group.sources.some(d=>d.valid!==false&&d.provider===a.provider&&domainRowKey(d)===a.name))).map(a=>a.id);
        if(ids.length)window.Relationships.showDomains(ids);else toast('没有可展示的有效域名，请更新列表','error');
    }catch(error){toast(error.message,'error');}finally{renderDomainSelection();}
};
