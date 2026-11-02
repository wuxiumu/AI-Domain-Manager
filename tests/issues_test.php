<?php
/** Offline regressions for the unified triage issue list. Run: php tests/issues_test.php */
require_once __DIR__ . '/../app/services/IssueService.php';
date_default_timezone_set('Asia/Shanghai');

$root = sys_get_temp_dir() . '/workbench-issues-test-' . bin2hex(random_bytes(6));
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
    $now = strtotime('2026-11-02 16:00:00');
    $clock = static function () use ($now) { return $now; };
    file_put_contents($root . '/domains.json', json_encode([
        ['domain' => 'broken.example', 'provider' => 'cloud_1', 'provider_name' => '示例云'],
        ['domain' => 'pending.example', 'provider' => 'manual'],
        ['domain' => 'old.example', 'provider' => 'manual'],
        ['domain' => 'fine.example', 'provider' => 'manual'],
        ['domain' => 'expiring.example', 'provider' => 'manual', 'expire_at' => date('Y-m-d', $now + 8 * 86400)],
        ['domain' => 'drift.example', 'provider' => 'manual'],
    ]));
    $state = [
        'version' => 1, 'manual_ips' => [], 'check_events' => [],
        'observations' => [
            // P0：检查异常，带消息与解析 IP。
            'broken' => ['health' => ['status' => 'unhealthy', 'checked_at' => date('Y-m-d H:i:s', $now - 3600),
                'message' => 'HTTPS 证书已过期', 'resolved_ips' => ['8.8.8.8', '9.9.9.9'], 'protocols' => ['https' => ['status' => 'unhealthy']]], 'history' => []],
            // P1：HTTPS 未确认（未检查过的手工域名）。
            'pending' => ['health' => ['status' => 'unchecked', 'checked_at' => null, 'message' => '尚未检测', 'resolved_ips' => [], 'protocols' => []], 'history' => []],
            // P1：结果过时的健康 HTTPS（超过 24 小时）。
            'old' => ['health' => ['status' => 'healthy', 'checked_at' => date('Y-m-d H:i:s', $now - 3 * 86400),
                'message' => '正常', 'resolved_ips' => ['1.1.1.1'], 'protocols' => ['https' => ['status' => 'healthy']]], 'history' => []],
            // 健康域名：不出现。
            'fine' => ['health' => ['status' => 'healthy', 'checked_at' => date('Y-m-d H:i:s', $now - 600),
                'message' => '正常', 'resolved_ips' => ['1.0.0.1'], 'protocols' => ['https' => ['status' => 'healthy']]], 'history' => []],
            // DNS 已更新：HTTPS 未确认 + 结果过时，只保留一条 https_pending。
            'drift' => ['health' => ['status' => 'stale', 'invalidated' => true, 'checked_at' => date('Y-m-d H:i:s', $now - 2 * 86400),
                'message' => 'DNS 已更新，请重新检查；上次结果：正常', 'resolved_ips' => [], 'protocols' => ['https' => ['status' => 'healthy']]], 'history' => []],
        ],
    ];
    // 观测必须挂在真实资产 ID 上：先建空看板取回 ID 映射，再写回观测。
    file_put_contents($root . '/health.json', json_encode(['version' => 1, 'manual_ips' => [], 'observations' => [], 'check_events' => []]));
    $ids = [];
    foreach ((new HealthService($root, null, $clock))->dashboard()['assets'] as $asset) { $ids[$asset['name']] = $asset['id']; }
    $state['observations'] = array_combine(array_map(static function ($name) use ($ids) { return $ids[$name]; },
        ['broken.example', 'pending.example', 'old.example', 'fine.example', 'drift.example']), array_values($state['observations']));
    file_put_contents($root . '/health.json', json_encode($state));
    // 过时的 IP：走真实登记 + 检查流程，再把时钟推进 2 天让它变过时。
    $seedProbe = static function ($asset) { return ['status' => 'unhealthy', 'message' => 'HTTP 503', 'checks' => [], 'resolved_ips' => [], 'latency_ms' => 30]; };
    $past = new HealthService($root, $seedProbe, static function () use ($now) { return $now - 2 * 86400; });
    $ipAsset = $past->addIp(['ip' => '9.9.9.9', 'name' => '9.9.9.9'])['asset'];
    $past->check([$ipAsset['id']]);

    $result = (new IssueService($root, $clock))->issues();
    $counts = $result['counts'];
    expect($result['ok'] === true && $counts['total'] === 7, "seven issues across four types (got {$counts['total']})");
    expect($counts['unhealthy'] === 1 && $counts['https_pending'] === 3 && $counts['stale'] === 2 && $counts['expiring'] === 1, 'counts per type');
    expect($result['issues'][0]['severity'] === 'P0' && $result['issues'][0]['type'] === 'unhealthy' && $result['issues'][0]['name'] === 'broken.example', 'P0 unhealthy sorts first');
    expect(strpos($result['issues'][0]['summary'], 'HTTPS 证书已过期') !== false, 'P0 summary carries evidence');
    expect($result['issues'][0]['ips'] === ['8.8.8.8', '9.9.9.9'], 'observed IPs included');
    expect($result['issues'][0]['dns_path'] === '/api/domains/cloud_1/broken.example/records', 'cloud domain carries DNS path');
    $ids = array_column($result['issues'], 'id');
    expect(in_array('unhealthy:domain:broken.example', $ids, true), 'stable issue id');
    expect(in_array('https_pending:domain:pending.example', $ids, true) && in_array('https_pending:domain:drift.example', $ids, true), 'pending domains listed');
    expect(!in_array('stale:domain:drift.example', $ids, true), 'invalidated domain not duplicated as stale');
    expect(in_array('stale:domain:old.example', $ids, true), 'stale domain listed');
    expect(in_array('stale:ip:9.9.9.9', $ids, true), 'stale IP listed by address');
    expect(in_array('expiring:domain:expiring.example', $ids, true), 'expiring domain listed');
    $expiring = $result['issues'][array_search('expiring:domain:expiring.example', $ids, true)];
    expect($expiring['severity'] === 'P2' && strpos($expiring['summary'], '天内到期') !== false && $expiring['due'] !== null, 'expiring issue carries due date');
    expect(!isset($result['issues'][array_search('unhealthy:domain:broken.example', $ids, true)]['due']), 'check issues have no due field set');
    foreach ($result['issues'] as $issue) {
        expect(in_array($issue['severity'], ['P0', 'P1', 'P2'], true), 'severity present');
        expect(is_array($issue['actions']) && $issue['actions'] !== [], 'suggested actions present');
        if (($issue['provider'] ?? 'manual') === 'manual' && $issue['type'] !== 'expiring') { expect(!isset($issue['dns_path']) || $issue['dns_path'] === null, 'manual domain has no dns_path'); }
    }
    expect($result['truncated'] === false && count($result['severity_labels']) === 3, 'payload flags and severity labels');
    expect(strpos($result['usage'], 'proposals:write') !== false && strpos($result['usage'], 'checks:run') !== false, 'usage explains AI three-step flow');

    // 只读：清单不写任何文件。
    $before = glob($root . '/*');
    (new IssueService($root, $clock))->issues();
    expect(glob($root . '/*') === $before, 'issue reads never create or modify files');

    echo 'PASS: ' . $assertions . " issue triage assertions\n";
} finally {
    removeTree($root);
}
