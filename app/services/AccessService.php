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

    /** 并发探测一批域名（https HEAD，405/失败时回退 http HEAD） */
    private static function probeBatch(array $targets): array
    {
        $mh      = curl_multi_init();
        $handles = [];

        foreach ($targets as $key => $t) {
            $host = self::toAscii($t['domain']);
            $ch = curl_init('https://' . $host);
            self::setProbeOpts($ch);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.5);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $key => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ms   = (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            $err  = curl_error($ch);
            $scheme = 'https';
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            // HEAD 不支持或连接失败：回退 http HEAD 单发探测
            if ($code === 0 || $code === 405) {
                $host = self::toAscii($targets[$key]['domain']);
                $ch2 = curl_init('http://' . $host);
                self::setProbeOpts($ch2);
                curl_exec($ch2);
                $code2 = (int) curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                $ms2   = (int) round(curl_getinfo($ch2, CURLINFO_TOTAL_TIME) * 1000);
                $err2  = curl_error($ch2);
                curl_close($ch2);
                if ($code2 > 0 && $code2 !== 405) {
                    $code = $code2;
                    $ms   = $ms2;
                    $err  = $err2;
                    $scheme = 'http';
                } elseif ($code === 0) {
                    $err = $err2 !== '' ? $err2 : ($err !== '' ? $err : '连接失败');
                }
            }

            $out[$key] = [
                'http_code' => $code,
                'ms'        => $ms,
                'scheme'    => $scheme,
                'error'     => $err,
            ];
        }
        curl_multi_close($mh);
        return $out;
    }

    private static function setProbeOpts($ch): void
    {
        curl_setopt_array($ch, [
            CURLOPT_NOBODY          => true,        // HEAD
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_TIMEOUT         => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT  => 2,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYHOST  => 0,
            CURLOPT_USERAGENT       => 'DomainManager/1.0',
        ]);
        $proxy = config('http.proxy');
        if (is_string($proxy) && $proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
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
