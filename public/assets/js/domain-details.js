'use strict';
window.DomainDetails = (() => {
    let snapshot=null, activeId=null, overlay=null, selected='hosts', recordHost=null, busy=false;
    const h=escapeHtml;
    const asset=id=>snapshot?.assets.find(a=>a.id===id);
    const endpoint=a=>(a.ip.includes(':')?'['+a.ip+']':a.ip)+':'+a.port;
    const labels={healthy:'正常',unhealthy:'检查异常',stale:'结果过时',unchecked:'未检查'};
    const badge=health=>`<span class="hc-status" data-status="${h(health?.status||'unchecked')}"><i></i>${h(labels[health?.status]||'未检查')}</span>`;
    async function refresh(force=false){snapshot=await api('/api/health',{refresh:force});renderSummary(snapshot);return snapshot;}
    function renderSummary(data){snapshot=data;const s=data.summary;$('#dns-inventory-summary').textContent=`主域名 ${s.domains} · 已缓存子域名 ${s.subdomains||0} · DNS 已缓存 ${s.dns_cached_domains||0}/${s.domains} · ${s.dns_uncached_domains||0} 个待缓存`;
        if(overlay&&activeId)render();}
    function close(){overlay?.remove();overlay=null;activeId=null;}
    async function open(id,mode='hosts',host=null){await refresh();activeId=id;selected=mode;recordHost=host;if(!asset(id))return;overlay=document.createElement('div');overlay.className='modal-overlay domain-detail-overlay';(document.fullscreenElement||document.body).appendChild(overlay);overlay.addEventListener('click',e=>{if(e.target===overlay||e.target.closest('[data-domain-close]'))close();});overlay.addEventListener('keydown',e=>{if(e.key==='Escape')close();});render();overlay.querySelector('.modal-close')?.focus();}
    function render(){const d=asset(activeId);if(!d){close();return;}
        const children=d.subdomains||[], records=d.dns_records||[], hosts=[{id:d.id,name:d.name,health:d.health,checkable:true,records:records.filter(r=>r.fqdn===d.name),ips:d.health.resolved_ips||[]},...children];
        overlay.innerHTML=`<section class="modal modal-wide domain-detail" role="dialog" aria-modal="true" aria-labelledby="domain-detail-title"><div class="modal-head"><div><p class="eyebrow">DOMAIN INVENTORY</p><h3 id="domain-detail-title">${h(d.name)}</h3><p class="muted">${h(d.provider_name)} · DNS ${d.dns_snapshot_at ? '快照 '+h(d.dns_snapshot_at) : '尚未缓存，数量未知'}</p></div><button class="modal-close" data-domain-close aria-label="关闭域名详情">×</button></div><div class="domain-detail-metrics">${badge(d.health)}<span>${d.subdomain_count==null?'子域名：待缓存':d.subdomain_count+' 个子域名'}</span><span>${records.length} 条已缓存记录</span><span>${d.linked_ips.length} 个 IP 目标</span></div><div class="domain-detail-actions"><button class="btn btn-sm" data-domain-action="dns">${d.dns_cached?'刷新 DNS 缓存':'读取并缓存 DNS'}</button><button class="btn btn-sm" data-domain-action="check-hosts">检查全部网站主机</button><button class="btn btn-sm" data-rel-edit="${h(d.id)}">编辑域名资料</button><button class="btn btn-sm" data-rel-bind="${h(d.id)}">管理绑定</button></div><div class="rel-quick-tabs" role="tablist" aria-label="域名详情查询"><button role="tab" aria-selected="${selected==='hosts'}" data-domain-view="hosts">主域名 / 子域名</button><button role="tab" aria-selected="${selected==='records'}" data-domain-view="records">IP 与解析记录</button></div><div class="modal-body"><div class="table-wrap">${selected==='hosts'?`<table><thead><tr><th>主机名</th><th>解析记录 / IP</th><th>健康状态</th><th>操作</th></tr></thead><tbody>${hosts.map(host=>`<tr><td>${h(host.name)}<small class="hc-subline">${host.id===d.id?'主域名':host.name.includes('*')?'泛解析规则':'子域名'}</small></td><td>${host.records.map(r=>`<span class="badge">${h(r.type)}</span> ${h(r.value)}<br>`).join('')||'尚无缓存记录'}${host.ips.length?`<small class="hc-subline">解析 IP（缓存）：${host.ips.map(h).join('、')}</small>`:''}</td><td>${host.checkable?badge(host.health):'不适用网站检查'}<small class="hc-subline">${h(host.health.checked_at||'尚未检查')}</small></td><td><button class="btn btn-sm" data-host-check="${h(host.id)}" ${!host.checkable||busy?'disabled':''}>检查</button><button class="btn btn-sm" data-host-records="${h(host.name)}">修改解析</button></td></tr>`).join('')}</tbody></table>`:recordTable(d,recordHost?records.filter(r=>r.fqdn===recordHost):records)}</div><p id="domain-detail-progress" class="muted" role="status"></p><p class="hc-footnote">数量来自缓存的 DNS 主机名，泛解析单独计为一条规则；不表示发现了全部实际运行网站。检查结果与 DNS 快照各自保留时间。解析写入前显示目标和值，提交前显示差异并核对云端版本，提交后回读核实。</p></div><div class="modal-foot"><button class="btn" data-domain-close>关闭</button><button class="btn" data-domain-action="full-dns">完整 DNS 管理</button></div></section>`;
        overlay.querySelectorAll('[data-host-records]').forEach(b=>b.onclick=()=>{selected='records';recordHost=b.dataset.hostRecords;render();});
        overlay.querySelectorAll('[data-domain-view]').forEach(b=>b.onclick=()=>{selected=b.dataset.domainView;recordHost=null;render();});
        overlay.querySelectorAll('[data-host-check]').forEach(b=>b.onclick=()=>checkHosts([b.dataset.hostCheck]));
        overlay.querySelector('[data-domain-action=dns]').onclick=()=>loadDNS(d,true);
        overlay.querySelector('[data-domain-action=check-hosts]').onclick=()=>checkHosts(hosts.filter(x=>x.checkable).map(x=>x.id));
        overlay.querySelector('[data-domain-action=full-dns]').onclick=()=>{close();openRecordsModal(d.name,d.provider);};
        overlay.querySelectorAll('[data-dns-edit]').forEach(b=>b.onclick=()=>editRecord(d,records.find(r=>String(r.id)===b.dataset.dnsEdit)));
        overlay.querySelectorAll('[data-rel-edit],[data-rel-bind]').forEach(b=>b.addEventListener('click',close));
    }
    function recordTable(d,records){return `<table><thead><tr><th>主机名</th><th>类型</th><th>解析值</th><th>TTL</th><th>操作</th></tr></thead><tbody>${records.length?records.map(r=>`<tr data-record-host="${h(r.fqdn)}"><td>${h(r.fqdn)}</td><td><span class="badge">${h(r.type)}</span></td><td class="rec-value">${h(r.value)}</td><td>${r.ttl}</td><td><button class="btn btn-sm" data-dns-edit="${h(r.id)}">编辑解析</button></td></tr>`).join(''):'<tr><td colspan="5" class="hc-empty">尚无 DNS 记录缓存。点击“读取并缓存 DNS”获取一次。</td></tr>'}</tbody></table>`;}
    async function loadDNS(d,force=false){if(d.provider==='manual'){toast('手工域名尚未配置 DNS 托管账号','error');return;}if(busy)return;busy=true;
        const button=overlay?.querySelector('[data-domain-action=dns]');if(button){button.disabled=true;button.textContent='正在读取…';}
        try{await api(`/api/domains/${encodeURIComponent(d.provider)}/${encodeURIComponent(d.name)}/records${force?'?refresh=1':''}`);window.WorkspaceCache.clear();await refresh(true);await window.HealthWorkbench.load(true);if(overlay)render();toast('DNS 缓存已更新');}
        catch(e){toast(e.message,'error',6000);}finally{busy=false;if(overlay)render();}}
    async function checkHosts(ids){if(busy)return;busy=true;
        try{for(let i=0;i<ids.length;i+=2){if(overlay){const el=overlay.querySelector('#domain-detail-progress');el.textContent=`检查中 ${Math.min(i+2,ids.length)}/${ids.length}，请保持页面打开`;}
            await api('/api/health/check',{method:'POST',body:JSON.stringify({asset_ids:ids.slice(i,i+2)})});}
            await refresh(true);await window.HealthWorkbench.load(true);toast(`已完成 ${ids.length} 个主机检查`);}
        catch(e){toast(e.message,'error',6000);}finally{busy=false;if(overlay)render();}}
    function editRecord(d,r){if(!r)return;const account=state.accounts.find(a=>a.id===d.provider);
        const modal=document.createElement('div');modal.className='modal-overlay dns-edit-overlay';
        modal.innerHTML=`<section class="modal" role="dialog" aria-modal="true" aria-label="编辑云端解析"><div class="modal-head"><h3>编辑云端解析</h3><button class="modal-close" aria-label="关闭解析编辑">×</button></div><div class="modal-body"><p><strong>${h(r.fqdn)}</strong> · ${h(r.type)}</p><p class="muted">当前：${h(r.value)} · 账号：${h(d.provider_name)}</p><form class="form-grid" id="quick-dns-form"><label class="span-2">新的解析值<input id="quick-dns-value" value="${h(r.value)}" required></label><label>TTL（秒）<input type="number" id="quick-dns-ttl" value="${r.ttl||600}" min="1" max="2147483647" required></label></form><p class="error-text hidden" role="alert"></p></div><div class="modal-foot"><button class="btn quick-cancel">取消</button><button class="btn btn-primary quick-save">预览解析变更</button></div></section>`;
        (document.fullscreenElement||document.body).appendChild(modal);const remove=()=>modal.remove();modal.querySelector('.modal-close').onclick=remove;modal.querySelector('.quick-cancel').onclick=remove;
        modal.querySelector('.quick-save').onclick=async event=>{const button=event.currentTarget,form=modal.querySelector('form');if(!form.reportValidity())return;const value=$('#quick-dns-value').value.trim();const type=r.type;
            if((type==='A'&&!/^(\d{1,3}\.){3}\d{1,3}$/.test(value))||(type==='AAAA'&&!value.includes(':'))){toast('解析值与记录类型不匹配','error');return;}
            button.disabled=true;try{const result=await window.WorkspaceDns.perform({endpoint:`/api/domains/${encodeURIComponent(d.provider)}/${encodeURIComponent(d.name)}/records/${encodeURIComponent(r.id)}`,action:'update',record:r,params:{value,ttl:Number($('#quick-dns-ttl').value)},owner:modal,target:`${d.name} · ${d.provider_name}`});
                if(!result)return;remove();window.WorkspaceDns.notify(result);window.WorkspaceCache.clear();await refresh(true);window.HealthWorkbench.load(true);}
            catch(error){const el=modal.querySelector('.error-text');if(el){el.textContent=error.message;el.classList.remove('hidden');}else toast('解析已写入，缓存刷新未完成：'+error.message,'error',7000);}
            finally{button.disabled=false;}};
    }
    async function openIp(id){await refresh();const ip=asset(id);if(!ip)return;
        const modal=document.createElement('div');modal.className='modal-overlay';
        const links=ip.dns_links||[];
        modal.innerHTML=`<section class="modal modal-wide" role="dialog" aria-modal="true" aria-label="IP 解析关联"><div class="modal-head"><div><h3>${h(endpoint(ip))} · ${h(ip.ip_version||'IP')}</h3><p class="muted">${ip.domain_count||0} 个主域名 · ${ip.subdomain_count||0} 个子域名（基于已缓存 DNS）</p></div><button class="modal-close" aria-label="关闭IP关联">×</button></div><div class="modal-body"><div class="table-wrap"><table><thead><tr><th>主域名</th><th>主机名</th><th>操作</th></tr></thead><tbody>${links.map(link=>`<tr><td>${h(link.domain)}</td><td>${h(link.fqdn)}</td><td><button class="btn btn-sm" data-ip-domain="${h(link.domain_id)}" data-ip-host="${h(link.fqdn)}">查看与修改解析</button></td></tr>`).join('')||'<tr><td colspan="3" class="hc-empty">尚无 DNS 记录关联缓存。可先从健康看板缓存 DNS，或打开关联域名详情。</td></tr>'}</tbody></table></div>${(ip.linked_domains||[]).map(name=>`<button class="btn btn-sm" data-ip-name="${h(name)}">${h(name)}</button>`).join('')}</div></section>`;
        (document.fullscreenElement||document.body).appendChild(modal);modal.querySelector('.modal-close').onclick=()=>modal.remove();
        modal.querySelectorAll('[data-ip-domain]').forEach(b=>b.onclick=()=>{modal.remove();open(b.dataset.ipDomain,'records',b.dataset.ipHost);});
        modal.querySelectorAll('[data-ip-name]').forEach(b=>b.onclick=()=>{const d=snapshot.assets.find(a=>a.kind==='domain'&&a.name===b.dataset.ipName);if(d){modal.remove();open(d.id);}});
    }
    $('#dns-cache-missing').onclick=async()=>{if(busy)return;await refresh();const domains=snapshot.assets.filter(a=>a.kind==='domain'&&!a.dns_cached&&a.provider!=='manual');const button=$('#dns-cache-missing');button.disabled=true;let done=0,fail=0;
        try{for(const d of domains){$('#dns-cache-progress').textContent=`缓存 DNS ${++done}/${domains.length}`;try{await api(`/api/domains/${encodeURIComponent(d.provider)}/${encodeURIComponent(d.name)}/records`);}catch{fail++;}}
            window.WorkspaceCache.clear();await window.HealthWorkbench.load(true);$('#dns-cache-progress').textContent=`完成 ${done-fail} 个，失败 ${fail} 个；成功结果已缓存`;}
        finally{button.disabled=false;}};
    document.addEventListener('click',event=>{const button=event.target.closest('[data-domain-ip]');if(button)openIp(button.dataset.domainIp);});
    return {open,renderSummary};
})();
