<?php
/**
 * Print the cached-evidence Markdown health report to stdout, ready to paste into
 * an AI assistant or attach to cron mail. Read-only: never triggers checks or syncs.
 * Run with cron or CLI as the user that owns the application data.
 * Use --prompt to emit the full ready-to-run analysis prompt wrapping the report.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../app/bootstrap.php';
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
$report = (new AiReportService())->report();
echo in_array('--prompt', $argv ?? [], true) ? $report['prompt'] : $report['markdown'];
