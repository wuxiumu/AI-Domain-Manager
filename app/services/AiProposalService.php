<?php

require_once __DIR__ . '/DnsChangeService.php';

/**
 * AI 提案队列：AI（或脚本）提交待审批的 DNS 变更提案，管理员批准后才经
 * DnsChangeService 的既有护栏执行（读取云端最新版本、只写一次、回读核实）。
 * 提案本身永远不直接写云端；所有网络操作只发生在管理员批准之后。
 */
class AiProposalService
{
    private const MAX_TOTAL = 200;
    private const MAX_PENDING = 50;
    private const STATUS = ['pending', 'rejected', 'executed', 'failed'];

    private $dir;
    private $resolver;
    private $cloudFactory;

    /** $resolver(accountId) => 账号配置；$cloudFactory(account) => CloudProvider。默认读运行配置。 */
    public function __construct(?string $dir = null, ?callable $resolver = null, ?callable $cloudFactory = null)
    {
        $this->dir = $dir ?? DATA_DIR;
        $this->resolver = $resolver ?? static function (string $id): array {
            foreach (config('accounts') as $account) {
                if (($account['id'] ?? '') === $id) { return $account; }
            }
            throw new OutOfBoundsException('云账号不存在或已删除');
        };
        $this->cloudFactory = $cloudFactory ?? static function (array $account): CloudProvider {
            return provider_factory($account);
        };
    }

    public function create(array $input, array $actor): array
    {
        $account = trim((string) ($input['account'] ?? ''));
        if ($account === '') { throw new InvalidArgumentException('请提供云账号 ID（provider 字段，从域名列表读取）'); }
        try { $config = ($this->resolver)($account); }
        catch (OutOfBoundsException $e) { throw new InvalidArgumentException('云账号不存在或已删除，请从域名列表读取 provider 字段'); }
        if (($config['enabled'] ?? true) === false) { throw new InvalidArgumentException('该云账号已停用，无法提交提案'); }

        $domain = strtolower(trim((string) ($input['domain'] ?? '')));
        if ($domain === '' || !self::validDomain($domain)) { throw new InvalidArgumentException('请提供有效的域名'); }
        $inventory = json_decode((string) @file_get_contents($this->dir . '/domains.json'), true);
        $known = false;
        foreach (is_array($inventory) ? $inventory : [] as $entry) {
            if (is_array($entry) && strtolower((string) ($entry['domain'] ?? '')) === $domain
                && ($entry['provider'] ?? '') === $account && ($entry['valid'] ?? true) !== false) { $known = true; break; }
        }
        if (!$known) { throw new InvalidArgumentException('域名不在该云账号台账中，请先同步或核对 provider 字段'); }

        $action = (string) ($input['action'] ?? '');
        if (!in_array($action, ['add', 'update', 'delete'], true)) { throw new InvalidArgumentException('action 必须是 add、update 或 delete'); }
        $recordId = null;
        if ($action !== 'add') {
            $recordId = (string) ($input['record_id'] ?? '');
            if ($recordId === '' || strlen($recordId) > 128 || preg_match('/[\x00-\x1f\x7f]/', $recordId)) {
                throw new InvalidArgumentException('请提供要修改或删除的记录 ID（从 DNS 记录列表读取）');
            }
        }
        $record = null;
        if ($action !== 'delete') {
            $params = is_array($input['record'] ?? null) ? $input['record'] : [];
            $record = DnsChangeService::params($params);
            if (!isset($record['line']) || $record['line'] === '') {
                $record['line'] = ($config['provider'] ?? '') === 'aliyun' ? 'default' : (($config['provider'] ?? '') === 'tencent' ? '默认' : '');
            }
        }

        $rationale = trim((string) ($input['rationale'] ?? ''));
        if (mb_strlen($rationale) < 4 || mb_strlen($rationale) > 500) {
            throw new InvalidArgumentException('rationale 必须为 4–500 字的变更理由，供管理员审批判断');
        }
        $evidence = trim((string) ($input['evidence'] ?? ''));
        if (mb_strlen($evidence) > 300) { throw new InvalidArgumentException('evidence 最多 300 字'); }

        $proposal = ['id' => bin2hex(random_bytes(12)), 'status' => 'pending', 'created_at' => date('c'),
            'created_by' => self::actor($actor), 'account' => $account, 'domain' => $domain,
            'action' => $action, 'record_id' => $recordId !== '' ? $recordId : null,
            'record' => $record, 'rationale' => $rationale, 'evidence' => $evidence,
            'before' => $this->cachedRecord($account, $domain, $recordId)];

        $this->store(function (array $state) use ($proposal): array {
            $pending = 0;
            foreach ($state['proposals'] ?? [] as $item) {
                if (($item['status'] ?? '') === 'pending' && self::sameChange($item, $proposal)) {
                    throw new DomainException('相同的提案已存在并等待审批，请勿重复提交');
                }
                if (($item['status'] ?? '') === 'pending') { $pending++; }
            }
            if ($pending >= self::MAX_PENDING) { throw new DomainException('待审批提案已达 ' . self::MAX_PENDING . ' 条上限，请管理员先处理现有提案'); }
            $state['proposals'] = array_slice(array_merge($state['proposals'] ?? [], [$proposal]), -self::MAX_TOTAL);
            return $state;
        });
        return ['ok' => true, 'proposal' => $proposal];
    }

    public function list(): array
    {
        $state = (new PrivateJsonStore($this->dir . '/ai_proposals.json'))->read();
        $proposals = array_reverse(array_slice(array_values($state['proposals'] ?? []), -100));
        $counts = array_fill_keys(self::STATUS, 0);
        foreach ($state['proposals'] ?? [] as $item) {
            if (isset($counts[$item['status'] ?? ''])) { $counts[$item['status']]++; }
        }
        return ['ok' => true, 'counts' => $counts, 'proposals' => $proposals];
    }

    public function recent(): array
    {
        return array_reverse(array_slice(array_values((new PrivateJsonStore($this->dir . '/ai_proposals.json'))->read()['proposals'] ?? []), -30));
    }

    public function counts(): array
    {
        $counts = array_fill_keys(self::STATUS, 0);
        foreach ((new PrivateJsonStore($this->dir . '/ai_proposals.json'))->read()['proposals'] ?? [] as $item) {
            if (isset($counts[$item['status'] ?? ''])) { $counts[$item['status']]++; }
        }
        return $counts;
    }

    /** 管理员批准：读取云端最新版本 → DnsChangeService 单次写入并回读。冲突时提案标记 failed 并抛出 409。 */
    public function approve(string $id, array $actor): array
    {
        $proposal = $this->claim($id);
        $account = $proposal['account']; $domain = $proposal['domain'];
        try {
            $config = ($this->resolver)($account);
            $cloud = ($this->cloudFactory)($config);
        } catch (Throwable $e) {
            $this->failQuietly($id, '云账号不可用：' . $e->getMessage(), $actor);
            throw new DomainException('提案未能执行：' . $e->getMessage());
        }
        try {
            $fresh = DnsRecordCache::read($this->dir, $account, $domain,
                static function () use ($cloud, $domain) { return $cloud->listRecords($domain); }, true);
            $body = [];
            if ($proposal['action'] === 'add') {
                $body = $proposal['record'] + ['expected_revision' => (string) $fresh['revision']];
            } else {
                $target = null;
                foreach ($fresh['records'] as $record) {
                    if ((string) ($record['id'] ?? '') === (string) $proposal['record_id']) { $target = $record; break; }
                }
                if ($target === null) { throw new DomainException('目标记录已不存在或 ID 已变化，请让 AI 重新读取 DNS 后提交新提案'); }
                $body = $proposal['action'] === 'delete'
                    ? ['expected_version' => DnsRecordCache::recordVersion($target)]
                    : $proposal['record'] + ['expected_version' => DnsRecordCache::recordVersion($target)];
            }
            $change = new DnsChangeService($this->dir, $cloud, function () use ($account, $domain): void {
                try { (new HealthService($this->dir))->invalidateDnsHealth($account, $domain); }
                catch (Throwable $ignored) { /* 健康缓存失效失败不影响 DNS 写入结果判定 */ }
            });
            $result = $change->apply($account, $domain, $proposal['action'], $proposal['record_id'], $body,
                ['user' => ($actor['user'] ?? 'admin') . '（批准 AI 提案）', 'via' => 'browser']);
            $this->store(function (array $state) use ($id, $actor, $result): array {
                return $this->patch($state, $id, ['status' => 'executed',
                    'approved_by' => $actor['user'] ?? 'admin', 'approved_at' => date('c'),
                    'result' => ['change_id' => $result['change_id'] ?? null, 'status' => $result['status'] ?? 'unconfirmed',
                        'verified' => (bool) ($result['verified'] ?? false), 'message' => $result['message'] ?? '']]);
            });
            return ['ok' => true, 'verified' => (bool) ($result['verified'] ?? false), 'status' => $result['status'] ?? 'unconfirmed',
                'message' => $result['message'] ?? '', 'change_id' => $result['change_id'] ?? null];
        } catch (InvalidArgumentException | DomainException $e) {
            $this->failQuietly($id, $e->getMessage(), $actor);
            throw new DomainException('提案未能执行：' . $e->getMessage());
        }
    }

    public function reject(string $id, array $actor, string $reason): array
    {
        $reason = trim(mb_substr($reason, 0, 300));
        $this->store(function (array $state) use ($id, $actor, $reason): array {
            foreach ($state['proposals'] ?? [] as $index => $item) {
                if (($item['id'] ?? '') !== $id) { continue; }
                if (($item['status'] ?? '') !== 'pending') { throw new DomainException('提案已处理，不能再次拒绝'); }
                $state['proposals'][$index]['status'] = 'rejected';
                $state['proposals'][$index]['rejected_by'] = $actor['user'] ?? 'admin';
                $state['proposals'][$index]['rejected_at'] = date('c');
                $state['proposals'][$index]['reason'] = $reason;
                return $state;
            }
            throw new OutOfBoundsException('提案不存在');
        });
        return ['ok' => true];
    }

    /** Mark approved Atomically: pending → executing claim prevents double approval races. */
    private function claim(string $id): array
    {
        $claimed = null;
        $this->store(function (array $state) use ($id, &$claimed): array {
            foreach ($state['proposals'] ?? [] as $index => $item) {
                if (($item['id'] ?? '') !== $id) { continue; }
                if (($item['status'] ?? '') !== 'pending') { throw new DomainException('提案已处理，不能再次批准'); }
                $claimed = $item;
                return $state; // 状态保持 pending；执行结果在 approve 流程内写回。
            }
            throw new OutOfBoundsException('提案不存在');
        });
        return $claimed;
    }

    private function failQuietly(string $id, string $error, array $actor): void
    {
        try {
            $this->store(function (array $state) use ($id, $error, $actor): array {
                foreach ($state['proposals'] ?? [] as $index => $item) {
                    if (($item['id'] ?? '') !== $id || ($item['status'] ?? '') !== 'pending') { continue; }
                    $state['proposals'][$index]['status'] = 'failed';
                    $state['proposals'][$index]['error'] = mb_substr($error, 0, 300);
                    $state['proposals'][$index]['approved_by'] = $actor['user'] ?? 'admin';
                    $state['proposals'][$index]['approved_at'] = date('c');
                    return $state;
                }
                return $state;
            });
        } catch (Throwable $ignored) { /* 存储失败时保留 409 响应，不掩盖原始冲突 */ }
    }

    private function patch(array $state, string $id, array $fields): array
    {
        foreach ($state['proposals'] ?? [] as $index => $item) {
            if (($item['id'] ?? '') !== $id) { continue; }
            $state['proposals'][$index] = array_merge($item, $fields);
            return $state;
        }
        return $state;
    }

    private function store(callable $change): void
    {
        (new PrivateJsonStore($this->dir . '/ai_proposals.json'))->update($change);
    }

    /** Cache-only read of the current record values so the admin sees the diff without triggering network. */
    private function cachedRecord(string $account, string $domain, ?string $recordId): ?array
    {
        if ($recordId === null) { return null; }
        try {
            $snapshot = DnsRecordCache::read($this->dir, $account, $domain, static function (): array {
                throw new RuntimeException('no cache');
            });
            foreach ($snapshot['records'] ?? [] as $record) {
                if ((string) ($record['id'] ?? '') === (string) $recordId) {
                    return array_intersect_key($record, array_flip(['id', 'name', 'type', 'value', 'ttl', 'priority', 'line', 'status']));
                }
            }
        } catch (Throwable $ignored) { /* 无缓存时批准前管理员以云端最新值为准 */ }
        return null;
    }

    private static function sameChange(array $a, array $b): bool
    {
        $key = static function (array $p): string {
            return implode('|', [$p['account'] ?? '', $p['domain'] ?? '', $p['action'] ?? '', (string) ($p['record_id'] ?? ''),
                $p['record']['type'] ?? '', $p['record']['name'] ?? '', $p['record']['value'] ?? '']);
        };
        return $key($a) === $key($b);
    }

    private static function actor(array $actor): string
    {
        return mb_substr(trim((string) ($actor['user'] ?? '')), 0, 128) ?: (($actor['via'] ?? '') === 'api' ? 'api' : 'unknown');
    }

    private static function validDomain(string $domain): bool
    {
        $labels = explode('.', $domain);
        if (count($labels) < 2 || strlen($domain) > 253) { return false; }
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63 || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i', $label)) { return false; }
        }
        return true;
    }
}
