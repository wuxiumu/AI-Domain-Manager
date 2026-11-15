<?php
/**
 * 独立日历页（/calendar）：月历打卡、待办任务、完整检查运行记录。
 * 自包含页面：不加载 SPA 的 CSS/JS；由 CalendarController::page() 引入，需登录会话。
 *
 * @var array|null $calendarBootstrap
 */
$boot = is_array($calendarBootstrap ?? null) ? $calendarBootstrap : [];
$assetVer = static function (string $path): string {
    return htmlspecialchars($path) . '?v=' . filemtime(PUBLIC_DIR . $path);
};
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0b1220">
<title>日历与打卡 · 资产大屏</title>
<link rel="icon" type="image/svg+xml" href="/assets/img/asset-workspace-logo.svg">
<link rel="stylesheet" href="<?= $assetVer('/assets/css/admin-page.css') ?>">
<link rel="stylesheet" href="<?= $assetVer('/assets/css/calendar-page.css') ?>">
</head>
<body>
<header class="ap-head">
    <div class="ap-brand">
        <h1>日历与打卡</h1>
        <span class="ap-sub">心情打卡 · 待办记录 · 完整检查记录</span>
    </div>
    <nav class="ap-nav">
        <a href="/screen">首页</a>
        <a class="is-current" href="/calendar">日历</a>
        <a href="/settings">设置</a>
        <a href="/docs">文档</a>
        <button type="button" id="ap-logout" title="退出登录">退出</button>
    </nav>
</header>

<main class="ap-main ap-wide">
    <p id="cl-error" class="ap-error hidden" role="alert"></p>

    <div class="ap-panel cl-month-panel">
        <div class="cl-month">
            <button type="button" class="ap-btn" id="cl-prev" title="上一个月">‹</button>
            <h2 id="cl-month-title">--</h2>
            <button type="button" class="ap-btn" id="cl-next" title="下一个月">›</button>
            <button type="button" class="ap-btn ap-btn-sm" id="cl-today">回到今天</button>
        </div>
    </div>

    <div class="ap-panel cl-cal-panel">
        <div class="cl-weekdays">
            <span>一</span><span>二</span><span>三</span><span>四</span><span>五</span><span>六</span><span>日</span>
        </div>
        <div class="cl-grid" id="cl-grid"></div>
    </div>

    <section class="cl-day-panel">
        <div style="display:flex;flex-direction:column;gap:14px">
            <div class="ap-panel">
                <h2 class="cl-day-title" id="cl-day-title">--</h2>
                <p class="cl-day-caption" id="cl-day-caption"></p>
                <div id="cl-checkin-saved"></div>
                <form id="cl-checkin-form" class="ap-form" hidden>
                    <label style="margin-bottom:6px">今天的心情</label>
                    <div class="cl-moods" role="radiogroup" aria-label="选择心情">
                        <label><input type="radio" name="mood" value="great" required><span class="cl-mood-emoji" aria-hidden="true">😄</span>很开心</label>
                        <label><input type="radio" name="mood" value="good"><span class="cl-mood-emoji" aria-hidden="true">🙂</span>不错</label>
                        <label><input type="radio" name="mood" value="calm"><span class="cl-mood-emoji" aria-hidden="true">😌</span>平静</label>
                        <label><input type="radio" name="mood" value="tired"><span class="cl-mood-emoji" aria-hidden="true">😮‍💨</span>有点累</label>
                        <label><input type="radio" name="mood" value="low"><span class="cl-mood-emoji" aria-hidden="true">🥺</span>低落</label>
                    </div>
                    <label style="margin-bottom:6px">写下一句话 <span class="ap-muted">点短语快速填入</span></label>
                    <div class="cl-quick" aria-label="快捷心情描述">
                        <button type="button" data-cl-quick="今天很开心">😄 今天很开心</button>
                        <button type="button" data-cl-quick="今天不开心">🙁 今天不开心</button>
                        <button type="button" data-cl-quick="今天有点累，早点休息">😮‍💨 有点累</button>
                        <button type="button" data-cl-quick="一切顺利">✅ 一切顺利</button>
                        <button type="button" data-cl-quick="今天有进步">📈 有进步</button>
                        <button type="button" data-cl-quick="保持专注，稳步推进">🎯 保持专注</button>
                    </div>
                    <textarea id="cl-checkin-text" name="text" rows="3" maxlength="280" required placeholder="记录此刻或这一天"></textarea>
                    <p class="ap-help">最多 280 字</p>
                    <div class="ap-actions">
                        <button type="submit" class="ap-btn ap-btn-primary ap-btn-sm" id="cl-checkin-submit">记录此刻</button>
                        <button type="button" class="ap-btn ap-btn-sm hidden" id="cl-checkin-cancel">取消</button>
                    </div>
                </form>
            </div>

            <div class="ap-panel">
                <div class="ap-panel-head"><h2>待办与记录</h2></div>
                <div class="cl-tasks" id="cl-tasks"></div>
                <form id="cl-task-form" class="ap-form" style="margin-top:14px;border-top:1px dashed var(--line);padding-top:12px">
                    <label>标题<input name="title" required maxlength="120" placeholder="例如：检查域名续费"></label>
                    <label>描述<textarea name="description" rows="3" maxlength="2000" placeholder="补充说明、记录或处理步骤"></textarea></label>
                    <div class="ap-actions">
                        <button type="submit" class="ap-btn ap-btn-primary ap-btn-sm">保存待办</button>
                        <button type="button" class="ap-btn ap-btn-sm hidden" id="cl-task-cancel">取消编辑</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="ap-panel">
            <div class="ap-panel-head">
                <h2>完整检查记录</h2>
                <button type="button" class="ap-btn ap-btn-sm" id="cl-run-toggle" style="margin-left:auto">记录本次完整检查</button>
            </div>
            <p class="ap-muted">记录一次全量检查后的数量统计，按天留档。</p>
            <div class="cl-runs" id="cl-runs"></div>
            <form id="cl-run-form" class="cl-run-form ap-form" hidden>
                <div class="ap-row">
                    <div class="cell"><strong>域名</strong><span>正常 / 异常</span>
                        <input name="d_ok" type="number" min="0" value="0" required><input name="d_bad" type="number" min="0" value="0" required style="margin-top:6px">
                    </div>
                    <div class="cell"><strong>IP</strong><span>正常 / 异常</span>
                        <input name="i_ok" type="number" min="0" value="0" required><input name="i_bad" type="number" min="0" value="0" required style="margin-top:6px">
                    </div>
                    <div class="cell"><strong>子域名</strong><span>正常 / 异常</span>
                        <input name="s_ok" type="number" min="0" value="0" required><input name="s_bad" type="number" min="0" value="0" required style="margin-top:6px">
                    </div>
                </div>
                <div class="ap-actions">
                    <button type="submit" class="ap-btn ap-btn-primary ap-btn-sm">保存记录</button>
                </div>
            </form>
        </div>
    </section>
</main>

<footer class="ap-foot">资产大屏 · 日历与打卡</footer>
<div class="ap-toast" id="ap-toast" role="status"></div>

<script>
window.__ADMIN__ = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= $assetVer('/assets/js/admin-page.js') ?>"></script>
<script src="<?= $assetVer('/assets/js/calendar-page.js') ?>"></script>
</body>
</html>
