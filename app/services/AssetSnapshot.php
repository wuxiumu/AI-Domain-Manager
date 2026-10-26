<?php

/** Portable durable asset data; cloud payloads, credentials and probe caches are excluded. */
class AssetSnapshot
{
    public const FORMAT = 'asset-workspace/assets-v1';
    public const VERSION = '1.1.1';
    public const MAX_BYTES = 5242880;

    public static function capture(string $dir): array
    {
        $domains = WorkspaceData::read($dir . '/domains.json');
        $health = WorkspaceData::read($dir . '/health.json');
        $clean = [];
        $fields = ['domain', 'provider', 'provider_name', 'status', 'expire_at', 'registration_date', 'registrar', 'note', 'valid', 'synced_at', 'first_seen', 'invalid_at'];
        foreach ($domains as $entry) {
            if (!is_array($entry)) { throw new RuntimeException('域名数据损坏，无法备份'); }
            $clean[] = array_intersect_key($entry, array_flip($fields));
        }
        $ips = []; $bindings = [];
        foreach ($health['manual_ips'] ?? [] as $id => $entry) {
            if (!is_array($entry)) { throw new RuntimeException('IP 数据损坏，无法备份'); }
            $ips[$id] = array_intersect_key($entry, array_flip(['id', 'ip', 'port', 'name', 'note', 'links', 'created_at', 'updated_at', 'server_provider', 'expires_at']));
        }
        foreach ($health['bindings'] ?? [] as $id => $links) {
            if (!is_array($links)) { throw new RuntimeException('关联数据损坏，无法备份'); }
            foreach ($links as $ipId => $link) {
                if (!is_array($link)) { throw new RuntimeException('关联数据损坏，无法备份'); }
                $bindings[$id][$ipId] = array_intersect_key($link, array_flip(['ip', 'port', 'confirmed_at']));
            }
        }
        return ['format' => self::FORMAT, 'version' => self::VERSION, 'created_at' => date('c'),
            'scope' => 'assets-only', 'domains' => $clean,
            'health' => ['version' => 1, 'manual_ips' => $ips, 'bindings' => $bindings]];
    }

    public static function decode(string $raw): array
    {
        if (strlen($raw) > self::MAX_BYTES) { throw new InvalidArgumentException('资产文件不能超过 5 MB'); }
        $snapshot = json_decode($raw, true);
        if (!is_array($snapshot) || ($snapshot['format'] ?? '') !== self::FORMAT || ($snapshot['scope'] ?? '') !== 'assets-only') {
            throw new InvalidArgumentException('请选择本工作台导出的 assets-v1 JSON 资产文件');
        }
        return $snapshot;
    }

    /** Strict reconstruction prevents uploaded paths, extra fields and cache data entering storage. */
    public static function validate(array $snapshot, array $accountIds): array
    {
        if (($snapshot['format'] ?? '') !== self::FORMAT || !is_array($snapshot['domains'] ?? null) || !is_array($snapshot['health'] ?? null)) {
            throw new InvalidArgumentException('资产备份结构不正确');
        }
        if (count($snapshot['domains']) > 10000) { throw new InvalidArgumentException('单次恢复最多 10,000 条域名'); }
        $domains = []; $domainIds = []; $keys = []; $converted = 0;
        foreach ($snapshot['domains'] as $entry) {
            if (!is_array($entry)) { throw new InvalidArgumentException('域名条目格式不正确'); }
            $name = DomainController::normalizeDomain($entry['domain'] ?? '');
            $originalProvider = self::text($entry['provider'] ?? 'manual', 120);
            if ($originalProvider === '') { throw new InvalidArgumentException('域名来源不能为空'); }
            $provider = $originalProvider;
            $sourceName = self::text($entry['provider_name'] ?? $provider, 200);
            if ($provider !== 'manual' && !in_array($provider, $accountIds, true)) { $provider = 'manual'; $converted++; }
            $key = $provider . '|' . $name;
            if (isset($keys[$key])) { throw new InvalidArgumentException('恢复后存在重复域名，请先整理来源：' . $name); }
            $keys[$key] = true;
            $domainIds['domain_' . substr(hash('sha256', $originalProvider . '|' . $name), 0, 24)] = 'domain_' . substr(hash('sha256', $key), 0, 24);
            $d = ['domain' => $name, 'provider' => $provider, 'provider_name' => $provider === 'manual' && $originalProvider !== 'manual' ? mb_substr('手动导入：' . $sourceName, 0, 200) : $sourceName,
                'status' => self::text($entry['status'] ?? '', 200), 'expire_at' => DomainController::validateDate($entry['expire_at'] ?? '', '到期日期'),
                'registration_date' => DomainController::validateDate($entry['registration_date'] ?? '', '注册日期'),
                'registrar' => self::text($entry['registrar'] ?? '', 500), 'note' => self::text($entry['note'] ?? '', 2000), 'raw' => [],
                'valid' => $entry['valid'] ?? true];
            if (!is_bool($d['valid'])) { throw new InvalidArgumentException('域名有效性字段必须为布尔值'); }
            foreach (['synced_at', 'first_seen', 'invalid_at'] as $field) { $d[$field] = self::text($entry[$field] ?? '', 40); }
            $domains[] = $d;
        }
        $health = $snapshot['health'];
        if (!is_array($health['manual_ips'] ?? null) || !is_array($health['bindings'] ?? null) || count($health['manual_ips']) > 10000 || count($health['bindings']) > 10000) {
            throw new InvalidArgumentException('IP 或关联数据格式 / 数量不正确');
        }
        $ips = [];
        foreach ($health['manual_ips'] as $id => $entry) {
            if (!is_array($entry) || !is_string($entry['ip'] ?? null)) { throw new InvalidArgumentException('IP 条目格式不正确'); }
            $packed = @inet_pton($entry['ip']);
            $ip = $packed === false ? '' : inet_ntop($packed);
            $port = $entry['port'] ?? 443;
            if (!$ip || !HealthService::isPublicIp($ip) || !is_int($port) || $port < 1 || $port > 65535 || $id !== 'ip_' . substr(hash('sha256', $ip . '|' . $port), 0, 24)) {
                throw new InvalidArgumentException('IP 地址、标识或历史端口无效');
            }
            $ips[$id] = ['id' => $id, 'ip' => $ip, 'port' => $port, 'name' => self::text($entry['name'] ?? $ip, 120),
                'note' => self::text($entry['note'] ?? '', 2000), 'links' => HealthService::links($entry['links'] ?? []),
                'server_provider' => self::text($entry['server_provider'] ?? '', 120),
                'expires_at' => DomainController::validateDate($entry['expires_at'] ?? '', 'IP 到期日期')];
            foreach (['created_at', 'updated_at'] as $field) { $ips[$id][$field] = self::text($entry[$field] ?? '', 40); }
        }
        $bindings = []; $bindingCount = 0; $orphaned = 0;
        foreach ($health['bindings'] as $domainId => $links) {
            if (!is_array($links) || count($links) > 100) { throw new InvalidArgumentException('单个域名关联最多 100 个 IP'); }
            // Deleted domains may leave old bindings in an existing installation.
            if (!isset($domainIds[$domainId])) { $orphaned += count($links); continue; }
            foreach ($links as $id => $link) {
                if (!isset($ips[$id]) || !is_array($link) || ($link['ip'] ?? null) !== $ips[$id]['ip'] || ($link['port'] ?? 443) !== $ips[$id]['port']) {
                    throw new InvalidArgumentException('关联包含缺失或不一致的 IP 资产');
                }
                $bindings[$domainIds[$domainId]][$id] = ['ip' => $ips[$id]['ip'], 'port' => $ips[$id]['port'], 'confirmed_at' => self::text($link['confirmed_at'] ?? '', 40)];
                $bindingCount++;
            }
        }
        return ['files' => ['domains.json' => $domains, 'health.json' => ['version' => 1, 'manual_ips' => $ips, 'bindings' => $bindings, 'observations' => []],
            'access_check.json' => [], 'record_access.json' => []], 'counts' => ['domains' => count($domains), 'ips' => count($ips), 'bindings' => $bindingCount],
            'converted_domains' => $converted, 'orphaned_bindings' => $orphaned];
    }

    private static function text($value, int $max): string
    {
        if (!is_string($value) || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('资产文本字段格式或长度不正确');
        }
        return $value;
    }

    public static function counts(array $snapshot): array
    {
        return ['domains' => count($snapshot['domains']), 'ips' => count($snapshot['health']['manual_ips']),
            'bindings' => array_sum(array_map('count', $snapshot['health']['bindings']))];
    }

    public static function csv(array $snapshot): string
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['类型', '地址', '名称/来源', '到期日期', '用途与备注', '网站链接'], ',', '"', '');
        foreach ($snapshot['domains'] as $d) {
            self::csvRow($stream, ['域名', $d['domain'], $d['provider_name'] ?? '', $d['expire_at'] ?? '', $d['note'] ?? '', '']);
        }
        foreach ($snapshot['health']['manual_ips'] as $ip) {
            self::csvRow($stream, ['IP', $ip['ip'], $ip['name'] ?? '', $ip['expires_at'] ?? '', $ip['note'] ?? '',
                implode("\n", array_map(static function ($l) { return $l['name'] . ' ' . $l['url']; }, $ip['links'] ?? []))]);
        }
        rewind($stream); $csv = stream_get_contents($stream); fclose($stream); return $csv;
    }

    private static function csvRow($stream, array $values): void
    {
        fputcsv($stream, array_map(static function ($v) {
            $v = (string) $v;
            return preg_match('/^[\s\x00-\x20]*[=+@-]|^[\t\r\n]/u', $v) ? "'" . $v : $v;
        }, $values), ',', '"', '');
    }
}
