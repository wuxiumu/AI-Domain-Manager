<?php

/** Atomic per-IP budgets shared by every PHP worker and browser session. */
class LoginRateLimiter
{
    public const MAX_FAILURES = 5;
    public const LOCK_SECONDS = 300;
    private PrivateJsonStore $store;
    private $clock;

    public function __construct(string $directory, ?callable $clock = null)
    {
        $this->store = new PrivateJsonStore($directory . '/login_limits.json');
        $this->clock = $clock ?? static function () { return time(); };
    }

    public static function clientIp(): string
    {
        // Forwarding headers are caller-controlled unless a trusted web server resolves them.
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $packed = @inet_pton($ip);
        return $packed === false ? substr($ip, 0, 128) : inet_ntop($packed);
    }

    public function run(string $ip, string $bucket, ?callable $verify = null): array
    {
        if (!in_array($bucket, ['login', 'captcha'], true)) { throw new InvalidArgumentException('Invalid login budget'); }
        $now = ($this->clock)();
        $key = hash('sha256', $bucket . ':' . $ip);
        $result = [];
        $this->store->update(function (array $state) use ($now, $key, $bucket, $verify, &$result): array {
            $entries = $state['entries'] ?? [];
            foreach ($entries as $id => $entry) { if (($entry['expires_at'] ?? 0) <= $now) { unset($entries[$id]); } }
            if (!isset($entries[$key]) && count($entries) >= 10000) {
                $result = $this->limited(60);
                return ['entries' => $entries];
            }
            $entry = $entries[$key] ?? [];
            if (($entry['blocked_until'] ?? 0) > $now) {
                $result = $this->limited($entry['blocked_until'] - $now);
            } else {
                if (!empty($entry['blocked_until']) || ($entry['failure_start'] ?? 0) + 300 <= $now) {
                    $entry['failures'] = 0; $entry['failure_start'] = $now; $entry['blocked_until'] = 0;
                }
                if (($entry['window_start'] ?? 0) + 60 <= $now) { $entry['requests'] = 0; $entry['window_start'] = $now; }
                $entry += ['window_start' => $now, 'requests' => 0, 'failures' => 0, 'failure_start' => $now];
                if ($entry['requests'] >= ($bucket === 'login' ? 10 : 30)) {
                    $result = $this->limited(max(1, $entry['window_start'] + 60 - $now));
                } else {
                    $entry['requests']++;
                    // Verification stays inside the stable lock: parallel failures cannot race past the limit.
                    $result = $verify ? $verify() : ['ok' => true];
                    if ($bucket === 'login') {
                        if ($result['ok']) { $entry['failures'] = 0; $entry['failure_start'] = $now; }
                        else {
                            if ($entry['failures'] === 0) { $entry['failure_start'] = $now; }
                            if (++$entry['failures'] >= self::MAX_FAILURES) {
                                $entry['blocked_until'] = $now + self::LOCK_SECONDS;
                                $result = $this->limited(self::LOCK_SECONDS);
                            }
                        }
                    }
                }
            }
            $entry['expires_at'] = $now + 600;
            $entries[$key] = $entry;
            return ['entries' => $entries];
        });
        return $result;
    }

    private function limited(int $seconds): array
    {
        return ['ok' => false, 'status' => 429, 'code' => 'rate_limited', 'retry_after' => $seconds,
            'error' => '尝试过于频繁，请 ' . $seconds . ' 秒后重试'];
    }
}
