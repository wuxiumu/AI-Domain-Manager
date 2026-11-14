<?php

/**
 * 大屏展示：独立全屏页面（管理员会话）与聚合数据端点（会话 / assets:read 密钥）。
 * 数据一律来自 ScreenService 快照；刷新走 POST /api/accounts/{id}/sync 显式触发。
 */
class ScreenController
{
    public function page(): void
    {
        Auth::requireSession();
        header('Cache-Control: no-store');
        $screenBootstrap = [
            'csrf' => Auth::csrfToken(),
            'user' => (string) Auth::user(),
        ];
        require PUBLIC_DIR . '/views/screen.php';
    }

    public function index(): void
    {
        Auth::requireLogin();
        // 台账、解析快照或 5 分钟时间桶未变化时直接 304。
        // ?refresh=1 手动刷新：跳过快照 TTL 立即重建（数据未变时 304 仍生效）。
        conditional_get(asset_etag((string) ($_SERVER['QUERY_STRING'] ?? '')));
        $force = ($_GET['refresh'] ?? '') === '1';
        json_out(['ok' => true] + ScreenService::snapshot(DATA_DIR, config('accounts'), $force));
    }

    /** 纯 Markdown 快照（screen.md）：一份自包含文档，AI 可读清单并按指引执行 CRUD。 */
    public function markdown(): void
    {
        Auth::requireLogin();
        conditional_get(asset_etag((string) ($_SERVER['QUERY_STRING'] ?? '')));
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: inline; filename="screen.md"');
        header('Cache-Control: no-store');
        echo ScreenService::markdown(DATA_DIR, config('accounts'));
    }

    /** 每日异常报告（AI 每日分析入口）；?format=markdown 直出正文，便于 cron 或脚本投喂。 */
    public function report(): void
    {
        Auth::requireLogin();
        $report = ScreenService::report(DATA_DIR, config('accounts'));
        $format = strtolower(trim((string) ($_GET['format'] ?? '')));
        if ($format === 'markdown' || $format === 'md') {
            // GET 请求不落操作日志；纯文本报告可直接输出。
            header('Content-Type: text/markdown; charset=utf-8');
            header('Content-Disposition: inline; filename="screen-report.md"');
            header('Cache-Control: no-store');
            echo $report['markdown'];
            return;
        }
        json_out($report);
    }
}
