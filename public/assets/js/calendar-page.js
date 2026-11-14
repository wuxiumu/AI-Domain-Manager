/**
 * 独立日历页（/calendar）：月历打卡（心情+一句话）、待办任务、完整检查运行记录。
 * 自包含模块；交互移植自原 SPA calendar.js（去掉健康栈相关的资产概况/检查明细），使用 AdminPage 共享工具。
 */
(function () {
'use strict';

var A = window.AdminPage;
var esc = A.escapeHtml, api = A.fetchJson, toast = A.toast, confirmDialog = A.confirmDialog;

function $(id) { return document.getElementById(id); }
function error(message) {
    var el = $('cl-error');
    el.textContent = message || '';
    el.classList.toggle('hidden', !message);
}

var moods = { great: ['😄', '很开心'], good: ['🙂', '不错'], calm: ['😌', '平静'], tired: ['😮‍💨', '有点累'], low: ['🥺', '低落'] };
var localDate = function (date) {
    return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
};

var selected = localDate(new Date());
var data = { tasks: [], checkins: [], runs: [] };
var editing = null, generation = 0, checkinDirty = false, checkinEditing = false;

function resetForm() { editing = null; $('cl-task-form').reset(); $('cl-task-cancel').classList.add('hidden'); }
function resetCheckin() { checkinDirty = false; checkinEditing = false; }

function load() {
    var current = ++generation;
    error();
    api('/api/calendar?month=' + encodeURIComponent(selected.slice(0, 7))).then(function (next) {
        if (current !== generation) { return; }
        data = { tasks: next.tasks || [], checkins: next.checkins || [], runs: next.runs || [] };
        render();
    }).catch(function (e) { if (current === generation) { error(e.message); } });
}

function render() {
    var parts = selected.split('-').map(Number), year = parts[0], month = parts[1];
    var first = new Date(year, month - 1, 1), count = new Date(year, month, 0).getDate();
    $('cl-month-title').textContent = year + ' 年 ' + month + ' 月';

    var html = '<span class="cl-day is-blank"></span>'.repeat((first.getDay() + 6) % 7);
    for (var day = 1; day <= count; day++) {
        var date = year + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
        var checkin = data.checkins.find(function (item) { return item.date === date; });
        var mood = checkin && moods[checkin.mood];
        var tasks = data.tasks.filter(function (item) { return item.date === date; }).length;
        var runs = data.runs.filter(function (item) { return item.date === date; }).length;
        var meta = [];
        if (runs) { meta.push(runs + ' 检查'); }
        if (tasks) { meta.push(tasks + ' 待办'); }
        html += '<button type="button" class="cl-day' + (date === localDate(new Date()) ? ' is-today' : '') + '"' +
            ' data-cl-date="' + date + '" aria-pressed="' + String(date === selected) + '"' +
            ' aria-label="' + date + (mood ? '，已打卡，' + mood[1] : '') + (tasks ? '，' + tasks + ' 个待办' : '') + '">' +
            '<span class="cl-day-top"><strong class="cl-day-num">' + day + '</strong>' +
            (mood ? '<span class="cl-day-mood" aria-hidden="true">' + mood[0] + '</span>' : '') + '</span>' +
            '<span class="cl-day-meta">' + meta.join(' · ') + '</span></button>';
    }
    $('cl-grid').innerHTML = html;
    $('cl-day-title').textContent = selected;
    var dayNum = Number(selected.slice(8));
    var weekday = ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'][new Date(year, month - 1, dayNum).getDay()];
    $('cl-day-caption').textContent = (selected === localDate(new Date()) ? '今天 · ' : '') + weekday;
    renderCheckin();
    renderTasks();
    renderRuns();
}

function renderCheckin() {
    var saved = data.checkins.find(function (item) { return item.date === selected; });
    var mood = saved && moods[saved.mood];
    var display = $('cl-checkin-saved'), form = $('cl-checkin-form');
    display.innerHTML = saved && mood
        ? '<div class="cl-checkin-entry"><span class="cl-entry-emoji" aria-hidden="true">' + mood[0] + '</span><div><strong>' + mood[1] + '</strong><small>这一天的心情</small><p>' + esc(saved.text) + '</p>' +
            '<div class="ap-actions"><button type="button" class="ap-btn ap-btn-sm" data-checkin-edit>编辑</button>' +
            '<button type="button" class="ap-btn ap-btn-sm" data-checkin-delete>删除</button></div></div></div>'
        : '';
    display.hidden = !saved || checkinEditing;
    form.hidden = !!saved && !checkinEditing;
    $('cl-checkin-cancel').classList.toggle('hidden', !saved || !checkinEditing);
    $('cl-checkin-submit').textContent = saved ? '保存修改' : '记录此刻';
    if (!checkinDirty && !form.hidden) {
        form.reset();
        if (saved && checkinEditing) { form.elements.mood.value = saved.mood; form.elements.text.value = saved.text; }
    }
}

function renderTasks() {
    var tasks = data.tasks.filter(function (item) { return item.date === selected; });
    $('cl-tasks').innerHTML = tasks.length ? tasks.map(function (task) {
        return '<article class="cl-task' + (task.done ? ' done' : '') + '">' +
            '<label class="cl-task-label"><input type="checkbox" data-cl-task="' + esc(task.id) + '"' + (task.done ? ' checked' : '') + '><strong>' + esc(task.title) + '</strong></label>' +
            (task.description ? '<p>' + esc(task.description) + '</p>' : '') +
            '<div class="ap-actions"><button type="button" class="ap-btn ap-btn-sm" data-cl-edit="' + esc(task.id) + '">编辑</button>' +
            '<button type="button" class="ap-btn ap-btn-sm" data-cl-delete="' + esc(task.id) + '">删除</button></div></article>';
    }).join('') : '<p style="color:var(--muted);font-size:12.5px">这一天还没有待办。</p>';
}

function renderRuns() {
    var runs = data.runs.filter(function (item) { return item.date === selected; });
    $('cl-runs').innerHTML = runs.length ? runs.map(function (run) {
        var c = run.counts;
        var count = function (x) { return x.healthy + ' 正常 / ' + (x.healthy + x.unhealthy) + ' 项'; };
        return '<article class="cl-run"><strong>' + esc(new Date(run.completed_at).toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' })) + ' · 完整检查记录</strong>' +
            '<span>域名 ' + count(c.domain) + '　IP ' + count(c.ip) + '　子域名 ' + count(c.subdomain) + '</span></article>';
    }).join('') : '<p style="color:var(--muted);font-size:12.5px">当天暂无完整检查记录。</p>';
}

/* ---- 月份导航与日期选择 ---- */

function changeMonth(offset) {
    var parts = selected.split('-').map(Number);
    selected = localDate(new Date(parts[0], parts[1] - 1 + offset, 1));
    resetForm(); resetCheckin(); load();
}
$('cl-prev').addEventListener('click', function () { changeMonth(-1); });
$('cl-next').addEventListener('click', function () { changeMonth(1); });
$('cl-today').addEventListener('click', function () { selected = localDate(new Date()); resetForm(); resetCheckin(); load(); });
$('cl-grid').addEventListener('click', function (event) {
    var button = event.target.closest('[data-cl-date]');
    if (button && button.dataset.clDate !== selected) {
        selected = button.dataset.clDate;
        resetForm(); resetCheckin(); render();
    }
});

/* ---- 打卡 ---- */

$('cl-checkin-form').addEventListener('input', function () { checkinDirty = true; });

// 快捷心情短语：点击填入一句话（已有内容时追加，逗号衔接）
document.querySelectorAll('[data-cl-quick]').forEach(function (button) {
    button.addEventListener('click', function () {
        var textarea = $('cl-checkin-text');
        var phrase = button.dataset.clQuick;
        textarea.value = textarea.value.trim() ? textarea.value.trim() + '，' + phrase : phrase;
        textarea.dispatchEvent(new Event('input', { bubbles: true })); // 标记 dirty
        textarea.focus();
    });
});

$('cl-checkin-form').addEventListener('submit', function (event) {
    event.preventDefault();
    var form = event.currentTarget, button = $('cl-checkin-submit'), date = selected;
    button.disabled = true; error();
    api('/api/calendar/checkins/' + encodeURIComponent(date), { method: 'PUT', body: { mood: form.elements.mood.value, text: form.elements.text.value } })
        .then(function () { if (selected === date) { resetCheckin(); } return load(); })
        .catch(function (e) { error(e.message); })
        .finally(function () { button.disabled = false; });
});
$('cl-checkin-cancel').addEventListener('click', function () { resetCheckin(); renderCheckin(); });
$('cl-checkin-saved').addEventListener('click', function (event) {
    if (event.target.closest('[data-checkin-edit]')) {
        checkinEditing = true; checkinDirty = false; renderCheckin(); $('cl-checkin-text').focus();
    }
    if (event.target.closest('[data-checkin-delete]')) {
        var date = selected;
        confirmDialog({ title: '删除打卡记录', message: '确定删除这一天的心情和一句话？', danger: true, confirmText: '删除' })
            .then(function (ok) {
                if (!ok) { return; }
                api('/api/calendar/checkins/' + encodeURIComponent(date), { method: 'DELETE' })
                    .then(function () { if (selected === date) { resetCheckin(); } return load(); })
                    .catch(function (e) { error(e.message); });
            });
    }
});

/* ---- 待办 ---- */

$('cl-task-cancel').addEventListener('click', resetForm);
$('cl-task-form').addEventListener('submit', function (event) {
    event.preventDefault();
    var form = event.currentTarget, button = form.querySelector('[type="submit"]');
    button.disabled = true; error();
    var existing = editing && data.tasks.find(function (item) { return item.id === editing; });
    api('/api/calendar/tasks' + (editing ? '/' + encodeURIComponent(editing) : ''), {
        method: editing ? 'PUT' : 'POST',
        body: { date: selected, title: form.elements.title.value, description: form.elements.description.value, done: (existing && existing.done) || false },
    }).then(function () { resetForm(); return load(); })
      .catch(function (e) { error(e.message); })
      .finally(function () { button.disabled = false; });
});
$('cl-tasks').addEventListener('change', function (event) {
    var input = event.target.closest('[data-cl-task]');
    if (!input) { return; }
    var task = data.tasks.find(function (item) { return item.id === input.dataset.clTask; });
    if (!task) { return; }
    input.disabled = true;
    api('/api/calendar/tasks/' + encodeURIComponent(task.id), { method: 'PUT', body: { date: task.date, title: task.title, description: task.description, done: input.checked } })
        .then(function () { return load(); })
        .catch(function (e) { input.checked = task.done; error(e.message); })
        .finally(function () { input.disabled = false; });
});
$('cl-tasks').addEventListener('click', function (event) {
    var edit = event.target.closest('[data-cl-edit]');
    var del = event.target.closest('[data-cl-delete]');
    if (edit) {
        var task = data.tasks.find(function (item) { return item.id === edit.dataset.clEdit; });
        if (!task) { return; }
        editing = task.id;
        var form = $('cl-task-form');
        form.elements.title.value = task.title;
        form.elements.description.value = task.description;
        $('cl-task-cancel').classList.remove('hidden');
        form.elements.title.focus();
    }
    if (del) {
        var task = data.tasks.find(function (item) { return item.id === del.dataset.clDelete; });
        if (!task) { return; }
        confirmDialog({ title: '删除待办', message: '确定删除这条待办及描述？', danger: true, confirmText: '删除' })
            .then(function (ok) {
                if (!ok) { return; }
                del.disabled = true;
                api('/api/calendar/tasks/' + encodeURIComponent(task.id), { method: 'DELETE' })
                    .then(function () { resetForm(); return load(); })
                    .catch(function (e) { error(e.message); del.disabled = false; });
            });
    }
});

/* ---- 完整检查运行记录 ---- */

$('cl-run-toggle').addEventListener('click', function () {
    var form = $('cl-run-form');
    form.hidden = !form.hidden;
    if (!form.hidden) { form.querySelector('input').focus(); }
});
$('cl-run-form').addEventListener('submit', function (event) {
    event.preventDefault();
    var form = event.currentTarget, button = form.querySelector('[type="submit"]');
    button.disabled = true; error();
    var num = function (name) { return Math.max(0, parseInt(form.elements[name].value, 10) || 0); };
    var counts = {
        domain: { healthy: num('d_ok'), unhealthy: num('d_bad') },
        ip: { healthy: num('i_ok'), unhealthy: num('i_bad') },
        subdomain: { healthy: num('s_ok'), unhealthy: num('s_bad') },
    };
    api('/api/calendar/runs', { method: 'POST', body: { counts: counts } })
        .then(function () { form.reset(); form.hidden = true; return load(); })
        .then(function () { toast('已记录本次完整检查'); })
        .catch(function (e) { error(e.message); })
        .finally(function () { button.disabled = false; });
});

/* ---- 初始化 ---- */

load();
})();
