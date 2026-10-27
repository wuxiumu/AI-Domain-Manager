<?php

/**
 * GoDaddy Provider
 * 接口：GET /v1/domains（api.godaddy.com）
 * 认证：Authorization: sso-key {API_KEY}:{API_SECRET}
 */
class GoDaddyProvider extends CloudProvider
{
    private array $cfg;
    private string $base = 'https://api.godaddy.com';

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function testConnection(): array
    {
        $start = microtime(true);
        $list  = $this->get('/v1/domains?limit=5&statuses=ACTIVE');
        $latency = (int) round((microtime(true) - $start) * 1000);

        $samples = array_values(array_filter(array_slice(array_map(fn ($d) => $d['domain'] ?? '', $list), 0, 3)));

        return [
            'ok'         => true,
            'count'      => count($list),
            'samples'    => $samples,
            'latency_ms' => $latency,
        ];
    }

    public function listDomains(): array
    {
        $result = [];
        $offset = 0;
        $limit  = 500;
        do {
            $list = $this->get("/v1/domains?limit={$limit}&offset={$offset}&statuses=ACTIVE");
            foreach ($list as $d) {
                $result[] = $this->normalize($d);
            }
            if (count($list) < $limit) {
                break;
            }
            $offset += $limit;
        } while (true);

        return $result;
    }

    private function normalize(array $d): array
    {
        $ts = strtotime($d['expires'] ?? '');
        return $this->normalizeCommon([
            'domain'    => $d['domain'] ?? '',
            'status'    => (string) ($d['status'] ?? ''),
            'expire_at' => $ts ? date('Y-m-d', $ts) : '',
            'raw'       => $d,
        ], $d['registrar'] ?? 'GoDaddy');
    }

    private function get(string $path): array
    {
        $headers = [
            'Authorization: sso-key ' . $this->cfg['api_key'] . ':' . $this->cfg['api_secret'],
            'Accept: application/json',
        ];
        $res = HttpClient::get($this->base . $path, $headers);

        $json = json_decode($res['body'], true);
        if ($res['status'] >= 400 || (is_array($json) && isset($json['code'], $json['message']))) {
            throw new RuntimeException(($json['code'] ?? ('HTTP ' . $res['status'])) . ': ' . ($json['message'] ?? '未知错误'));
        }
        if (!is_array($json)) {
            throw new RuntimeException('GoDaddy 返回非 JSON: ' . mb_substr($res['body'], 0, 200));
        }
        return $json;
    }

    private function request(string $method, string $path, ?array $body): array
    {
        $headers = [
            'Authorization: sso-key ' . $this->cfg['api_key'] . ':' . $this->cfg['api_secret'],
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        $res = $method === 'PUT' ? HttpClient::put($this->base . $path, json_encode($body), $headers) : ($method === 'DELETE'
            ? HttpClient::delete($this->base . $path, $headers)
            : ($method === 'PATCH'
                ? HttpClient::patch($this->base . $path, json_encode($body), $headers)
                : HttpClient::post($this->base . $path, json_encode($body), $headers)));

        $json = $res['body'] === '' ? [] : json_decode($res['body'], true);
        if ($res['status'] >= 400 || (is_array($json) && isset($json['code'], $json['message']))) {
            throw new RuntimeException(($json['code'] ?? ('HTTP ' . $res['status'])) . ': ' . ($json['message'] ?? '未知错误'));
        }
        return is_array($json) ? $json : [];
    }

    /* ---------------- DNS 解析记录 ---------------- */

    public function listRecords(string $domain): array
    {
        $list = $this->get('/v1/domains/' . rawurlencode($domain) . '/records');
        $out  = [];
        foreach ($list as $r) {
            $out[] = [
                // GoDaddy 无记录 ID，用 type|name|data 复合键
                'id'       => ($r['type'] ?? '') . '|' . ($r['name'] ?? '') . '|' . ($r['data'] ?? ''),
                'name'     => (string) ($r['name'] ?? ''),
                'type'     => (string) ($r['type'] ?? ''),
                'value'    => (string) ($r['data'] ?? ''),
                'ttl'      => (int) ($r['ttl'] ?? 0),
                'priority' => (int) ($r['priority'] ?? 0),
                'status'   => '',
                'line'     => '',
                'raw'      => $r,
            ];
        }
        return $out;
    }

    public function addRecord(string $domain, array $params): array
    {
        $this->request('PATCH', '/v1/domains/' . rawurlencode($domain) . '/records', [[
            'type'     => $params['type'] ?? 'A',
            'name'     => $params['name'] ?? '@',
            'data'     => $params['value'] ?? '',
            'ttl'      => (int) ($params['ttl'] ?? 600) ?: 600,
            'priority' => (int) ($params['priority'] ?? 0),
        ]]);
        return ['id' => ($params['type'] ?? 'A') . '|' . ($params['name'] ?? '@') . '|' . ($params['value'] ?? ''), 'raw' => []];
    }

    public function updateRecord(string $domain, string $recordId, array $params): bool
    {
        [$type, $name, $old] = array_pad(explode('|', $recordId, 3), 3, '');
        if (($params['type'] ?? $type) !== $type || ($params['name'] ?? $name) !== $name) {
            throw new RuntimeException('GoDaddy 记录类型和主机名变更请通过新增记录完成');
        }
        $path = '/v1/domains/' . rawurlencode($domain) . '/records/' . rawurlencode($type) . '/' . rawurlencode($name);
        $records = $this->get($path); $matched = 0;
        foreach ($records as &$record) {
            if (($record['data'] ?? '') === $old) {
                $record['data'] = $params['value'];
                $record['ttl'] = (int) ($params['ttl'] ?? 600);
                $record['priority'] = (int) ($params['priority'] ?? 0);
                $matched++;
            }
            unset($record['type'], $record['name']);
        }
        unset($record);
        if ($matched !== 1) throw new RuntimeException('原解析记录已改变，请刷新 DNS 后重试');
        $this->request('PUT', $path, $records);
        return true;
    }

    public function deleteRecord(string $domain, string $recordId): bool
    {
        [$type, $name, $value] = array_pad(explode('|', $recordId, 3), 3, '');
        if ($type === '' || $name === '' || $value === '') {
            throw new RuntimeException('无效的记录标识');
        }
        $path = '/v1/domains/' . rawurlencode($domain) . '/records/' . rawurlencode($type) . '/' . rawurlencode($name);
        $records = $this->get($path); $remaining = []; $matched = 0;
        foreach ($records as $record) {
            if (($record['data'] ?? '') === $value) { $matched++; continue; }
            unset($record['type'], $record['name']);
            $remaining[] = $record;
        }
        if ($matched !== 1) { throw new RuntimeException('原解析记录已改变或无法唯一识别，请刷新 DNS 后重试'); }
        // The DELETE endpoint removes the entire RRset. Replace it when siblings remain.
        if ($remaining) { $this->request('PUT', $path, $remaining); }
        else { $this->request('DELETE', $path, null); }
        return true;
    }
}
