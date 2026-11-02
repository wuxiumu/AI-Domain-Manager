<?php
/** Offline regressions for the AI proposal queue. Run: php tests/ai_proposal_test.php */
require_once __DIR__ . '/../app/providers/CloudProvider.php';
require_once __DIR__ . '/../app/core/PrivateJsonStore.php';
require_once __DIR__ . '/../app/core/JsonStore.php';
require_once __DIR__ . '/../app/services/DnsRecordCache.php';
require_once __DIR__ . '/../app/services/AiProposalService.php';
date_default_timezone_set('Asia/Shanghai');

/** 可控的假云商：记录存内存，可中途变化以模拟版本冲突。 */
final class FakeProvider extends CloudProvider
{
    public array $records = [];
    public array $log = [];

    public function __construct(array $records = []) { $this->records = $records; }

    public function testConnection(): array { return ['ok' => true, 'count' => 0, 'samples' => [], 'latency_ms' => 1]; }
    public function listDomains(): array { return []; }
    public function listRecords(string $domain): array { return array_map(static fn (array $r): array => $r, array_values($this->records)); }
    public function addRecord(string $domain, array $params): array
    {
        $this->log[] = ['add', $params];
        $record = array_merge(['id' => 'rec-' . (count($this->records) + 1), 'ttl' => 600, 'priority' => 0, 'status' => 'enable', 'line' => 'default'], $params);
        $this->records[] = $record;
        return $record;
    }
    public function updateRecord(string $domain, string $recordId, array $params): bool
    {
        $this->log[] = ['update', $recordId, $params];
        foreach ($this->records as $i => $record) {
            if ($record['id'] === $recordId) { $this->records[$i] = array_merge($record, $params); return true; }
        }
        return false;
    }
    public function deleteRecord(string $domain, string $recordId): bool
    {
        $this->log[] = ['delete', $recordId];
        foreach ($this->records as $i => $record) {
            if ($record['id'] === $recordId) { unset($this->records[$i]); return true; }
        }
        return false;
    }
}

$root = sys_get_temp_dir() . '/workbench-ai-proposal-test-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$assertions = 0;
function expect($condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}
function throws(callable $work, string $class, string $message): void
{
    try { $work(); } catch (Throwable $e) { expect($e instanceof $class, $message . ' (' . get_class($e) . ')'); return; }
    expect(false, $message);
}
function removeTree(string $dir): void
{
    foreach (glob($dir . '/*') as $path) { is_dir($path) ? removeTree($path) : unlink($path); }
    rmdir($dir);
}

try {
    file_put_contents($root . '/domains.json', json_encode([
        ['domain' => 'api.example', 'provider' => 'cloud_1'],
        ['domain' => 'gone.example', 'provider' => 'cloud_1', 'valid' => false],
    ]));
    $cloud = new FakeProvider([
        ['id' => 'rec-a', 'type' => 'A', 'name' => '@', 'value' => '8.8.8.8', 'ttl' => 600, 'priority' => 0, 'status' => 'enable', 'line' => 'default'],
    ]);
    $resolver = static function (string $id): array {
        if ($id === 'disabled') { return ['id' => 'disabled', 'provider' => 'aliyun', 'enabled' => false]; }
        if ($id !== 'cloud_1') { throw new OutOfBoundsException('云账号不存在'); }
        return ['id' => 'cloud_1', 'provider' => 'aliyun', 'enabled' => true];
    };
    $factory = static function (array $account) use ($cloud): CloudProvider { return $cloud; };
    $service = new AiProposalService($root, $resolver, $factory);
    $actor = ['user' => 'ai-bot', 'via' => 'api'];

    // 校验：账号、域名、动作、记录值、理由。
    throws(fn () => $service->create(['account' => 'nope', 'domain' => 'api.example', 'action' => 'add', 'record' => ['type' => 'A', 'name' => '@', 'value' => '8.8.4.4'], 'rationale' => '测试理由'], $actor), InvalidArgumentException::class, 'unknown account rejected');
    throws(fn () => $service->create(['account' => 'disabled', 'domain' => 'api.example', 'action' => 'add', 'record' => ['type' => 'A', 'name' => '@', 'value' => '8.8.4.4'], 'rationale' => '测试理由'], $actor), InvalidArgumentException::class, 'disabled account rejected');
    throws(fn () => $service->create(['account' => 'cloud_1', 'domain' => 'other.example', 'action' => 'add', 'record' => ['type' => 'A', 'name' => '@', 'value' => '8.8.4.4'], 'rationale' => '测试理由'], $actor), InvalidArgumentException::class, 'domain not in inventory');
    throws(fn () => $service->create(['account' => 'cloud_1', 'domain' => 'gone.example', 'action' => 'add', 'record' => ['type' => 'A', 'name' => '@', 'value' => '8.8.4.4'], 'rationale' => '测试理由'], $actor), InvalidArgumentException::class, 'invalid inventory entry rejected');
    throws(fn () => $service->create(['account' => 'cloud_1', 'domain' => 'api.example', 'action' => 'move', 'rationale' => '测试理由'], $actor), InvalidArgumentException::class, 'unknown action');
    throws(fn () => $service->create(['account' => 'cloud_1', 'domain' => 'api.example', 'action' => 'update', 'rationale' => '测试理由'], $actor), InvalidArgumentException::class, 'update requires record id');
    throws(fn () => $service->create(['account' => 'cloud_1', 'domain' => 'api.example', 'action' => 'add', 'record' => ['type' => 'A', 'name' => '@', 'value' => 'not-an-ip'], 'rationale' => '测试理由'], $actor), InvalidArgumentException::class, 'A record needs valid IP');
    throws(fn () => $service->create(['account' => 'cloud_1', 'domain' => 'api.example', 'action' => 'add', 'record' => ['type' => 'A', 'name' => '@', 'value' => '8.8.4.4'], 'rationale' => '短'], $actor), InvalidArgumentException::class, 'rationale length bound');

    // 新增提案：填充云商默认线路，管理员批准前可看到缓存原值。
    $add = $service->create(['account' => 'cloud_1', 'domain' => 'api.example', 'action' => 'add',
        'record' => ['type' => 'A', 'name' => 'www', 'value' => '8.8.4.4'], 'rationale' => 'www 子域名尚未解析，需要指向服务器', 'evidence' => '报告第二节 www 未确认'], $actor)['proposal'];
    expect($add['record']['line'] === 'default' && $add['status'] === 'pending' && $add['created_by'] === 'ai-bot', 'add proposal stored with provider default line');
    throws(fn () => $service->create(['account' => 'cloud_1', 'domain' => 'api.example', 'action' => 'add',
        'record' => ['type' => 'A', 'name' => 'www', 'value' => '8.8.4.4'], 'rationale' => '重复提交同样的变更建议'], $actor), DomainException::class, 'duplicate pending rejected');

    // 修改提案：携带缓存 before 供管理员比对（接管流程中 AI 先做 dns:read 填充缓存）。
    DnsRecordCache::read($root, 'cloud_1', 'api.example', static function () use ($cloud) { return $cloud->listRecords('api.example'); });
    $cached = $service->create(['account' => 'cloud_1', 'domain' => 'api.example', 'action' => 'update', 'record_id' => 'rec-a',
        'record' => ['type' => 'A', 'name' => '@', 'value' => '9.9.9.9'], 'rationale' => '服务器迁移，A 记录需切换到新地址'], $actor)['proposal'];
    expect($cached['before']['value'] === '8.8.8.8', 'cached before values captured for admin diff');

    // 批准新增：云端最新 revision → 单次写入 → 回读核实。
    $result = $service->approve($add['id'], ['user' => 'admin', 'via' => 'browser']);
    expect($result['ok'] === true && $result['verified'] === true && $result['status'] === 'verified', 'approved add executes and verifies');
    expect(count($cloud->log) === 1 && $cloud->log[0][0] === 'add', 'exactly one cloud write happened');
    $state = json_decode(file_get_contents($root . '/ai_proposals.json'), true);
    $executed = null;
    foreach ($state['proposals'] as $item) { if ($item['id'] === $add['id']) { $executed = $item; } }
    expect($executed['status'] === 'executed' && $executed['approved_by'] === 'admin' && $executed['result']['change_id'], 'proposal records execution result');

    // 目标消失：批准前记录已被他人删除 → 提案标记 failed 并抛出冲突，云端无写入。
    $cloud->updateRecord('api.example', 'rec-a', ['value' => '1.1.1.1']); // 先漂移值（批准以云端最新版本执行，不构成冲突）
    $drift = $service->approve($cached['id'], ['user' => 'admin']);
    expect($drift['verified'] === true, 'approval executes with the fresh cloud version');
    foreach ($cloud->records as $i => $record) { if ($record['id'] === 'rec-a') { unset($cloud->records[$i]); } }
    $missing = $service->create(['account' => 'cloud_1', 'domain' => 'api.example', 'action' => 'update', 'record_id' => 'rec-a',
        'record' => ['type' => 'A', 'name' => '@', 'value' => '9.9.9.9'], 'rationale' => '目标记录已不存在时的提案'], $actor)['proposal'];
    throws(fn () => $service->approve($missing['id'], ['user' => 'admin']), DomainException::class, 'missing target rejected with conflict');
    $state = json_decode(file_get_contents($root . '/ai_proposals.json'), true);
    foreach ($state['proposals'] as $item) { if ($item['id'] === $missing['id']) { $failed = $item; } }
    expect($failed['status'] === 'failed' && strpos($failed['error'], '已不存在') !== false, 'missing-target proposal marked failed with reason');

    // 拒绝流程：附带原因，状态可见，且不能重复处理。
    $rejectable = $service->create(['account' => 'cloud_1', 'domain' => 'api.example', 'action' => 'delete', 'record_id' => 'rec-a',
        'rationale' => '该记录已废弃，建议清理'], $actor)['proposal'];
    $service->reject($rejectable['id'], ['user' => 'admin'], '该记录仍被邮件系统使用');
    throws(fn () => $service->reject($rejectable['id'], ['user' => 'admin'], ''), DomainException::class, 'cannot reject twice');
    throws(fn () => $service->approve($rejectable['id'], ['user' => 'admin']), DomainException::class, 'cannot approve rejected proposal');
    throws(fn () => $service->approve('missing-id', ['user' => 'admin']), OutOfBoundsException::class, 'unknown proposal 404');
    $list = $service->list();
    expect($list['counts'] === ['pending' => 0, 'rejected' => 1, 'executed' => 2, 'failed' => 1], 'status counts accurate');
    expect(count($service->recent()) === 4, 'recent returns bounded newest-first list');

    // 待审批上限：第 51 条被拒。
    $fill = new AiProposalService($root, $resolver, static function (array $account) {
        return new FakeProvider([['id' => 'x', 'type' => 'TXT', 'name' => '@', 'value' => 'seed', 'ttl' => 600, 'priority' => 0, 'status' => '', 'line' => '']]);
    });
    // 直接写入 50 条 pending，再提交第 51 条。
    (new PrivateJsonStore($root . '/ai_proposals.json'))->update(static function (array $state): array {
        $kept = [];
        foreach ($state['proposals'] as $item) { if (in_array($item['status'], ['pending'])) { continue; } $kept[] = $item; }
        for ($i = 0; $i < 50; $i++) {
            $kept[] = ['id' => 'p' . $i, 'status' => 'pending', 'account' => 'cloud_1', 'domain' => 'api.example',
                'action' => 'add', 'record' => ['type' => 'TXT', 'name' => 'n' . $i, 'value' => 'v' . $i, 'ttl' => 600, 'priority' => 0, 'line' => 'default'],
                'rationale' => '测试上限', 'evidence' => '', 'record_id' => null, 'created_at' => '2026-11-02T00:00:00+08:00', 'created_by' => 'ai-bot', 'before' => null];
        }
        return ['proposals' => $kept];
    });
    throws(fn () => $fill->create(['account' => 'cloud_1', 'domain' => 'api.example', 'action' => 'add',
        'record' => ['type' => 'TXT', 'name' => 'overflow', 'value' => 'v'], 'rationale' => '超过待审批上限'], $actor), DomainException::class, 'pending cap enforced');

    // 云端工厂不可用：批准时标记 failed 并抛出冲突，提案保留原因。
    $brokenFactory = new AiProposalService($root, $resolver, static function (array $account): CloudProvider {
        throw new RuntimeException('该云账号已停用，请先在云账号配置中启用');
    });
    $store2 = (new PrivateJsonStore($root . '/ai_proposals.json'));
    $store2->update(static function (array $state): array {
        $state['proposals'][] = ['id' => 'factory0', 'status' => 'pending', 'account' => 'cloud_1', 'domain' => 'api.example',
            'action' => 'delete', 'record_id' => 'whatever', 'record' => null, 'rationale' => '工厂不可用场景', 'evidence' => '',
            'created_at' => '2026-11-02T00:00:00+08:00', 'created_by' => 'ai-bot', 'before' => null];
        return $state;
    });
    throws(fn () => $brokenFactory->approve('factory0', ['user' => 'admin']), DomainException::class, 'unavailable cloud fails the approval');
    $state = json_decode(file_get_contents($root . '/ai_proposals.json'), true);
    foreach ($state['proposals'] as $item) { if ($item['id'] === 'factory0') { $broken = $item; } }
    expect($broken['status'] === 'failed' && strpos($broken['error'], '已停用') !== false, 'factory failure stored on proposal');

    echo 'PASS: ' . $assertions . " AI proposal assertions\n";
} finally {
    removeTree($root);
}
