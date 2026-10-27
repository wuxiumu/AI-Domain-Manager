<?php

/**
 * 域名访问检测服务：对全部有效域名并发发起 HTTP 探测，
 * 结果持久化到 data/access_check.json，支持"无法访问"专题。
 */
class AccessService
{
    private const CONCURRENCY = 20; // 并发数
    private const TIMEOUT = 4;      // 单请求超时（秒）
    private const FILE = 'access_check.json';

    /**
     * 执行全量检测，返回汇总结果。
     */
    public static function runCheck(): array
    {
        @set_time_limit(600);
        $epoch = WorkspaceData::locked(DATA_DIR, static function () { return WorkspaceData::epoch(DATA_DIR); });

        $domains = JsonStore::read(DATA_DIR . '/domains.json', []);
        if (!is_array($domains)) {
            $domains = [];
        }

        $targets = [];
        foreach ($domains as $d) {
            if (($d['valid'] ?? true) === false || empty($d['domain'])) {
                continue;
            }
            $targets[$d['provider'] . '|' . $d['domain']] = [
                'provider' => $d['provider'],
                'domain'   => $d['domain'],
            ];
        }

        $checkedAt = date('Y-m-d H:i:s');
        $results   = [];
        $okCount   = 0;
        $failCount = 0;

        foreach (array_chunk($targets, self::CONCURRENCY, true) as $chunk) {
            $probed = self::probeBatch($chunk);
            foreach ($probed as $key => $p) {
                $status = ($p['http_code'] >= 200 && $p['http_code'] < 400) ? 'ok' : 'fail';
                if ($status === 'ok') {
                    $okCount++;
                } else {
                    $failCount++;
                }
                $results[$key] = [
                    'provider'   => $chunk[$key]['provider'],
                    'domain'     => $chunk[$key]['domain'],
                    'status'     => $status,
                    'http_code'  => $p['http_code'],
                    'ms'         => $p['ms'],
                    'scheme'     => $p['scheme'],
                    'error'      => $p['error'],
                    'checked_at' => $checkedAt,
                ];
            }
        }

        $data = [
            'checked_at' => $checkedAt,
            'total'      => count($results),
            'ok'         => $okCount,
            'fail'       => $failCount,
            'results'    => $results,
        ];
        WorkspaceData::locked(DATA_DIR, static function () use ($data, $epoch) {
            if ($epoch !== WorkspaceData::epoch(DATA_DIR)) { throw new DomainException('检测期间资产已恢复，旧结果已丢弃'); }
            JsonStore::write(DATA_DIR . '/' . self::FILE, $data);
        });

        return [
            'checked_at' => $checkedAt,
            'total'      => $data['total'],
            'ok'         => $okCount,
            'fail'       => $failCount,
            'fail_list'  => self::failList($data),
        ];
    }

    /** 读取上次检测结果（未检测过返回 null） */
    public static function lastResult(): ?array
    {
        $data = JsonStore::read(DATA_DIR . '/' . self::FILE, []);
        if (empty($data) || empty($data['checked_at'])) {
            return null;
        }
        return [
            'checked_at' => $data['checked_at'],
            'total'      => (int) ($data['total'] ?? 0),
            'ok'         => (int) ($data['ok'] ?? 0),
            'fail'       => (int) ($data['fail'] ?? 0),
            'fail_list'  => self::failList($data),
        ];
    }

    /** 某域名（provider|domain）是否在"无法访问"列表中 */
    public static function isFail(string $provider, string $domain): bool
    {
        $data = JsonStore::read(DATA_DIR . '/' . self::FILE, []);
        return !empty($data['results'][$provider . '|' . $domain]['status'])
            && $data['results'][$provider . '|' . $domain]['status'] === 'fail';
    }

    /** 单个域名最近一次检测详情（无则 null） */
    public static function detailOf(string $provider, string $domain): ?array
    {
        $data = JsonStore::read(DATA_DIR . '/' . self::FILE, []);
        return $data['results'][$provider . '|' . $domain] ?? null;
    }

    private static function failList(array $data): array
    {
        $out = [];
        foreach ($data['results'] ?? [] as $r) {
            if (($r['status'] ?? '') === 'fail') {
                $out[] = [
                    'provider' => $r['provider'] ?? '',
                    'domain'   => $r['domain'] ?? '',
                    'http_code' => $r['http_code'] ?? 0,
                    'ms'       => $r['ms'] ?? 0,
                    'error'    => $r['error'] ?? '',
                    'checked_at' => $r['checked_at'] ?? '',
                ];
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['domain'], $b['domain']));
        return $out;
    }

    /** Public-only HTTPS HEAD checks; no insecure HTTP fallback or redirect following. */
    private static function probeBatch(array $targets): array
    {
        $urls = [];
        foreach ($targets as $key => $target) { $urls[$key] = 'https://' . self::toAscii($target['domain']) . '/'; }
        return PublicHttpProbe::run($urls);
    }

    /** IDN 转 punycode（中文域名 → xn-- 形式） */
    private static function toAscii(string $domain): string
    {
        $ascii = function_exists('idn_to_ascii')
            ? idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)
            : false;
        return $ascii ?: $domain;
    }
}
