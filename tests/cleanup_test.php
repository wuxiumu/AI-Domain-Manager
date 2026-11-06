<?php

require_once __DIR__ . '/../app/core/JsonStore.php';
require_once __DIR__ . '/../app/core/PrivateJsonStore.php';
require_once __DIR__ . '/../app/core/WorkspaceData.php';
require_once __DIR__ . '/../app/services/DnsRecordCache.php';
require_once __DIR__ . '/../app/services/HealthService.php';
require_once __DIR__ . '/../app/services/CleanupService.php';

$n = 0;
function check($pass, string $message): void { global $n; $n++; if (!$pass) throw new RuntimeException($message); }
function rejects(callable $action, string $class, string $message): void {
    try { $action(); } catch (Throwable $error) { check($error instanceof $class, $message . '（实际：' . get_class($error) . ' ' . $error->getMessage() . '）'); return; }
    check(false, $message);
}
function cleanupDir(string $path): void {
    if (is_dir($path)) { foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') cleanupDir($path . '/' . $name); } rmdir($path); }
    elseif (is_file($path)) unlink($path);
}
function domainId(string $provider, string $domain): string { return 'domain_' . substr(hash('sha256', $provider . '|' . strtolower($domain)), 0, 24); }
function hostId(string $provider, string $fqdn): string { return 'host_' . substr(hash('sha256', $provider . '|' . strtolower($fqdn)), 0, 24); }

$root = sys_get_temp_dir() . '/cleanup-' . bin2hex(random_bytes(8));
mkdir($root . '/cache', 0700, true);

$accounts = [
    ['id' => 'acc_live', 'name' => '在用账号', 'enabled' => true],
    ['id' => 'acc_off', 'name' => '停用账号', 'enabled' => false],
];
$domains = [
    ['domain' => 'example.com', 'provider' => 'aliyun_1', 'provider_name' => '阿里云-账号1', 'expire_at' => '2027-01-01', 'note' => '孤儿', 'valid' => true],
    ['domain' => 'legacy.example.com', 'provider' => 'aliyun_1', 'provider_name' => '阿里云-账号1', 'expire_at' => '2027-02-01', 'note' => '', 'valid' => true],
    ['domain' => 'other.com', 'provider' => 'acc_off', 'provider_name' => '停用账号', 'expire_at' => '2027-03-01', 'note' => '停用账号下', 'valid' => true],
    ['domain' => 'live.com', 'provider' => 'acc_live', 'provider_name' => '在用账号', 'expire_at' => '2027-04-01', 'note' => '', 'valid' => true],
    ['domain' => 'mine.com', 'provider' => 'manual', 'provider_name' => '手动添加', 'expire_at' => null, 'note' => '', 'valid' => true],
];
JsonStore::write($root . '/domains.json', $domains);

$orphanId = domainId('aliyun_1', 'example.com');
$orphanHostId = hostId('aliyun_1', 'www.example.com');
$liveId = domainId('acc_live', 'live.com');
$now = date('Y-m-d H:i:s');
$old = date('Y-m-d H:i:s', time() - 90 * 86400);
$okHealth = static fn (string $at) => ['status' => 'healthy', 'checked_at' => $at, 'message' => 'HTTP 200', 'checks' => [], 'resolved_ips' => []];
$health = [
    'manual_ips' => [],
    'observations' => [
        $orphanId => ['health' => $okHealth($old), 'history' => [
            ['checked_at' => $now, 'status' => 'healthy', 'message' => 'HTTP 200'],
            ['checked_at' => $old, 'status' => 'unhealthy', 'message' => 'HTTP 500'],
        ]],
        $orphanHostId => ['health' => $okHealth($old), 'history' => []],
        $liveId => ['health' => $okHealth($now), 'history' => [
            ['checked_at' => $old, 'status' => 'healthy', 'message' => 'HTTP 200'],
        ]],
    ],
    'bindings' => [$orphanId => ['ip_fixture' => ['ip' => '192.0.2.10', 'port' => 443, 'confirmed_at' => $old]]],
    'check_events' => [
        ['id' => 'e1', 'asset_id' => $orphanId, 'kind' => 'domain', 'name' => 'example.com', 'provider' => 'aliyun_1', 'domain' => 'example.com', 'checked_at' => $old, 'status' => 'unhealthy'],
        ['id' => 'e2', 'asset_id' => $orphanHostId, 'kind' => 'domain', 'name' => 'www.example.com', 'provider' => 'aliyun_1', 'domain' => 'example.com', 'checked_at' => $now, 'status' => 'healthy'],
        ['id' => 'e3', 'asset_id' => $liveId, 'kind' => 'domain', 'name' => 'live.com', 'provider' => 'acc_live', 'domain' => 'live.com', 'checked_at' => $now, 'status' => 'healthy'],
    ],
];
JsonStore::write($root . '/health.json', $health);

// 孤儿域名的 DNS 缓存（子域名清单来源）；启用账号的缓存一并存在以验证不被误删。
DnsRecordCache::locked($root, 'aliyun_1', 'example.com', static function () use ($root, $old) {
    JsonStore::write($root . '/cache/dns-' . hash('sha256', 'aliyun_1|example.com') . '.json',
        ['records' => [['id' => 'r1', 'type' => 'A', 'fqdn' => 'www.example.com', 'value' => '192.0.2.10', 'ttl' => 600]], 'snapshot_at' => $old]);
});
JsonStore::write($root . '/access_check.json', ['results' => [
    'aliyun_1|example.com' => ['status' => 'ok'], 'acc_live|live.com' => ['status' => 'ok'],
]]);
JsonStore::write($root . '/record_access.json', [
    'aliyun_1|example.com' => ['results' => []], 'acc_live|live.com' => ['results' => []],
]);
JsonStore::write($root . '/dns_changes.json', ['entries' => [
    ['id' => 'c1', 'domain' => 'example.com', 'account' => 'aliyun_1', 'started_at' => $old, 'outcome' => 'verified'],
    ['id' => 'c2', 'domain' => 'live.com', 'account' => 'acc_live', 'started_at' => $now, 'outcome' => 'verified'],
    ['id' => 'c3', 'domain' => 'example.com', 'account' => 'aliyun_1', 'started_at' => $now, 'outcome' => 'verified'],
]]);
JsonStore::write($root . '/audit.json', ['entries' => [
    ['id' => 'a1', 'started_at' => date('c', time() - 90 * 86400), 'route' => '/api/domains'],
    ['id' => 'a2', 'started_at' => date('c'), 'route' => '/api/sync'],
]]);
JsonStore::write($root . '/sync_log.json', [
    ['time' => $old, 'type' => 'sync', 'data' => []],
    ['time' => $now, 'type' => 'domain_create', 'data' => []],
    ['time' => $now, 'type' => 'sync', 'data' => []],
]);

$service = new CleanupService($root, $accounts);

try {
    /* 概览：孤儿只统计已删除与已停用账号 */
    $overview = $service->overview();
    check(count($overview['groups']) === 2, '概览应发现 2 个不可用账号分组');
    $aliyun = $overview['groups'][0];
    check($aliyun['provider'] === 'aliyun_1' && $aliyun['label'] === 'aliyun_1' && $aliyun['disabled'] === false, '已删除账号分组显示原始 id');
    check(count($aliyun['domains']) === 2, '已删除账号下有 2 条域名');
    $off = $overview['groups'][1];
    check($off['provider'] === 'acc_off' && $off['disabled'] === true && $off['label'] === '停用账号', '停用账号分组带名称与停用标记');
    check($overview['logs']['check_events']['total'] === 3, '检查事件计数');
    check($overview['logs']['check_events']['history_entries'] === 3, '资产历史条数');
    check($overview['logs']['dns_changes']['total'] === 3 && $overview['logs']['sync_log']['total'] === 3 && $overview['logs']['audit']['total'] === 2, '其余日志计数');
    check($overview['logs']['sync_log']['types']['sync'] === 2, '同步日志类型统计');

    /* 护栏：启用中的账号拒绝清理 */
    rejects(fn () => $service->cleanOrphans('acc_live', 'delete'), DomainException::class, '启用中的账号拒绝清理');
    rejects(fn () => $service->cleanOrphans('aliyun_1', 'purge'), InvalidArgumentException::class, '无效动作被拒绝');
    rejects(fn () => $service->cleanOrphans('aliyun_1', 'delete', ['not-here.com']), OutOfBoundsException::class, '组外域名被拒绝');
    rejects(fn () => $service->cleanLogs('nope', ['all' => true]), InvalidArgumentException::class, '未知日志类型被拒绝');
    rejects(fn () => $service->cleanLogs('dns_changes', []), InvalidArgumentException::class, '缺少维度被拒绝');

    /* 转为手工：保留台账行，清理该域名全部残留 */
    $result = $service->cleanOrphans('aliyun_1', 'manual', ['EXAMPLE.com']);
    check($result['ok'] && $result['domains'] === 1, '转为手工按域名执行（大小写不敏感）');
    $rows = JsonStore::read($root . '/domains.json');
    $converted = array_values(array_filter($rows, static fn ($r) => strtolower((string) $r['domain']) === 'example.com'))[0];
    check($converted['provider'] === 'manual' && $converted['provider_name'] === '手动添加', '台账行转为手工资产');
    check(!is_file($root . '/cache/dns-' . hash('sha256', 'aliyun_1|example.com') . '.json'), 'DNS 缓存已删除');
    $state = JsonStore::read($root . '/health.json');
    check(!isset($state['observations'][$orphanId]) && !isset($state['observations'][$orphanHostId]), '主域名与子域名观测已删除');
    check(!isset($state['bindings'][$orphanId]), '孤儿绑定已删除');
    check(count(array_filter($state['check_events'], static fn ($e) => ($e['provider'] ?? '') === 'aliyun_1')) === 0, '该域名的检查事件已删除');
    check(count(array_filter($state['check_events'], static fn ($e) => ($e['provider'] ?? '') === 'acc_live')) === 1, '在用账号的检查事件保留');
    check(isset($state['observations'][$liveId]), '在用账号观测保留');
    $access = JsonStore::read($root . '/access_check.json');
    check(!isset($access['results']['aliyun_1|example.com']) && isset($access['results']['acc_live|live.com']), '遗留访问检测条目删除且在用条目保留');
    $recordAccess = JsonStore::read($root . '/record_access.json');
    check(!isset($recordAccess['aliyun_1|example.com']) && isset($recordAccess['acc_live|live.com']), '解析记录访问条目删除且在用条目保留');

    /* 同名域名兜底：孤儿账号观测不在展示资产表时也能按哈希删除（legacy.example.com 无缓存文件） */
    $state = JsonStore::read($root . '/health.json');
    JsonStore::write($root . '/health.json', array_replace($state, ['observations' => $state['observations'] + [
        domainId('aliyun_1', 'legacy.example.com') => ['health' => ['status' => 'healthy', 'checked_at' => $old, 'checks' => [], 'resolved_ips' => []], 'history' => []],
    ]]));
    $result = $service->cleanOrphans('aliyun_1', 'delete');
    check($result['ok'] && $result['domains'] === 1, '整组删除剩余孤儿域名');
    $rows = JsonStore::read($root . '/domains.json');
    check(!in_array('aliyun_1', array_column($rows, 'provider'), true), '台账中已无该孤儿账号的行');
    check(count(array_filter(array_column($rows, 'provider'), static fn ($p) => $p === 'acc_off')) === 1, '停用账号的行不受影响');
    check(!isset(JsonStore::read($root . '/health.json')['observations'][domainId('aliyun_1', 'legacy.example.com')]), '无缓存文件时也能按哈希删除观测');

    /* 日志按维度删除 */
    $result = $service->cleanLogs('dns_changes', ['domain' => 'example.com']);
    check($result['removed'] === 2, '按域名删 DNS 变更（转手工前后共残留 2 条）');
    check(count(JsonStore::read($root . '/dns_changes.json')['entries']) === 1, 'DNS 变更剩余在用账号条目');
    $result = $service->cleanLogs('audit', ['before' => date('Y-m-d', time() - 30 * 86400)]);
    check($result['removed'] === 1, '按时间删操作记录');
    check(count(JsonStore::read($root . '/audit.json')['entries']) === 1, '操作记录保留新条目');
    $result = $service->cleanLogs('sync_log', ['type' => 'sync']);
    check($result['removed'] === 2, '按类型删同步日志');
    $result = $service->cleanLogs('check_events', ['before' => date('Y-m-d', time() - 30 * 86400)]);
    check($result['events_removed'] === 0 && $result['history_removed'] === 1, '检查历史按时间删除旧条目');
    $state = JsonStore::read($root . '/health.json');
    check(($state['observations'][$liveId]['health']['status'] ?? '') === 'healthy' && !is_file($root . '/history/' . $liveId . '.json'), '清历史后保留最近健康状态，历史文件一并删除');
    $result = $service->cleanLogs('check_events', ['all' => true]);
    check($result['events_removed'] === 1 && $result['removed'] === 1, '清空检查事件');
    check(JsonStore::read($root . '/health.json')['check_events'] === [], '检查事件已清空');

    echo "数据清理：$n assertions passed（孤儿删除/转手工、四类日志按维度删除、护栏拒绝）\n";
} finally { cleanupDir($root); }
