<?php
/** Offline regressions for the AI analysis report. Run: php tests/ai_report_test.php */
require_once __DIR__ . '/../app/services/AiReportService.php';
date_default_timezone_set('Asia/Shanghai');

$root = sys_get_temp_dir() . '/workbench-ai-report-test-' . bin2hex(random_bytes(6));
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
    $now = strtotime('2026-11-02 12:00:00');
    $clock = static function () use (&$now) { return $now; };
    file_put_contents($root . '/domains.json', json_encode([
        ['domain' => 'good.example', 'provider' => 'cloud_1', 'provider_name' => '示例云账号'],
        ['domain' => 'bad.example', 'provider' => 'cloud_1', 'provider_name' => '示例云账号', 'note' => "生产站点|含换行\n第二行"],
        ['domain' => 'pending.example', 'provider' => 'manual'],
        ['domain' => 'expiring.example', 'provider' => 'manual', 'expire_at' => date('Y-m-d', $now + 10 * 86400)],
    ]));

    $ids = [];
    $catalog = static function () use ($root, &$ids) {
        foreach ((new HealthService($root, null, static function () { return 0; }))->dashboard()['assets'] as $asset) {
            $ids[$asset['name']] = $asset['id'];
        }
    };
    $catalog();
    $healthy = static function ($asset) {
        return ['status' => 'healthy', 'message' => 'HTTP 与 HTTPS 均正常', 'checks' => [],
            'resolved_ips' => $asset['kind'] === 'domain' ? ['9.9.9.9'] : [], 'latency_ms' => 20,
            'protocols' => ['https' => ['status' => 'healthy']]];
    };
    (new HealthService($root, $healthy, $clock))->check([$ids['good.example']]);

    $unhealthy = static function ($asset) {
        $ip = $asset['kind'] === 'domain' ? ['8.8.8.8'] : [];
        return ['status' => 'unhealthy', 'message' => $asset['kind'] === 'domain' ? 'HTTPS 证书已过期' : 'HTTP 503',
            'checks' => [], 'resolved_ips' => $ip,
            'protocols' => $asset['kind'] === 'domain' ? ['https' => ['status' => 'unhealthy']] : []];
    };
    $unhealthyService = new HealthService($root, $unhealthy, $clock);
    $unhealthyService->check([$ids['bad.example']]);
    $badIp = $unhealthyService->addIp(['ip' => '1.1.1.1', 'name' => '坏服务器', 'note' => '入口机器'])['asset'];
    $unhealthyService->check([$badIp['id']]);

    $report = (new AiReportService(new HealthService($root, null, $clock), $clock))->report();
    expect($report['ok'] === true && is_string($report['markdown']) && $report['markdown'] !== '', 'report payload carries markdown');
    $prompt = $report['prompt'];
    expect(strpos($prompt, '运维顾问') !== false && strpos($prompt, '<report>') !== false && strpos($prompt, '</report>') !== false, 'prompt wraps the report for chat assistants');
    expect(strpos($prompt, $report['markdown']) !== false && strpos($prompt, 'P0') !== false && strpos($prompt, '不要臆测') !== false, 'prompt embeds the full report and analysis requirements');
    expect($report['generated_at'] === '2026-11-02 12:00:00', 'report marks the snapshot time');
    $md = $report['markdown'];

    expect(strpos($md, '# 资产健康报告') === 0, 'report opens with a title');
    expect(strpos($md, '## 一、检查异常的域名（1）') !== false, 'failing domain section header with count');
    expect(strpos($md, '| bad.example ') !== false && strpos($md, 'HTTPS 证书已过期') !== false, 'failing domain row lists evidence');
    expect(strpos($md, '8.8.8.8') !== false, 'failing domain exposes observed IP');
    expect(strpos($md, '## 二、HTTPS 尚未确认的域名（2）') !== false, 'unchecked plus expiring domains are pending');
    expect(strpos($md, '| pending.example ') !== false && strpos($md, '| expiring.example ') !== false, 'pending rows present');
    expect(strpos($md, 'good.example') === false, 'healthy domain is not listed as a problem row');
    expect(strpos($md, '## 三、检查异常的 IP / 服务器（1）') !== false && strpos($md, '| 坏服务器 / 1.1.1.1 ') !== false, 'failing IP row present');
    expect(strpos($md, '## 四、30 天内到期（1）') !== false && strpos($md, date('Y-m-d', $now + 10 * 86400)) !== false, 'expiring asset listed');
    expect(strpos($md, '生产站点\\|含换行 第二行') !== false, 'pipe and newline in notes are escaped for the table');
    expect(preg_match('/^' . preg_quote('请基于以上证据输出', '/') . '/m', $md) === 1, 'analysis guide included');

    // Within each contiguous table block, every row keeps a uniform column count.
    $columns = null;
    foreach (array_merge(explode("\n", $md), ['']) as $line) {
        if ($line !== '' && $line[0] === '|') {
            $count = preg_match_all('/(?<!\\\\)\|/', $line);
            if ($columns === null) { $columns = $count; }
            expect($count === $columns, 'table rows keep uniform columns: ' . $line);
        } else {
            $columns = null;
        }
    }

    // Bounded output: a large inventory truncates instead of flooding the AI context.
    $many = [];
    for ($i = 0; $i < 60; $i++) { $many[] = ['domain' => "bulk$i.example", 'provider' => 'manual']; }
    file_put_contents($root . '/domains.json', json_encode($many));
    $ids = [];
    $catalog();
    $md = (new AiReportService(new HealthService($root, null, $clock), $clock))->markdown();
    expect(substr_count($md, '| bulk') === 50, 'pending table is capped at 50 rows');
    expect(strpos($md, '其余 10 项省略') !== false, 'truncation discloses the omitted remainder');
    expect(strlen($md) < 20000, 'report stays bounded for AI context windows');

    // Stale evidence must not be reported as current health.
    $now += 2 * HealthService::FRESHNESS_SECONDS;
    $md = (new AiReportService(new HealthService($root, null, $clock), $clock))->markdown();
    expect(substr_count($md, '结果过时') >= 1, 'stale domains are labelled');

    // The CLI entry prints the same Markdown on a disposable installation copy.
    $cli = $root . '/cli-install';
    mkdir($cli . '/app', 0700, true);
    foreach (['core', 'providers', 'services', 'controllers'] as $dir) {
        mkdir($cli . '/app/' . $dir, 0700, true);
        foreach (glob(__DIR__ . '/../app/' . $dir . '/*.php') ?: [] as $file) { copy($file, $cli . '/app/' . $dir . '/' . basename($file)); }
    }
    copy(__DIR__ . '/../app/bootstrap.php', $cli . '/app/bootstrap.php');
    mkdir($cli . '/scripts');
    copy(__DIR__ . '/../scripts/generate-health-report.php', $cli . '/scripts/generate-health-report.php');
    mkdir($cli . '/data');
    file_put_contents($cli . '/data/domains.json', json_encode([['domain' => 'cli.example', 'provider' => 'manual']]));
    exec('php ' . escapeshellarg($cli . '/scripts/generate-health-report.php') . ' 2>&1', $out, $code);
    $cliOut = implode("\n", $out);
    expect($code === 0 && strpos($cliOut, '# 资产健康报告') === 0 && strpos($cliOut, 'cli.example') !== false, 'CLI entry prints the markdown report');
    expect(strpos($cliOut, 'Warning') === false && strpos($cliOut, 'Fatal') === false, 'CLI entry emits a clean report');
    exec('php ' . escapeshellarg($cli . '/scripts/generate-health-report.php') . ' --prompt 2>&1', $promptOut, $promptCode);
    $cliPrompt = implode("\n", $promptOut);
    expect($promptCode === 0 && strpos($cliPrompt, '<report>') !== false && strpos($cliPrompt, $cliOut) !== false, 'CLI --prompt wraps the same report in the analysis prompt');

    echo 'PASS: ' . $assertions . " AI report assertions\n";
} finally {
    removeTree($root);
}
