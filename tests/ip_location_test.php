<?php
require __DIR__ . '/../app/bootstrap.php';
$dir = sys_get_temp_dir() . '/ip-location-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
function locationAssert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function locationClean($dir) { foreach (glob($dir . '/*') ?: [] as $path) { if (is_dir($path)) locationClean($path); else unlink($path); } rmdir($dir); }
try {
    $asset = (new HealthService($dir))->addIp(['ip' => '8.8.8.8'])['asset'];
    $service = new IpLocationService($dir, static function ($url) {
        locationAssert($url === 'https://ipwho.is/8.8.8.8', 'Expected fixed HTTPS provider and IP');
        return ['status' => 200, 'body' => json_encode(['success' => true, 'ip' => '8.8.8.8', 'country' => 'United States', 'city' => 'Mountain View', 'connection' => ['isp' => 'Example']])];
    });
    $result = $service->refresh($asset['id']);
    locationAssert($result['location']['city'] === 'Mountain View', 'Provider response parsed');
    $dashboard = (new HealthService($dir))->dashboard();
    locationAssert($dashboard['assets'][0]['location']['isp'] === 'Example', 'Cached location appears on dashboard');
    $failed = new IpLocationService($dir, static function () { return ['status' => 429, 'body' => '']; });
    try { $failed->refresh($asset['id']); throw new RuntimeException('Expected failure'); } catch (RuntimeException $e) { locationAssert(strpos($e->getMessage(), '限流') !== false, 'Rate limit reported'); }
    locationAssert((new HealthService($dir))->dashboard()['assets'][0]['location']['city'] === 'Mountain View', 'Failed refresh keeps cache');
    try { $service->refresh('missing'); throw new RuntimeException('Expected missing asset'); } catch (OutOfBoundsException $e) {}
    echo "IP location cache: passed\n";
} finally { locationClean($dir); }
