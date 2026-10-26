<?php
/** Offline restore, validation and recovery regressions. No live assets or cloud requests. */
spl_autoload_register(static function ($class) {
    foreach (['core', 'services', 'controllers'] as $folder) {
        $path = __DIR__ . '/../app/' . $folder . '/' . $class . '.php';
        if (is_file($path)) { require_once $path; return; }
    }
});
date_default_timezone_set('Asia/Shanghai');
$root = sys_get_temp_dir() . '/workspace-maintenance-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$assertions = 0;
function verifyMaintenance($ok, string $message): void { global $assertions; $assertions++; if (!$ok) { throw new RuntimeException($message); } }
function rejectsMaintenance(callable $work, string $type): void {
    try { $work(); } catch (Throwable $e) { verifyMaintenance($e instanceof $type, 'Wrong exception: ' . get_class($e) . ' ' . $e->getMessage()); return; }
    throw new RuntimeException('Expected ' . $type);
}
function cleanMaintenance(string $dir): void { foreach (scandir($dir) as $name) { if ($name === '.' || $name === '..') continue; is_dir($dir . '/' . $name) ? cleanMaintenance($dir . '/' . $name) : unlink($dir . '/' . $name); } rmdir($dir); }
try {
    $domainId = 'domain_' . substr(hash('sha256', 'cloud_fixture|example.com'), 0, 24);
    $ipId = 'ip_' . substr(hash('sha256', '8.8.8.8|443'), 0, 24);
    WorkspaceData::write($root . '/domains.json', [['domain' => 'example.com', 'provider' => 'cloud_fixture', 'provider_name' => 'Fixture cloud', 'valid' => true,
        'note' => '=SUM(1,2)', 'expire_at' => '2030-01-01', 'raw' => ['credential' => 'must-never-export']]]);
    WorkspaceData::write($root . '/health.json', ['version' => 1, 'manual_ips' => [$ipId => ['id' => $ipId, 'ip' => '8.8.8.8', 'port' => 443, 'name' => 'Fixture IP', 'note' => '交接备注',
        'links' => [['name' => '示例站点', 'url' => 'https://www.example.com/path?a=1&b=2']], 'extra_secret' => 'must-never-export']],
        'bindings' => [$domainId => [$ipId => ['ip' => '8.8.8.8', 'port' => 443, 'confirmed_at' => '2026-01-01 00:00:00']]],
        'observations' => ['do-not-export' => ['health' => ['status' => 'healthy']]]]);
    WorkspaceData::write($root . '/settings.json', ['secret' => 'must-never-export']);
    WorkspaceData::write($root . '/record_access.json', ['old-result' => ['status' => 'ok']]);
    $service = new MaintenanceService($root, ['cloud_fixture']);
    $snapshot = AssetSnapshot::capture($root);
    $raw = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
    verifyMaintenance(strpos($raw, 'must-never-export') === false && strpos($raw, 'do-not-export') === false, 'No secrets, raw cloud data or observations exported');
    $valid = AssetSnapshot::validate(AssetSnapshot::decode($raw), ['cloud_fixture']);
    verifyMaintenance($valid['counts'] === ['domains' => 1, 'ips' => 1, 'bindings' => 1], 'Counts match fixtures');
    verifyMaintenance(isset($valid['files']['health.json']['bindings'][$domainId][$ipId]), 'Stable association retained');
    $migrated = AssetSnapshot::validate($snapshot, []);
    $manualId = 'domain_' . substr(hash('sha256', 'manual|example.com'), 0, 24);
    verifyMaintenance($migrated['converted_domains'] === 1 && $migrated['files']['domains.json'][0]['provider'] === 'manual', 'Unknown account becomes a manual asset');
    verifyMaintenance(isset($migrated['files']['health.json']['bindings'][$manualId][$ipId]), 'Migration remaps association IDs');
    $backup = $service->backup();
    verifyMaintenance(count($service->backups()) === 1, 'Backup listed');
    verifyMaintenance((fileperms($root . '/backups/' . $backup['id'] . '.json') & 0777) === 0600, 'Backup owner-only');
    verifyMaintenance((fileperms($root . '/backups') & 0777) === 0700, 'Backup folder owner-only');
    $download = $service->download($backup['id']);
    verifyMaintenance(hash('sha256', $download['content']) === $download['sha256'], 'Download checksum verified');
    verifyMaintenance(strpos($service->export('csv')['content'], "'=SUM(1,2)") !== false, 'CSV formula is neutralized');
    verifyMaintenance(substr($service->export('csv')['content'], 0, 3) === "\xEF\xBB\xBF", 'CSV is UTF-8 with BOM');
    rejectsMaintenance(fn () => $service->download('../../settings'), InvalidArgumentException::class);
    rejectsMaintenance(fn () => $service->download(str_repeat('a', 32)), OutOfBoundsException::class);
    rejectsMaintenance(fn () => $service->export('php'), InvalidArgumentException::class);
    rejectsMaintenance(fn () => AssetSnapshot::decode('{}'), InvalidArgumentException::class);
    rejectsMaintenance(fn () => AssetSnapshot::decode(str_repeat('x', AssetSnapshot::MAX_BYTES + 1)), InvalidArgumentException::class);
    foreach (['domain' => 'http://example.com/path', 'expire_at' => '2026-02-30', 'valid' => 'yes', 'note' => str_repeat('x', 2001)] as $field => $bad) {
        $invalid = $snapshot; $invalid['domains'][0][$field] = $bad;
        rejectsMaintenance(fn () => AssetSnapshot::validate($invalid, ['cloud_fixture']), InvalidArgumentException::class);
    }
    $invalid = $snapshot; $invalid['health']['manual_ips'][$ipId]['ip'] = '127.0.0.1';
    rejectsMaintenance(fn () => AssetSnapshot::validate($invalid, ['cloud_fixture']), InvalidArgumentException::class);
    $invalid = $snapshot; $invalid['health']['manual_ips'][$ipId]['links'][0]['url'] = 'javascript:alert(1)';
    rejectsMaintenance(fn () => AssetSnapshot::validate($invalid, ['cloud_fixture']), InvalidArgumentException::class);
    $invalid = $snapshot; $invalid['health']['bindings'][$domainId]['missing'] = ['ip' => '8.8.8.8'];
    rejectsMaintenance(fn () => AssetSnapshot::validate($invalid, ['cloud_fixture']), InvalidArgumentException::class);
    $invalid = $snapshot; $invalid['domains'][] = $snapshot['domains'][0];
    rejectsMaintenance(fn () => AssetSnapshot::validate($invalid, ['cloud_fixture']), InvalidArgumentException::class);
    $invalid = $snapshot; $invalid['health']['bindings']['removed-domain'] = $snapshot['health']['bindings'][$domainId];
    verifyMaintenance(AssetSnapshot::validate($invalid, ['cloud_fixture'])['orphaned_bindings'] === 1, 'Orphaned association explicitly counted');

    $preview = $service->preview(['backup_id' => $backup['id']], 'session-one');
    rejectsMaintenance(fn () => $service->restore($preview['token'], 'session-two'), DomainException::class);
    $planPath = $root . '/restore-previews/' . $preview['token'] . '.json';
    $plan = WorkspaceData::read($planPath); $plan['expires'] = time() - 1; WorkspaceData::write($planPath, $plan);
    rejectsMaintenance(fn () => $service->restore($preview['token'], 'session-one'), DomainException::class);
    $preview = $service->preview(['backup_id' => $backup['id']], 'session-one');
    WorkspaceData::write($root . '/meta.json', ['new_sync' => true]);
    rejectsMaintenance(fn () => $service->restore($preview['token'], 'session-one'), DomainException::class);
    $preview = $service->preview(['backup_id' => $backup['id']], 'session-one');
    $restored = $service->restore($preview['token'], 'session-one');
    verifyMaintenance($restored['ok'] && $restored['safety_backup']['reason'] === 'before-restore', 'Restore has a safety backup');
    verifyMaintenance(WorkspaceData::read($root . '/health.json')['observations'] === [], 'Probe observations cleared');
    verifyMaintenance(WorkspaceData::read($root . '/access_check.json') === [], 'Legacy probe results cleared');
    verifyMaintenance(WorkspaceData::read($root . '/record_access.json') === [], 'Record access results cleared');
    verifyMaintenance(!isset(WorkspaceData::read($root . '/meta.json')['last_sync']) && WorkspaceData::read($root . '/meta.json')['status'] === 'restored', 'Restored inventory cannot inherit a newer sync timestamp');
    verifyMaintenance(isset(WorkspaceData::read($root . '/health.json')['bindings'][$domainId][$ipId]), 'Restored association retained');
    rejectsMaintenance(fn () => $service->restore($preview['token'], 'session-one'), DomainException::class);
    verifyMaintenance(WorkspaceData::read($root . '/settings.json')['secret'] === 'must-never-export', 'Settings never restored or replaced');
    $service->deleteBackup($backup['id']);
    verifyMaintenance(count($service->backups()) === 1, 'Explicit deletion removes only selected backup');

    $before = file_get_contents($root . '/domains.json');
    WorkspaceData::write($root . '/restore-journal.json', ['before' => ['domains.json' => $before, 'meta.json' => null]]);
    WorkspaceData::write($root . '/domains.json', []);
    WorkspaceData::recover($root);
    verifyMaintenance(file_get_contents($root . '/domains.json') === $before && !is_file($root . '/meta.json'), 'Interrupted restore rolled back, including formerly absent files');
    verifyMaintenance(!is_file($root . '/restore-journal.json'), 'Recovery journal consumed');
    WorkspaceData::write($root . '/restore-journal.json', ['before' => ['../outside.json' => '{}']]);
    rejectsMaintenance(fn () => WorkspaceData::recover($root), RuntimeException::class);
    unlink($root . '/restore-journal.json');
    verifyMaintenance(!is_file(dirname($root) . '/outside.json'), 'Journal cannot escape file allowlist');

    $probe = new HealthService($root, static function () use ($root) {
        WorkspaceData::write($root . '/workspace-epoch.json', ['epoch' => 'restored-during-probe']);
        return ['status' => 'healthy'];
    });
    rejectsMaintenance(fn () => $probe->check([$ipId]), DomainException::class);
    verifyMaintenance(WorkspaceData::read($root . '/health.json')['observations'] === [], 'In-flight pre-restore checks cannot repopulate old state');
    file_put_contents($root . '/domains.json', 'corrupt');
    rejectsMaintenance(fn () => $service->backup(), RuntimeException::class);
    echo 'Maintenance: ' . $assertions . " offline assertions passed\n";
} finally { WorkspaceData::release($root); cleanMaintenance($root); }
