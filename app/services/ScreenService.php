<?php

/**
 * 大屏聚合快照：未过期域名与其子域名 A 记录（IPv4）。
 * 只读缓存台账与解析快照，绝不发起云请求；网络刷新由账号同步显式触发。
 */
class ScreenService
{
    /** 到期剩余天数：按日界计算（当天 23:59:59 前都算当天），未知或非法到期返回 null（不视为过期）。 */
    public static function daysLeft(?string $expireAt): ?int
    {
        $exp = trim((string) $expireAt);
        if ($exp === '') { return null; }
        $ts = strtotime($exp . ' 23:59:59');
        if ($ts === false) { return null; }
        return (int) floor(($ts - strtotime(date('Y-m-d'))) / 86400);
    }

    /** 聚合快照（SnapshotCache 包裹；数据源：域名台账 + DNS 解析快照 + 访问检测结果）。 */
    public static function snapshot(string $dir, array $accounts, bool $force = false): array
    {
        $sources = array_merge(
            [$dir . '/domains.json', $dir . '/record_access.json'],
            glob($dir . '/cache/dns-*.json') ?: []
        );
        return SnapshotCache::remember($dir, 'domain-screen-v1', $sources,
            static function () use ($dir, $accounts) { return self::build($dir, $accounts); },
            $force ? time() - 1 : time() + 120); // force：过期快照立即重建（手动刷新），不改变指纹判定
    }

    private static function build(string $dir, array $accounts): array
    {
        $accountById = [];
        foreach ($accounts as $acc) {
            if (isset($acc['id'])) { $accountById[$acc['id']] = $acc; }
        }

        $domains = JsonStore::read($dir . '/domains.json', []);
        if (!is_array($domains)) { $domains = []; }

        $rows = [];
        $hidden = ['expired' => 0, 'invalid' => 0, 'orphaned' => 0];
        foreach ($domains as $d) {
            $pid = (string) ($d['provider'] ?? '');
            if (!isset($accountById[$pid])) { $hidden['orphaned']++; continue; }
            if (($d['valid'] ?? true) === false) { $hidden['invalid']++; continue; }
            $days = self::daysLeft($d['expire_at'] ?? null);
            if ($days !== null && $days < 0) { $hidden['expired']++; continue; }
            $rows[] = self::row($dir, $pid, $accountById[$pid], $d, $days);
        }

        // 默认按账号 + 域名稳定排序；展示层再按用户选择的排序重排。
        usort($rows, static function (array $a, array $b): int {
            return [$a['provider'], $a['domain']] <=> [$b['provider'], $b['domain']];
        });

        $accountsOut = [];
        foreach ($accounts as $acc) {
            $pid = (string) ($acc['id'] ?? '');
            $own = array_values(array_filter($rows, static function (array $r) use ($pid) { return $r['provider'] === $pid; }));
            $snapshotAt = null;
            foreach ($own as $r) {
                if ($r['dns_snapshot_at'] !== null && ($snapshotAt === null || $r['dns_snapshot_at'] > $snapshotAt)) {
                    $snapshotAt = $r['dns_snapshot_at'];
                }
            }
            $accountsOut[] = [
                'id' => $pid,
                'name' => (string) ($acc['name'] ?? $pid),
                'provider' => (string) ($acc['provider'] ?? ''),
                'domains' => count($own),
                'a_records' => array_sum(array_column($own, 'a_count')),
                'subdomains' => array_sum(array_column($own, 'subdomain_count')),
                'dns_pending' => count(array_filter($own, static function (array $r) { return !$r['dns_cached']; })),
                'dns_snapshot_at' => $snapshotAt,
            ];
        }

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'stats' => [
                'domains' => count($rows),
                'subdomains' => array_sum(array_column($rows, 'subdomain_count')),
                'a_records' => array_sum(array_column($rows, 'a_count')),
                'hidden' => $hidden,
            ],
            'accounts' => $accountsOut,
            'domains' => $rows,
        ];
    }

    /** 单个域名的展示行：只保留 A 记录并按 FQDN 分组，根记录以 @ 呈现；附带记录版本与访问检测结果。 */
    private static function row(string $dir, string $pid, array $acc, array $d, ?int $days): array
    {
        $domain = strtolower(rtrim(trim((string) ($d['domain'] ?? '')), '.'));
        $snapshot = $domain === '' ? null : DnsRecordCache::peek($dir, $pid, (string) ($d['domain'] ?? ''));

        // 访问检测结果（与解析快照版本对齐才有效；结果本身由 access-check / 前端检查显式触发产生，
        // 持久化于 record_access.json —— 后端探测按记录、浏览器探测按 FQDN，页面直接展示缓存结果不重新检查）
        $accessByRecord = [];
        $browserByFqdn = [];
        if ($snapshot !== null) {
            $store = JsonStore::read($dir . '/record_access.json', []);
            $group = is_array($store) ? ($store[$pid . '|' . (string) ($d['domain'] ?? '')] ?? null) : null;
            if (is_array($group)
                && ($group['records_revision'] ?? null) === ($snapshot['revision'] ?? null)) {
                if (is_array($group['results'] ?? null)) { $accessByRecord = $group['results']; }
                if (is_array($group['browser']['results'] ?? null)) { $browserByFqdn = $group['browser']['results']; }
            }
        }

        $groups = [];
        $aCount = 0;
        $otherCount = 0;
        if ($snapshot !== null) {
            foreach ($snapshot['records'] as $record) {
                $type = strtoupper((string) ($record['type'] ?? ''));
                if ($type !== 'A') { $otherCount++; continue; }
                $value = trim((string) ($record['value'] ?? ''));
                if ($value === '') { continue; }
                $aCount++;
                $fqdn = self::fqdnOf((string) ($record['name'] ?? '@'), $domain);
                if (!isset($groups[$fqdn])) {
                    $groups[$fqdn] = ['name' => self::relativeName($fqdn, $domain), 'fqdn' => $fqdn, 'ips' => [], 'ttl' => null, 'records' => [], 'access' => null, 'browser_at' => null];
                }
                if (!in_array($value, $groups[$fqdn]['ips'], true)) { $groups[$fqdn]['ips'][] = $value; }
                $ttl = (int) ($record['ttl'] ?? 0);
                if ($ttl > 0 && ($groups[$fqdn]['ttl'] === null || $ttl < $groups[$fqdn]['ttl'])) { $groups[$fqdn]['ttl'] = $ttl; }

                $entry = ['id' => (string) ($record['id'] ?? ''), 'value' => $value,
                    'ttl' => $ttl > 0 ? $ttl : null, 'version' => (string) ($record['version'] ?? ''),
                    'status' => (string) ($record['status'] ?? ''), 'access' => null];
                $probe = $accessByRecord[$entry['id']] ?? null;
                if (is_array($probe) && isset($probe['status'])) {
                    $entry['access'] = [
                        'status' => (string) $probe['status'],
                        'http_code' => (int) ($probe['http_code'] ?? 0),
                        'ms' => (int) ($probe['ms'] ?? 0),
                        // HTTPS 单列结果：ok=证书有效可达（上线运营）；fail=未启用或证书无效；空=旧结果未探测
                        'https' => (string) ($probe['https_status'] ?? ''),
                        'https_code' => (int) ($probe['https_code'] ?? 0),
                        'https_expires' => (string) ($probe['https_expires'] ?? ''),
                        // HTTP / HTTPS 分列访问耗时（ms）；旧结果无此字段
                        'http_status' => (string) ($probe['http_status'] ?? ''),
                        'http_ms' => isset($probe['http_ms']) ? (int) $probe['http_ms'] : null,
                        'https_ms' => isset($probe['https_ms']) ? (int) $probe['https_ms'] : null,
                        'checked_at' => (string) ($probe['checked_at'] ?? ''),
                    ];
                    if ($entry['access']['status'] === 'ok') { $groups[$fqdn]['access'] = 'ok'; }
                    elseif ($groups[$fqdn]['access'] === null) { $groups[$fqdn]['access'] = 'fail'; }
                }
                $groups[$fqdn]['records'][] = $entry;
            }
            // 组级 HTTPS 聚合（与 🔒 锁标一致的语义）：有锁 ok=任一记录 HTTPS 通过（到期取最早）；
            // 浏览器 https 探测通过也算有锁（fetch https 成功即证书有效）；
            // 无锁 http-only=HTTP 可达但没有锁（HTTPS 未通过或未检查）；组不可达 → null
            foreach ($groups as $fqdn => $group) {
                $httpsOk = false; $earliestExpire = '';
                foreach ($group['records'] as $e) {
                    $a = $e['access'];
                    $h = is_array($a) ? $a['https'] : '';
                    if ($h === 'ok') {
                        $httpsOk = true;
                        $exp = $a['https_expires'] ?? '';
                        if ($exp !== '' && ($earliestExpire === '' || $exp < $earliestExpire)) { $earliestExpire = $exp; }
                    }
                }
                $browser = $browserByFqdn[$fqdn] ?? null;
                $browserHttps = is_array($browser) && ($browser['ok'] ?? false) === true && ($browser['scheme'] ?? '') === 'https';
                if ($httpsOk) {
                    $groups[$fqdn]['https'] = 'ok';
                    $groups[$fqdn]['https_expires'] = $earliestExpire;
                } elseif ($browserHttps) {
                    $groups[$fqdn]['https'] = 'ok';
                    $groups[$fqdn]['https_expires'] = null;
                } else {
                    $groups[$fqdn]['https'] = $group['access'] === 'ok' ? 'http-only' : null;
                    $groups[$fqdn]['https_expires'] = null;
                }
            }
            // 浏览器探测结果（前端检查上报）：按 FQDN 合并，任一通过即组级 ok；耗时与时间透出供页面显示
            foreach ($browserByFqdn as $fqdn => $browser) {
                if (!isset($groups[$fqdn])) { continue; }
                $groups[$fqdn]['browser_at'] = (string) ($browser['checked_at'] ?? '');
                $groups[$fqdn]['browser_ms'] = isset($browser['ms']) && is_int($browser['ms']) ? $browser['ms'] : null;
                if (($browser['ok'] ?? false) === true && $groups[$fqdn]['access'] !== 'ok') { $groups[$fqdn]['access'] = 'ok'; }
                elseif (($browser['ok'] ?? false) === false && $groups[$fqdn]['access'] === null) { $groups[$fqdn]['access'] = 'fail'; }
            }
        }
        $subdomains = array_values($groups);
        // 根记录（@）排最前，其余按名称排序
        usort($subdomains, static function (array $a, array $b): int {
            return (($a['name'] === '@' ? 0 : 1) <=> ($b['name'] === '@' ? 0 : 1)) ?: strcmp($a['name'], $b['name']);
        });

        return [
            'domain' => $domain,
            'provider' => $pid,
            'provider_name' => (string) ($d['provider_name'] ?? $acc['name'] ?? $pid),
            'registrar' => (string) ($d['registrar'] ?? ''),
            'expire_at' => (string) ($d['expire_at'] ?? ''),
            'days_left' => $days,
            'synced_at' => (string) ($d['synced_at'] ?? ''),
            'dns_cached' => $snapshot !== null,
            'dns_snapshot_at' => $snapshot['snapshot_at'] ?? null,
            'dns_revision' => $snapshot['revision'] ?? null,
            'subdomains' => $subdomains,
            'subdomain_count' => count(array_filter($subdomains, static function (array $s) { return $s['name'] !== '@'; })),
            'a_count' => $aCount,
            'other_count' => $otherCount,
        ];
    }

    /** 相对主机记录 → 完整域名（沿用 DomainInventory 的 FQDN 归一化规则）。 */
    public static function fqdnOf(string $name, string $domain): string
    {
        $name = strtolower(rtrim(trim($name), '.'));
        if ($name === '' || $name === '@' || $name === $domain) { return $domain; }
        return substr($name, -strlen('.' . $domain)) === '.' . $domain ? $name : $name . '.' . $domain;
    }

    public static function relativeName(string $fqdn, string $domain): string
    {
        if ($fqdn === $domain) { return '@'; }
        return $fqdn === '' ? '@' : substr($fqdn, 0, strlen($fqdn) - strlen('.' . $domain));
    }

    /** 供 AI 阅读的纯 Markdown 快照：完整清单 + 可执行 CRUD 指引，一份文件自包含。 */
    public static function markdown(string $dir, array $accounts): string
    {
        $s = self::snapshot($dir, $accounts);
        $h = $s['stats']['hidden'] ?? [];
        $exp30 = 0;
        $exp90 = 0;
        foreach ($s['domains'] as $row) {
            if ($row['days_left'] === null) { continue; }
            if ($row['days_left'] <= 30) { $exp30++; }
            elseif ($row['days_left'] <= 90) { $exp90++; }
        }

        $lines = [];
        $lines[] = '# 域名资产大屏 · Markdown 快照';
        $lines[] = '';
        $lines[] = '- 生成时间：' . $s['generated_at'] . '（缓存于 ' . ($s['cache']['saved_at'] ?? '') . '；只读快照，台账或解析变化后约 2 分钟内刷新）';
        $lines[] = '- 规模：域名 ' . count($s['domains']) . ' · 子域名 ' . ($s['stats']['subdomains'] ?? 0) . ' · A 记录 ' . ($s['stats']['a_records'] ?? 0);
        $lines[] = '- 已隐藏（不在此清单）：已过期 ' . ($h['expired'] ?? 0) . ' · 已失效 ' . ($h['invalid'] ?? 0) . ' · 账号缺失 ' . ($h['orphaned'] ?? 0);
        $lines[] = '- 到期预警：≤30 天 ' . $exp30 . ' 个；31–90 天 ' . $exp90 . ' 个';
        $lines[] = '';
        $lines[] = '## 账号概览';
        $lines[] = '';
        $lines[] = '| 账号 ID | 名称 | 域名 | 子域名 | A 记录 | 解析未缓存 | 最近解析快照 |';
        $lines[] = '| --- | --- | --- | --- | --- | --- | --- |';
        foreach ($s['accounts'] as $a) {
            $lines[] = '| ' . $a['id'] . ' | ' . $a['name'] . ' | ' . $a['domains'] . ' | ' . $a['subdomains'] . ' | ' . $a['a_records'] . ' | ' . $a['dns_pending'] . ' | ' . ($a['dns_snapshot_at'] ?? '—') . ' |';
        }
        $lines[] = '';
        $lines[] = '## 域名清单（子域名 A 记录）';
        foreach ($s['domains'] as $row) {
            $lines[] = '';
            $expire = $row['days_left'] === null ? '到期未知' : '剩 ' . $row['days_left'] . ' 天';
            $lines[] = '### ' . $row['domain'] . ' — ' . $expire . '（到期 ' . ($row['expire_at'] ?: '未知') . ' · ' . $row['provider_name'] . '）';
            if (!$row['dns_cached']) {
                $lines[] = '- 解析未缓存：执行 POST /api/accounts/' . $row['provider'] . '/sync，body {"refresh_dns":true} 后可见子域名';
                continue;
            }
            if (!count($row['subdomains'])) {
                $lines[] = '- 该域名暂无 A 记录';
                continue;
            }
            foreach ($row['subdomains'] as $sub) {
                $lines[] = '- ' . $sub['fqdn'] . ' → ' . implode('、', $sub['ips'])
                    . '（TTL ' . ($sub['ttl'] ?? '—') . self::accessSuffix($sub) . '）';
            }
        }
        $lines[] = '';
        $lines[] = self::crudGuide();
        return implode("\n", $lines);
    }

    /** 每日异常报告：只读快照编排，重点关注到期与访问异常，供 AI 每日分析。 */
    public static function report(string $dir, array $accounts): array
    {
        $s = self::snapshot($dir, $accounts);
        $summary = self::reportSummary($s);
        $markdown = self::reportMarkdown($s, $summary);
        return ['ok' => true, 'generated_at' => date('Y-m-d H:i:s'),
            'summary' => $summary, 'format' => 'markdown',
            'usage' => '推荐用 prompt（已内嵌报告与分析要求）交给 AI 每日分析；也可让 AI 以 assets:read 请求 /api/domains/screen/report?format=markdown，或 cron 运行 scripts/generate-screen-report.php。',
            'prompt' => self::reportPrompt($markdown), 'markdown' => $markdown];
    }

    private static function accessSuffix(array $sub): string
    {
        if (($sub['https'] ?? null) === 'ok') {
            $expire = ($sub['https_expires'] ?? '') !== '' ? '，证书 ' . $sub['https_expires'] . ' 到期' : '';
            return '｜HTTPS 通过（有锁' . $expire . '）';
        }
        if (($sub['access'] ?? null) === 'ok') { return '｜HTTP 通过（无锁）'; }
        if (($sub['access'] ?? null) === 'fail') { return '｜访问异常'; }
        return '';
    }

    private static function crudGuide(): string
    {
        return <<<'MD'
## AI 可执行的操作（CRUD 指引）
管理员会话（Cookie + X-CSRF-Token）或 Bearer 密钥均可调用；括号内为 Bearer 权限范围：
- 读本清单 JSON：GET /api/domains/screen（assets:read）
- 每日异常报告：GET /api/domains/screen/report（assets:read；?format=markdown 直出正文）
- 同步账号域名并刷新解析缓存：POST /api/accounts/{id}/sync，body {"refresh_dns":true}（sync:run）
- 新增解析：POST /api/domains/{provider}/{domain}/records，body {"type":"A","name":"www","value":"192.0.2.10","expected_revision":"<dns_revision>"}（dns:write）。expected_revision 取本清单响应的 dns_revision，或同区域上一条成功添加响应返回的 revision；重复记录返回 409，视为跳过
- 读取记录 ID 与 version：GET /api/domains/{provider}/{domain}/records（dns:read）
- 修改解析：PUT /api/domains/{provider}/{domain}/records/{recordId}，body {"value":"192.0.2.99","expected_version":"<记录 version>"}（dns:write）
- 删除解析：DELETE /api/domains/{provider}/{domain}/records/{recordId}，body {"expected_version":"<记录 version>"}（dns:write）
- 检测该域名解析访问状态：POST /api/domains/{provider}/{domain}/records/access-check（checks:run）
写入原则：只写一次并回读核实；409 表示版本已变化，重新读取后重试；不要为展示列表而触发同步或写入。
MD;
    }

    /** 异常分类汇总：报告正文与 JSON summary 共用。 */
    private static function reportSummary(array $s): array
    {
        $summary = ['domains' => count($s['domains']), 'expiring_30' => 0, 'expiring_31_90' => 0,
            'access_fail_groups' => 0, 'access_unchecked_groups' => 0, 'dns_not_cached' => 0,
            'domains_without_a' => 0, 'non_public_records' => 0];
        $shared = [];
        foreach ($s['domains'] as $row) {
            if ($row['days_left'] !== null) {
                if ($row['days_left'] <= 30) { $summary['expiring_30']++; }
                elseif ($row['days_left'] <= 90) { $summary['expiring_31_90']++; }
            }
            if (!$row['dns_cached']) { $summary['dns_not_cached']++; continue; }
            if (!count($row['subdomains'])) { $summary['domains_without_a']++; }
            foreach ($row['subdomains'] as $sub) {
                if (($sub['access'] ?? null) === 'fail') { $summary['access_fail_groups']++; }
                elseif (($sub['access'] ?? null) === null) { $summary['access_unchecked_groups']++; }
                foreach ($sub['ips'] as $ip) {
                    if (!PublicHttpProbe::isPublicIp($ip)) { $summary['non_public_records']++; }
                    $shared[$ip][$row['domain']] = true;
                }
            }
        }
        foreach ($shared as $domains) {
            if (count($domains) > 1) { $summary['shared_ips'] = ($summary['shared_ips'] ?? 0) + 1; }
        }
        return $summary;
    }

    private static function reportMarkdown(array $s, array $summary): string
    {
        $maxRows = 50;
        $lines = [];
        $lines[] = '# 域名资产异常日报';
        $lines[] = '';
        $lines[] = '- 生成时间：' . $s['generated_at'] . '（只读缓存快照 ' . ($s['cache']['saved_at'] ?? '') . '，未触发任何检查或同步）';
        $lines[] = '- 规模：域名 ' . $summary['domains'] . ' · 子域名 ' . ($s['stats']['subdomains'] ?? 0) . ' · A 记录 ' . ($s['stats']['a_records'] ?? 0)
            . '；已隐藏：过期 ' . ($s['stats']['hidden']['expired'] ?? 0) . ' / 失效 ' . ($s['stats']['hidden']['invalid'] ?? 0) . ' / 账号缺失 ' . ($s['stats']['hidden']['orphaned'] ?? 0);
        $lines[] = '- 异常计数：≤30 天到期 ' . $summary['expiring_30'] . ' · 31–90 天 ' . $summary['expiring_31_90']
            . ' · 访问异常组 ' . $summary['access_fail_groups'] . ' · 解析未缓存 ' . $summary['dns_not_cached']
            . ' · 无 A 记录 ' . $summary['domains_without_a'] . ' · 非公网 A 记录 ' . $summary['non_public_records'];

        $expiring = array_filter($s['domains'], static function (array $r) { return $r['days_left'] !== null && $r['days_left'] <= 30; });
        usort($expiring, static function (array $a, array $b) { return $a['days_left'] <=> $b['days_left']; });
        $lines[] = '';
        $lines[] = '## P1 · 30 天内到期（' . count($expiring) . '）';
        if (!count($expiring)) { $lines[] = '无'; }
        else {
            $lines[] = '| 域名 | 账号 | 到期日 | 剩余天数 |';
            $lines[] = '| --- | --- | --- | --- |';
            foreach (array_slice($expiring, 0, $maxRows) as $r) {
                $lines[] = '| ' . $r['domain'] . ' | ' . $r['provider_name'] . ' | ' . ($r['expire_at'] ?: '未知') . ' | ' . $r['days_left'] . ' |';
            }
            if (count($expiring) > $maxRows) { $lines[] = '（其余 ' . (count($expiring) - $maxRows) . ' 个省略）'; }
        }

        $failRows = [];
        foreach ($s['domains'] as $row) {
            foreach ($row['subdomains'] as $sub) {
                if (($sub['access'] ?? null) !== 'fail') { continue; }
                $probe = null;
                foreach ($sub['records'] as $rec) { if (!empty($rec['access'])) { $probe = $rec['access']; break; } }
                $failRows[] = [$row['domain'], $sub['fqdn'], implode('、', $sub['ips']),
                    $probe ? ('HTTP ' . $probe['http_code'] . ' · ' . $probe['checked_at']) : '未知'];
            }
        }
        $lines[] = '';
        $lines[] = '## P1 · 访问异常（最近后端检测未通过，' . count($failRows) . ' 组）';
        if (!count($failRows)) { $lines[] = '无'; }
        else {
            $lines[] = '| 域名 | 子域名 | 解析 IP | 证据 |';
            $lines[] = '| --- | --- | --- | --- |';
            foreach (array_slice($failRows, 0, $maxRows) as $r) { $lines[] = '| ' . implode(' | ', $r) . ' |'; }
            if (count($failRows) > $maxRows) { $lines[] = '（其余 ' . (count($failRows) - $maxRows) . ' 组省略）'; }
        }

        $exp90 = array_filter($s['domains'], static function (array $r) { return $r['days_left'] !== null && $r['days_left'] > 30 && $r['days_left'] <= 90; });
        usort($exp90, static function (array $a, array $b) { return $a['days_left'] <=> $b['days_left']; });
        $lines[] = '';
        $lines[] = '## P2 · 31–90 天到期（' . count($exp90) . '）';
        if (!count($exp90)) { $lines[] = '无'; }
        else {
            $lines[] = '| 域名 | 账号 | 到期日 | 剩余天数 |';
            $lines[] = '| --- | --- | --- | --- |';
            foreach (array_slice($exp90, 0, $maxRows) as $r) {
                $lines[] = '| ' . $r['domain'] . ' | ' . $r['provider_name'] . ' | ' . ($r['expire_at'] ?: '未知') . ' | ' . $r['days_left'] . ' |';
            }
            if (count($exp90) > $maxRows) { $lines[] = '（其余 ' . (count($exp90) - $maxRows) . ' 个省略）'; }
        }

        $noCache = array_filter($s['domains'], static function (array $r) { return !$r['dns_cached']; });
        $lines[] = '';
        $lines[] = '## P2 · 解析未缓存（看不到子域名，' . count($noCache) . '）';
        if (!count($noCache)) { $lines[] = '无'; }
        else {
            foreach (array_slice($noCache, 0, $maxRows) as $r) {
                $lines[] = '- ' . $r['domain'] . '（' . $r['provider_name'] . '）→ POST /api/accounts/' . $r['provider'] . '/sync，body {"refresh_dns":true}';
            }
        }

        $nonPublic = [];
        foreach ($s['domains'] as $row) {
            foreach ($row['subdomains'] as $sub) {
                foreach ($sub['ips'] as $ip) {
                    if (!PublicHttpProbe::isPublicIp($ip)) { $nonPublic[] = [$row['domain'], $sub['fqdn'], $ip]; }
                }
            }
        }
        $lines[] = '';
        $lines[] = '## P2 · 非公网 A 记录（保留 / 内网地址，' . count($nonPublic) . '）';
        if (!count($nonPublic)) { $lines[] = '无'; }
        else {
            foreach (array_slice($nonPublic, 0, $maxRows) as $r) { $lines[] = '- ' . $r[0] . ' · ' . $r[1] . ' → ' . $r[2]; }
            if (count($nonPublic) > $maxRows) { $lines[] = '（其余 ' . (count($nonPublic) - $maxRows) . ' 条省略）'; }
        }

        $unchecked = 0;
        foreach ($s['domains'] as $row) {
            foreach ($row['subdomains'] as $sub) { if (($sub['access'] ?? null) === null) { $unchecked++; } }
        }
        $lines[] = '';
        $lines[] = '## P2 · 访问从未检测（' . $unchecked . ' 组）';
        $lines[] = $unchecked ? '可对具体域名执行 POST /api/domains/{provider}/{domain}/records/access-check 补齐证据；未检查 ≠ 正常。' : '无';

        $noA = array_filter($s['domains'], static function (array $r) { return $r['dns_cached'] && !count($r['subdomains']); });
        $lines[] = '';
        $lines[] = '## P3 · 无 A 记录的域名（' . count($noA) . '）';
        $lines[] = count($noA) ? implode('、', array_slice(array_column($noA, 'domain'), 0, $maxRows)) : '无';

        $shared = [];
        foreach ($s['domains'] as $row) {
            foreach ($row['subdomains'] as $sub) {
                foreach ($sub['ips'] as $ip) { $shared[$ip][$row['domain']] = true; }
            }
        }
        $sharedRows = [];
        foreach ($shared as $ip => $domains) {
            if (count($domains) > 1) { $sharedRows[] = [count($domains), $ip, implode('、', array_keys($domains))]; }
        }
        usort($sharedRows, static function (array $a, array $b) { return $b[0] <=> $a[0]; });
        $lines[] = '';
        $lines[] = '## 提示 · 多域名共享解析 IP（' . count($sharedRows) . ' 个）';
        if (!count($sharedRows)) { $lines[] = '无'; }
        else {
            foreach (array_slice($sharedRows, 0, 10) as $r) { $lines[] = '- ' . $r[1] . ' ← ' . $r[0] . ' 个域名：' . $r[2]; }
            if (count($sharedRows) > 10) { $lines[] = '（其余 ' . (count($sharedRows) - 10) . ' 个省略）'; }
        }

        $lines[] = '';
        $lines[] = '## 解读指引';
        $lines[] = '- 未检查 ≠ 正常；检测结果超过 24 小时为过时；DNS 解析 IP 可能是 CDN 或代理入口。';
        $lines[] = '- 浏览器前端检查结果缓存于管理员浏览器，不在本报告；本报告只含服务端检测。';
        $lines[] = '- 修复动作参考 CRUD 指引：GET /api/domains/screen.md。';

        return implode("\n", $lines);
    }

    private static function reportPrompt(string $markdown): string
    {
        return <<<'TEXT'
你是一名资深的域名与 DNS 运维顾问。请基于下方 <report> 标签内的「域名资产异常日报」（资产工作台大屏只读快照生成）完成每日分析。

输出要求：
1. 先用不超过 3 句话概括整体状况：异常总量、主要风险、最紧迫事项。
2. 把异常按类型分组（到期 / 访问异常 / 解析数据缺口 / 非公网记录值 / 共享 IP），每组列出涉及域名并注明依据报告中的哪一行。
3. 结合解析 IP 判断哪些域名可能共用同一服务器或 CDN，说明单点故障的影响面。
4. 给出建议动作清单，按 P0（立即）/ P1（本周）/ P2（择期）排序；写操作给出具体接口与参数（如 POST /api/accounts/{id}/sync、access-check、PUT records），注明需要的权限范围。
5. 证据不足时明确说明，并指出需要补充哪项检查，不要臆测。
6. 始终遵守数据边界：未检查 ≠ 正常；解析 IP 可能是 CDN；本报告是生成时刻的快照；写操作必须只写一次并回读核实。

TEXT
            . "<report>\n" . $markdown . "</report>\n";
    }
}
