<?php
/** Offline freshness regressions; cloud calls are replaced by local test doubles. */
require_once __DIR__ . '/../app/core/JsonStore.php';
require_once __DIR__ . '/../app/services/SyncService.php';
$root = sys_get_temp_dir() . '/workspace-sync-test-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true); define('DATA_DIR', $root);
class Logger { public static function log(string $event, array $data): void {} }
function config(?string $key = null) { return $GLOBALS['syncAccounts']; }
function provider_factory(array $account) { return new class($account) {
    private array $account;
    public function __construct(array $account) { $this->account = $account; }
    public function listDomains(): array {
        if (!empty($this->account['fail'])) { throw new RuntimeException('Offline simulated cloud error'); }
        return [['domain' => 'example.com', 'status' => 'ok']];
    }
}; }
$count = 0;
function syncVerify(bool $ok, string $message): void { global $count; $count++; if (!$ok) throw new RuntimeException($message); }
try {
    JsonStore::write($root . '/domains.json', [['domain' => 'example.com', 'provider' => 'fixture', 'note' => 'Keep note', 'valid' => true]]);
    JsonStore::write($root . '/meta.json', ['last_sync' => '2020-01-01 00:00:00']);
    $GLOBALS['syncAccounts'] = [['id' => 'fixture', 'name' => 'Fixture cloud', 'fail' => true]];
    $failed = SyncService::syncAll();
    syncVerify($failed['status'] === 'failed' && $failed['last_sync'] === '2020-01-01 00:00:00', 'Failed sync preserves freshness');
    syncVerify(!empty($failed['last_attempt_at']), 'Failed attempt has its own timestamp');
    syncVerify(JsonStore::read($root . '/domains.json')[0]['valid'], 'Failure does not invalidate assets');
    $GLOBALS['syncAccounts'][0]['enabled'] = false;
    $skipped = SyncService::syncAll();
    syncVerify($skipped['status'] === 'skipped' && $skipped['last_sync'] === '2020-01-01 00:00:00', 'Disabled accounts do not make data look fresh');
    $GLOBALS['syncAccounts'] = [['id' => 'fixture', 'name' => 'Fixture cloud']];
    $success = SyncService::syncAll();
    syncVerify($success['status'] === 'success' && $success['last_sync'] !== '2020-01-01 00:00:00', 'Success updates freshness');
    syncVerify(JsonStore::read($root . '/domains.json')[0]['note'] === 'Keep note', 'Sync preserves local notes');
    $GLOBALS['syncAccounts'][] = ['id' => 'failed-fixture', 'name' => 'Other fixture', 'fail' => true];
    $partial = SyncService::syncAll();
    syncVerify($partial['status'] === 'partial' && count($partial['results']) === 2, 'Partial sync remains explicit');
    syncVerify(JsonStore::read($root . '/meta.json')['status'] === 'partial', 'Partial state persists for the dashboard');
    echo 'Sync freshness: ' . $count . " offline assertions passed\n";
} finally { foreach (glob($root . '/*') as $path) { unlink($path); } rmdir($root); }
