<?php
/** Offline regressions for the human trend chart and monitoring metrics. Run: php tests/trends_test.php */
require_once __DIR__ . '/../app/services/MetricsService.php';
require_once __DIR__ . '/../app/providers/CloudProvider.php';
require_once __DIR__ . '/../app/core/PrivateJsonStore.php';
require_once __DIR__ . '/../app/core/JsonStore.php';
require_once __DIR__ . '/../app/services/DnsRecordCache.php';
date_default_timezone_set('Asia/Shanghai');

$root = sys_get_temp_dir() . '/workbench-trends-test-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$assertions = 0;
function expect($condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}
function removeTree(string $dir): void
{
    foreach (glob($dir . '/*') as $path) { is_dir($path) ? removeTree($path) : unlink($path); }
    rmdir($dir);
}

try {
    $now = strtotime('2026-11-02 15:00:00');
    $clock = static function () use ($now) { return $now; };
    file_put_contents($root . '/domains.json', json_encode([
        ['domain' => 'trend.example', 'provider' => 'manual'],
    ]));

    $today = date('Y-m-d', $now);
    $yesterday = date('Y-m-d', $now - 86400);
    $old = date('Y-m-d H:i:s', $now - 40 * 86400); // 40 天前：必须在 30 天窗口之外
    $events = [
        ['kind' => 'domain', 'name' => 'trend.example', 'checked_at' => $today . ' 08:00:00', 'status' => 'healthy'],
        ['kind' => 'domain', 'name' => 'trend.example', 'checked_at' => $today . ' 09:00:00', 'status' => 'unhealthy'],
        ['kind' => 'domain', 'name' => 'trend.example', 'checked_at' => $yesterday . ' 10:00:00', 'status' => 'healthy'],
        ['kind' => 'domain', 'name' => 'trend.example', 'checked_at' => $old, 'status' => 'healthy'],
        ['garbage'],
    ];
    $history = [
        ['checked_at' => $today . ' 08:00:00', 'status' => 'healthy', 'latency_ms' => 100],
        ['checked_at' => $today . ' 09:00:00', 'status' => 'unhealthy', 'latency_ms' => 300],
        ['checked_at' => $old, 'status' => 'healthy', 'latency_ms' => 50],
    ];
    file_put_contents($root . '/health.json', json_encode([
        'version' => 1, 'manual_ips' => [], 'check_events' => $events,
        'observations' => ['dom' => ['health' => [], 'history' => $history]],
    ]));
    file_put_contents($root . '/calendar.json', json_encode([
        'runs' => [['id' => 'r1', 'date' => $today, 'counts' => []], ['id' => 'r2', 'date' => date('Y-m-d', $now - 45 * 86400), 'counts' => []]],
    ]));
    (new PrivateJsonStore($root . '/ai_proposals.json'))->update(static function (array $state): array {
        $state['proposals'] = [
            ['id' => 'a', 'status' => 'pending'], ['id' => 'b', 'status' => 'executed'], ['id' => 'c', 'status' => 'failed'], ['id' => 'd', 'status' => 'pending'],
        ];
        return $state;
    });

    $series = (new TrendService($root, $clock))->series();
    expect($series['ok'] === true && count($series['daily']) === 30, 'exactly 30 daily buckets');
    expect($series['daily'][0]['date'] === date('Y-m-d', $now - 29 * 86400) && $series['daily'][29]['date'] === $today, 'buckets run oldest to today');
    $todayRow = $series['daily'][29];
    $yesterdayRow = $series['daily'][28];
    expect($todayRow['checks'] === 2 && $todayRow['healthy'] === 1 && $todayRow['unhealthy'] === 1, 'today bucket counts both statuses');
    expect($yesterdayRow['checks'] === 1 && $yesterdayRow['healthy'] === 1, 'yesterday bucket counted');
    expect(array_sum(array_column($series['daily'], 'checks')) === 3, '40-day-old event stays outside the window');
    expect($todayRow['avg_latency_ms'] === 200, 'daily latency averages the in-window samples');
    expect($todayRow['full_runs'] === 1 && $series['summary']['full_runs_30d'] === 1, 'full-run summaries counted inside the window');
    $summary = $series['summary'];
    expect($summary['checks_30d'] === 3 && $summary['healthy_30d'] === 2 && $summary['unhealthy_30d'] === 1, 'window summary aggregates');
    expect($summary['active_days'] === 2 && $summary['avg_latency_ms'] === 200, 'active days and overall average');
    expect($series['current']['domains'] === 1, 'current snapshot included for chart context');

    // 只读：趋势不创建或修改任何状态文件。
    $before = glob($root . '/*');
    (new TrendService($root, $clock))->series();
    expect(glob($root . '/*') === $before, 'trend reads never create or modify files');

    // Prometheus 指标文本：结构、数值与可抓取性。
    $text = (new MetricsService($root, $clock))->text();
    expect(strpos($text, '# TYPE asset_workspace_assets gauge') !== false, 'metrics declare types');
    expect(preg_match('/^asset_workspace_assets\{kind="domain"\} 1$/m', $text) === 1, 'domain count metric');
    expect(preg_match('/^asset_workspace_domains_pending_https 1$/m', $text) === 1, 'pending https metric counts unconfirmed domains');
    expect(preg_match('/^asset_workspace_proposals\{status="pending"\} 2$/m', $text) === 1, 'proposal metrics use status labels');
    expect(preg_match('/^asset_workspace_checks_30d 3$/m', $text) === 1, '30d checks metric from trend window');
    expect(preg_match('/^asset_workspace_last_checked_timestamp_seconds \d+$/m', $text) === 1, 'last-checked timestamp present');
    foreach (explode("\n", $text) as $line) {
        if ($line === '' || strpos($line, '#') === 0) { continue; }
        expect(preg_match('/^asset_workspace_[a-z0-9_]+(\{[a-z0-9_="-]+\})? \d+$/', $line) === 1, 'every metric line is scrapeable: ' . $line);
    }

    // 损坏文件必须报错，而不是渲染成空图。
    file_put_contents($root . '/health.json', '{broken');
    try {
        (new TrendService($root, $clock))->series();
        expect(false, 'corrupt health.json must throw');
    } catch (RuntimeException $e) {
        expect(true, 'corrupt health.json throws');
    }

    echo 'PASS: ' . $assertions . " trend and metrics assertions\n";
} finally {
    removeTree($root);
}
