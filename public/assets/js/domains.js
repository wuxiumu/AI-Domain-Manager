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
        domainListData=allDomains.list;visibleDomainRows=data.list;
        const eligible=new Set(domainListData.filter(d=>d.valid!==false).map(domainRowKey));
        for(const key of selectedDomainRows.keys())if(!eligible.has(key))selectedDomainRows.delete(key);
        renderDomainFilterTabs();renderDomainSelection();
        $('#domains-sub').textContent = `共 ${data.total} 个域名${state.expiring ? '（到期过滤已开启）' : ''}${access ? '（健康状态筛选）' : ''}`;
        $('#domains-empty').classList.toggle('hidden', data.total !== 0);

        if (!data.list.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="muted">无匹配域名</td></tr>';
            return;
        }
        cacheDomainsForModal(data.list);
        tbody.innerHTML = data.list.map((d) => {
            const info = providerInfo(d.provider);
            const invalid = d.valid === false;
            return `
            <tr class="${invalid ? 'row-invalid' : ''}">
                <td><input type="checkbox" data-domain-select="${escapeHtml(domainRowKey(d))}" aria-label="选择域名 ${escapeHtml(d.domain)}" ${selectedDomainRows.has(domainRowKey(d))?'checked':''} ${invalid?'disabled title="失效域名不参与关系图"':''}></td><td class="domain-name">${escapeHtml(d.domain)}${invalid ? ' <span class="badge b-gray">已失效</span>' : ''}</td>
                <td><span class="badge ${info.cls}">${escapeHtml(info.name)}</span></td>
                <td><span class="domain-registration-status" title="${escapeHtml(d.status || '—')}">${escapeHtml(d.status || '—')}</span></td>
                <td>${escapeHtml(d.expire_at || '—')}${d.expire_at ? '<br>' + expireBadge(d.expire_at) : ''}</td>
                <td>${accessHtml(d.health || d.access)}</td>
                <td class="note-cell" title="${escapeHtml(d.note || '')}">${d.note ? escapeHtml(d.note) : '<span class="muted">—</span>'}</td>
                <td class="row-actions">
                    <button class="btn btn-link btn-sm act-relation" data-domain="${escapeHtml(d.domain)}" data-provider="${escapeHtml(d.provider)}">关联</button>
                    <button class="btn btn-link btn-sm act-records" data-domain="${escapeHtml(d.domain)}" data-provider="${escapeHtml(d.provider)}" title="管理 DNS 解析记录">解析</button>
                    <button class="btn btn-link btn-sm act-edit" data-domain="${escapeHtml(d.domain)}" data-provider="${escapeHtml(d.provider)}">编辑</button>
                    <button class="btn btn-link-danger btn-sm act-del" data-domain="${escapeHtml(d.domain)}" data-provider="${escapeHtml(d.provider)}">删除</button>
                </td>
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
        if (!data.ok) {
            el.innerHTML = '尚未执行访问检测，点击"🛰 访问检测"探测全部域名';
            return;
        }
        const failLink = data.fail > 0
            ? `<a class="rec-link" id="access-fail-link" href="javascript:void(0)">异常 ${data.fail} 个</a>`
            : '异常 0 个';
        el.innerHTML = `历史 HTTP 检测：共 ${data.total ?? 0} 项 / ${failLink} · ${escapeHtml(data.checked_at)} · 当前健康请查看看板`;
        $('#access-fail-link')?.addEventListener('click', () => {
            $('#f-access').value = 'unhealthy';
            loadDomains();
        });
    } catch {
        el.innerHTML = '';
    }
}

$('#access-check-btn').addEventListener('click', () => {
    switchTab('dashboard');
    window.HealthWorkbench?.checkDomains();
});

/* 操作列事件（事件委托） */
$('#domains-table tbody').addEventListener('click', async (e) => {
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
            message: `确定删除域名「${domain}」吗？\n（仅删除本地记录，不影响云商侧域名）`,
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

    overlay.querySelector('.modal-close').addEventListener('click', () => overlay.remove());
    overlay.querySelector('.btn-cancel').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

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
            overlay.remove();
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
function domainRowKey(d){return d.provider+'|'+d.domain;}
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
    $('#domain-selected-count').textContent=selectedDomainRows.size?`已选择 ${selectedDomainRows.size} 个域名（筛选切换时保留）`:'未选择域名';
    $('#domains-to-relations').disabled=selectedDomainRows.size===0;
    const rows=visibleDomainRows.filter(d=>d.valid!==false),n=rows.filter(d=>selectedDomainRows.has(domainRowKey(d))).length;
    $('#domain-select-all').checked=rows.length>0&&n===rows.length;
    $('#domain-select-all').indeterminate=n>0&&n<rows.length;$('#domain-select-all').disabled=!rows.length;
}
document.addEventListener('click',event=>{
    const tab=event.target.closest('[data-domain-filter]');if(!tab)return;
    const control=$('#'+tab.dataset.domainFilter);control.value=tab.dataset.filterValue;control.dispatchEvent(new Event('change',{bubbles:true}));
});
document.addEventListener('change',event=>{
    const check=event.target.closest('[data-domain-select]');if(!check)return;
    const key=check.dataset.domainSelect,row=domainListData.find(d=>domainRowKey(d)===key);
    if(check.checked&&row)selectedDomainRows.set(key,row);else selectedDomainRows.delete(key);renderDomainSelection();
});
$('#domain-select-all').onchange=event=>{visibleDomainRows.filter(d=>d.valid!==false).forEach(d=>event.target.checked?selectedDomainRows.set(domainRowKey(d),d):selectedDomainRows.delete(domainRowKey(d)));loadDomains();};
$('#domain-selection-clear').onclick=()=>{selectedDomainRows.clear();loadDomains();};
$('#domains-to-relations').onclick=async event=>{
    const button=event.currentTarget;button.disabled=true;
    try{const snapshot=await api('/api/health');const ids=snapshot.assets.filter(a=>a.kind==='domain'&&[...selectedDomainRows.values()].some(d=>d.provider===a.provider&&d.domain.toLowerCase()===a.name)).map(a=>a.id);
        if(ids.length)window.Relationships.showDomains(ids);else toast('没有可展示的有效域名，请更新列表','error');
    }catch(error){toast(error.message,'error');}finally{renderDomainSelection();}
};
