<?php

/**
 * DNS 解析记录管理：代理到各云商 DNS API。
 * 路径：/api/domains/{provider}/{domain}/records[/{recordId}]
 */
class RecordController
{
    /** 列出解析记录（合并最近一次访问检测状态） */
    public function list(string $provider, string $domain): void
    {
        Auth::requireLogin();
        $this->releaseSession();
        try {
            $acc = self::account($provider);
            $snapshot = DnsRecordCache::read(DATA_DIR, $provider, $domain,
                static function () use ($acc, $domain) { return provider_factory($acc)->listRecords($domain); },
                ($_GET['refresh'] ?? '') === '1');
            $records = $snapshot['records'];
            $accessMap = RecordAccessService::mapOf($provider, $domain);
            foreach ($records as &$r) {
                $r['access'] = $accessMap[$r['id']] ?? null;
            }
            unset($r);
            json_out(['ok' => true, 'domain' => $domain, 'provider' => $provider, 'records' => $records,
                'snapshot_at' => $snapshot['snapshot_at'], 'cached' => $snapshot['cached'], 'revision' => $snapshot['revision']]);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => 'DNS 记录读取未完成，请检查账号权限与网络后刷新', 'code' => 'dns_read_failed'], 502);
        }
    }

    /** 检测该域名全部解析记录的可访问性；body.record_ids 可选，只探测指定记录（弹窗按勾选范围检查） */
    public function accessCheck(string $provider, string $domain): void
    {
        Auth::requireLogin();
        $this->releaseSession();
        try {
            $acc = self::account($provider);
            $snapshot = DnsRecordCache::read(DATA_DIR, $provider, $domain, static function () use ($acc, $domain) { return provider_factory($acc)->listRecords($domain); }, true);
            $records = $snapshot['records'];
            $recordIds = body_json()['record_ids'] ?? null;
            if (is_array($recordIds) && $recordIds) {
                $wanted = array_flip(array_map('strval', $recordIds));
                $records = array_values(array_filter($records, static function (array $r) use ($wanted): bool {
                    return isset($wanted[(string) ($r['id'] ?? '')]);
                }));
                if (!$records) { json_out(['ok' => false, 'error' => '勾选的记录在云端已不存在，请刷新解析后重试'], 422); }
            }
            json_out(['ok' => true] + RecordAccessService::runCheck($provider, $domain, $records));
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => 'DNS 访问检查未完成，请检查账号权限与网络后重试', 'code' => 'dns_check_failed'], 502);
        }
    }

    public function add(string $provider, string $domain): void
    {
        $this->change($provider, $domain, 'add', null);
    }

    /** HTTPS 专题检查：仅探测已通过 HTTP 访问检查记录的 https://主机名（证书到期随之更新）；body.record_ids 可选 */
    public function httpsCheck(string $provider, string $domain): void
    {
        Auth::requireLogin();
        $this->releaseSession();
        try {
            $acc = self::account($provider);
            $snapshot = DnsRecordCache::read(DATA_DIR, $provider, $domain, static function () use ($acc, $domain) { return provider_factory($acc)->listRecords($domain); }, true);
            $records = $snapshot['records'];
            $recordIds = body_json()['record_ids'] ?? null;
            if (is_array($recordIds) && $recordIds) {
                $wanted = array_flip(array_map('strval', $recordIds));
                $records = array_values(array_filter($records, static function (array $r) use ($wanted): bool {
                    return isset($wanted[(string) ($r['id'] ?? '')]);
                }));
                if (!$records) { json_out(['ok' => false, 'error' => '勾选的记录在云端已不存在，请刷新解析后重试'], 422); }
            }
            json_out(['ok' => true] + RecordAccessService::runHttpsCheck($provider, $domain, $records,
                is_array($recordIds) ? array_map('strval', $recordIds) : null));
        } catch (DomainException $e) {
            json_out(['ok' => false, 'error' => $e->getMessage(), 'code' => 'conflict'], 409);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => 'HTTPS 检查未完成，请检查网络后重试', 'code' => 'dns_check_failed'], 502);
        }
    }

    public function update(string $provider, string $domain, string $recordId): void
    {
        $this->change($provider, $domain, 'update', $recordId);
    }

    public function delete(string $provider, string $domain, string $recordId): void
    {
        $this->change($provider, $domain, 'delete', $recordId);
    }

    /** DNS 变更记录（最近 50 条）：原值、拟提交值、回读值与结果，供设置页展示 */
    public function changes(): void
    {
        Auth::requireSession();
        header('Cache-Control: no-store');
        json_out(['ok' => true, 'changes' => DnsChangeService::recent(DATA_DIR)]);
    }

    /** 暂停 / 恢复单条解析记录（body: {paused: bool, expected_version}） */
    public function status(string $provider, string $domain, string $recordId): void
    {
        Auth::requireLogin();
        $this->releaseSession();
        header('Cache-Control: no-store');
        $account = self::account($provider);
        $body = body_json();
        $paused = $body['paused'] ?? null;
        if (!is_bool($paused)) { json_out(['ok' => false, 'error' => 'paused 必须为布尔值'], 422); }
        $service = new DnsChangeService(DATA_DIR, provider_factory($account));
        $result = $service->applyStatus($provider, $domain, $recordId, $paused, $body,
            ['user' => Auth::user(), 'via' => Auth::isBearer() ? 'api' : 'browser']);
        json_out($result, $result['verified'] ? 200 : 202);
    }

    /** 大屏前端检查结果上报（body: {revision, results: {fqdn: {ok}}}），与解析快照版本对齐后全局缓存 */
    public function browserCheck(string $provider, string $domain): void
    {
        Auth::requireLogin();
        header('Cache-Control: no-store');
        $body = body_json();
        $revision = (string) ($body['revision'] ?? '');
        $results = $body['results'] ?? null;
        if (!preg_match('/^[a-f0-9]{64}$/D', $revision)) { json_out(['ok' => false, 'error' => 'revision 缺失或无效'], 422); }
        if (!is_array($results)) { json_out(['ok' => false, 'error' => 'results 必须为对象'], 422); }
        json_out(RecordAccessService::mergeBrowser($provider, $domain, $revision, $results));
    }

    private function change(string $provider, string $domain, string $action, ?string $recordId): void
    {
        Auth::requireLogin();
        $this->releaseSession();
        header('Cache-Control: no-store');
        $account = self::account($provider);
        $body = body_json();
        if ($action === 'add' && (!isset($body['line']) || $body['line'] === '')) {
            $body['line'] = $account['provider'] === 'aliyun' ? 'default' : ($account['provider'] === 'tencent' ? '默认' : '');
        }
        $service = new DnsChangeService(DATA_DIR, provider_factory($account));
        $result = $service->apply($provider, $domain, $action, $recordId, $body,
            ['user' => Auth::user(), 'via' => Auth::isBearer() ? 'api' : 'browser']);
        json_out($result, $result['verified'] ? 200 : 202);
    }

    private function releaseSession(): void
    {
        // Authorization is complete; cloud I/O must not block the user's other requests.
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    }

    private static function account(string $provider): array
    {
        if ($provider === 'manual') {
            json_out(['error' => '手动添加的域名不支持解析管理'], 400);
        }
        foreach (config('accounts') as $a) {
            if ($a['id'] === $provider) {
                return $a;
            }
        }
        json_out(['error' => '该域名对应的云账号已移除，请选择其他可用账号或重新配置账号'], 404);
    }
}
