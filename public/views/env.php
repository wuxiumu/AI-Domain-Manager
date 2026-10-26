<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>环境检查 · 域名管家</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif;
    background: #f1f5f9; color: #0f172a; padding: 28px 16px; line-height: 1.6;
}
.wrap { max-width: 780px; margin: 0 auto; }
h1 { font-size: 20px; margin-bottom: 4px; }
.sub { color: #64748b; font-size: 13px; margin-bottom: 20px; }
.meta {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
    padding: 14px 18px; margin-bottom: 16px; font-size: 13px; display: flex; flex-wrap: wrap; gap: 18px;
}
.meta b { color: #2563eb; }
.card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; margin-bottom: 16px;
}
.card-head { padding: 12px 18px; border-bottom: 1px solid #e2e8f0; font-weight: 600; font-size: 14px; }
.row {
    display: flex; gap: 12px; padding: 11px 18px; border-bottom: 1px solid #f1f5f9;
    font-size: 13px; align-items: flex-start;
}
.row:last-child { border-bottom: none; }
.icon { flex-shrink: 0; font-weight: 700; width: 18px; text-align: center; }
.ok { color: #16a34a; }
.fail { color: #dc2626; }
.warn { color: #d97706; }
.info { color: #2563eb; }
.row-name { flex-shrink: 0; width: 150px; font-weight: 500; }
.row-detail { color: #475569; word-break: break-all; }
.tips { background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 14px 18px; font-size: 13px; color: #78350f; }
.tips h3 { font-size: 13px; margin-bottom: 6px; }
.tips ul { padding-left: 18px; }
.tips li { margin: 3px 0; }
code { background: #f1f5f9; padding: 1px 5px; border-radius: 4px; font-size: 12px; }
</style>
</head>
<body>
<div class="wrap">
    <h1>🔍 环境检查</h1>
    <p class="sub" id="loading">正在检查…</p>
    <div class="meta" id="meta"></div>
    <div class="card">
        <div class="card-head">检查项</div>
        <div id="checks"></div>
    </div>
    <div class="tips">
        <h3>常见部署问题速查</h3>
        <ul>
            <li><b>白屏/500</b>：多为 PHP 版本低于 7.4 或扩展缺失，先看上方「PHP 版本 / 扩展」两项，再到主机面板升级 PHP。</li>
            <li><b>登录后跳回登录页</b>：请维护人员检查 PHP Session 存储权限与 HTTPS Cookie 配置。</li>
            <li><b>页面没样式/按钮无响应</b>：确认网站根目录严格指向项目 public 目录，静态文件可访问。</li>
            <li><b>首次使用</b>：回到工作台初始化管理员，再检查网络与云账号配置。</li>
            <li>仍无法定位时，请维护人员查看服务器错误日志；生产环境保持 display_errors 关闭。</li>
        </ul>
    </div>
</div>
<script>
'use strict';
fetch('/api/env-check')
    .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.error || '请先登录管理员'); return d; }); })
    .then(function (d) {
        document.getElementById('loading').textContent = d.time ? '检查完成于 ' + d.time : '安装环境基础检查；初始化后可查看交付自检';
        document.getElementById('meta').textContent = '详细检查仅管理员会话可读取，不公开服务器路径与配置。';
        var el = document.getElementById('checks');
        el.innerHTML = (d.checks || []).map(function (c) {
            var icon = c.status === 'ok' ? '<span class="icon ok">✓</span>'
                : c.status === 'fail' ? '<span class="icon fail">✗</span>'
                : c.status === 'warn' ? '<span class="icon warn">!</span>'
                : '<span class="icon info">i</span>';
            return '<div class="row">' + icon + '<span class="row-name">' + escapeHtml(c.name) + '</span>' +
                '<span class="row-detail">' + escapeHtml(c.detail) + '</span></div>';
        }).join('');
    })
    .catch(function (e) {
        document.getElementById('loading').textContent = '环境检查接口调用失败：' + e.message;
        document.getElementById('loading').style.color = '#dc2626';
    });
function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
</script>
</body>
</html>
