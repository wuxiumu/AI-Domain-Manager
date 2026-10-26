<?php
require __DIR__ . '/../app/core/PrivateJsonStore.php';
require __DIR__ . '/../app/services/LoginCaptcha.php';
require __DIR__ . '/../app/services/LoginRateLimiter.php';

$assertions = 0;
function verify($condition, string $message): void {
    global $assertions; $assertions++;
    if (!$condition) { throw new RuntimeException($message); }
}
$root = sys_get_temp_dir() . '/workspace-auth-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    $now = 1000;
    $clock = static function () use (&$now) { return $now; };
    $_SESSION = [];
    $captcha = new LoginCaptcha($clock, static function () { return 'AC234'; });
    $first = $captcha->issue();
    $image = base64_decode(explode(',', $first['image'], 2)[1]);
    verify(strpos($image, '<svg') === 0 && strpos($image, '<path') !== false, 'Local SVG image rendered without GD');
    verify(strpos($image, 'AC234') === false && strpos($image, '<text') === false, 'Image does not embed the answer as readable text');
    verify(strpos(json_encode($_SESSION), 'AC234') === false, 'Only an answer hash is stored');
    verify($first['expires_at'] === 1120, 'Two-minute expiry');
    verify($captcha->consume($first['captcha_id'], 'ac234'), 'Answer ignores ASCII letter case');
    verify(!$captcha->consume($first['captcha_id'], 'AC234'), 'Successful answer cannot be replayed');
    $first = $captcha->issue();
    verify(!$captcha->consume($first['captcha_id'], 'wrong'), 'Wrong answer rejected');
    verify(!$captcha->consume($first['captcha_id'], 'AC234'), 'Wrong answer also consumes the challenge');
    $first = $captcha->issue(); $second = $captcha->issue();
    verify(!$captcha->consume($first['captcha_id'], 'AC234'), 'Refresh invalidates the old ID');
    $first = $captcha->issue(); $now += 120;
    verify(!$captcha->consume($first['captcha_id'], 'AC234'), 'Challenge expires at its deadline');
    $first = $captcha->issue(); $_SESSION = [];
    verify(!$captcha->consume($first['captcha_id'], 'AC234'), 'Challenge bound to the issuing session');
    $first = $captcha->issue();
    verify(!$captcha->consume([], []), 'Malformed inputs safely rejected');

    $limiter = new LoginRateLimiter($root, $clock);
    $called = 0;
    $fail = static function () use (&$called) { $called++; return ['ok' => false, 'status' => 401]; };
    for ($i = 0; $i < 4; $i++) { verify($limiter->run('fixture', 'login', $fail)['status'] === 401, 'Initial failures are counted'); }
    verify($limiter->run('fixture', 'login', $fail)['retry_after'] === 300, 'Fifth failure locks the IP');
    $now += 119;
    verify($limiter->run('fixture', 'login', $fail)['retry_after'] === 181, 'Lock reports remaining time');
    verify($called === 5, 'Locked requests never reach password verification');
    verify($limiter->run('other-ip', 'login', static function () { return ['ok' => true]; })['ok'], 'Different IP has an independent budget');
    $now += 181;
    verify($limiter->run('fixture', 'login', $fail)['status'] === 401, 'Lock expiry resets failures and request window');
    $limiter->run('fixture', 'login', static function () { return ['ok' => true]; });
    for ($i = 0; $i < 4; $i++) { verify($limiter->run('fixture', 'login', $fail)['status'] === 401, 'Success clears failure counter'); }
    verify($limiter->run('fixture', 'login', $fail)['status'] === 429, 'New failure sequence locks normally');
    for ($i = 0; $i < 10; $i++) { verify($limiter->run('successful', 'login', static function () { return ['ok' => true]; })['ok'], 'Successful attempts still consume request budget'); }
    verify($limiter->run('successful', 'login', $fail)['retry_after'] === 60, 'Eleventh login request is throttled');
    for ($i = 0; $i < 30; $i++) { verify($limiter->run('captcha-ip', 'captcha')['ok'], 'Captcha budget admits 30 requests'); }
    verify($limiter->run('captcha-ip', 'captcha')['status'] === 429, 'Captcha fetches independently throttled');
    $now += 60;
    verify($limiter->run('captcha-ip', 'captcha')['ok'], 'Captcha window recovers');
    $_SERVER['REMOTE_ADDR'] = '2001:0db8:0:0:0:0:0:1'; $_SERVER['HTTP_X_FORWARDED_FOR'] = 'spoof';
    verify(LoginRateLimiter::clientIp() === '2001:db8::1', 'Normalize remote IP and ignore untrusted forwarding headers');
    verify(strpos(file_get_contents($root . '/login_limits.json'), 'captcha-ip') === false, 'Stored keys do not contain raw IPs');
    verify((fileperms($root . '/login_limits.json') & 0777) === 0600, 'Rate state is private');
    $now += 1000; $limiter->run('new-ip', 'captcha');
    verify(count(json_decode(file_get_contents($root . '/login_limits.json'), true)['entries']) === 1, 'Expired IP entries are pruned');

    // Separate PHP processes exercise the same stable file lock, rather than a single-server simulation.
    $workers = [];
    $source = 'require ' . var_export(__DIR__ . '/../app/core/PrivateJsonStore.php', true) . ';require '
        . var_export(__DIR__ . '/../app/services/LoginRateLimiter.php', true) . ';'
        . '$dir=$argv[1];$limiter=new LoginRateLimiter($dir);$result=$limiter->run("parallel","login",function()use($dir){'
        . '$n=is_file($dir."/verified")?(int)file_get_contents($dir."/verified"):0;usleep(20000);'
        . 'file_put_contents($dir."/verified",$n+1);return ["ok"=>false,"status"=>401];});echo $result["status"];';
    for ($i = 0; $i < 12; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-r', $source, $root], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        fclose($pipes[0]); $workers[] = [$process, $pipes];
    }
    $statuses = [];
    foreach ($workers as [$process, $pipes]) {
        $statuses[] = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); verify(proc_close($process) === 0, 'Concurrent worker succeeds: ' . $error);
    }
    verify((int) file_get_contents($root . '/verified') === 5, 'Only five concurrent failures reach verification');
    verify(count(array_filter($statuses, static function ($status) { return $status === '429'; })) === 8, 'Fifth and remaining concurrent requests are throttled');
    echo "Login security: $assertions assertions passed\n";
} finally {
    foreach (glob($root . '/*') as $file) { unlink($file); }
    rmdir($root);
}
