<?php
/** 并发探测回归：Fiber + curl_multi 加速比与「同资产串行、跨资产并行」语义。Run: php tests/concurrent_curl_test.php */
require_once __DIR__ . '/../app/core/ConcurrentCurl.php';

$assertions = 0;
function expect($condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}
function makeHandle(string $url, int $timeoutMs)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => $timeoutMs, CURLOPT_CONNECTTIMEOUT_MS => 500]);
    return $ch;
}
function removeTree(string $dir): void
{
    foreach (glob($dir . '/*') as $path) { is_dir($path) ? removeTree($path) : unlink($path); }
    rmdir($dir);
}

$root = sys_get_temp_dir() . '/concurrent-curl-' . bin2hex(random_bytes(4));
mkdir($root, 0700, true);
$port = 8098 + random_int(0, 200);
try {
    // /sleep 端点：固定 300ms 后回显 step，用于测量加速比与请求顺序
    file_put_contents($root . '/index.php', '<?php
        $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
        if ($path === "/sleep") { usleep(300000); header("Content-Type: application/json"); echo json_encode(["step" => $_GET["step"] ?? ""]); return true; }
        http_response_code(404); return true;');
    $env = getenv();
    $env['PHP_CLI_SERVER_WORKERS'] = '8';
    $server = proc_open(PHP_BINARY . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($server)) { throw new RuntimeException('无法启动本地并发测试服务器'); }
    $base = "http://127.0.0.1:$port";
    $ready = false;
    for ($i = 0; $i < 50 && !$ready; $i++) {
        usleep(100000);
        $probe = @file_get_contents("$base/sleep?step=0", false, stream_context_create(['http' => ['timeout' => 1]]));
        $ready = $probe !== false;
    }
    expect($ready, 'local test server becomes ready');

    expect(ConcurrentCurl::supported(), 'Fiber + curl_multi available on this runtime');

    // 并发外直接 exec：等价阻塞 curl_exec
    $plain = new ConcurrentCurl();
    $direct = $plain->exec(makeHandle("$base/sleep?step=serial", 2000));
    expect($direct['errno'] === 0 && $direct['code'] === 200, 'exec outside a job behaves like blocking curl_exec');

    // 4 个作业 × 各 2 次串行请求（每次服务端 300ms）：串行需 ≥2.4s，跨资产并行应 ≈0.6s
    $jobs = [];
    for ($i = 1; $i <= 4; $i++) {
        $jobs["job$i"] = static function (ConcurrentCurl $curl) use ($base, $i) {
            $first = $curl->exec(makeHandle("$base/sleep?step=1&job=$i", 2000));
            if ($first['errno'] !== 0) { throw new RuntimeException("first request failed: $first[error]"); }
            $second = $curl->exec(makeHandle("$base/sleep?step=2&job=$i", 2000));
            if ($second['errno'] !== 0) { throw new RuntimeException("second request failed: $second[error]"); }
            return ['first' => $first, 'second' => $second];
        };
    }
    $start = microtime(true);
    $outcomes = ConcurrentCurl::run($jobs);
    $elapsed = microtime(true) - $start;
    expect(count($outcomes) === 4, 'every job produces an outcome');
    foreach ($outcomes as $id => $outcome) {
        expect($outcome['ok'], "$id succeeds");
        expect($outcome['value']['first']['code'] === 200 && $outcome['value']['second']['code'] === 200, "$id both requests OK");
    }
    expect($elapsed < 1.6, "cross-asset parallelism (4 jobs x 2 x 300ms in {$elapsed}s, serial floor 2.4s)");
    echo "并发加速实测：4 作业 × 2 串行请求 × 300ms 用时 {$elapsed}s（串行下限 2.4s）\n";

    // 作业内异常被捕获为 error outcome，不影响其他作业
    $mixed = ConcurrentCurl::run([
        'bad' => static function (ConcurrentCurl $curl) { throw new DomainException('boom'); },
        'good' => static function (ConcurrentCurl $curl) use ($base) { return $curl->exec(makeHandle("$base/sleep?step=ok", 2000))['code']; },
    ]);
    expect($mixed['bad']['ok'] === false && $mixed['bad']['error'] instanceof DomainException, 'job exception captured as failed outcome');
    expect($mixed['good']['ok'] === true && $mixed['good']['value'] === 200, 'sibling job unaffected by failure');

    echo 'PASS: ' . $assertions . " concurrent curl assertions\n";
} finally {
    if (isset($server) && is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (is_dir($root)) { removeTree($root); }
}
