'use strict';
window.Relationships = (() => {
    let data = null, tab = 'all', view = 'table', page = 0, generation = 0;
    let selectedRoots = null, focusedDomain = null, focusedHost = null, focusedIp = null;
    const h = escapeHtml;
    const domainAssets = () => (data?.assets || []).filter(a => a.kind === 'domain' && (!selectedRoots || selectedRoots.has(a.id)));
    const ipAssets = () => (data?.assets || []).filter(a => a.kind === 'ip');
    const byId = id => data?.assets.find(a => a.id === id);
    const labels = {healthy:'正常',unhealthy:'检查异常',stale:'结果过时',unchecked:'未检查'};
    const badge = a => `<span class="hc-status" data-status="${h(a.health.status)}"><i></i>${h(labels[a.health.status])}</span>`;
    const linkIds = d => d.linked_ips || [];
    const endpoint = a => (a.ip.includes(':') ? '['+a.ip+']' : a.ip) + ':' + a.port;
    async function load(force = false) {
        const current = ++generation;
        try {
            const result = await api('/api/health', {refresh:force === true});
            if (current !== generation) return;
            data = result;
            // Time-dependent classification is recalculated without a request.
            data.assets.forEach(a => {
                if (a.health.checked_at && Date.now() - Date.parse(a.health.checked_at.replace(' ','T') + '+08:00') >= 86400000) a.health.status = 'stale';
            });
            const selected = $('#rel-ip').value;
            $('#rel-ip').innerHTML = '<option value="">全部 IP / 服务器</option>' + ipAssets().map(a => `<option value="${h(a.id)}">${h(a.name)} · ${h(endpoint(a))}</option>`).join('');
            if (ipAssets().some(a => a.id === selected)) $('#rel-ip').value = selected;
            $('#rel-cache-note').dataset.error = 'false';
            $('#rel-cache-note').textContent = `文件快照 ${data.cache?.saved_at || data.generated_at} · Tab 与筛选复用已加载数据 · 保存后刷新缓存`;
            const query = new URLSearchParams(location.hash.split('?')[1] || '');
            selectedRoots = query.has('domains') ? new Set(query.get('domains').split(',').filter(Boolean)) : null;
            focusedDomain = query.get('focus') || null;
            if (query.has('q')) $('#rel-query').value = query.get('q');
            if (query.has('ip') && ipAssets().some(a => a.id === query.get('ip'))) $('#rel-ip').value = query.get('ip');
            if (['all','confirmed','dns','unlinked','unhealthy'].includes(query.get('tab'))) tab = query.get('tab');
            render();
        } catch (error) { $('#rel-cache-note').dataset.error = 'true'; $('#rel-cache-note').textContent = '加载失败：' + error.message; }
    }
    function filtered() {
        const query = $('#rel-query').value.trim().toLowerCase(), ip = $('#rel-ip').value;
        return domainAssets().filter(d => {
            const linked = linkIds(d).map(byId).filter(Boolean);
            if (ip && !linkIds(d).includes(ip)) return false;
            if (query && ![d.name,d.note,...linked.flatMap(a => [a.name,a.ip,a.note,a.server_provider])].join(' ').toLowerCase().includes(query)) return false;
            if (tab === 'confirmed' && !d.bound_ips.length) return false;
            if (tab === 'dns' && (!linkIds(d).length || d.bound_ips.length)) return false;
            if (tab === 'unlinked' && linkIds(d).length) return false;
            if (tab === 'unhealthy' && d.health.status !== 'unhealthy') return false;
            return true;
        });
    }
    function render() {
        const domains = domainAssets(), ips = selectedRoots ? ipAssets().filter(a=>domains.some(d=>linkIds(d).includes(a.id))) : ipAssets();
        $('#rel-scope-note').classList.toggle('hidden',!selectedRoots);
        $('#rel-scope-note').innerHTML=selectedRoots ? `<strong>选定范围：${domains.length} 个域名</strong><span>只展示健康看板中选中的域名及其关联目标</span><button class="btn btn-sm" data-rel-scope-clear>返回全部资产</button>` : '';
        const confirmed = domains.filter(d => d.bound_ips.length).length;
        const dns = domains.filter(d => linkIds(d).length && !d.bound_ips.length).length;
        const unlinked = domains.length - confirmed - dns;
        $('#rel-summary').innerHTML = [['有效域名',domains.length],['独立 IP',new Set(ips.map(a => a.ip)).size],['已确认绑定',confirmed],['待确认 / 未关联',dns + unlinked]].map(([label,value]) => `<div class="panel"><span class="muted">${label}</span><strong>${value}</strong></div>`).join('');
        let offset = 0;
        const colors = ['#2d6b58','#91b3a5','#dedfd8'];
        const counts = [confirmed,dns,unlinked];
        $('#rel-coverage').innerHTML = `<svg viewBox="0 0 160 160" role="img" aria-label="${confirmed} 个确认绑定、${dns} 个仅DNS、${unlinked} 个未关联"><circle cx="80" cy="80" r="59" fill="none" stroke="#eef0eb" stroke-width="15"/>${counts.map((n,i) => {const length=domains.length ? n/domains.length*370.708:0;const circle=`<circle cx="80" cy="80" r="59" fill="none" stroke="${colors[i]}" stroke-width="15" stroke-dasharray="${length} 370.708" stroke-dashoffset="${-offset}" transform="rotate(-90 80 80)"/>`;offset+=length;return circle;}).join('')}<text x="80" y="78" text-anchor="middle" class="rel-ring-value">${domains.length ? Math.round((confirmed+dns)/domains.length*100):0}%</text><text x="80" y="100" text-anchor="middle" class="rel-ring-label">有关联线索</text></svg><div class="rel-ring-legend">${['confirmed','dns','unlinked'].map((t,i) => `<button data-rel-tab="${t}"><i style="background:${colors[i]}"></i>${['已确认绑定','仅 DNS 关联','未关联'][i]}<b>${counts[i]}</b></button>`).join('')}</div>`;
        const ranked = ips.map(a => ({asset:a,count:domains.filter(d => linkIds(d).includes(a.id)).length})).sort((a,b)=>b.count-a.count).slice(0,5);
        const max = Math.max(1,...ranked.map(a=>a.count));
        $('#rel-ranking').innerHTML = ranked.length ? ranked.map(({asset:a,count}) => `<button class="rel-rank" data-rel-focus="${h(a.id)}"><span title="${h(a.name)}">${h(endpoint(a))}</span><i><b style="width:${count/max*100}%"></b></i><strong>${count}</strong></button>`).join('') : '<p class="hc-empty">尚无 IP 资产，可从 IP 资产页添加。</p>';
        renderResults();
    }
    function renderResults() {
        window.ChoiceTabs?.sync();
        const rows = filtered(), size = $('#rel-show-all').checked ? Math.max(1,rows.length) : Math.max(1,Math.min(10000,Number($('#rel-page-size').value)||12));
        page = Math.min(page, Math.max(0,Math.ceil(rows.length / size)-1));
        const slice = rows.slice(page*size,(page+1)*size);
        $('#rel-count').textContent = `${rows.length} 个域名`;
        $$('[data-rel-tab]').forEach(b => b.setAttribute('aria-selected',String(b.dataset.relTab === tab)));
        $('#rel-graph').classList.toggle('hidden', view !== 'graph');
        $('#rel-table').classList.remove('hidden');
        $('#rel-prev').disabled = page === 0;
        $('#rel-next').disabled = (page+1)*size >= rows.length;
        $('#rel-page').textContent = rows.length ? `${page*size+1}–${Math.min((page+1)*size,rows.length)} / ${rows.length} · 每页 ${size} 个域名` : '无匹配域名';
        if (view === 'graph') renderGraph(slice);
        $('#rel-table').innerHTML = `<table><thead><tr><th>域名</th><th>DNS 解析 IP</th><th>确认绑定服务器</th><th>健康</th><th>操作</th></tr></thead><tbody>${slice.length ? slice.map(d=>`<tr><td><button class="hc-asset-link" data-rel-detail="${h(d.id)}">${h(d.name)}</button></td><td>${(d.health.resolved_ips||[]).map(h).join('<br>')||'暂无解析证据'}</td><td>${d.bound_ips.map(id=>byId(id)).filter(Boolean).map(a=>`<button class="btn btn-link" data-rel-detail="${h(a.id)}">${h(a.name)} · ${h(a.ip)}</button>`).join('')||'尚未确认'}</td><td>${badge(d)}</td><td><button class="btn btn-sm" data-rel-detail="${h(d.id)}">关系详情</button><button class="btn btn-sm" data-rel-edit="${h(d.id)}">编辑</button><button class="btn btn-sm" data-rel-bind="${h(d.id)}">绑定</button></td></tr>`).join('') : '<tr><td colspan="5" class="hc-empty">暂无匹配关系，可调整筛选条件。</td></tr>'}</tbody></table>`;
    }
    function renderGraph(domains) {
        $('#rel-drilldown-note').classList.toggle('hidden',!focusedDomain);
        if (focusedDomain) { renderDomainGraph(focusedDomain); return; }
        const selected = $('#rel-ip').value;
        const ids = new Set(domains.flatMap(linkIds));
        if (selected) ids.add(selected);
        // With no domain matches, still show registered standalone servers.
        const ips = ipAssets().filter(a => ids.has(a.id) || (!$('#rel-query').value && tab === 'all' && !selected && !domains.length));
        if (!domains.length && !ips.length) { $('#rel-graph').innerHTML='<div class="hc-empty">没有符合条件的关系，点击“全部”或调整搜索条件。</div>'; return; }
        $('#rel-graph').innerHTML = window.RelationshipGraph.overview({
            domains, ips, ...graphDisplayOptions(),
        });
    }
    function graphDisplayOptions() {
        return {
            width: $('#rel-graph').clientWidth,
            compact: document.documentElement.dataset.density === 'compact',
        };
    }
    let graphFrame = null;
    function refreshGraphLayout() {
        if (graphFrame !== null) return;
        graphFrame = requestAnimationFrame(() => {
            graphFrame = null;
            if (data && state.tab === 'relations' && view === 'graph') renderResults();
        });
    }
    let graphWidth = 0;
    new ResizeObserver(entries => {
        const width = Math.round(entries[0].contentRect.width);
        if (width !== graphWidth) { graphWidth = width; refreshGraphLayout(); }
    }).observe($('#rel-graph'));
    window.addEventListener('workspace-display-changed', refreshGraphLayout);

    function openView(nextView) {
        window.WorkspaceUI.closeDialog();
        view = nextView;
        window.WorkspaceUI.openDialog({
            title: view === 'graph' ? '资产关联图' : '关联统计',
            anchor: document.querySelector(`[data-rel-open="${nextView}"]`), align: 'end',
            panels: [{nodes: view === 'graph' ? ['rel-graph-panel', 'rel-pagination'] : ['rel-summary-panel']}],
            onOpen: () => { if (data) render(); },
            onClose: () => { view = 'table'; if (data) renderResults(); },
        });
    }
    function showDomains(ids) {
        selectedRoots=new Set(ids);focusedDomain=null;tab='all';page=0;view='graph';
        $('#rel-query').value='';$('#rel-ip').value='';
        history.pushState(null,'','#relations?domains='+ids.join(','));
        switchTab('relations');
        openView('graph');
    }
    function renderDomainGraph(id) {
        const d=byId(id);
        if(!d || d.kind!=='domain' || (selectedRoots&&!selectedRoots.has(id))){focusedDomain=null;renderResults();return;}
        const allHosts=[{id:d.id,name:d.name,ips:linkIds(d).filter(ipId=>d.bound_ips.includes(ipId)).map(ipId=>byId(ipId)?.ip).filter(Boolean).concat(d.health.resolved_ips||[]),health:d.health,isRoot:true},...(d.subdomains||[])];
        // Root records provide authoritative address hints alongside timestamped checks.
        allHosts[0].ips=[...new Set(allHosts[0].ips.concat((d.dns_records||[]).filter(r=>r.fqdn===d.name&&['A','AAAA'].includes(r.type)).map(r=>r.value)))];
        const hosts=allHosts.filter(host=>(!focusedHost||host.id===focusedHost)&&(!focusedIp||host.ips.includes(byId(focusedIp)?.ip)));
        const ips=ipAssets().filter(a=>(!focusedIp||a.id===focusedIp)&&(hosts.some(host=>host.ips.includes(a.ip))||(!focusedHost&&d.bound_ips.includes(a.id))));
        $('#rel-drilldown-note').innerHTML=`<button class="btn btn-sm" data-rel-up>← 返回选定域名总图</button><strong>${h(d.name)} · ${d.subdomain_count==null?'子域名待缓存':d.subdomain_count+' 个子域名'}</strong>${focusedHost||focusedIp?'<button class="btn btn-sm" data-rel-clear-host>返回全部子域名</button>':''}<button class="btn btn-sm" data-rel-host-detail="${h(d.id)}">子域名明细 / 检查 / 编辑解析</button>`;
        $('#rel-graph').innerHTML = window.RelationshipGraph.detail({
            domain: d, hosts, ips, labels, ...graphDisplayOptions(),
        });
    }
    document.addEventListener('click',event=>{
        if(event.target.closest('[data-rel-scope-clear]')){selectedRoots=null;focusedDomain=null;tab='all';page=0;updateUrl();render();}
        if(event.target.closest('[data-rel-up]')){focusedDomain=null;updateUrl();renderResults();}
        const host=event.target.closest('[data-rel-graph-host]');if(host){focusedHost=host.dataset.relGraphHost==='all'?null:host.dataset.relGraphHost;focusedIp=null;renderDomainGraph(focusedDomain);}
        const ip=event.target.closest('[data-rel-graph-ip]');if(ip){focusedIp=ip.dataset.relGraphIp;focusedHost=null;renderDomainGraph(focusedDomain);}
        if(event.target.closest('[data-rel-clear-host]')){focusedHost=null;focusedIp=null;renderDomainGraph(focusedDomain);}
        const detail=event.target.closest('[data-rel-host-detail]');if(detail)window.DomainDetails.open(detail.dataset.relHostDetail);
    });
    function updateUrl() {
        const q=new URLSearchParams();
        if (selectedRoots) q.set('domains',[...selectedRoots].join(','));
        if (focusedDomain) q.set('focus',focusedDomain);
        if (tab!=='all') q.set('tab',tab);
        if ($('#rel-query').value) q.set('q',$('#rel-query').value);
        if ($('#rel-ip').value) q.set('ip',$('#rel-ip').value);
        if (state.tab === 'relations') history.replaceState(null,'','#relations'+(q.size?'?'+q:''));
    }
    async function openDomain(provider,name) {
        await load();
        const a=domainAssets().find(d=>d.provider===provider && d.name===name.toLowerCase());
        if(a) window.HealthWorkbench.openDetail(a.id);
    }
    async function edit(id) {
        await load();
        const a=byId(id); if(!a)return;
        window.HealthWorkbench.closeDetail();
        if(a.kind==='domain') {
            const domains=await api('/api/domains?all=1');
            cacheDomainsForModal(domains.list);
            openDomainModal('edit',{domain:a.name,provider:a.provider});
            return;
        }
        const modal=dialog(`编辑服务器 / IP`, `<form id="rel-edit-form" class="form-grid"><label>名称<input id="rel-name" value="${h(a.name)}" maxlength="120"></label><label>IP 地址<input value="${h(a.ip)}" readonly></label><label>供应商<input id="rel-provider" value="${h(a.server_provider||'')}" maxlength="120"></label><label>到期日期<input type="date" id="rel-expiry" value="${h(a.expires_at||'')}"></label><label class="span-2">用途与备注<textarea id="rel-note" rows="3" maxlength="2000">${h(a.note||'')}</textarea></label><fieldset class="asset-links-field span-2"><legend>网站链接</legend><div id="rel-links-editor"></div></fieldset></form>`, '保存资产');
        const links = window.AssetLinks.editor(modal.overlay.querySelector('#rel-links-editor'), a.links || []);
        modal.save.addEventListener('click', () => {
            if (!modal.overlay.querySelector('form').reportValidity()) return;
            save(modal, () => api('/api/health/ips/' + encodeURIComponent(id), {method: 'PUT', body: JSON.stringify({name: $('#rel-name').value, server_provider: $('#rel-provider').value, expires_at: $('#rel-expiry').value, note: $('#rel-note').value, links: links.read()})}));
        });
    }
    async function bind(id) {
        await load(); const a=byId(id); if(!a)return;
        window.HealthWorkbench.closeDetail();
        const ips=ipAssets();
        const modal=dialog('管理绑定 · '+a.name, `<p class="muted">选择实际承载该域名的服务器。取消勾选可解除人工绑定，DNS 线索仍保留。</p><input id="binding-search" class="dialog-input" placeholder="搜索服务器 / IP" aria-label="搜索可绑定服务器"><div class="rel-bind-list">${ips.map(ip=>`<label data-bind-search="${h((ip.name+' '+ip.ip).toLowerCase())}"><input type="checkbox" value="${h(ip.id)}" ${a.bound_ips.includes(ip.id)?'checked':''}><span><strong>${h(ip.name)}</strong><small>${h(endpoint(ip))}${(a.health.resolved_ips||[]).includes(ip.ip)?' · DNS 解析匹配':''}</small></span></label>`).join('')||'<p class="hc-empty">尚无 IP 资产，请先在 IP 资产页添加服务器地址。</p>'}</div>`, '保存绑定');
        $('#binding-search').addEventListener('input',event=>modal.overlay.querySelectorAll('[data-bind-search]').forEach(row=>row.classList.toggle('hidden',!row.dataset.bindSearch.includes(event.target.value.toLowerCase()))));
        modal.save.addEventListener('click',()=>save(modal,()=>api('/api/health/domains/'+encodeURIComponent(id)+'/bindings',{method:'PUT',body:JSON.stringify({ip_ids:Array.from(modal.overlay.querySelectorAll('input[type=checkbox]:checked')).map(el=>el.value)})})));
    }
    function dialog(title,body,saveLabel) {
        const overlay=document.createElement('div'); overlay.className='modal-overlay';
        overlay.innerHTML=`<section class="modal" role="dialog" aria-modal="true" aria-label="${h(title)}"><div class="modal-head"><h3>${h(title)}</h3><button class="modal-close" aria-label="关闭编辑">×</button></div><div class="modal-body">${body}<p class="error-text hidden" role="alert"></p></div><div class="modal-foot"><button class="btn rel-cancel">取消</button><button class="btn btn-primary rel-save">${saveLabel}</button></div></section>`;
        (document.fullscreenElement || document.body).appendChild(overlay);
        const close=()=>overlay.remove(); overlay.querySelector('.modal-close').onclick=close; overlay.querySelector('.rel-cancel').onclick=close;
        overlay.addEventListener('click',e=>{if(e.target===overlay)close();});
        overlay.addEventListener('keydown',e=>{if(e.key==='Escape')close();});
        overlay.querySelector('input')?.focus();
        return {overlay,save:overlay.querySelector('.rel-save')};
    }
    async function save(modal,work) {
        modal.save.disabled=true;
        try {await work();modal.overlay.remove();toast('已保存，关联与缓存已更新');await load();window.HealthWorkbench.load();}
        catch(error){const el=modal.overlay.querySelector('.error-text');el.textContent=error.message;el.classList.remove('hidden');}
        finally{modal.save.disabled=false;}
    }
    document.addEventListener('click',async event=>{
        const target=event.target.closest('[data-rel-detail],[data-rel-dns],[data-rel-edit],[data-rel-bind],[data-rel-tab],[data-rel-open],[data-rel-focus]'); if(!target)return;
        if(target.dataset.relDns){ if(!data)await load();const a=byId(target.dataset.relDns);if(a){window.HealthWorkbench.closeDetail();openRecordsModal(a.name,a.provider);}}
        else if(target.dataset.relDetail) {const a=byId(target.dataset.relDetail);if(a?.kind==='domain'){focusedDomain=a.id;focusedHost=null;focusedIp=null;updateUrl();openView('graph');}else await window.HealthWorkbench.openDetail(target.dataset.relDetail);}
        else if(target.dataset.relEdit) await edit(target.dataset.relEdit);
        else if(target.dataset.relBind) await bind(target.dataset.relBind);
        else if(target.dataset.relFocus){$('#rel-ip').value=target.dataset.relFocus;tab='all';page=0;updateUrl();openView('graph');}
        else if(target.dataset.relTab){tab=target.dataset.relTab;const fromSummary=view==='summary';if(tab==='all'){$('#rel-query').value='';$('#rel-ip').value='';}page=0;updateUrl();if(fromSummary)openView('graph');else renderResults();}
        else if(target.dataset.relOpen){openView(target.dataset.relOpen);}
    });
    document.addEventListener('keydown',e=>{if((e.key==='Enter'||e.key===' ')&&e.target.matches('.rel-node')){e.preventDefault();e.target.dispatchEvent(new MouseEvent('click',{bubbles:true}));}});
    ['rel-query','rel-ip'].forEach(id=>$('#'+id).addEventListener(id==='rel-query'?'input':'change',()=>{page=0;updateUrl();renderResults();}));
    try { $('#rel-page-size').value=localStorage.getItem('relation-page-size')||'12';$('#rel-show-all').checked=localStorage.getItem('relation-show-all')==='1';$('#rel-page-size').disabled=$('#rel-show-all').checked; } catch {}
    $('#rel-page-size').oninput=()=>{page=0;try{localStorage.setItem('relation-page-size',$('#rel-page-size').value);}catch{}renderResults();};
    $('#rel-show-all').onchange=()=>{page=0;try{localStorage.setItem('relation-show-all',$('#rel-show-all').checked?'1':'0');}catch{}$('#rel-page-size').disabled=$('#rel-show-all').checked;renderResults();};
    $('#rel-fullscreen').onclick=async()=>{const panel=$('#rel-graph-panel').closest('.workspace-content-modal') || $('.rel-panel');if(panel.classList.contains('rel-fallback-fullscreen')){panel.classList.remove('rel-fallback-fullscreen');$('#rel-fullscreen').textContent='全屏展示';return;}if(document.fullscreenElement){await document.exitFullscreen();}else{try{await panel.requestFullscreen();}catch{panel.classList.add('rel-fallback-fullscreen');$('#rel-fullscreen').textContent='退出全屏';}}};
    document.addEventListener('fullscreenchange',()=>{$('#rel-fullscreen').textContent=document.fullscreenElement?'退出全屏':'全屏展示';});
    document.addEventListener('keydown',e=>{if(e.key==='Escape'){$('.rel-fallback-fullscreen')?.classList.remove('rel-fallback-fullscreen');if(!document.fullscreenElement)$('#rel-fullscreen').textContent='全屏展示';}});
    $('#rel-prev').onclick=()=>{page--;renderResults();}; $('#rel-next').onclick=()=>{page++;renderResults();};
    $('#rel-refresh').onclick=async()=>{window.WorkspaceCache.clear();await load(true);};
    window.addEventListener('workspace-invalidated',()=>{data=null;generation++;if(state.tab==='relations'&&!$('#app-view').classList.contains('hidden'))setTimeout(()=>{if(state.tab==='relations'&&!$('#app-view').classList.contains('hidden'))load();},0);});
    if (!$('#app-view').classList.contains('hidden') && state.tab==='relations') load();
    return {load,openDomain,showDomains};
})();
