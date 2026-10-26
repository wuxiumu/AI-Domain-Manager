<?php

class DomainController
{
    /** 域名列表（支持 provider / 关键字 / 有效性 / 到期过滤与排序） */
    public function index(): void
    {
        Auth::requireLogin();

        $domains = JsonStore::read(DATA_DIR . '/domains.json', []);
        if (!is_array($domains)) {
            $domains = [];
        }

        $provider = (string) ($_GET['provider'] ?? '');
        $q        = trim((string) ($_GET['q'] ?? ''));
        $validRaw = $_GET['valid'] ?? '';
        $valid    = $validRaw === '' ? null : ($validRaw === '1');
        $sort     = (string) ($_GET['sort'] ?? 'expire');
        $expiring = (string) ($_GET['expiring'] ?? '');
        $access   = (string) ($_GET['access'] ?? ''); // healthy | unhealthy | stale | unchecked
        $access = ['ok' => 'healthy', 'fail' => 'unhealthy'][$access] ?? $access;

        $accessMap = JsonStore::read(DATA_DIR . '/access_check.json', [])['results'] ?? [];
        if (!is_array($accessMap)) {
            $accessMap = [];
        }
        $healthMap = [];
        foreach ((new HealthService())->dashboard()['assets'] as $asset) {
            if ($asset['kind'] === 'domain') {
                $healthMap[$asset['provider'] . '|' . $asset['name']] = $asset['health'];
            }
        }
        $healthKey = static function (array $domain): string {
            return ($domain['provider'] ?? 'manual') . '|' . strtolower(rtrim(trim((string) ($domain['domain'] ?? '')), '.'));
        };
        $unchecked = ['status' => 'unchecked', 'last_status' => null, 'checked_at' => null,
            'latency_ms' => null, 'message' => '尚未检测', 'checks' => [], 'resolved_ips' => []];

        $today = strtotime(date('Y-m-d'));

        $filtered = array_values(array_filter($domains, function ($d) use ($provider, $q, $valid, $expiring, $access, $healthMap, $healthKey, $today) {
            if ($provider !== '' && ($d['provider'] ?? '') !== $provider) {
                return false;
            }
            if ($valid !== null && (($d['valid'] ?? true) !== $valid)) {
                return false;
            }
            if ($q !== '') {
                $hay = ($d['domain'] ?? '') . ' ' . ($d['provider_name'] ?? '') . ' ' . ($d['note'] ?? '');
                if (mb_stripos($hay, $q) === false) {
                    return false;
                }
            }
            if ($expiring !== '') {
                if (($d['valid'] ?? true) === false) {
                    return false; // 已失效域名不参与到期统计
                }
                $exp = $d['expire_at'] ?? '';
                $days = $exp ? (int) floor((strtotime($exp . ' 23:59:59') - $today) / 86400) : null;
                if ($expiring === 'expired') {
                    if ($days === null || $days >= 0) {
                        return false;
                    }
                } else {
                    $n = (int) $expiring;
                    if ($days === null || $days < 0 || $days > $n) {
                        return false;
                    }
                }
            }
            if ($access !== '') {
                $st = $healthMap[$healthKey($d)]['status'] ?? 'unchecked';
                if ($st !== $access) {
                    return false;
                }
            }
            return true;
        }));

        // 工作台与域名列表共享健康状态；旧 access 字段保留兼容。
        array_walk($filtered, function (&$d) use ($accessMap, $healthMap, $healthKey, $unchecked) {
            $d['health'] = $healthMap[$healthKey($d)] ?? $unchecked;
            $key = ($d['provider'] ?? '') . '|' . ($d['domain'] ?? '');
            $a   = $accessMap[$key] ?? null;
            $d['access'] = $a ? [
                'status'    => $a['status'],
                'http_code' => $a['http_code'],
                'ms'        => $a['ms'],
                'scheme'    => $a['scheme'],
                'error'     => $a['error'],
                'checked_at' => $a['checked_at'],
            ] : null;
        });

        usort($filtered, function ($a, $b) use ($sort) {
            if ($sort === 'domain') {
                return strcmp($a['domain'] ?? '', $b['domain'] ?? '');
            }
            if ($sort === 'expire_desc') {
                return self::expireCmp($b, $a);
            }
            if ($sort === 'synced') {
                return strcmp($b['synced_at'] ?? '', $a['synced_at'] ?? '');
            }
            return self::expireCmp($a, $b); // expire 升序，空到期排最后
        });

        json_out([
            'total' => count($filtered),
            'list'  => ($_GET['all'] ?? '') === '1' ? $filtered : array_slice($filtered, 0, 1000),
            'meta'  => JsonStore::read(DATA_DIR . '/meta.json', []),
        ]);
    }

    /** 仪表盘统计 */
    public function stats(): void
    {
        Auth::requireLogin();

        $domains = JsonStore::read(DATA_DIR . '/domains.json', []);
        if (!is_array($domains)) {
            $domains = [];
        }

        $stats = [
            'total'      => count($domains),
            'valid'      => 0,
            'invalid'    => 0,
            'providers'  => [],        // id => count
            'expiring_30' => 0,
            'expiring_15' => 0,
            'expiring_7'  => 0,
            'expired'    => 0,
            'last_sync'  => null,
        ];

        $today = strtotime(date('Y-m-d'));

        foreach ($domains as $d) {
            if (($d['valid'] ?? true) === false) {
                $stats['invalid']++;
                continue;
            }
            $stats['valid']++;
            $pid = $d['provider'] ?? 'unknown';
            $stats['providers'][$pid] = ($stats['providers'][$pid] ?? 0) + 1;

            $exp = $d['expire_at'] ?? '';
            if ($exp && ($t = strtotime($exp . ' 23:59:59'))) {
                $days = (int) floor(($t - $today) / 86400);
                if ($days < 0) {
                    $stats['expired']++;
                } elseif ($days <= 7) {
                    $stats['expiring_7']++;
                    $stats['expiring_15']++;
                    $stats['expiring_30']++;
                } elseif ($days <= 15) {
                    $stats['expiring_15']++;
                    $stats['expiring_30']++;
                } elseif ($days <= 30) {
                    $stats['expiring_30']++;
                }
            }
        }

        // provider 展示信息
        $names = [];
        foreach (config('accounts') as $a) {
            $names[$a['id']] = $a['name'];
        }
        $stats['provider_names'] = $names;
        $meta = JsonStore::read(DATA_DIR . '/meta.json', []);
        $stats['last_sync'] = $meta['last_sync'] ?? null;
        $stats['sync_status'] = $meta['status'] ?? null;
        $stats['last_attempt_at'] = $meta['last_attempt_at'] ?? null;
        $stats['restored_at'] = $meta['restored_at'] ?? null;

        json_out($stats);
    }

    /** 新增域名（手动添加） */
    public function create(): void
    {
        Auth::requireLogin();
        $b = body_json();

        try {
            $domain = self::normalizeDomain($b['domain'] ?? '');
            $expireAt = self::validateDate($b['expire_at'] ?? '', '到期日期');
            $registrationDate = self::validateDate($b['registration_date'] ?? '', '注册日期');
        } catch (InvalidArgumentException $e) {
            json_out(['error' => $e->getMessage()], 422);
            return;
        }
        $provider = trim((string) ($b['provider'] ?? ''));
        if ($provider === '' || !self::providerExists($provider)) {
            $provider = 'manual';
        }

        $store = JsonStore::read(DATA_DIR . '/domains.json', []);
        foreach ($store as $d) {
            if (($d['provider'] ?? '') === $provider && strtolower((string) ($d['domain'] ?? '')) === strtolower($domain)) {
                json_out(['error' => '该云商下已存在此域名'], 409);
            }
        }

        $now = date('Y-m-d H:i:s');
        $entry = [
            'domain'            => $domain,
            'provider'          => $provider,
            'provider_name'     => self::providerName($provider),
            'status'            => trim((string) ($b['status'] ?? '')),
            'expire_at'         => $expireAt,
            'registration_date' => $registrationDate,
            'registrar'         => trim((string) ($b['registrar'] ?? '')),
            'note'              => trim((string) ($b['note'] ?? '')),
            'raw'               => [],
            'valid'             => true,
            'synced_at'         => $now,
            'first_seen'        => $now,
        ];

        $store[] = $entry;
        JsonStore::write(DATA_DIR . '/domains.json', $store);
        Logger::log('domain_create', ['provider' => $provider, 'domain' => $domain]);
        json_out(['ok' => true, 'domain' => $entry]);
    }

    /** 编辑域名（按 provider + domain 定位） */
    public function update(): void
    {
        Auth::requireLogin();
        $b = body_json();

        $provider = (string) ($b['provider'] ?? '');
        $domain   = (string) ($b['domain'] ?? '');
        if ($provider === '' || $domain === '') {
            json_out(['error' => '缺少定位参数 provider/domain'], 422);
        }

        try {
            foreach (['expire_at' => '到期日期', 'registration_date' => '注册日期'] as $field => $label) {
                if (array_key_exists($field, $b)) {
                    $b[$field] = self::validateDate($b[$field], $label);
                }
            }
        } catch (InvalidArgumentException $e) {
            json_out(['error' => $e->getMessage()], 422);
            return;
        }

        $store = JsonStore::read(DATA_DIR . '/domains.json', []);
        $idx   = self::findIndex($store, $provider, $domain);
        if ($idx === -1) {
            json_out(['error' => '域名不存在'], 404);
        }

        foreach (['status', 'expire_at', 'registration_date', 'registrar', 'note'] as $k) {
            if (array_key_exists($k, $b)) {
                $store[$idx][$k] = trim((string) $b[$k]);
            }
        }
        if (array_key_exists('valid', $b)) {
            $store[$idx]['valid'] = (bool) $b['valid'];
        }

        JsonStore::write(DATA_DIR . '/domains.json', $store);
        Logger::log('domain_update', ['provider' => $provider, 'domain' => $domain]);
        json_out(['ok' => true, 'domain' => $store[$idx]]);
    }

    /** 删除域名（按 provider + domain 定位） */
    public function delete(): void
    {
        Auth::requireLogin();
        $b = body_json();

        $provider = (string) ($b['provider'] ?? '');
        $domain   = (string) ($b['domain'] ?? '');
        if ($provider === '' || $domain === '') {
            json_out(['error' => '缺少定位参数 provider/domain'], 422);
        }

        $store = JsonStore::read(DATA_DIR . '/domains.json', []);
        $idx   = self::findIndex($store, $provider, $domain);
        if ($idx === -1) {
            json_out(['error' => '域名不存在'], 404);
        }

        $removed = $store[$idx];
        array_splice($store, $idx, 1);
        JsonStore::write(DATA_DIR . '/domains.json', $store);
        Logger::log('domain_delete', ['provider' => $provider, 'domain' => $domain, 'removed' => $removed]);
        json_out(['ok' => true]);
    }

    /** 仅保存可公开解析的完整域名；实际探测仍须校验 DNS 返回的 IP。 */
    public static function normalizeDomain($value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('请填写有效的域名');
        }
        $domain = trim($value);
        if (preg_match('/[\s\x00-\x20\x7f\/\\\\:@?#%]/u', $domain) !== 0) {
            throw new InvalidArgumentException('域名不能包含协议、端口、路径或空白');
        }
        if (function_exists('idn_to_ascii')) {
            $info = [];
            $domain = idn_to_ascii($domain, IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46, $info);
            if ($domain === false || ($info['errors'] ?? 0) !== 0) {
                throw new InvalidArgumentException('国际化域名格式不正确');
            }
        } elseif (preg_match('/[^\x00-\x7f]/', $domain)) {
            throw new InvalidArgumentException('当前服务器未启用 intl 扩展，请填写域名的 Punycode 格式');
        }
        $domain = strtolower($domain);
        if (substr($domain, -1) === '.') {
            $domain = substr($domain, 0, -1);
        }
        if (strlen($domain) > 253 || filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('请填写域名，不能使用 IP 地址');
        }
        $labels = explode('.', $domain);
        $suffix = end($labels);
        if (count($labels) < 2 || preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})$/D', $suffix) !== 1 ||
            in_array($suffix, ['localhost', 'local', 'lan', 'home', 'internal', 'invalid', 'test', 'onion'], true) ||
            $domain === 'home.arpa' || substr($domain, -10) === '.home.arpa') {
            throw new InvalidArgumentException('请填写完整的公网域名，不能使用内网主机名');
        }
        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) !== 1) {
                throw new InvalidArgumentException('域名格式不正确：每段只能使用字母、数字和中间的连字符');
            }
        }
        return $domain;
    }

    public static function validateDate($value, string $label): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . '必须为 YYYY-MM-DD 格式，可留空');
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) ||
            !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException($label . '必须是有效日期，格式为 YYYY-MM-DD');
        }
        return $value;
    }

    private static function findIndex(array $store, string $provider, string $domain): int
    {
        foreach ($store as $i => $d) {
            if (($d['provider'] ?? '') === $provider && strtolower((string) ($d['domain'] ?? '')) === strtolower($domain)) {
                return $i;
            }
        }
        return -1;
    }

    private static function providerExists(string $provider): bool
    {
        foreach (config('accounts') as $a) {
            if ($a['id'] === $provider) {
                return true;
            }
        }
        return false;
    }

    private static function providerName(string $provider): string
    {
        if ($provider === 'manual') {
            return '手动添加';
        }
        foreach (config('accounts') as $a) {
            if ($a['id'] === $provider) {
                return $a['name'];
            }
        }
        return $provider;
    }

    private static function expireCmp(array $a, array $b): int
    {
        $ea = $a['expire_at'] ?? '';
        $eb = $b['expire_at'] ?? '';
        if ($ea === $eb) {
            return strcmp($a['domain'] ?? '', $b['domain'] ?? '');
        }
        if ($ea === '') {
            return 1;
        }
        if ($eb === '') {
            return -1;
        }
        return strcmp($ea, $eb);
    }
}
