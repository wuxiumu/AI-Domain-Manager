'use strict';

/* 健康看板只读取后端证据；没有检测数据时不推断资产健康。 */
window.HealthWorkbench = (() => {
    let snapshot = null;
    let loading = null;
    let running = false;
    let stopped = false;
    let detailId = null;
    let detailReturnFocus = null;
    const selectedDomains = new Set();
    const labels = { healthy: '正常', unhealthy: '检查异常', stale: '结果过时', unchecked: '未检查' };
    const h = value => escapeHtml(value ?? '');
    const getHealth = asset => {
        const health = asset.health || {status:'unchecked'};
        if (health.checked_at && Date.now() - Date.parse(health.checked_at.replace(' ','T') + '+08:00') >= 86400000) return {...health,status:'stale'};
        return health;
    };
    const pill = status => `<span class="hc-status" data-status="${h(status)}"><i aria-hidden="true"></i>${h(labels[status] || status || '未检查')}</span>`;
    const timeText = time => time ? h(time) : '尚未检查';
    const sourceText = asset => asset.kind === 'domain' ? (asset.provider_name || '域名资产') : asset.source === 'manual' ? '手工登记' : 'DNS 解析发现';
    const domainNames = asset => [...new Set([...(asset.linked_domains || []).map(item => typeof item === 'string' ? item : item.domain || item.name || ''), ...(asset.bound_domains || []).map(id => snapshot?.assets.find(a => a.id === id)?.name || '')])].filter(Boolean);

    async function load(force = false) {
        if (loading) return loading;
        loading = (async () => {
            try {
                snapshot = await api('/api/health', {refresh: force === true});
                render();
                return snapshot;
            } catch (err) {
                const row = `<tr><td colspan="7"><div class="hc-empty"><strong>健康数据暂时不可用</strong><p>${h(err.message)}</p><button class="btn btn-sm" data-health-action="refresh">重试</button></div></td></tr>`;
                $('#health-table tbody').innerHTML = row;
                $('#ip-table tbody').innerHTML = row;
                $('#health-count').textContent = '加载失败';
                $('#health-empty').classList.add('hidden');
                if (!snapshot) $('#health-stats').innerHTML = '<div class="hc-error">未取得数据，暂不显示资产统计。</div>';
                return null;
            } finally {
                loading = null;
            }
        })();
        return loading;
    }

    function render() {
        if (!snapshot) return;
        const assets = snapshot.assets || [];
        const summary = { domains: 0, ips: 0, healthy: 0, unhealthy: 0, stale: 0, unchecked: 0 };
        assets.forEach(a => { summary[a.kind === 'domain' ? 'domains' : 'ips']++; summary[getHealth(a).status]++; });
        const cards = [
            { label: '域名资产', value: summary.domains, filter: 'domain', category: 'kind', text: '已纳入当前看板' },
            { label: 'IP 检查目标', value: summary.ips, filter: 'ip', category: 'kind', text: '检查 http://IP/ 访问状态' },
            { label: '检查正常', value: summary.healthy, filter: 'healthy', category: 'status', text: '24 小时内有效结果' },
            { label: '检查异常', value: summary.unhealthy, filter: 'unhealthy', category: 'status', text: '查看具体失败环节' },
            { label: '结果过时', value: summary.stale, filter: 'stale', category: 'status', text: '需要重新确认状态' },
            { label: '尚未检查', value: summary.unchecked, filter: 'unchecked', category: 'status', text: '执行检查以获取证据' },
        ];
        $('#health-stats').innerHTML = cards.map(c => `<button type="button" class="hc-stat-card" data-filter-category="${c.category}" data-filter="${c.filter}" data-status="${c.filter}"><span class="hc-stat-label">${c.label}<span aria-hidden="true">↗</span></span><strong class="hc-stat-value">${c.value}</strong><span class="hc-stat-note">${c.text}</span></button>`).join('');
        const statuses = ['healthy', 'unhealthy', 'stale', 'unchecked'];
        $('#health-status-bar').innerHTML = `<div class="hc-bar" aria-label="健康状态分布">${statuses.map(s => `<span data-status="${s}" style="width:${assets.length ? summary[s] / assets.length * 100 : 0}%" title="${labels[s]} ${summary[s]}"></span>`).join('')}</div><div class="hc-legend">${statuses.map(s => `<button type="button" data-filter-category="status" data-filter="${s}">${pill(s)}<b>${summary[s]}</b></button>`).join('')}<span class="muted">${assets.length ? '统计包含域名与 IP 检查目标' : '尚未登记资产'}</span></div>`;
        window.DomainDetails?.renderSummary(snapshot);
        const availableIds = new Set(assets.filter(a=>a.kind==='domain').map(a=>a.id));
        for (const id of selectedDomains) if(!availableIds.has(id)) selectedDomains.delete(id);
        renderRows();
        renderIPs();
        if (detailId) renderDetail(detailId);
        updateBusy();
    }

    function filteredAssets() {
        const query = $('#health-query').value.trim().toLowerCase();
        const kind = $('#health-kind').value;
        const status = $('#health-status').value;
        return (snapshot?.assets || []).filter(a => (!kind || a.kind === kind) && (!status || getHealth(a).status === status) && (!query || [a.name, a.ip, a.provider_name, ...domainNames(a), ...(getHealth(a).resolved_ips || []), a.note].join(' ').toLowerCase().includes(query)));
    }

    function renderRows() {
        window.ChoiceTabs?.sync();
        const rows = filteredAssets();
        renderSelection();
        const order = { unhealthy: 0, stale: 1, unchecked: 2, healthy: 3 };
        rows.sort((a, b) => (order[getHealth(a).status] ?? 4) - (order[getHealth(b).status] ?? 4) || a.name.localeCompare(b.name));
        $('#health-count').textContent = `${rows.length} / ${snapshot?.assets?.length || 0} 项资产`;
        $('#health-empty').classList.add('hidden');
        $('#health-table tbody').innerHTML = rows.length ? rows.map(a => {
            const health = getHealth(a);
            return `<tr><td>${a.kind === 'domain' ? `<input type="checkbox" data-health-select="${h(a.id)}" aria-label="选择域名 ${h(a.name)}" ${selectedDomains.has(a.id)?'checked':''}>` : ''}</td><td><button class="hc-asset-link" data-health-action="detail" data-id="${h(a.id)}">${h(a.name)}</button><small class="hc-subline">${a.kind === 'ip' ? h(a.ip) : (health.resolved_ips || []).map(h).join(' · ') || '解析地址待检查'}</small></td><td><span class="hc-type">${a.kind === 'domain' ? '域名' : (a.ip_version || (a.ip.includes(':')?'IPv6':'IPv4'))}</span>${a.kind === 'domain' ? `<small class="hc-subline">${a.subdomain_count == null ? '子域名待缓存' : a.subdomain_count+' 个子域名'}</small>` : ''}<small class="hc-subline">${h(sourceText(a))}</small></td><td>${pill(health.status)}</td><td><span class="hc-message" title="${h(health.message)}">${h(health.message || '尚无检查证据')}</span>${health.latency_ms != null ? `<small class="hc-subline">${h(health.latency_ms)} ms</small>` : ''}</td><td class="hc-time">${timeText(health.checked_at)}</td><td class="row-actions"><button class="btn btn-sm" data-health-action="detail" data-id="${h(a.id)}">详情</button><button class="btn btn-sm btn-link" data-health-action="check" data-id="${h(a.id)}" ${running ? 'disabled' : ''}>检查</button></td></tr>`;
        }).join('') : '<tr><td colspan="7"><div class="hc-empty">没有符合条件的资产。<button class="btn btn-link" data-health-action="clear">清除筛选</button></div></td></tr>';
    }

    function renderSelection() {
        const count=selectedDomains.size;
        $('#health-selected-count').closest('.health-selection-bar').classList.toggle('hidden', count === 0);
        $('#health-selected-count').textContent=count ? `已选择 ${count} 个域名` : '未选择域名';
        $('#health-to-relations').disabled=count===0;
        const visible=filteredAssets().filter(a=>a.kind==='domain');
        const selected=visible.filter(a=>selectedDomains.has(a.id)).length;
        $('#health-select-all').checked=visible.length>0 && selected===visible.length;
        $('#health-select-all').indeterminate=selected>0 && selected<visible.length;
        $('#health-select-all').disabled=visible.length===0;
    }
    document.addEventListener('change',event=>{
        const target=event.target.closest('[data-health-select]');
        if(target){if(target.checked)selectedDomains.add(target.dataset.healthSelect);else selectedDomains.delete(target.dataset.healthSelect);renderSelection();}
    });
    $('#health-select-all').onchange=event=>{filteredAssets().filter(a=>a.kind==='domain').forEach(a=>event.target.checked?selectedDomains.add(a.id):selectedDomains.delete(a.id));renderRows();};
    $('#health-selection-clear').onclick=()=>{selectedDomains.clear();renderRows();};
    $('#health-to-relations').onclick=()=>window.Relationships.showDomains([...selectedDomains]);

    function renderIPs() {
        const query = $('#ip-query').value.trim().toLowerCase();
        const all = (snapshot?.assets || []).filter(a => a.kind === 'ip');
        const rows = all.filter(a => (!$('#ip-version').value || (a.ip_version || (a.ip.includes(':')?'IPv6':'IPv4')) === $('#ip-version').value) && (!$('#ip-status').value || getHealth(a).status === $('#ip-status').value) && (!query || [a.name, a.ip, a.note, ...(a.links || []).flatMap(link => [link.name, link.url]), ...domainNames(a)].join(' ').toLowerCase().includes(query)));
        window.ChoiceTabs?.sync();
        $('#ip-count').textContent = `${rows.length} 个检查目标 · ${new Set(all.map(a => a.ip)).size} 个 IP`;
        $('#ip-empty').classList.add('hidden');
        $('#ip-table tbody').innerHTML = rows.length ? rows.map(a => `<tr><td><button class="hc-asset-link" data-health-action="detail" data-id="${h(a.id)}">${h(a.name)}</button><small class="hc-subline">${h(a.ip)} · ${h(a.ip_version || (a.ip.includes(':') ? 'IPv6' : 'IPv4'))} · ${h(sourceText(a))}</small></td><td><div class="asset-site-links">${window.AssetLinks.render(a.links) || '<span class="muted">—</span>'}</div></td><td class="asset-note">${h(a.note) || '<span class="muted">—</span>'}</td><td><span class="hc-message">${a.domain_count ?? domainNames(a).length} 个主域名 · ${a.subdomain_count ?? 0} 个子域名</span><small class="hc-subline">${domainNames(a).slice(0,3).map(h).join('、') || '暂无解析关联'}</small><button class="btn btn-link btn-sm" data-domain-ip="${h(a.id)}">查看 / 修改解析</button></td><td><button type="button" class="hc-status-button" data-health-action="check" data-id="${h(a.id)}" title="点击检查 HTTP 访问状态" aria-label="${h(labels[getHealth(a).status])}，点击检查 ${h(a.name)} 的 HTTP 访问状态" ${running ? 'disabled' : ''}>${pill(getHealth(a).status)}</button></td><td class="hc-time">${timeText(getHealth(a).checked_at)}</td><td class="row-actions"><button class="btn btn-sm" data-health-action="check" data-id="${h(a.id)}" ${running ? 'disabled' : ''}>检查</button><button class="btn btn-sm btn-link" data-health-action="detail" data-id="${h(a.id)}">详情</button><button class="btn btn-sm btn-link" data-rel-edit="${h(a.id)}">编辑</button>${a.source === 'manual' ? `<button class="btn btn-sm btn-link-danger" data-health-action="delete" data-id="${h(a.id)}" ${running ? 'disabled' : ''}>移除</button>` : ''}</td></tr>`).join('') : '<tr><td colspan="7"><div class="hc-empty">暂无 IP 检查目标。可点击“添加 IP 资产”登记公网 IP，或检查域名以发现解析地址。</div></td></tr>';
    }

    function closeDetail() {
        $('#health-detail-overlay')?.remove();
        detailId = null;
        detailReturnFocus?.focus();
    }

    function renderDetail(id) {
        const asset = (snapshot?.assets || []).find(a => a.id === id);
        if (!asset) { closeDetail(); return; }
        if (asset.kind === 'domain' && window.DomainDetails) { closeDetail(); window.DomainDetails.open(id); return; }
        detailId = id;
        let overlay = $('#health-detail-overlay');
        if (!overlay) {
            detailReturnFocus = document.activeElement;
            overlay = document.createElement('div');
            overlay.id = 'health-detail-overlay';
            overlay.className = 'modal-overlay hc-detail-overlay';
            (document.fullscreenElement || document.body).appendChild(overlay);
            overlay.addEventListener('click', event => { if (event.target === overlay || event.target.closest('[data-close-detail]')) closeDetail(); });
            overlay.addEventListener('keydown', event => {
                if (event.key === 'Escape') closeDetail();
                if (event.key === 'Tab') {
                    const focusable = Array.from(overlay.querySelectorAll('button:not(:disabled), a[href]'));
                    const first = focusable[0], last = focusable[focusable.length - 1];
                    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
                    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
                }
            });
        }
        const health = getHealth(asset);
        const history = asset.history || health.history || [];
        const checks = health.checks || [];
        overlay.innerHTML = `<section class="hc-detail modal" role="dialog" aria-modal="true" aria-labelledby="hc-detail-title"><div class="modal-head"><div><span class="hc-eyebrow">${asset.kind === 'domain' ? '域名健康详情' : 'IP 访问详情'}</span><h3 id="hc-detail-title">${h(asset.name)}</h3></div><button class="modal-close" data-close-detail aria-label="关闭详情">×</button></div><div class="modal-body"><div class="hc-detail-summary">${pill(health.status)}<span class="muted">${timeText(health.checked_at)}</span></div><p class="hc-detail-message">${h(health.message || '执行一次检查，获取当前状态。')}</p><dl class="hc-facts"><div><dt>来源</dt><dd>${h(sourceText(asset))}</dd></div><div><dt>检查目标</dt><dd>${asset.kind === 'domain' ? 'https://' + h(asset.name) : 'http://' + h(asset.ip.includes(':') ? '[' + asset.ip + ']' : asset.ip) + '/'}</dd></div>${asset.expires_at ? `<div><dt>到期日期</dt><dd>${h(asset.expires_at)}</dd></div>` : ''}<div><dt>解析 IP</dt><dd>${(health.resolved_ips || []).map(h).join('、') || (asset.kind === 'ip' ? h(asset.ip) : '暂无数据')}</dd></div>${asset.kind === 'ip' ? `<div><dt>关联域名</dt><dd>${domainNames(asset).map(h).join('、') || '暂无解析关联'}</dd></div>` : ''}</dl>${asset.kind === 'ip' ? `<h4>用途与备注</h4><p class="asset-note">${h(asset.note) || '暂无备注'}</p><h4>网站链接</h4><div class="asset-site-links">${window.AssetLinks.render(asset.links) || '<span class="muted">暂无网站链接</span>'}</div>` : ''}<h4>检查证据</h4><ul class="hc-check-list">${checks.length ? checks.map(c => `<li data-status="${h(c.status)}"><div><strong>${h(c.label || c.name || c.type || '检查项')}</strong><span>${h(labels[c.status] || ({ok:'正常',fail:'失败',skipped:'未执行',warning:'需关注'})[c.status] || c.status || '')}</span></div><p>${h(c.message || c.error || '—')}</p></li>`).join('') : '<li class="muted">尚无分层检测记录。旧 HTTP 结果仅作为历史参考。</li>'}</ul><h4>最近检查</h4><div class="hc-history">${history.length ? history.slice(0, 10).map(item => `<div><span>${timeText(item.checked_at)}</span>${pill(item.status || item.last_status)}<small>${h(item.message || '')}</small></div>`).join('') : '<p class="muted">执行检查后保存最近 10 次记录。</p>'}</div><p class="hc-footnote">${asset.kind === 'ip' ? 'IP 检查访问 http://IP/，以 HTTP 2xx / 3xx 为正常，不跟随重定向。结果和时间保存在服务器。' : '域名检查使用 HTTPS，并验证 TLS 证书。HTTP 受限或网站未配置可能导致检查异常，不等同于服务器宕机。'} 结果超过 24 小时标记为过时。</p></div><div class="modal-foot"><button class="btn" data-close-detail>关闭</button><button class="btn" data-rel-edit="${h(asset.id)}">编辑${asset.kind === 'domain' ? '域名' : '服务器'}</button>${asset.kind === 'domain' ? `<button class="btn" data-rel-bind="${h(asset.id)}">管理绑定</button>${asset.provider !== 'manual' ? `<button class="btn" data-rel-dns="${h(asset.id)}">管理 DNS</button>` : ''}` : ''}<button class="btn btn-primary" data-health-action="check" data-id="${h(asset.id)}" ${running ? 'disabled' : ''}>重新检查</button></div></section>`;
        overlay.querySelector('.modal-close')?.focus();
    }

    function updateBusy() {
        ['health-check-all', 'ip-check-all'].forEach(id => { if ($('#' + id)) $('#' + id).disabled = running; });
        $$('[data-health-action="check"], [data-health-action="delete"]').forEach(button => { button.disabled = running; });
        $('#ip-open-create').disabled = running;
        if ($('#ip-add-btn')) $('#ip-add-btn').disabled = running;
    }

    async function check(ids, discover = false) {
        if (running) { toast('已有检查正在进行', 'info'); return; }
        const unique = [...new Set(ids)];
        if (!unique.length) { toast('没有可检查的资产', 'info'); return; }
        running = true;
        stopped = false;
        updateBusy();
        const progress = $('#health-progress');
        const slot = state.tab === 'ips' ? $('#ip-progress-slot') : $('#health-progress-slot');
        slot.after(progress);
        progress.classList.remove('hidden');
        let done = 0, abnormal = 0, failed = 0;
        let discovered = false;
        const line = $('#health-progress-text');
        let stop = $('#health-stop');
        if (!stop) {
            stop = document.createElement('button');
            stop.id = 'health-stop'; stop.className = 'btn btn-sm'; stop.type = 'button'; stop.textContent = '停止后续检查';
            stop.addEventListener('click', () => { stopped = true; stop.disabled = true; stop.textContent = '当前批次完成后停止'; });
            progress.appendChild(stop);
        }
        stop.disabled = false; stop.textContent = '停止后续检查';
        try {
            for (let i = 0; i < unique.length && !stopped; i += 2) {
                line.textContent = `正在检查 ${Math.min(i + 1, unique.length)}–${Math.min(i + 2, unique.length)} / ${unique.length} 项，请保持页面打开`;
                $('#health-progress-fill').style.width = `${done / unique.length * 100}%`;
                const batch = unique.slice(i, i + 2);
                try {
                    const response = await api('/api/health/check', { method: 'POST', body: JSON.stringify({ asset_ids: batch }) });
                    abnormal += (response.results || []).filter(r => r.health?.status === 'unhealthy').length;
                } catch (err) {
                    failed += batch.length;
                    toast('本批检查未完成：' + err.message, 'error', 6000);
                    // 网络/认证/存储失败时不继续发送余下任务，也不伪造健康结果。
                    stopped = true;
                }
                done += batch.length;
                await load();
                if (discover && !discovered && i + 2 >= unique.length && !stopped) {
                    discovered = true;
                    const additions = (snapshot?.assets || []).filter(a => a.kind === 'ip' && !unique.includes(a.id));
                    unique.push(...additions.map(a => a.id));
                }
            }
            $('#health-progress-fill').style.width = `${done / unique.length * 100}%`;
            line.textContent = `${stopped ? '检查已停止' : '检查完成'} · 已处理 ${done}/${unique.length} 项 · 检查异常 ${abnormal} 项${failed ? ` · 未完成 ${failed} 项` : ''}`;
            stop.classList.add('hidden');
        } finally {
            running = false;
            updateBusy();
            stop.classList.remove('hidden');
            stop.disabled = true;
            stop.textContent = '本次检查结束';
        }
    }

    async function checkKind(kind) {
        if (!await load()) return;
        if (kind !== 'ip' && state.tab !== 'dashboard') switchTab('dashboard');
        $('#health-kind').value = kind;
        $('#health-status').value = '';
        $('#health-query').value = '';
        renderRows();
        return check(snapshot.assets.filter(a => !kind || a.kind === kind).map(a => a.id));
    }

    document.addEventListener('click', async event => {
        const filter = event.target.closest('[data-filter-category]');
        if (filter) {
            window.WorkspaceUI?.closeDialog();
            $('#health-query').value = '';
            $('#health-kind').value = filter.dataset.filterCategory === 'kind' ? filter.dataset.filter : '';
            $('#health-status').value = filter.dataset.filterCategory === 'status' ? filter.dataset.filter : '';
            renderRows();
            $('#health-table').scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
        const button = event.target.closest('[data-health-action]');
        if (!button) return;
        const id = button.dataset.id;
        switch (button.dataset.healthAction) {
            case 'detail': renderDetail(id); break;
            case 'check': await check([id]); break;
            case 'refresh': await load(); break;
            case 'clear': ['health-query', 'health-kind', 'health-status'].forEach(key => { $('#' + key).value = ''; }); renderRows(); break;
            case 'delete': {
                const asset = snapshot.assets.find(a => a.id === id);
                if (!asset || !await confirmDialog({title:'移除 IP 检查目标',message:`移除 ${asset.ip} 的手工登记？已有 DNS 解析关联和历史证据仍会保留。`,confirmText:'移除',danger:true})) break;
                try { await api('/api/health/ips/' + encodeURIComponent(id), { method:'DELETE' }); toast('已移除手工检查目标'); await load(); }
                catch (err) { toast(err.message, 'error'); }
                break;
            }
        }
    });

    ['health-query', 'health-kind', 'health-status'].forEach(id => $('#' + id).addEventListener(id === 'health-query' ? 'input' : 'change', renderRows));
    $('#ip-query').addEventListener('input', renderIPs);
    ['ip-version','ip-status'].forEach(id=>$('#'+id).addEventListener('change',renderIPs));
    $('#health-refresh').addEventListener('click', () => { window.WorkspaceCache?.clear(); load(true); loadDashboard(); });
    $('#health-check-all').addEventListener('click', async () => { if (await load()) await check(snapshot.assets.map(a => a.id), true); });
    $('#ip-check-all').addEventListener('click', () => checkKind('ip'));
    function openIpCreate() {
        if (running || $('#ip-create-overlay')) return;
        const overlay = document.createElement('div');
        overlay.id = 'ip-create-overlay'; overlay.className = 'modal-overlay';
        overlay.innerHTML = $('#ip-create-template').innerHTML;
        const returnFocus = document.activeElement;
        (document.fullscreenElement || document.body).appendChild(overlay);
        const ipLinks = window.AssetLinks.editor($('#ip-links-editor'));
        let saving = false;
        const close = () => { if (!saving) { overlay.remove(); returnFocus?.focus(); } };
        overlay.querySelector('.modal-close').onclick = close;
        overlay.querySelector('[data-ip-cancel]').onclick = close;
        overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
        overlay.addEventListener('keydown', event => {
            if (event.key === 'Escape') { event.preventDefault(); close(); }
            if (event.key === 'Tab') {
                const items = [...overlay.querySelectorAll('button:not(:disabled),input,textarea')];
                const first = items[0], last = items[items.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
            }
        });
        $('#ip-address').focus();
        $('#ip-add-form').addEventListener('submit', async event => {
            event.preventDefault();
            if (saving) return;
            saving = true;
            const error = $('#ip-add-error');
            error.classList.add('hidden');
            $('#ip-add-btn').disabled = true;
            try {
                await api('/api/health/ips', { method:'POST', body: JSON.stringify({name:$('#ip-name').value.trim(),ip:$('#ip-address').value.trim(),note:$('#ip-note').value.trim(),links:ipLinks.read()}) });
                saving = false;
                close();
                toast('IP 已登记，可检查 HTTP 访问状态');
                await load();
            } catch (err) {
                error.textContent = err.message;
                error.classList.remove('hidden');
            } finally { saving = false; if ($('#ip-add-btn')) $('#ip-add-btn').disabled = false; }
        });
    }
    $('#ip-open-create').addEventListener('click', openIpCreate);
    window.addEventListener('beforeunload', event => { if (running) { event.preventDefault(); event.returnValue = ''; } });
    if (!$('#app-view').classList.contains('hidden')) load();
    return { load, closeDetail, checkIds: check, openDetail: async id => { await load(); renderDetail(id); }, checkDomains: () => checkKind('domain') };
})();
