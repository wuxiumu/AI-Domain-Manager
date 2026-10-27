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

        // 每条记录 → 探测目标列表（主机名优先，记录值为 IP/域名时补充）
        $tasks = [];   // recordId => [url, ...]
        $allUrls = [];
        foreach ($records as $r) {
            $id = (string) ($r['id'] ?? '');
            if ($id === '' || !in_array(strtoupper((string) ($r['type'] ?? '')), self::PROBE_TYPES, true)) {
                continue;
            }
            $targets = [];
            $name = (($r['name'] ?? '') === '' || ($r['name'] ?? '') === '@') ? $domain : ($r['name'] . '.' . $domain);
            $targets[] = 'https://' . self::toAscii($name);

            $v = trim((string) ($r['value'] ?? ''));
            if (self::isIp($v)) {
                $targets[] = 'http://' . (strpos($v, ':') !== false ? '[' . $v . ']' : $v);
            } elseif (self::isHost($v)) {
                $targets[] = 'https://' . self::toAscii(rtrim($v, '.'));
            }
            $targets = array_values(array_unique($targets));
            $tasks[$id] = $targets;
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
                $results[$id] = ['status' => 'na', 'http_code' => 0, 'ms' => 0, 'target' => '', 'error' => '不适用', 'checked_at' => $checkedAt];
                $naCount++;
                continue;
            }
            $best = null;
            foreach ($tasks[$id] as $url) {
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
