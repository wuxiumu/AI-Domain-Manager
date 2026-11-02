<?php
/** Run with cron as the same user that owns the application data. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../app/bootstrap.php';
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
$service = new HealthService();
$ids = array_column(array_filter($service->dashboard()['assets'], static function ($asset) {
    return $asset['kind'] === 'domain';
}), 'id');
$checked = 0;
try {
    foreach (array_chunk($ids, 5) as $batch) { $checked += $service->check($batch)['checked']; }
    echo json_encode(['ok' => true, 'checked' => $checked], JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Domain check failed after $checked checks: " . get_class($e) . "\n");
    exit(1);
}
