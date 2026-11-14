<?php
/**
 * Print the daily domain-screen anomaly report to stdout, ready to paste into
 * an AI assistant or attach to cron mail. Read-only: never triggers checks, syncs
 * or DNS writes. Run with cron or CLI as the user that owns the application data.
 * Options: --prompt emits the ready-to-run analysis prompt wrapping the report;
 *          --digest emits the full plain-Markdown screen snapshot instead.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../app/bootstrap.php';
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
if (in_array('--digest', $argv ?? [], true)) {
    echo ScreenService::markdown(DATA_DIR, config('accounts'));
    exit;
}
$report = ScreenService::report(DATA_DIR, config('accounts'));
echo in_array('--prompt', $argv ?? [], true) ? $report['prompt'] : $report['markdown'];
