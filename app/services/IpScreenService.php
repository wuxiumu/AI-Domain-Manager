<?php

/**
 * 大屏 IP 视图聚合快照：DNS 解析自动发现的公网 IP + 手工登记 IP。
 * 只读缓存台账与解析快照，绝不发起云请求或探测；归属地来自 ip_locations.json 缓存，
 * 刷新由 POST /api/ips/{id}/location 显式触发；访问状态复用 record_access.json 的既有结果。
 */
class IpScreenService
{
    /** 聚合快照（SnapshotCache 包裹；数据源：域名台账 + 手工 IP + DNS 解析快照 + 访问检测结果 + 归属地缓存）。 */
    public static function snapshot(string $dir, array $accounts, bool $force = false): array
    {
        $sources = array_merge(
            [$dir . '/domains.json', $dir . '/ip_assets.json', $dir . '/record_access.json', $dir . '/ip_locations.json'],
            glob($dir . '/cache/dns-*.json') ?: []
        );
        return SnapshotCache::remember($dir, 'ip-screen-v1', $sources,
            static function () use ($dir, $accounts) { return self::build($dir, $accounts); },
            $force ? time() - 1 : time() + 120); // force：过期快照立即重建（手动刷新）
    }

    private static function build(string $dir, array $accounts): array
    {
        $accountById = [];
        foreach ($accounts as $acc) {
            if (isset($acc['id'])) { $accountById[$acc['id']] = $acc; }
        }

        $domains = JsonStore::read($dir . '/domains.json', []);
        if (!is_array($domains)) { $domains = []; }
        $accessStore = JsonStore::read($dir . '/record_access.json', []);
        if (!is_array($accessStore)) { $accessStore = []; }
        $locations = (new PrivateJsonStore($dir . '/ip_locations.json'))->read();
        if (!is_array($locations)) { $locations = []; }

        // 聚合表：IP → 记录与来源；一个 IP 可被多账号多域名指向。
        $ips = [];

        $ensure = static function (string $ip) use (&$ips): array {
            if (!isset($ips[$ip])) {
                $ips[$ip] = ['ip' => $ip, 'records' => 0, 'domains' => [], 'sources' => [],
                    'manual' => false, 'manual_id' => '', 'name' => null, 'note' => null, 'port' => null];
            }
            return $ips[$ip];
        };

        foreach ($domains as $d) {
            $pid = (string) ($d['provider'] ?? '');
            if (!isset($accountById[$pid])) { continue; }             // 孤儿账号域名不上 IP 视图
            if (($d['valid'] ?? true) === false) { continue; }        // 已失效
            $days = ScreenService::daysLeft($d['expire_at'] ?? null);
            if ($days !== null && $days < 0) { continue; }            // 已过期

            $domain = strtolower(rtrim(trim((string) ($d['domain'] ?? '')), '.'));
            if ($domain === '') { continue; }
            $snapshot = DnsRecordCache::peek($dir, $pid, (string) ($d['domain'] ?? ''));
            if ($snapshot === null) { continue; }
            $accessGroup = $accessStore[$pid . '|' . (string) ($d['domain'] ?? '')] ?? null;
            $accessByRecord = [];
            if (is_array($accessGroup)
                && ($accessGroup['records_revision'] ?? null) === ($snapshot['revision'] ?? null)
                && is_array($accessGroup['results'] ?? null)) {
                $accessByRecord = $accessGroup['results'];
            }

            foreach ($snapshot['records'] as $record) {
                if (strtoupper((string) ($record['type'] ?? '')) !== 'A') { continue; }
                $value = trim((string) ($record['value'] ?? ''));
                if ($value === '' || filter_var($value, FILTER_VALIDATE_IP) === false) { continue; }
                $value = inet_ntop(inet_pton($value));
                $ensure($value);
                $ips[$value]['records']++;
                $fqdn = ScreenService::fqdnOf((string) ($record['name'] ?? '@'), $domain);
                $probe = $accessByRecord[(string) ($record['id'] ?? '')] ?? null;
                if (!in_array($pid, $ips[$value]['sources'], true)) { $ips[$value]['sources'][] = $pid; }
                $ips[$value]['domains'][] = [
                    'provider' => $pid,
                    'provider_name' => (string) ($accountById[$pid]['name'] ?? $pid),
                    'domain' => $domain,
                    'fqdn' => $fqdn,
                    'name' => ScreenService::relativeName($fqdn, $domain),
                    'record_id' => (string) ($record['id'] ?? ''),
                    'paused' => strtoupper((string) ($record['status'] ?? '')) === 'DISABLE',
                    'access' => is_array($probe) ? (string) ($probe['status'] ?? '') : null,
                    'http_code' => is_array($probe) ? (int) ($probe['http_code'] ?? 0) : null,
                    'https' => is_array($probe) ? (string) ($probe['https_status'] ?? '') : null,
                    'access_ms' => is_array($probe) && isset($probe['ms']) ? (int) $probe['ms'] : null,
                    'checked_at' => is_array($probe) ? ($probe['checked_at'] ?? null) : null,
                ];
            }
        }

        // 手工登记 IP：与自动发现按 IP 合并（manual 标记 + 名称/备注/端口）。
        foreach ((new IpAssetService($dir))->listing() as $entry) {
            $ip = (string) ($entry['ip'] ?? '');
            if ($ip === '' || !PublicHttpProbe::isPublicIp($ip)) { continue; }
            $row = $ensure($ip);
            $ips[$ip]['manual'] = true;
            $ips[$ip]['manual_id'] = (string) ($entry['id'] ?? ''); // 供前端定位编辑 / 删除
            $ips[$ip]['name'] = $entry['name'] !== '' ? $entry['name'] : null;
            $ips[$ip]['note'] = $entry['note'] !== '' ? $entry['note'] : null;
            $ips[$ip]['port'] = $entry['port'];
            if (($entry['created_at'] ?? null) && !in_array('manual', $ips[$ip]['sources'], true)) {
                // 手工来源参与账号分组（“手工”标签）。
                $ips[$ip]['sources'][] = 'manual';
            }
        }

        // IP 手动检查结果（浏览器 / 服务端直连探测）：作为独立“IP 直连”证据展示，
        // 仅在关联记录全部未检查时提供状态兜底；不覆盖关联记录的可达性结论。
        $ipAccess = self::ipAccessMap($dir);

        // 组装展示行：归属地 + 状态（最佳结果——该 IP 上任一站点可达即正常；取最快成功记录的响应时间）。
        $rows = [];
        foreach ($ips as $ip => $row) {
            $status = null; $checkedAt = null; $okCount = 0; $failCount = 0; $bestMs = null;
            foreach ($row['domains'] as $d) {
                if ($d['access'] === 'ok') {
                    $okCount++;
                    // 最快成功记录的响应时间
                    if ($d['access_ms'] !== null && ($bestMs === null || $d['access_ms'] < $bestMs)) { $bestMs = $d['access_ms']; }
                } elseif ($d['access'] === 'fail') { $failCount++; }
                if ($d['checked_at'] && ($checkedAt === null || $d['checked_at'] > $checkedAt)) { $checkedAt = $d['checked_at']; }
            }
            if ($okCount > 0) { $status = 'ok'; }
            elseif ($failCount > 0) { $status = 'fail'; }

            // 直连证据：仅在关联记录全部未检查时作为状态兜底
            $manual = $ipAccess[$ip] ?? null;
            if (is_array($manual) && $status === null) {
                $status = $manual['ok'] ? 'ok' : 'fail';
                if (isset($manual['ms'])) { $bestMs = (int) $manual['ms']; }
                $checkedAt = $manual['checked_at'];
            }
            $loc = $locations[$ip] ?? null;
            $rows[] = [
                'ip' => $ip,
                'manual' => $row['manual'],
                'id' => $row['manual_id'] !== '' ? $row['manual_id'] : null,
                'name' => $row['name'],
                'note' => $row['note'],
                'port' => $row['port'],
                'sources' => $row['sources'],
                'domain_count' => count($row['domains']),
                'records' => $row['records'],
                'domains' => $row['domains'],
                'status' => $status,
                'access_ms' => $bestMs,
                'direct' => is_array($manual) ? [
                    'ok' => (bool) $manual['ok'],
                    'ms' => isset($manual['ms']) ? (int) $manual['ms'] : null,
                    'source' => (string) ($manual['source'] ?? ''),
                    'checked_at' => (string) ($manual['checked_at'] ?? ''),
                ] : null,
                'access_ok' => $okCount,
                'access_fail' => $failCount,
                'checked_at' => $checkedAt,
                'location' => is_array($loc) ? [
                    'country' => (string) ($loc['country'] ?? ''),
                    'country_code' => (string) ($loc['country_code'] ?? ''),
                    'region' => (string) ($loc['region'] ?? ''),
                    'city' => (string) ($loc['city'] ?? ''),
                    'isp' => (string) ($loc['isp'] ?? ''),
                    'checked_at' => (string) ($loc['checked_at'] ?? ''),
                ] : null,
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            return [$a['ip']] <=> [$b['ip']];
        });

        // 账号分组统计（“手工”作为虚拟来源追加）。
        $accountsOut = [];
        foreach ($accounts as $acc) {
            $pid = (string) ($acc['id'] ?? '');
            $own = array_values(array_filter($rows, static function (array $r) use ($pid) { return in_array($pid, $r['sources'], true); }));
            $accountsOut[] = [
                'id' => $pid,
                'name' => (string) ($acc['name'] ?? $pid),
                'provider' => (string) ($acc['provider'] ?? ''),
                'ips' => count($own),
                'records' => array_sum(array_column($own, 'records')),
            ];
        }
        $manualRows = array_values(array_filter($rows, static function (array $r) { return $r['manual']; }));
        $accountsOut[] = [
            'id' => 'manual',
            'name' => '手工登记',
            'provider' => 'manual',
            'ips' => count($manualRows),
            'records' => 0,
        ];

        $countries = [];
        $linkedDomains = [];
        foreach ($rows as $r) {
            $cc = $r['location']['country_code'] ?? '';
            if ($cc !== '') { $countries[$cc] = true; }
            foreach ($r['domains'] as $d) { $linkedDomains[$d['provider'] . '|' . $d['domain']] = true; }
        }

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'stats' => [
                'ips' => count($rows),
                'manual' => count($manualRows),
                'auto' => count($rows) - count($manualRows),
                'countries' => count($countries),
                'records' => array_sum(array_column($rows, 'records')),
                'domains_linked' => count($linkedDomains),
            ],
            'accounts' => $accountsOut,
            'ips' => $rows,
        ];
    }


    /* ---- IP 访问检查（手动触发，结果缓存于 data/ip_access.json，覆盖派生状态） ---- */

    private const IP_ACCESS_FILE = 'ip_access.json';

    /** IP 访问状态映射（ip => {ok, ms, checked_at}），无则空数组 */
    public static function ipAccessMap(string $dir): array
    {
        $store = (new PrivateJsonStore($dir . '/' . self::IP_ACCESS_FILE))->read();
        return is_array($store) ? $store : [];
    }

    /** 记录 IP 访问检查结果（浏览器或服务端探测），仅公网 IP 入表 */
    public static function recordIpAccess(string $dir, array $results): void
    {
        $checkedAt = date('Y-m-d H:i:s');
        $clean = [];
        foreach ($results as $ip => $item) {
            $ip = trim((string) $ip);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) { $ip = inet_ntop(inet_pton($ip)); }
            if ($ip === '' || !PublicHttpProbe::isPublicIp($ip)) { continue; }
            if (!is_array($item) || !isset($item['ok']) || !is_bool($item['ok'])) { continue; }
            $entry = [$ip => ['ok' => $item['ok'], 'source' => (string) ($item['source'] ?? 'browser'), 'checked_at' => $checkedAt]];
            if (isset($item['ms']) && is_int($item['ms']) && $item['ms'] >= 0) { $entry[$ip]['ms'] = $item['ms']; }
            $clean += $entry;
        }
        if (!$clean) { throw new InvalidArgumentException('IP 检查结果为空或格式无效'); }
        (new PrivateJsonStore($dir . '/' . self::IP_ACCESS_FILE))->update(static function (array $state) use ($clean): array {
            return array_replace($state, $clean);
        });
    }

    /** 服务端并发探测指定 IP（http://IP/，不跟随跳转），结果入库 */
    public static function probeIps(string $dir, array $ips): array
    {
        $targets = [];
        foreach ($ips as $ip) {
            $ip = trim((string) $ip);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) { $ip = inet_ntop(inet_pton($ip)); }
            if ($ip === '' || !PublicHttpProbe::isPublicIp($ip)) { continue; }
            $targets[$ip] = 'http://' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip) . '/';
        }
        if (!$targets) { throw new InvalidArgumentException('没有可检查的公网 IP'); }
        $probed = PublicHttpProbe::run($targets);
        $results = [];
        foreach ($targets as $ip => $url) {
            $p = $probed[$url] ?? ['http_code' => 0, 'ms' => 0, 'error' => ''];
            $results[$ip] = [
                'ok' => $p['http_code'] >= 200 && $p['http_code'] < 400,
                'ms' => (int) $p['ms'],
                'source' => 'probe',
            ];
        }
        self::recordIpAccess($dir, $results);
        return [
            'checked_at' => date('Y-m-d H:i:s'),
            'total' => count($targets),
            'ok' => count(array_filter($results, static function (array $r) { return $r['ok']; })),
            'fail' => count(array_filter($results, static function (array $r) { return !$r['ok']; })),
        ];
    }

    /** 供 AI 阅读的纯 Markdown 快照：IP 清单 + 关联域名与访问状态。 */
    public static function markdown(string $dir, array $accounts): string
    {
        $s = self::snapshot($dir, $accounts);
        $lines = [];
        $lines[] = '# IP 资产大屏 · Markdown 快照';
        $lines[] = '';
        $lines[] = '- 生成时间：' . $s['generated_at'] . '（缓存于 ' . ($s['cache']['saved_at'] ?? '') . '；只读快照，台账或解析变化后约 2 分钟内刷新）';
        $lines[] = '- 规模：IP ' . $s['stats']['ips'] . '（自动发现 ' . $s['stats']['auto'] . ' · 手工登记 ' . $s['stats']['manual'] . '）· 覆盖国家/地区 ' . $s['stats']['countries'] . ' · 关联 A 记录 ' . $s['stats']['records'];
        $lines[] = '- 状态语义：ok=至少一条关联记录最近访问检查通过；fail=存在失败记录；空=未检查（未检查 ≠ 正常）。归属地为最近一次 ipwho.is 缓存，可能是 CDN 出口。';
        $lines[] = '';
        foreach ($s['ips'] as $row) {
            $loc = $row['location'];
            $locText = $loc ? ($loc['country'] . ($loc['city'] && $loc['city'] !== $loc['country'] ? ' · ' . $loc['city'] : '') . ($loc['isp'] ? ' · ' . $loc['isp'] : '')) : '归属地未知（未刷新过）';
            $tag = $row['manual'] ? ' [手工]' : '';
            $statusText = $row['status'] === 'ok' ? '访问正常' : ($row['status'] === 'fail' ? '存在访问失败' : '未检查');
            $lines[] = '## ' . $row['ip'] . $tag;
            $lines[] = '- 归属地：' . $locText;
            $lines[] = '- 状态：' . $statusText . '（ok ' . $row['access_ok'] . ' / fail ' . $row['access_fail'] . '，最近检查 ' . ($row['checked_at'] ?? '从未') . '）';
            if ($row['name'] || $row['note'] || $row['port']) {
                $lines[] = '- 登记：' . implode(' · ', array_filter([
                    $row['name'], $row['port'] ? '端口 ' . $row['port'] : null, $row['note'],
                ]));
            }
            if ($row['domains']) {
                $lines[] = '- 关联域名（' . $row['domain_count'] . '）：';
                foreach ($row['domains'] as $d) {
                    $https = ($d['https'] ?? '') === 'ok' ? ' · HTTPS 正常（已上线运营）'
                        : (($d['https'] ?? '') === 'fail' && $d['access'] === 'ok' ? ' · 仅 HTTP（未启用 HTTPS）' : '');
                    $suffix = $d['access'] === null ? '未检查' : ($d['access'] === 'ok' ? 'HTTP ' . $d['http_code'] : ($d['access'] === 'na' ? '不适用' : '失败'));
                    $lines[] = '  - ' . $d['fqdn'] . '（' . $d['provider_name'] . ' · ' . $suffix . $https . '）';
                }
            } else {
                $lines[] = '- 暂无域名 A 记录指向此 IP（纯手工登记）。';
            }
            $lines[] = '';
        }
        return implode("\n", $lines);
    }
}
