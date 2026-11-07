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
    $now = time(); // 相对真实时钟构造时间戳，dashboard 的 staleness 用默认时钟计算
    $clock = static function () use ($now) { return $now; };
    file_put_contents($root . '/domains.json', json_encode([
        ['domain' => 'broken.example', 'provider' => 'cloud_1', 'provider_name' => '示例云'],
        ['domain' => 'pending.example', 'provider' => 'manual'],
        ['domain' => 'old.example', 'provider' => 'manual'],
        ['domain' => 'fine.example', 'provider' => 'manual'],
        ['domain' => 'expiring.example', 'provider' => 'manual', 'expire_at' => date('Y-m-d', $now + 8 * 86400)],
        ['domain' => 'drift.example', 'provider' => 'manual'],
        ['domain' => 'tls.example', 'provider' => 'manual'],
        ['domain' => 'cert7.example', 'provider' => 'manual'],
        ['domain' => 'cert14.example', 'provider' => 'manual'],
        ['domain' => 'cert30.example', 'provider' => 'manual'],
        ['domain' => 'certok.example', 'provider' => 'manual'],
    ]));
    $state = [
        'version' => 1, 'manual_ips' => [], 'check_events' => [],
        'observations' => [
            // P0：HTTP 检查异常（可达性只看 HTTP），带消息与解析 IP。
            'broken' => ['health' => ['status' => 'unhealthy', 'checked_at' => date('Y-m-d H:i:s', $now - 3600),
                'message' => 'HTTP 检查异常', 'resolved_ips' => ['8.8.8.8', '9.9.9.9'],
                'protocols' => ['http' => ['status' => 'unhealthy'], 'https' => ['status' => 'unhealthy']]], 'history' => []],
            // HTTP 正常但 HTTPS 证书过期：不进问题中心，进入 HTTPS 跟进。
            'tls' => ['health' => ['status' => 'healthy', 'checked_at' => date('Y-m-d H:i:s', $now - 600),
                'message' => 'HTTP 可访问；HTTPS 待跟进', 'resolved_ips' => ['2.2.2.2'],
                'protocols' => ['http' => ['status' => 'healthy'], 'https' => ['status' => 'unhealthy']]], 'history' => []],
            // P1：结果过时的健康 HTTPS（超过 24 小时）。
            'old' => ['health' => ['status' => 'healthy', 'checked_at' => date('Y-m-d H:i:s', $now - 3 * 86400),
                'message' => '正常', 'resolved_ips' => ['1.1.1.1'], 'protocols' => ['https' => ['status' => 'healthy']]], 'history' => []],
            // 健康域名：不出现。
            'fine' => ['health' => ['status' => 'healthy', 'checked_at' => date('Y-m-d H:i:s', $now - 600),
                'message' => '正常', 'resolved_ips' => ['1.0.0.1'], 'protocols' => ['https' => ['status' => 'healthy']]], 'history' => []],
            // DNS 已更新：HTTPS 待复检 + 结果过时。问题中心记为 stale，HTTPS 跟进记为 pending。
            'drift' => ['health' => ['status' => 'stale', 'invalidated' => true, 'checked_at' => date('Y-m-d H:i:s', $now - 2 * 86400),
                'message' => 'DNS 已更新，请重新检查；上次结果：正常', 'resolved_ips' => [], 'protocols' => ['https' => ['status' => 'healthy']]], 'history' => []],
        ],
    ];
    // TLS 证书临期夹具：同一「现状/方案/验收」分级（≤7 P1、≤14 P2、≤30 P2），60 天不报；健康 HTTPS 也进入跟进页。
    $certHealth = static function (int $days) use ($now): array {
        return ['status' => 'healthy', 'checked_at' => date('Y-m-d H:i:s', $now - 600),
            'message' => 'HTTP 和 HTTPS 均正常', 'resolved_ips' => ['1.2.3.4'],
            'protocols' => ['http' => ['status' => 'healthy'], 'https' => ['status' => 'healthy']],
            'cert' => ['expires_at' => date('Y-m-d', $now + $days * 86400), 'subject' => 'site.example', 'issuer' => 'R3']];
    };
    $state['observations']['cert7'] = ['health' => $certHealth(5), 'history' => []];
    $state['observations']['cert14'] = ['health' => $certHealth(12), 'history' => []];
    $state['observations']['cert30'] = ['health' => $certHealth(25), 'history' => []];
    $state['observations']['certok'] = ['health' => $certHealth(60), 'history' => []];
    // 观测必须挂在真实资产 ID 上：先建空看板取回 ID 映射，再写回观测。
    file_put_contents($root . '/health.json', json_encode(['version' => 1, 'manual_ips' => [], 'observations' => [], 'check_events' => []]));
    $ids = [];
    foreach ((new HealthService($root, null, $clock))->dashboard()['assets'] as $asset) { $ids[$asset['name']] = $asset['id']; }
    $state['observations'] = array_combine(array_map(static function ($name) use ($ids) { return $ids[$name]; },
        ['broken.example', 'tls.example', 'old.example', 'fine.example', 'drift.example', 'cert7.example', 'cert14.example', 'cert30.example', 'certok.example']), array_values($state['observations']));
    file_put_contents($root . '/health.json', json_encode($state));
    // 过时的 IP：走真实登记 + 检查流程，再把时钟推进 2 天让它变过时。
    $seedProbe = static function ($asset) { return ['status' => 'unhealthy', 'message' => 'HTTP 503', 'checks' => [], 'resolved_ips' => [], 'latency_ms' => 30]; };
    $past = new HealthService($root, $seedProbe, static function () use ($now) { return $now - 2 * 86400; });
    $ipAsset = $past->addIp(['ip' => '9.9.9.9', 'name' => '9.9.9.9'])['asset'];
    $past->check([$ipAsset['id']]);

    $result = (new IssueService($root, $clock))->issues();
    $counts = $result['counts'];
    expect($result['ok'] === true && $counts['total'] === 8, "eight issues across four types (got {$counts['total']})");
    expect($counts['unhealthy'] === 1 && $counts['stale'] === 3 && $counts['expiring'] === 1 && $counts['cert_expiring'] === 3, 'counts per type');
    expect(!isset($counts['https_pending']) && !array_filter($result['issues'], static function ($i) { return $i['type'] === 'https_pending'; }), 'HTTPS items stay out of the issue center');
    expect($result['issues'][0]['severity'] === 'P0' && $result['issues'][0]['type'] === 'unhealthy' && $result['issues'][0]['name'] === 'broken.example', 'P0 unhealthy sorts first');
    expect(strpos($result['issues'][0]['summary'], 'HTTP 检查异常') !== false, 'P0 summary carries evidence');
    expect($result['issues'][0]['ips'] === ['8.8.8.8', '9.9.9.9'], 'observed IPs included');
    expect($result['issues'][0]['dns_path'] === '/api/domains/cloud_1/broken.example/records', 'cloud domain carries DNS path');
    expect($result['issues'][0]['url'] === 'http://broken.example/', 'issue carries probe URL for quick location');
    $ids = array_column($result['issues'], 'id');
    expect(in_array('unhealthy:domain:broken.example', $ids, true), 'stable issue id');
    expect(in_array('stale:domain:drift.example', $ids, true), 'invalidated domain listed as stale in issue center');
    expect(in_array('stale:domain:old.example', $ids, true), 'stale domain listed');
    expect(in_array('stale:ip:9.9.9.9', $ids, true), 'stale IP listed by address');
    expect(in_array('expiring:domain:expiring.example', $ids, true), 'expiring domain listed');
    $expiring = $result['issues'][array_search('expiring:domain:expiring.example', $ids, true)];
    expect($expiring['severity'] === 'P2' && strpos($expiring['summary'], '天内到期') !== false && $expiring['due'] !== null, 'expiring issue carries due date');
    // 证书临期：稳定 ID（cert:domain:域名）去重；≤7 天 P1、≤14/≤30 P2；60 天不报。
    expect(in_array('cert:domain:cert7.example', $ids, true) && in_array('cert:domain:cert14.example', $ids, true) && in_array('cert:domain:cert30.example', $ids, true), 'cert expiring issues listed with stable ids');
    expect(!in_array('cert:domain:certok.example', $ids, true), 'certificate beyond 30 days not listed');
    $certIssue = $result['issues'][array_search('cert:domain:cert7.example', $ids, true)];
    expect($certIssue['severity'] === 'P1' && strpos($certIssue['summary'], '天内到期') !== false, 'cert within 7 days escalates to P1');
    expect(strpos($certIssue['summary'], $certIssue['due']) !== false && strpos($certIssue['summary'], 'TLS 证书') !== false, 'cert issue summary carries expiry and type');
    expect(strpos($result['issues'][array_search('cert:domain:cert14.example', $ids, true)]['summary'], '天内到期') !== false, 'cert within 14 days uses middle tier');
    expect($result['issues'][array_search('cert:domain:cert30.example', $ids, true)]['severity'] === 'P2', 'cert within 30 days stays P2');
    $again = (new IssueService($root, $clock))->issues();
    $firstIds = array_column($result['issues'], 'id');
    expect(array_column($again['issues'], 'id') === $firstIds, 'repeated reads produce identical stable list (no duplicates)');
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

    // HTTPS 跟进清单：HTTP 正常但 HTTPS 异常的域名在这里，证书临期（≤30 天）的健康域名也进入；不影响问题中心。
    $review = (new IssueService($root, $clock))->httpsReview();
    expect($review['ok'] === true && $review['counts']['total'] === 8, 'https review totals eight items (got ' . $review['counts']['total'] . ')');
    expect($review['counts']['fail'] === 2 && $review['counts']['unknown'] === 2 && $review['counts']['pending'] === 1 && $review['counts']['stale'] === 0 && $review['counts']['expiring'] === 3, 'https review counts per state');
    $reviewIds = array_column($review['items'], 'name');
    expect(in_array('tls.example', $reviewIds, true) && in_array('broken.example', $reviewIds, true), 'https failures listed');
    expect(in_array('pending.example', $reviewIds, true) && in_array('expiring.example', $reviewIds, true), 'never-checked domains listed as unknown');
    expect(in_array('drift.example', $reviewIds, true), 'invalidated https listed as pending');
    expect(in_array('cert7.example', $reviewIds, true) && in_array('cert14.example', $reviewIds, true) && in_array('cert30.example', $reviewIds, true), 'healthy https with expiring certificate listed as expiring');
    expect(!in_array('fine.example', $reviewIds, true) && !in_array('old.example', $reviewIds, true) && !in_array('certok.example', $reviewIds, true), 'healthy https without cert window excluded');
    expect($review['items'][0]['state'] === 'fail', 'https failures sort first');
    expect($review['items'][0]['url'] === 'https://' . $review['items'][0]['name'] . '/', 'https review items carry the follow-up URL');
    $certRow = $review['items'][array_search('cert7.example', array_column($review['items'], 'name'), true)];
    expect($certRow['state'] === 'expiring' && $certRow['cert_days_left'] >= 4 && $certRow['cert_days_left'] <= 7 && $certRow['cert_issuer'] === 'R3' && $certRow['cert_expires_at'] === $certIssue['due'], 'expiring item carries certificate expiry and days left');

    echo 'PASS: ' . $assertions . " issue triage assertions\n";
} finally {
    removeTree($root);
}