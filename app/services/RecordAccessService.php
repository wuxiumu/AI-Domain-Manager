<?php

/**
 * 解析记录访问检测：对域名下 A/AAAA/CNAME 类解析记录探测可达性，
 * 结果持久化到 data/record_access.json（按 provider|domain 分组）。
 */
class RecordAccessService
{
    private const FILE = 'record_access.json';
    private const PROBE_TYPES = ['A', 'AAAA', 'CNAME']; // 其余类型（MX/NS/TXT/SRV/CAA）标记"不适用"
    private const CONCURRENCY = 20;
    private const TIMEOUT = 4;

    /**
     * 检测某域名全部解析记录，返回汇总。
     * $records 为已归一化的解析记录数组（来自 provider listRecords）。
     */
    public static function runCheck(string $provider, string $domain, array $records): array
    {
        @set_time_limit(300);
        $epoch = WorkspaceData::locked(DATA_DIR, static function () { return WorkspaceData::epoch(DATA_DIR); });

        // 每条记录 → 探测目标列表（主机名优先，记录值为 IP/域名时补充）；
        // HTTPS 目标单独记账：HTTPS 结果显式落库，用于区分「已上线运营（HTTPS 正常）」与「仅 HTTP（未启用）」。
        $tasks = [];   // recordId => ['urls' => [...], 'https' => url]
        $allUrls = [];
        foreach ($records as $r) {
            $id = (string) ($r['id'] ?? '');
            if ($id === '' || !in_array(strtoupper((string) ($r['type'] ?? '')), self::PROBE_TYPES, true)) {
                continue;
            }
            $targets = [];
            $name = (($r['name'] ?? '') === '' || ($r['name'] ?? '') === '@') ? $domain : ($r['name'] . '.' . $domain);
            $httpsUrl = 'https://' . self::toAscii($name);
            $targets[] = $httpsUrl;
            $httpUrl = null;

            $v = trim((string) ($r['value'] ?? ''));
            if (self::isIp($v)) {
                $httpUrl = 'http://' . (strpos($v, ':') !== false ? '[' . $v . ']' : $v);
                $targets[] = $httpUrl;
            } elseif (self::isHost($v)) {
                $targets[] = 'https://' . self::toAscii(rtrim($v, '.'));
            }
            $targets = array_values(array_unique($targets));
            $tasks[$id] = ['urls' => $targets, 'https' => $httpsUrl, 'http' => $httpUrl];
            foreach ($targets as $u) {
                $allUrls[$u] = $u;
            }
        }

        $revision = DnsRecordCache::versioned(['records' => $records])['revision'];
        $probed = self::probeUrls(array_values($allUrls));

        $checkedAt = date('Y-m-d H:i:s');
        $results = [];
        $okCount = 0;
        $failCount = 0;
        $naCount = 0;

        foreach ($records as $r) {
            $id = (string) ($r['id'] ?? '');
            if (!isset($tasks[$id])) {
                $results[$id] = ['status' => 'na', 'http_code' => 0, 'ms' => 0, 'target' => '', 'error' => '不适用',
                    'https_status' => 'na', 'https_code' => 0, 'checked_at' => $checkedAt];
                $naCount++;
                continue;
            }
            $best = null;
            foreach ($tasks[$id]['urls'] as $url) {
                $p = $probed[$url] ?? ['http_code' => 0, 'ms' => 0, 'error' => ''];
                $isOk = $p['http_code'] >= 200 && $p['http_code'] < 400;
                if ($best === null || ($isOk && $best['status'] !== 'ok')) {
                    $best = [
                        'status'    => $isOk ? 'ok' : 'fail',
                        'http_code' => $p['http_code'],
                        'ms'        => $p['ms'],
                        'target'    => $url,
                        'error'     => $p['error'],
                    ];
                }
                if ($isOk) {
                    break; // 任一目标可达即 ok
                }
            }
            // HTTPS 单列结果：https://主机名 探测（严格 TLS 校验）；证书无效或未启用均为 fail；附耗时与证书到期日
            $h = $probed[$tasks[$id]['https']] ?? ['http_code' => 0, 'ms' => 0];
            $best['https_status'] = ($h['http_code'] >= 200 && $h['http_code'] < 400) ? 'ok' : 'fail';
            $best['https_code'] = (int) $h['http_code'];
            $best['https_ms'] = (int) ($h['ms'] ?? 0);
            if (!empty($h['cert_expires'])) { $best['https_expires'] = (string) $h['cert_expires']; }
            // HTTP 单列结果：http://IP 探测（仅记录值为 IP 的记录有此目标）
            if (!empty($tasks[$id]['http'])) {
                $p2 = $probed[$tasks[$id]['http']] ?? ['http_code' => 0, 'ms' => 0];
                $best['http_status'] = ($p2['http_code'] >= 200 && $p2['http_code'] < 400) ? 'ok' : 'fail';
                $best['http_code'] = (int) $p2['http_code'];
                $best['http_ms'] = (int) ($p2['ms'] ?? 0);
            }
            $best['checked_at'] = $checkedAt;
            $results[$id] = $best;
            if ($best['status'] === 'ok') {
                $okCount++;
            } else {
                $failCount++;
            }
        }

        $data = [
            'records_revision' => $revision,
            'checked_at' => $checkedAt,
            'total'      => count($records),
            'ok'         => $okCount,
            'fail'       => $failCount,
            'na'         => $naCount,
            'results'    => $results,
        ];

        WorkspaceData::locked(DATA_DIR, static function () use ($provider, $domain, $data, $epoch, $revision) {
            if ($epoch !== WorkspaceData::epoch(DATA_DIR)) { throw new DomainException('检测期间资产已恢复，旧结果已丢弃'); }
            if ((DnsRecordCache::peek(DATA_DIR, $provider, $domain)['revision'] ?? null) !== $revision) { throw new DomainException('DNS 在检测期间已改变，旧检测结果已丢弃'); }
            $store = WorkspaceData::read(DATA_DIR . '/' . self::FILE);
            $store[$provider . '|' . $domain] = $data;
            JsonStore::write(DATA_DIR . '/' . self::FILE, $store);
        });

        return [
            'checked_at' => $checkedAt,
            'total'      => count($records),
            'ok'         => $okCount,
            'fail'       => $failCount,
            'na'         => $naCount,
        ];
    }

    /**
     * HTTPS 专题检查：仅对已通过 HTTP 访问检查的记录探测 https://主机名（严格 TLS + 证书到期），
     * 结果就地合并进既有记录结果（不动 status / http_code / ms 等可达性字段）。
     * HTTP 未通过的记录跳过——HTTPS 依附于可达性，主机本身不通时探测 HTTPS 没有意义。
     */
    public static function runHttpsCheck(string $provider, string $domain, array $records, ?array $recordIds = null): array
    {
        $epoch = WorkspaceData::locked(DATA_DIR, static function () { return WorkspaceData::epoch(DATA_DIR); });
        $revision = DnsRecordCache::versioned(['records' => $records])['revision'];

        $store = JsonStore::read(DATA_DIR . '/' . self::FILE, []);
        $group = is_array($store) ? ($store[$provider . '|' . $domain] ?? null) : null;
        if (!is_array($group) || ($group['records_revision'] ?? null) !== $revision) {
            throw new DomainException('解析快照已变化，请先刷新解析并重跑一次前端 / 后端检查');
        }
        $results = is_array($group['results'] ?? null) ? $group['results'] : [];

        $targets = [];   // recordId => https url
        $allUrls = [];
        $skippedNoHttp = 0;
        foreach ($records as $r) {
            $id = (string) ($r['id'] ?? '');
            if ($id === '' || !in_array(strtoupper((string) ($r['type'] ?? '')), self::PROBE_TYPES, true)) { continue; }
            if (is_array($recordIds) && $recordIds && !in_array($id, $recordIds, true)) { continue; }
            $existing = $results[$id] ?? null;
            if (!is_array($existing) || ($existing['status'] ?? '') !== 'ok') { $skippedNoHttp++; continue; }
            $name = (($r['name'] ?? '') === '' || ($r['name'] ?? '') === '@') ? $domain : ($r['name'] . '.' . $domain);
            $url = 'https://' . self::toAscii($name);
            $targets[$id] = $url;
            $allUrls[$url] = $url;
        }
        if (!$allUrls) {
            return ['checked' => 0, 'https_ok' => 0, 'https_fail' => 0, 'skipped_no_http' => $skippedNoHttp,
                'checked_at' => date('Y-m-d H:i:s'),
                'message' => '没有已通过 HTTP 访问检查的记录；请先跑前端 / 后端检查，通过后再做 HTTPS 检查'];
        }

        $probed = self::probeUrls(array_values($allUrls));
        $checkedAt = date('Y-m-d H:i:s');
        $okCount = 0;
        $failCount = 0;
        foreach ($targets as $id => $url) {
            $p = $probed[$url] ?? ['http_code' => 0];
            $results[$id]['https_status'] = ($p['http_code'] >= 200 && $p['http_code'] < 400) ? 'ok' : 'fail';
            $results[$id]['https_code'] = (int) $p['http_code'];
            if (!empty($p['cert_expires'])) { $results[$id]['https_expires'] = (string) $p['cert_expires']; }
            $results[$id]['checked_at'] = $checkedAt;
            if ($results[$id]['https_status'] === 'ok') { $okCount++; } else { $failCount++; }
        }

        WorkspaceData::locked(DATA_DIR, static function () use ($provider, $domain, $results, $epoch, $revision, $checkedAt): void {
            if ($epoch !== WorkspaceData::epoch(DATA_DIR)) { throw new DomainException('检测期间资产已恢复，结果已丢弃'); }
            if ((DnsRecordCache::peek(DATA_DIR, $provider, $domain)['revision'] ?? null) !== $revision) { throw new DomainException('DNS 在检测期间已改变，结果已丢弃'); }
            $current = WorkspaceData::read(DATA_DIR . '/' . self::FILE);
            $cur = $current[$provider . '|' . $domain] ?? null;
            if (!is_array($cur) || ($cur['records_revision'] ?? null) !== $revision) { throw new DomainException('解析快照已变化，结果已丢弃'); }
            $cur['results'] = $results;
            $cur['https_checked_at'] = $checkedAt;
            $current[$provider . '|' . $domain] = $cur;
            JsonStore::write(DATA_DIR . '/' . self::FILE, $current);
        });

        return ['checked' => count($targets), 'https_ok' => $okCount, 'https_fail' => $failCount,
            'skipped_no_http' => $skippedNoHttp, 'checked_at' => $checkedAt];
    }

    /** 某域名解析记录的访问状态映射（recordId => 结果），无则空数组 */
    public static function mapOf(string $provider, string $domain): array
    {
        $store = JsonStore::read(DATA_DIR . '/' . self::FILE, []);
        $group = $store[$provider . '|' . $domain] ?? [];
        $revision = DnsRecordCache::peek(DATA_DIR, $provider, $domain)['revision'] ?? null;
        if ($revision === null || ($group['records_revision'] ?? null) !== $revision) return [];
        $results = $group['results'] ?? [];
        return is_array($results) ? $results : [];
    }

    /**
     * 合并浏览器侧探测结果（大屏前端检查上报），按 FQDN 存储、与解析快照版本对齐。
     * $results: {fqdn: {ok: bool, scheme?: 'https'|'http', ms?: int}}；浏览器 fetch https 成功即证书有效。
     * 结果全局缓存，跨浏览器可见。
     */
    public static function mergeBrowser(string $provider, string $domain, string $revision, array $results): array
    {
        $checkedAt = date('Y-m-d H:i:s');
        $clean = [];
        foreach ($results as $fqdn => $item) {
            $fqdn = strtolower(rtrim(trim((string) $fqdn), '.'));
            if ($fqdn === '' || !is_array($item) || !isset($item['ok']) || !is_bool($item['ok'])) { continue; }
            $entry = ['ok' => $item['ok'], 'checked_at' => $checkedAt];
            $scheme = (string) ($item['scheme'] ?? '');
            if (in_array($scheme, ['https', 'http'], true)) { $entry['scheme'] = $scheme; }
            if (isset($item['ms']) && is_int($item['ms']) && $item['ms'] >= 0 && $item['ms'] <= 60000) {
                $entry['ms'] = $item['ms'];
            }
            $clean[$fqdn] = $entry;
        }
        if (!$clean) { throw new InvalidArgumentException('浏览器检查结果为空或格式无效'); }
        WorkspaceData::locked(DATA_DIR, static function () use ($provider, $domain, $revision, $clean, $checkedAt): void {
            if ((DnsRecordCache::peek(DATA_DIR, $provider, $domain)['revision'] ?? null) !== $revision) {
                throw new DomainException('DNS 已变化，浏览器检查结果未保存；请刷新解析后重新检查');
            }
            $store = WorkspaceData::read(DATA_DIR . '/' . self::FILE);
            $group = $store[$provider . '|' . $domain] ?? [];
            if (($group['records_revision'] ?? null) !== $revision) {
                throw new DomainException('DNS 已变化，浏览器检查结果未保存；请刷新解析后重新检查');
            }
            $existing = is_array($group['browser']['results'] ?? null) ? $group['browser']['results'] : [];
            $group['browser'] = ['checked_at' => $checkedAt, 'results' => array_merge($existing, $clean)];
            $store[$provider . '|' . $domain] = $group;
            JsonStore::write(DATA_DIR . '/' . self::FILE, $store);
        });
        return ['ok' => true, 'checked_at' => $checkedAt, 'count' => count($clean)];
    }

    /** 浏览器侧探测结果映射（fqdn => {ok, checked_at}），与解析快照版本对齐；无则空数组 */
    public static function browserMapOf(string $provider, string $domain): array
    {
        $store = JsonStore::read(DATA_DIR . '/' . self::FILE, []);
        $group = $store[$provider . '|' . $domain] ?? [];
        $revision = DnsRecordCache::peek(DATA_DIR, $provider, $domain)['revision'] ?? null;
        if ($revision === null || ($group['records_revision'] ?? null) !== $revision) return [];
        $browser = $group['browser'] ?? [];
        return is_array($browser['results'] ?? null) ? $browser['results'] : [];
    }

    /** 并发探测 URL 列表（HEAD），返回 url => ['http_code','ms','error'] */
    private static function probeUrls(array $urls): array
    {
        return PublicHttpProbe::run(array_combine($urls, $urls) ?: []);
    }

    private static function isIp(string $v): bool
    {
        if (preg_match('/^(\d{1,3}\.){3}\d{1,3}$/', $v)) {
            return true;
        }
        return preg_match('/^[0-9a-fA-F:.]+$/', $v) && strpos($v, ':') !== false;
    }

    private static function isHost(string $v): bool
    {
        $host = rtrim($v, '.');
        return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$/', $host);
    }

    private static function toAscii(string $domain): string
    {
        $ascii = function_exists('idn_to_ascii')
            ? idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)
            : false;
        return $ascii ?: $domain;
    }
}
