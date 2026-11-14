<?php

/**
 * 一次性迁移：health.json 的 manual_ips → ip_assets.json（手工 IP 新存储）。
 * 幂等：已存在于目标存储的条目按 id 跳过；非公网 IP 跳过并提示。仅 CLI。
 *
 * 用法：php scripts/migrate-ip-assets.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../app/bootstrap.php';

$canonicalIp = static function (string $ip): string {
    $ip = trim($ip);
    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) { $ip = inet_ntop(inet_pton($ip)); }
    if (!PublicHttpProbe::isPublicIp($ip)) { throw new InvalidArgumentException('非公网或保留 IP：' . $ip); }
    return $ip;
};

$source = DATA_DIR . '/health.json';
$health = is_file($source) ? (json_decode((string) file_get_contents($source), true) ?: []) : [];
$manual = $health['manual_ips'] ?? [];
if (!is_array($manual) || !$manual) {
    echo "health.json 无 manual_ips 或为空，无需迁移。\n";
    exit(0);
}

$target = new IpAssetService(DATA_DIR);
$existing = [];
foreach ($target->listing() as $entry) { $existing[$entry['id']] = true; }

$store = new PrivateJsonStore(DATA_DIR . '/ip_assets.json');
$migrated = 0; $skipped = 0; $invalid = 0;

foreach ($manual as $id => $entry) {
    if (!is_array($entry)) { continue; }
    $id = (string) $id;
    if ($id === '' || isset($existing[$id])) { $skipped++; continue; }
    try {
        $port = (int) ($entry['port'] ?? 0);
        $row = [
            'id' => $id,
            'ip' => $canonicalIp((string) ($entry['ip'] ?? '')),
            'port' => ($port >= 1 && $port <= 65535) ? $port : null,
            'name' => mb_substr(trim((string) ($entry['name'] ?? '')), 0, 120),
            'note' => mb_substr(trim((string) ($entry['note'] ?? '')), 0, 2000),
            'created_at' => !empty($entry['updated_at']) ? (string) $entry['updated_at'] : date('c'),
            'updated_at' => !empty($entry['updated_at']) ? (string) $entry['updated_at'] : date('c'),
        ];
        $store->update(static function (array $data) use ($row): array {
            $data[$row['id']] = $row;
            return $data;
        });
        $migrated++;
        echo "已迁移 {$row['id']} => {$row['ip']}\n";
    } catch (Throwable $failure) {
        $invalid++;
        echo "跳过 {$id}：" . $failure->getMessage() . "\n";
    }
}

echo "完成：迁移 {$migrated} 条，跳过已存在 {$skipped} 条，无效 {$invalid} 条。\n";
