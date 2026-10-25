<?php
/** Offline regressions. Run: php tests/health_test.php */
require_once __DIR__ . '/../app/services/HealthService.php';
date_default_timezone_set('Asia/Shanghai');

$root = sys_get_temp_dir() . '/workbench-health-test-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$assertions = 0;
function expect($condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}
function throwsClass(callable $work, string $class, string $message): void
{
    try { $work(); } catch (Throwable $e) { expect($e instanceof $class, $message . ' (' . get_class($e) . ')'); return; }
    expect(false, $message);
}
function fixture(string $dir, array $domains): void
{
    if (!is_dir($dir)) { mkdir($dir, 0700, true); }
    file_put_contents($dir . '/domains.json', json_encode($domains));
}
function assetByName(array $dashboard, string $name): array
{
    foreach ($dashboard['assets'] as $asset) { if ($asset['name'] === $name) { return $asset; } }
    throw new RuntimeException('Missing asset: ' . $name);
}
function removeTree(string $dir): void
{
    foreach (glob($dir . '/*') as $path) { is_dir($path) ? removeTree($path) : unlink($path); }
    rmdir($dir);
}

try {
    $now = strtotime('2026-10-23 12:00:00');
    $clock = static function () use (&$now) { return $now; };
    fixture($root, [
        ['domain' => 'Example.com', 'provider' => 'test', 'provider_name' => 'Test', 'expire_at' => '2026-11-01'],
        ['domain' => 'second.example', 'provider' => 'manual'],
        ['domain' => 'removed.example', 'provider' => 'test', 'valid' => false],
    ]);
    file_put_contents($root . '/access_check.json', json_encode(['results' => ['test|Example.com' => ['status' => 'ok', 'http_code' => 200, 'checked_at' => '2026-10-23 11:59:00', 'ms' => 15]]]));
    $probe = static function ($asset) {
        return ['status' => 'healthy', 'message' => 'Test success', 'checks' => [],
            'resolved_ips' => $asset['kind'] === 'domain' ? ['9.9.9.9', '127.0.0.1'] : [], 'latency_ms' => 12];
    };
    $service = new HealthService($root, $probe, $clock);
    $dashboard = $service->dashboard();
    expect($dashboard['summary']['domains'] === 2, 'exclude invalid domains');
    expect($dashboard['summary']['stale'] === 1 && $dashboard['summary']['healthy'] === 0, 'legacy success never becomes current healthy');
    expect($dashboard['summary']['unchecked'] === 1 && $dashboard['summary']['expiring_30'] === 1, 'unchecked and expiry summaries');
    $domain = assetByName($dashboard, 'example.com');
    expect($domain['health']['source'] === 'legacy' && $domain['history'][0]['source'] === 'legacy', 'legacy history labelled');
    expect(!is_file($root . '/health.json'), 'read-only dashboard does not create state');

    foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '192.0.2.1', '198.18.0.1', '224.0.0.1', '240.1.2.3', '::1', 'fc00::1', 'fe80::1', '::ffff:127.0.0.1', '2001:db8::1', '2002:a00:1::', '3fff::1', '8.8.8.8/24', '2130706433'] as $ip) {
        expect(!HealthService::isPublicIp($ip), 'reject non-public/ambiguous IP ' . $ip);
        throwsClass(static function () use ($service, $ip) { $service->addIp(['ip' => $ip]); }, InvalidArgumentException::class, 'invalid IP cannot be added');
    }
    expect(HealthService::isPublicIp('8.8.8.8') && HealthService::isPublicIp('2606:4700:4700::1111'), 'allow normal public addresses');
    foreach ([0, 65536, '443x', 443.5] as $port) {
        throwsClass(static function () use ($service, $port) { $service->addIp(['ip' => '8.8.8.8', 'port' => $port]); }, InvalidArgumentException::class, 'invalid port');
    }
    $manual = $service->addIp(['ip' => '8.8.8.8', 'port' => 443, 'name' => 'Manual IP'])['asset'];
    throwsClass(static function () use ($service) { $service->addIp(['ip' => '8.8.8.8']); }, DomainException::class, 'reject duplicate endpoint');
    $ipv6 = $service->addIp(['ip' => '2606:4700:4700:0:0:0:0:1111'])['asset'];
    expect($ipv6['ip'] === '2606:4700:4700::1111', 'canonical IPv6');
    throwsClass(static function () use ($service) { $service->addIp(['ip' => '2606:4700:4700::1111']); }, DomainException::class, 'canonical IPv6 duplicate');
    $service->deleteIp($ipv6['id']);

    $checked = $service->check([$domain['id']]);
    expect($checked['checked'] === 1 && count($checked['results'][0]['history']) === 1, 'check response includes newest-first history');
    expect($checked['results'][0]['health']['resolved_ips'] === ['9.9.9.9'], 'filter private addresses from probe results');
    $dashboard = $service->dashboard();
    $discovered = assetByName($dashboard, '9.9.9.9');
    expect($discovered['source'] === 'dns' && $discovered['linked_domains'] === ['example.com'], 'derive public DNS endpoint association');
    expect($discovered['health']['status'] === 'unchecked', 'domain success does not automatically mark IP healthy');
    throwsClass(static function () use ($service, $discovered) { $service->deleteIp($discovered['id']); }, OutOfBoundsException::class, 'cannot delete DNS-only asset');
    throwsClass(static function () use ($service) { $service->check([]); }, InvalidArgumentException::class, 'reject empty batch');
    throwsClass(static function () use ($service) { $service->check(array_fill(0, 6, 'x')); }, InvalidArgumentException::class, 'bound batch size');
    throwsClass(static function () use ($service) { $service->check(['missing']); }, OutOfBoundsException::class, 'reject unknown asset');
    $now += HealthService::FRESHNESS_SECONDS;
    $stale = assetByName($service->dashboard(), 'example.com')['health'];
    expect($stale['status'] === 'stale' && $stale['last_status'] === 'healthy', '24 hour freshness boundary');
    for ($i = 0; $i < 11; $i++) { $now++; $service->check([$domain['id']]); }
    $history = assetByName($service->dashboard(), 'example.com')['history'];
    expect(count($history) === 10 && $history[0]['checked_at'] > $history[9]['checked_at'], 'keep exactly latest ten checks newest first');

    // Mutation from a second service while a probe is running must survive the
    // observation commit (the network operation never holds the data lock).
    $other = new HealthService($root, $probe, $clock);
    $duringProbe = new HealthService($root, static function ($asset) use ($other, $probe) {
        $other->addIp(['ip' => '1.1.1.1', 'port' => 8443]);
        return $probe($asset);
    }, $clock);
    $duringProbe->check([$manual['id']]);
    expect(assetByName($service->dashboard(), '1.1.1.1')['port'] === 8443, 'concurrent mutation survives observation commit');
    $service->deleteIp($manual['id']);
    expect(isset(json_decode(file_get_contents($root . '/health.json'), true)['observations'][$manual['id']]), 'delete registration preserves history');

    $transportDir = $root . '/transport';
    fixture($transportDir, [['domain' => 'tls.example', 'provider' => 'manual']]);
    $calls = [];
    $dns = static function ($host) { return [['type' => 'A', 'ip' => '9.9.9.9'], ['type' => 'A', 'ip' => '127.0.0.1']]; };
    $transport = static function ($host, $ip, $head, $timeout) use (&$calls) {
        $calls[] = [$host, $ip, $head, $timeout];
        return ['code' => $head ? 405 : 200, 'errno' => 0, 'error' => '', 'ms' => 8];
    };
    $networkService = new HealthService($transportDir, null, $clock, $dns, $transport);
    $tlsId = $networkService->dashboard()['assets'][0]['id'];
    $health = $networkService->check([$tlsId])['results'][0]['health'];
    expect($health['status'] === 'healthy' && count($calls) === 2, 'HEAD unsupported triggers same-origin GET');
    expect($calls[0][0] === 'tls.example' && $calls[1][0] === 'tls.example' && $calls[0][1] === '9.9.9.9' && $calls[1][1] === '9.9.9.9', 'pin only validated public IP, retain hostname');
    expect($calls[0][2] && !$calls[1][2] && $calls[1][3] <= 4000, 'GET retains bounded request budget');
    $calls = [];
    $badTls = new HealthService($transportDir, null, $clock, $dns, static function ($host, $ip, $head, $timeout) use (&$calls) {
        $calls[] = [$host, $ip, $head];
        return ['code' => 0, 'errno' => 60, 'error' => 'certificate expired', 'ms' => 10];
    });
    $health = $badTls->check([$tlsId])['results'][0]['health'];
    expect($health['status'] === 'unhealthy' && count($calls) === 1, 'TLS failure cannot fall back to HTTP or direct IP');
    expect($health['checks'][1]['label'] === 'TLS' && $health['checks'][1]['status'] === 'unhealthy', 'classify certificate failure');
    $privateDns = new HealthService($transportDir, null, $clock, static function () { return [['type' => 'A', 'ip' => '169.254.169.254']]; }, static function () { throw new RuntimeException('Must not connect'); });
    $health = $privateDns->check([$tlsId])['results'][0]['health'];
    expect($health['checks'][0]['label'] === 'DNS' && $health['resolved_ips'] === [], 'block private DNS response before transport');
    $loopCalls = 0;
    $loopDns = new HealthService($transportDir, null, $clock, static function ($host) use (&$loopCalls) {
        $loopCalls++; return [['type' => 'CNAME', 'target' => $host]];
    }, $transport);
    $loopDns->check([$tlsId]);
    expect($loopCalls === 1, 'bound CNAME loops');
    foreach (['https://example.com', 'example.com/path', 'a..example', '127.0.0.1', 'localhost', 'x@example.com', 'example.com:443'] as $host) {
        expect(HealthService::normalizeHost($host) === null, 'reject host injection ' . $host);
    }

    $corrupt = $root . '/corrupt'; mkdir($corrupt);
    file_put_contents($corrupt . '/health.json', '{broken');
    throwsClass(static function () use ($corrupt) { (new HealthService($corrupt))->addIp(['ip' => '8.8.8.8']); }, RuntimeException::class, 'corrupt storage must not be overwritten');
    expect(file_get_contents($corrupt . '/health.json') === '{broken', 'preserve corrupt source for recovery');
    $writeFail = $root . '/writefail'; mkdir($writeFail); mkdir($writeFail . '/health.json');
    throwsClass(static function () use ($writeFail) { (new HealthService($writeFail))->addIp(['ip' => '8.8.8.8']); }, RuntimeException::class, 'atomic rename failure propagates');
    expect(count(glob($writeFail . '/health.json.tmp.*')) === 0, 'clean temporary file after failed save');

    if (function_exists('pcntl_fork')) {
        $parallel = $root . '/parallel'; mkdir($parallel);
        $children = [];
        for ($i = 0; $i < 6; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try { (new HealthService($parallel))->addIp(['ip' => '8.8.8.8', 'port' => 8000 + $i]); exit(0); }
                catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
            }
            if ($pid < 0) { throw new RuntimeException('Unable to fork'); }
            $children[] = $pid;
        }
        foreach ($children as $pid) { pcntl_waitpid($pid, $status); expect(pcntl_wexitstatus($status) === 0, 'parallel writer succeeded'); }
        expect((new HealthService($parallel))->dashboard()['summary']['ips'] === 6, 'no lost updates under concurrent writers');
    }
    echo 'PASS: ' . $assertions . " offline health assertions\n";
} finally {
    removeTree($root);
}
