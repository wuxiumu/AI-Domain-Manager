<?php

/** Compare a fresh cloud snapshot, write once, then verify by reading the cloud again. */
class DnsChangeService
{
    private string $dir;
    private CloudProvider $cloud;
    private $invalidate;

    public function __construct(string $dir, CloudProvider $cloud, ?callable $invalidate = null)
    {
        $this->dir = $dir; $this->cloud = $cloud; $this->invalidate = $invalidate;
    }

    public static function recent(string $dir): array
    {
        return array_reverse(array_slice((new PrivateJsonStore($dir . '/dns_changes.json'))->read()['entries'] ?? [], -50));
    }

    public function apply(string $account, string $domain, string $action, ?string $recordId, array $body, array $actor): array
    {
        if (!in_array($action, ['add', 'update', 'delete'], true)) { throw new InvalidArgumentException('无效的 DNS 操作'); }
        $condition = $action === 'add' ? 'expected_revision' : 'expected_version';
        if (!is_string($body[$condition] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $body[$condition])) {
            throw new InvalidArgumentException('请先读取最新 DNS 记录，并提交 ' . $condition . ' 以防覆盖其他修改');
        }
        return DnsRecordCache::locked($this->dir, $account, $domain, function () use ($account, $domain, $action, $recordId, $body, $actor, $condition): array {
            $fresh = $this->fetch($account, $domain);
            $records = $fresh['records']; $byId = [];
            foreach ($records as $record) {
                $id = (string) ($record['id'] ?? '');
                if ($id === '' || isset($byId[$id])) { throw new DomainException('云端记录无法唯一识别，请在云商控制台核对'); }
                $byId[$id] = $record;
            }
            $before = $action === 'add' ? null : ($byId[$recordId] ?? null);
            if ($action !== 'add' && $before === null) { throw new DomainException('记录已被删除或标识已改变，请刷新 DNS 后重新确认'); }
            $version = $action === 'add' ? $fresh['revision'] : $before['version'];
            if (!hash_equals($version, $body[$condition])) { throw new DomainException('DNS 记录已发生变化，本次未写入；请刷新后重新确认差异'); }
            $requested = $action === 'delete' ? null : self::params($body, $before);
            if ($action === 'update' && $this->cloud instanceof GoDaddyProvider) {
                if ($requested['type'] !== $before['type'] || $requested['name'] !== $before['name']) { throw new InvalidArgumentException('GoDaddy 记录类型和主机名变更请通过新增记录完成'); }
                $nextId = $requested['type'] . '|' . $requested['name'] . '|' . $requested['value'];
                if ($nextId !== $recordId && isset($byId[$nextId])) { throw new DomainException('同组已有这个记录值，本次未修改，请刷新后核对'); }
            }
            if ($action === 'add') {
                foreach ($records as $record) {
                    if (self::matches($record, $requested)) { throw new DomainException('云端已有相同记录，请刷新后核对，不要重复添加'); }
                }
            }
            $event = ['id' => bin2hex(random_bytes(16)), 'started_at' => date('c'), 'finished_at' => null,
                'account' => $account, 'domain' => $domain, 'action' => $action, 'record_id' => $recordId,
                'actor' => mb_substr((string) ($actor['user'] ?? ''), 0, 128), 'via' => ($actor['via'] ?? '') === 'api' ? 'api' : 'browser',
                'before' => self::clean($before), 'requested' => $requested, 'after' => null, 'outcome' => 'pending'];
            $this->save($event); // Fail closed before writing if the journal cannot be persisted.
            $result = ['ok' => true, 'verified' => false, 'status' => 'unconfirmed', 'change_id' => $event['id'], 'domain' => $domain, 'record' => null,
                'message' => '云端结果尚未确认，请刷新云端记录核实后再操作；不要直接重复提交'];
            if ($action === 'update' && self::matches($before, $requested)) {
                $event['after'] = self::clean($before);
                $result = array_replace($result, ['verified' => true, 'status' => 'unchanged', 'message' => '记录与云端当前值一致，无需修改']);
                $result['record'] = self::clean($before) + ['version' => $before['version']];
                $result['revision'] = $fresh['revision'];
            } else {
                try {
                    // A timeout can mean the remote write succeeded; never retry writes automatically.
                    DnsRecordCache::invalidate($this->dir, $account, $domain);
                    if ($action === 'add') { $this->cloud->addRecord($domain, $requested); }
                    elseif ($action === 'update') {
                        if (!$this->cloud->updateRecord($domain, $recordId, $requested)) { throw new RuntimeException('Cloud did not confirm update'); }
                    } elseif (!$this->cloud->deleteRecord($domain, $recordId)) { throw new RuntimeException('Cloud did not confirm deletion'); }
                    $afterSnapshot = $this->fetch($account, $domain);
                    $after = $afterSnapshot['records'];
                    $target = null; $verified = false;
                    if ($action === 'delete') {
                        $verified = !in_array($recordId, array_map('strval', array_column($after, 'id')), true);
                    } else {
                        foreach ($after as $record) {
                            if (self::matches($record, $requested)) { $target = $record; break; }
                        }
                        $verified = $target !== null;
                        // Providers with stable IDs must still identify the modified record, not a matching sibling.
                        if ($action === 'update') {
                            $expectedId = $this->cloud instanceof GoDaddyProvider ? $requested['type'] . '|' . $requested['name'] . '|' . $requested['value'] : $recordId;
                            $target = null;
                            $oldIdStillExists = false;
                            foreach ($after as $record) {
                                if ((string) $record['id'] === $expectedId) { $target = $record; }
                                if ((string) $record['id'] === $recordId && $recordId !== $expectedId) { $oldIdStillExists = true; }
                            }
                            $verified = $target !== null && self::matches($target, $requested);
                            if ($oldIdStillExists) { $verified = false; }
                            if ($target === null) {
                                foreach ($after as $record) { if ((string) $record['id'] === $recordId) { $target = $record; break; } }
                            }
                        }
                    }
                    // Detect accidental group deletion or replacement of unselected sibling records.
                    $versionsAfter = array_fill_keys(array_map([DnsRecordCache::class, 'recordVersion'], $after), true);
                    $idsAfter = [];
                    foreach ($after as $record) {
                        $id = (string) ($record['id'] ?? '');
                        if ($id === '' || isset($idsAfter[$id])) { $verified = false; }
                        $idsAfter[$id] = true;
                    }
                    foreach ($records as $record) {
                        if ($action !== 'add' && (string) $record['id'] === $recordId) { continue; }
                        if (!isset($versionsAfter[$record['version']])) { $verified = false; }
                    }
                    $event['after'] = self::clean($target);
                    if ($verified) {
                        $result = array_replace($result, ['verified' => true, 'status' => 'verified', 'message' => '云端变更已回读核实；解析传播仍需等待']);
                        $result['record'] = $target === null ? null : self::clean($target) + ['version' => DnsRecordCache::recordVersion($target)];
                        $result['revision'] = $afterSnapshot['revision'];
                    }
                } catch (Throwable $failure) {
                    // Keep raw provider errors out of journals and public responses: they may contain request details.
                    $event['failure_kind'] = 'cloud_or_cache_unconfirmed';
                }
                if ($this->invalidate) {
                    try { ($this->invalidate)(); }
                    catch (Throwable $failure) {
                        $result['status'] = 'unconfirmed'; $result['verified'] = false;
                        $result['message'] = '云端请求已结束，关联状态缓存未更新，请刷新核实后再操作';
                        $event['failure_kind'] = 'health_cache_unconfirmed';
                    }
                }
            }
            $event['outcome'] = $result['status']; $event['finished_at'] = date('c');
            try { $this->save($event); }
            catch (Throwable $failure) {
                $result['status'] = 'unconfirmed'; $result['verified'] = false;
                $result['message'] = '云端请求已结束，但操作记录未完成，请根据变更编号核实后再操作';
            }
            return $result;
        });
    }

    private function fetch(string $account, string $domain): array
    {
        return DnsRecordCache::read($this->dir, $account, $domain, function () use ($domain) { return $this->cloud->listRecords($domain); }, true);
    }

    private function save(array $event): void
    {
        (new PrivateJsonStore($this->dir . '/dns_changes.json'))->update(static function (array $state) use ($event): array {
            $entries = $state['entries'] ?? [];
            foreach ($entries as $index => $previous) {
                if ($previous['id'] === $event['id']) { $entries[$index] = $event; return ['entries' => $entries]; }
            }
            $entries[] = $event;
            return ['entries' => array_slice($entries, -200)];
        });
    }

    private static function clean(?array $record): ?array
    {
        return $record === null ? null : array_intersect_key($record, array_flip(['id', 'name', 'type', 'value', 'ttl', 'priority', 'line', 'status']));
    }

    public static function params(array $body, ?array $before = null): array
    {
        $params = [];
        foreach (['type' => 'A', 'name' => '@', 'value' => '', 'line' => ''] as $field => $default) {
            $value = $body[$field] ?? $before[$field] ?? $default;
            if (!is_string($value)) { throw new InvalidArgumentException('DNS ' . $field . ' 必须为字符串'); }
            $params[$field] = trim($value);
        }
        $params['type'] = strtoupper($params['type']);
        if ($params['name'] === '') { $params['name'] = '@'; }
        if (!in_array($params['type'], ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'], true)
            || strlen($params['name']) > 253 || strlen($params['line']) > 128 || $params['value'] === '' || strlen($params['value']) > 4096) {
            throw new InvalidArgumentException('DNS 记录类型、主机名或记录值不符合要求');
        }
        foreach (['ttl' => [600, 1, 2147483647], 'priority' => [0, 0, 65535]] as $field => [$default, $min, $max]) {
            $value = $body[$field] ?? $before[$field] ?? $default;
            if (!is_int($value) || $value < $min || $value > $max) { throw new InvalidArgumentException($field . ' 必须为 ' . $min . '–' . $max . ' 的整数'); }
            $params[$field] = $value;
        }
        if ($before !== null) {
            $params['status'] = (string) ($before['status'] ?? '');
            if (isset($before['raw']['Weight'])) { $params['weight'] = (int) $before['raw']['Weight']; }
        }
        if (($params['type'] === 'A' && !filter_var($params['value'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            || ($params['type'] === 'AAAA' && !filter_var($params['value'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))) { throw new InvalidArgumentException('A / AAAA 记录需要有效的对应 IP 地址'); }
        return $params;
    }

    private static function matches(array $record, array $requested): bool
    {
        if (isset($requested['status']) && (string) ($record['status'] ?? '') !== $requested['status']) { return false; }
        foreach (['type', 'name', 'value', 'line', 'ttl', 'priority'] as $field) {
            $a = $record[$field] ?? (in_array($field, ['ttl', 'priority'], true) ? 0 : ''); $b = $requested[$field];
            if (in_array($field, ['ttl', 'priority'], true)) { if ((int) $a !== $b) return false; }
            elseif ($field === 'name' || ($field === 'value' && in_array($requested['type'], ['CNAME', 'MX', 'NS'], true))) {
                if (strtolower(rtrim($a, '.')) !== strtolower(rtrim($b, '.'))) return false;
            } elseif ($field === 'value' && in_array($requested['type'], ['A', 'AAAA'], true)) {
                if (@inet_pton($a) !== @inet_pton($b)) return false;
            } elseif ((string) $a !== $b) { return false; }
        }
        return true;
    }
}
