<?php

/**
 * 手工登记的公网 IP 资产（data/ip_assets.json，私有存储 0600）。
 * 与 DNS 解析自动发现的 IP 并列进入大屏 IP 视图；只做台账，不发起网络请求。
 */
class IpAssetService
{
    private PrivateJsonStore $store;

    public function __construct(?string $dir = null)
    {
        $this->store = new PrivateJsonStore(($dir ?? DATA_DIR) . '/ip_assets.json');
    }

    /** 全量列表：按 IP 稳定排序。 */
    public function listing(): array
    {
        $entries = array_values($this->store->read());
        usort($entries, static function (array $a, array $b): int {
            return [$a['ip'], $a['id']] <=> [$b['ip'], $b['id']];
        });
        return $entries;
    }

    public function create(array $body): array
    {
        $entry = $this->normalize($body);
        $entry['id'] = 'ip_' . bin2hex(random_bytes(12));
        $entry['created_at'] = date('c');
        $entry['updated_at'] = $entry['created_at'];
        $this->store->update(static function (array $data) use ($entry): array {
            $data[$entry['id']] = $entry;
            return $data;
        });
        return ['ok' => true, 'entry' => $entry];
    }

    public function update(string $id, array $body): array
    {
        $current = $this->get($id);
        $entry = $this->normalize(array_merge($current, $body));
        $entry['id'] = $id;
        $entry['created_at'] = $current['created_at'] ?? date('c');
        $entry['updated_at'] = date('c');
        $this->store->update(static function (array $data) use ($id, $entry): array {
            $data[$id] = $entry;
            return $data;
        });
        return ['ok' => true, 'entry' => $entry];
    }

    public function delete(string $id): array
    {
        $this->get($id);
        $this->store->update(static function (array $data) use ($id): array {
            unset($data[$id]);
            return $data;
        });
        return ['ok' => true];
    }

    private function get(string $id): array
    {
        $data = $this->store->read();
        if (!isset($data[$id]) || !is_array($data[$id])) { throw new OutOfBoundsException('IP 资产不存在'); }
        return $data[$id];
    }

    /** 校验并归一化：仅公网 IP、可选端口、名称与备注限长。 */
    private function normalize(array $body): array
    {
        $ip = trim((string) ($body['ip'] ?? ''));
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) { $ip = inet_ntop(inet_pton($ip)); }
        if ($ip === '' || !PublicHttpProbe::isPublicIp($ip)) { throw new InvalidArgumentException('请填写有效的公网 IP 地址'); }
        $port = $body['port'] ?? null;
        if ($port !== null && $port !== '') {
            $port = (int) $port;
            if ($port < 1 || $port > 65535) { throw new InvalidArgumentException('端口范围应为 1–65535'); }
        } else {
            $port = null;
        }
        return [
            'ip' => $ip,
            'port' => $port,
            'name' => $this->text($body['name'] ?? '', 120),
            'note' => $this->text($body['note'] ?? '', 2000),
        ];
    }

    private function text($value, int $max): string
    {
        if (!is_string($value) || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('文本格式或长度无效');
        }
        return trim($value);
    }
}
