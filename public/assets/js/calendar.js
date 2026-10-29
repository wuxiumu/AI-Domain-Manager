'use strict';
window.WorkspaceCalendar = (() => {
    const localDate = date => [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
    let selected = localDate(new Date()), data = {tasks: [], runs: [], events: [], checkins: []}, editing = null, generation = 0, checkinDirty = false, checkinEditing = false;
    const moods = {great: ['😄', '很开心'], good: ['🙂', '不错'], calm: ['😌', '平静'], tired: ['😮‍💨', '有点累'], low: ['🥺', '低落']};
    const esc = value => escapeHtml(value ?? '');
    function error(message = '') { $('#calendar-error').textContent = message; $('#calendar-error').classList.toggle('hidden', !message); }
    function resetForm() { editing = null; $('#calendar-task-form').reset(); $('#calendar-cancel').classList.add('hidden'); }
    async function load() {
        const current = ++generation, month = selected.slice(0, 7);
        error();
        try { const next = await api('/api/calendar?month=' + encodeURIComponent(month), {cache: 'no-store'}); if (current !== generation) return; data = next; render(); }
        catch (e) { if (current === generation) error(e.message); }
    }
    function render() {
        const [year, month] = selected.split('-').map(Number), first = new Date(year, month - 1, 1), count = new Date(year, month, 0).getDate();
        $('#calendar-month').textContent = `${year} 年 ${month} 月`;
        let html = '<span class="calendar-blank"></span>'.repeat((first.getDay() + 6) % 7);
        for (let day = 1; day <= count; day++) {
            const date = `${year}-${String(month).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
            const runs = (data.events || []).filter(item => (item.checked_at || '').slice(0, 10) === date).length, tasks = data.tasks.filter(item => item.date === date).length, checkin = (data.checkins || []).find(item => item.date === date), mood = moods[checkin?.mood];
            html += `<button type="button" class="calendar-date ${date === localDate(new Date()) ? 'is-today' : ''}" data-calendar-date="${date}" aria-pressed="${date === selected}" aria-label="${date}，${mood ? '已打卡，' + mood[1] + '，' : ''}${runs} 次检查，${tasks} 个待办"><span class="calendar-date-top"><strong>${day}</strong>${mood ? `<span class="calendar-date-mood" aria-hidden="true">${mood[0]}</span>` : ''}</span><small>${runs ? `${runs} 检查` : ''}${runs && tasks ? ' · ' : ''}${tasks ? `${tasks} 待办` : ''}</small></button>`;
        }
        $('#calendar-grid').innerHTML = html;
        $('#calendar-day-title').textContent = selected;
        const weekday = ['星期日','星期一','星期二','星期三','星期四','星期五','星期六'][new Date(year, month - 1, Number(selected.slice(8))).getDay()];
        $('#calendar-day-caption').textContent = selected === localDate(new Date()) ? '今天 · ' + weekday : weekday;
        renderCheckin();
        const runs = data.runs.filter(item => item.date === selected), tasks = data.tasks.filter(item => item.date === selected), events = (data.events || []).filter(item => (item.checked_at || '').slice(0,10) === selected);
        const overview = data.overview || {};
        $('#calendar-overview').innerHTML = ['domain','ip','subdomain'].map(kind => { const c = overview[kind]; if (!c) return ''; const label = {domain:'主域名',ip:'IP / 服务器访问',subdomain:'可检查子域名'}[kind]; return `<div class="calendar-overview-line"><strong>${label} ${c.total}</strong><span>正常 ${c.healthy} · 异常 ${c.unhealthy} · 过期 ${c.stale} · 未检查 ${c.unchecked}</span></div>`; }).join('') + `<p class="form-help">这是当前缓存状态，并非所选日期的历史统计。${data.dns_uncached_domains ? '另有 ' + Number(data.dns_uncached_domains) + ' 个主域名无 DNS 快照，子域名数量未完整。' : ''}</p>`;
        $('#calendar-runs').innerHTML = runs.length ? runs.map(run => {
            const c = run.counts, count = x => `${x.healthy} 正常 / ${x.healthy + x.unhealthy} 项`;
            return `<article class="calendar-run"><strong>${esc(new Date(run.completed_at).toLocaleTimeString('zh-CN', {hour:'2-digit', minute:'2-digit'}))} · 全局检查完成</strong><span>域名 ${count(c.domain)}　IP ${count(c.ip)}　子域名 ${count(c.subdomain)}</span></article>`;
        }).join('') : '<p class="muted">当天暂无完整全局检查记录。</p>';
        $('#calendar-events').innerHTML = events.length ? events.map(event => {
            const kind = {domain:'主域名',ip:'IP',subdomain:'子域名'}[event.kind] || '资产';
            const status = event.status === 'healthy' ? '正常' : '异常';
            const checks = (event.checks || []).map(check => `<li>${esc(check.label || '检查')}：${esc(check.message || check.status || '—')}</li>`).join('');
            return `<details class="calendar-event"><summary><strong>${esc(event.name)}</strong> <span>${kind} · ${status} · ${esc((event.checked_at || '').slice(11,19))}</span></summary><p>${esc(event.message)}</p>${checks ? `<ul>${checks}</ul>` : ''}</details>`;
        }).join('') : '<p class="muted">当天暂无检查明细。</p>';
        $('#calendar-tasks').innerHTML = tasks.length ? tasks.map(task => `<article class="calendar-task"><label><input type="checkbox" data-calendar-toggle="${esc(task.id)}" ${task.done ? 'checked' : ''}><strong>${esc(task.title)}</strong></label>${task.description ? `<p>${esc(task.description)}</p>` : ''}<div><button type="button" class="btn btn-sm" data-calendar-edit="${esc(task.id)}">编辑</button> <button type="button" class="btn btn-sm" data-calendar-delete="${esc(task.id)}">删除</button></div></article>`).join('') : '<p class="muted">还没有待办，添加一条记录吧。</p>';
    }
    function renderCheckin() {
        const saved = (data.checkins || []).find(item => item.date === selected), mood = saved && moods[saved.mood];
        const display = $('#calendar-checkin-saved'), form = $('#calendar-checkin-form');
        display.innerHTML = saved && mood ? `<div class="calendar-checkin-entry"><div class="calendar-entry-mood"><span aria-hidden="true">${mood[0]}</span><div><strong>${mood[1]}</strong><small>这一天的心情</small></div></div><p>${esc(saved.text)}</p><div class="calendar-entry-actions"><button type="button" class="btn btn-sm" data-checkin-edit>编辑</button><button type="button" class="btn btn-sm" data-checkin-delete>删除</button></div></div>` : '';
        display.hidden = !saved || checkinEditing;
        form.hidden = !!saved && !checkinEditing;
        $('#calendar-checkin-cancel').classList.toggle('hidden', !saved || !checkinEditing);
        $('#calendar-checkin-submit').textContent = saved ? '保存修改' : '记录此刻';
        if (!checkinDirty && !form.hidden) {
            form.reset();
            if (saved && checkinEditing) { form.elements.mood.value = saved.mood; form.elements.text.value = saved.text; }
        }
    }
    function resetCheckin() { checkinDirty = false; checkinEditing = false; }
    function changeMonth(offset) { const [year, month] = selected.split('-').map(Number); selected = localDate(new Date(year, month - 1 + offset, 1)); resetForm(); resetCheckin(); load(); }
    $('#calendar-prev').addEventListener('click', () => changeMonth(-1));
    $('#calendar-next').addEventListener('click', () => changeMonth(1));
    $('#calendar-today').addEventListener('click', () => { selected = localDate(new Date()); resetForm(); resetCheckin(); load(); });
    $('#calendar-grid').addEventListener('click', event => { const button = event.target.closest('[data-calendar-date]'); if (button && button.dataset.calendarDate !== selected) { selected = button.dataset.calendarDate; resetForm(); resetCheckin(); render(); } });
    const tabs = [...document.querySelectorAll('[data-calendar-tab]')];
    function selectTab(name, focus = false) {
        tabs.forEach(tab => { const active = tab.dataset.calendarTab === name; tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1; if (active && focus) tab.focus(); });
        document.querySelectorAll('[data-calendar-panel]').forEach(panel => { panel.hidden = panel.dataset.calendarPanel !== name; });
    }
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => selectTab(tab.dataset.calendarTab));
        tab.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault(); const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
            selectTab(tabs[next].dataset.calendarTab, true);
        });
    });
    $('#calendar-checkin-form').addEventListener('input', () => { checkinDirty = true; });
    $('#calendar-checkin-form').addEventListener('submit', async event => {
        event.preventDefault(); const form = event.currentTarget, button = $('#calendar-checkin-submit'), date = selected; button.disabled = true; error();
        try { await api('/api/calendar/checkins/' + encodeURIComponent(date), {method: 'PUT', body: JSON.stringify({mood: form.elements.mood.value, text: form.elements.text.value})}); if (selected === date) resetCheckin(); await load(); }
        catch (e) { error(e.message); } finally { button.disabled = false; }
    });
    $('#calendar-checkin-cancel').addEventListener('click', () => { resetCheckin(); renderCheckin(); });
    $('#calendar-checkin-saved').addEventListener('click', async event => {
        if (event.target.closest('[data-checkin-edit]')) { checkinEditing = true; checkinDirty = false; renderCheckin(); $('#calendar-checkin-text').focus(); }
        if (event.target.closest('[data-checkin-delete]')) {
            const date = selected; if (!await confirmDialog({title:'删除打卡记录', message:'确定删除这一天的心情和一句话？', danger:true, confirmText:'删除'})) return;
            try { await api('/api/calendar/checkins/' + encodeURIComponent(date), {method:'DELETE'}); if (selected === date) resetCheckin(); await load(); }
            catch (e) { error(e.message); }
        }
    });
    $('#calendar-cancel').addEventListener('click', resetForm);
    $('#calendar-task-form').addEventListener('submit', async event => {
        event.preventDefault(); const form = event.currentTarget, button = form.querySelector('[type=submit]'); button.disabled = true; error();
        try { const task = editing && data.tasks.find(item => item.id === editing); await api('/api/calendar/tasks' + (editing ? '/' + encodeURIComponent(editing) : ''), {method: editing ? 'PUT' : 'POST', body: JSON.stringify({date: selected, title: form.elements.title.value, description: form.elements.description.value, done: task?.done || false})}); resetForm(); await load(); }
        catch (e) { error(e.message); } finally { button.disabled = false; }
    });
    $('#calendar-tasks').addEventListener('change', async event => {
        const input = event.target.closest('[data-calendar-toggle]'); if (!input) return;
        const task = data.tasks.find(item => item.id === input.dataset.calendarToggle); if (!task) return;
        input.disabled = true;
        try { await api('/api/calendar/tasks/' + encodeURIComponent(task.id), {method:'PUT', body:JSON.stringify({...task, done:input.checked})}); await load(); }
        catch (e) { input.checked = task.done; error(e.message); } finally { input.disabled = false; }
    });
    $('#calendar-tasks').addEventListener('click', async event => {
        const edit = event.target.closest('[data-calendar-edit]'), del = event.target.closest('[data-calendar-delete]');
        if (edit) { const task = data.tasks.find(item => item.id === edit.dataset.calendarEdit); if (!task) return; editing = task.id; const form = $('#calendar-task-form'); form.elements.title.value = task.title; form.elements.description.value = task.description; $('#calendar-cancel').classList.remove('hidden'); form.elements.title.focus(); }
        if (del) { const task = data.tasks.find(item => item.id === del.dataset.calendarDelete); if (!task || !await confirmDialog({title:'删除待办', message:'确定删除这条待办及描述？', danger:true, confirmText:'删除'})) return; del.disabled = true; try { await api('/api/calendar/tasks/' + encodeURIComponent(task.id), {method:'DELETE'}); resetForm(); await load(); } catch (e) { error(e.message); del.disabled = false; } }
    });
    function leave() {
        generation++;
        data = {tasks: [], runs: [], events: [], checkins: []};
        selected = localDate(new Date());
        resetForm(); resetCheckin();
        $('#calendar-checkin-form').reset();
        ['calendar-checkin-saved', 'calendar-tasks', 'calendar-events', 'calendar-runs', 'calendar-overview', 'calendar-grid'].forEach(id => { $('#' + id).innerHTML = ''; });
        error();
        selectTab('daily');
    }
    return {load, leave, open: () => { selectTab('daily'); return load(); }};
})();
