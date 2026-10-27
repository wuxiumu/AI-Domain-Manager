<?php

/**
 * 腾讯云 Provider
 * 接口：DescribeDomainList（domain.tencentcloudapi.com，版本 2018-08-08）
 * 认证：TC3-HMAC-SHA256 签名（POST，JSON）
 */
class TencentProvider extends CloudProvider
{
    private array $cfg;
    private string $host    = 'domain.tencentcloudapi.com';
    private string $version = '2018-08-08';

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function testConnection(): array
    {
        $start = microtime(true);
        $resp  = $this->call('DescribeDomainList', ['Limit' => 5, 'Offset' => 0]);
        $latency = (int) round((microtime(true) - $start) * 1000);

        $samples = [];
        foreach ($resp['DomainSet'] ?? [] as $d) {
            $samples[] = $d['DomainName'] ?? '';
        }
        $samples = array_values(array_filter(array_slice($samples, 0, 3)));

        return [
            'ok'         => true,
            'count'      => (int) ($resp['TotalCount'] ?? count($samples)),
            'samples'    => $samples,
            'latency_ms' => $latency,
        ];
    }

    public function listDomains(): array
    {
        $result = [];
        $offset = 0;
        $limit  = 100;
        $total  = 0;
        do {
            $resp = $this->call('DescribeDomainList', ['Limit' => $limit, 'Offset' => $offset]);
            $total = (int) ($resp['TotalCount'] ?? count($result));
            foreach ($resp['DomainSet'] ?? [] as $d) {
                $result[] = $this->normalize($d);
            }
            $offset += $limit;
        } while ($offset < $total);

        return $result;
    }

    private function normalize(array $d): array
    {
        $exp = '';
        if (!empty($d['ExpirationDateTime'])) {   // 字符串时间 "2026-07-09 13:11:51"
            $exp = date('Y-m-d', strtotime($d['ExpirationDateTime']) ?: time());
        } elseif (is_numeric($d['ExpirationDate'] ?? 0)) { // 秒/毫秒时间戳兜底
            $ts = (int) $d['ExpirationDate'];
            if ($ts > 10 ** 12) {
                $ts = (int) ($ts / 1000);
            }
            $exp = $ts > 0 ? date('Y-m-d', $ts) : '';
        }

        $status = $d['DomainStatus'] ?? '';
        if (is_array($status)) {
            $status = implode(', ', array_filter(array_map('strval', $status)));
        }

        return $this->normalizeCommon([
            'domain'    => $d['DomainName'] ?? '',
            'status'    => (string) $status,
            'expire_at' => $exp,
            'raw'       => $d,
        ], '腾讯云');
    }

    private function call(string $action, array $payload): array
    {
        return $this->tc3('domain', 'domain.tencentcloudapi.com', '2018-08-08', $action, $payload);
    }

    /** 通用 TC3-HMAC-SHA256 签名调用 */
    private function tc3(string $service, string $host, string $version, string $action, array $payload): array
    {
        $ts      = time();
        $date    = gmdate('Y-m-d', $ts);
        $body    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $canonicalHeaders = "content-type:application/json; charset=utf-8\n"
            . "host:{$host}\n"
            . 'x-tc-action:' . strtolower($action) . "\n";
        $signedHeaders = 'content-type;host;x-tc-action';
        $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n" . hash('sha256', $body);

        $scope     = "{$date}/{$service}/tc3_request";
        $str2sign  = "TC3-HMAC-SHA256\n{$ts}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $secretDate = hash_hmac('sha256', $date, 'TC3' . $this->cfg['secret_key'], true);
        $secretService = hash_hmac('sha256', $service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('sha256', $str2sign, $secretSigning);

        $authorization = "TC3-HMAC-SHA256 Credential={$this->cfg['secret_id']}/{$scope}, "
            . "SignedHeaders={$signedHeaders}, Signature={$signature}";

        $headers = [
            'Authorization: ' . $authorization,
            'Content-Type: application/json; charset=utf-8',
            'Host: ' . $host,
            'X-TC-Action: ' . $action,
            'X-TC-Timestamp: ' . $ts,
            'X-TC-Version: ' . $version,
            'X-TC-Language: zh-CN',
        ];

        $res  = HttpClient::post('https://' . $host . '/', $body, $headers);
        $json = json_decode($res['body'], true);
        if (!is_array($json)) {
            throw new RuntimeException('腾讯云返回非 JSON: ' . mb_substr($res['body'], 0, 200));
        }
        if (isset($json['Response']['Error'])) {
            $e = $json['Response']['Error'];
            throw new RuntimeException(($e['Code'] ?? '') . ': ' . ($e['Message'] ?? '未知错误'));
        }
        return $json['Response'] ?? [];
    }

    /* ---------------- DNS 解析记录（DNSPod） ---------------- */

    private function dns(string $action, array $payload): array
    {
        return $this->tc3('dnspod', 'dnspod.tencentcloudapi.com', '2021-03-23', $action, $payload);
    }

    public function listRecords(string $domain): array
    {
        $records = []; $seen = []; $offset = 0;
        do {
            $resp = $this->dns('DescribeRecordList', ['Domain' => $domain, 'Limit' => 300, 'Offset' => $offset]);
            $batch = $resp['RecordList'] ?? null;
            if (!is_array($batch)) throw new RuntimeException('腾讯云 DNS 记录响应不完整');
            foreach ($batch as $record) {
                $id = (string) ($record['RecordId'] ?? '');
                if ($id === '' || isset($seen[$id])) throw new RuntimeException('DNS 分页记录重复或缺少标识');
                $seen[$id] = true; $records[] = $record;
            }
            $offset += count($batch);
            $total = isset($resp['RecordCountInfo']['TotalCount']) ? (int) $resp['RecordCountInfo']['TotalCount'] : null;
            if (!$batch && $total !== null && $offset < $total) throw new RuntimeException('DNS 分页返回不完整');
            $more = $total !== null ? $offset < $total : count($batch) === 300;
            if ($more && $offset >= 30000) throw new RuntimeException('DNS 记录超过读取上限');
        } while ($more);
        $out  = [];
        foreach ($records as $r) {
            $out[] = [
                'id'       => (string) ($r['RecordId'] ?? ''),
                'name'     => (string) ($r['Name'] ?? ''),
                'type'     => (string) ($r['Type'] ?? ''),
                'value'    => (string) ($r['Value'] ?? ''),
                'ttl'      => (int) ($r['TTL'] ?? 0),
                'priority' => (int) ($r['MX'] ?? 0),
                'status'   => (string) ($r['Status'] ?? ''),
                'line'     => (string) ($r['Line'] ?? ''),
                'raw'      => $r,
            ];
        }
        return $out;
    }

    public function addRecord(string $domain, array $params): array
    {
        $resp = $this->dns('CreateRecord', [
            'Domain'     => $domain,
            'RecordType' => $params['type'] ?? 'A',
            'RecordLine' => $params['line'] ?? '默认',
            'Value'      => $params['value'] ?? '',
            'SubDomain'  => $params['name'] ?? '@',
            'TTL'        => (int) ($params['ttl'] ?? 600) ?: 600,
            'MX'         => (int) ($params['priority'] ?? 0),
        ]);
        return ['id' => (string) ($resp['RecordId'] ?? ''), 'raw' => $resp];
    }

    public function updateRecord(string $domain, string $recordId, array $params): bool
    {
        $request = [
            'Domain'     => $domain,
            'RecordId'   => (int) $recordId, // DNSPod 要求 uint64
            'RecordType' => $params['type'] ?? 'A',
            'RecordLine' => $params['line'] ?? '默认',
            'Value'      => $params['value'] ?? '',
            'SubDomain'  => $params['name'] ?? '@',
            'TTL'        => (int) ($params['ttl'] ?? 600) ?: 600,
            'MX'         => (int) ($params['priority'] ?? 0),
        ];
        if (in_array($params['status'] ?? '', ['ENABLE', 'DISABLE'], true)) { $request['Status'] = $params['status']; }
        if (isset($params['weight'])) { $request['Weight'] = (int) $params['weight']; }
        $this->dns('ModifyRecord', $request);
        return true;
    }

    public function deleteRecord(string $domain, string $recordId): bool
    {
        $this->dns('DeleteRecord', ['Domain' => $domain, 'RecordId' => (int) $recordId]);
        return true;
    }
}
