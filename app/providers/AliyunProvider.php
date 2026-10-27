<?php

/**
 * 阿里云 Provider
 * 接口：QueryDomainList（domain.aliyuncs.com，版本 2018-01-29）
 * 认证：RPC 风格 HmacSHA1 签名（GET）
 */
class AliyunProvider extends CloudProvider
{
    private array $cfg;
    private string $endpoint = 'https://domain.aliyuncs.com/';
    private string $version  = '2018-01-29';

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function testConnection(): array
    {
        $start = microtime(true);
        $json  = $this->call('QueryDomainList', ['PageNum' => 1, 'PageSize' => 5]);
        $latency = (int) round((microtime(true) - $start) * 1000);
        $domains = $this->extractDomains($json);
        $samples = array_slice(array_column($domains, 'domain'), 0, 3);

        return [
            'ok'         => true,
            'count'      => (int) ($json['TotalItemNum'] ?? count($samples)),
            'samples'    => array_values(array_filter($samples)),
            'latency_ms' => $latency,
        ];
    }

    public function listDomains(): array
    {
        $result = [];
        $seen = [];
        $page   = 1;
        $totalPages = 1;
        $totalItems = null;
        do {
            $json = $this->call('QueryDomainList', ['PageNum' => $page, 'PageSize' => 100]);
            $domains = $this->extractDomains($json);
            if ($totalItems !== null && ($totalItems !== (int) $json['TotalItemNum'] || $totalPages !== (int) $json['TotalPageNum'])) {
                throw new RuntimeException('阿里云域名列表在分页期间发生变化，请重新同步');
            }
            $totalItems = (int) $json['TotalItemNum'];
            $totalPages = (int) $json['TotalPageNum'];
            foreach ($domains as $d) {
                $key = strtolower($d['domain']);
                if (isset($seen[$key])) {
                    throw new RuntimeException('阿里云域名分页返回重复记录，请重新同步');
                }
                $seen[$key] = true;
                $result[] = $d;
            }
            $page++;
        } while ($page <= $totalPages && $totalPages > 0);

        if (count($result) !== $totalItems) {
            throw new RuntimeException('阿里云域名列表不完整：实际条数与总数不一致，请重新同步');
        }
        return $result;
    }

    private function extractDomains(array $json): array
    {
        foreach (['TotalItemNum', 'TotalPageNum'] as $field) {
            if (!array_key_exists($field, $json) ||
                !(is_int($json[$field]) || (is_string($json[$field]) && ctype_digit($json[$field]))) ||
                (int) $json[$field] < 0) {
                throw new RuntimeException('阿里云域名列表响应不完整：缺少有效的 ' . $field);
            }
        }
        $total = (int) $json['TotalItemNum'];
        $pages = (int) $json['TotalPageNum'];
        if (($total > 0 && ($pages < 1 || $pages > $total)) || ($total === 0 && $pages > 1)) {
            throw new RuntimeException('阿里云域名列表分页信息不一致');
        }
        if (!isset($json['Data']) || !is_array($json['Data'])) {
            throw new RuntimeException('阿里云域名列表响应不完整：缺少 Data');
        }
        $out  = [];
        $data = $json['Data'];
        $arr = array_key_exists('Domain', $data) ? $data['Domain'] : $data;
        if (!is_array($arr) || ($arr !== [] && array_keys($arr) !== range(0, count($arr) - 1))) {
            throw new RuntimeException('阿里云域名列表响应格式错误');
        }
        if (($total > 0 && $arr === []) || count($arr) > $total) {
            throw new RuntimeException('阿里云域名列表条数与总数不一致');
        }

        foreach ($arr as $d) {
            if (!is_array($d) || !isset($d['DomainName']) || !is_string($d['DomainName']) || trim($d['DomainName']) === '') {
                throw new RuntimeException('阿里云域名列表包含无效记录');
            }
            $out[] = $this->normalizeCommon([
                'domain'            => strtolower(trim($d['DomainName'])),
                'status'            => $this->statusText((string) ($d['DomainStatus'] ?? '')),
                'expire_at'         => $this->dateOf($d['ExpirationDate'] ?? ''),
                'registration_date' => $this->dateOf($d['RegistrationDate'] ?? ''),
                'raw'               => $d,
            ], '阿里云');
        }
        return $out;
    }

    private function statusText(string $s): string
    {
        if ($s === '1') {
            return '正常';
        }
        if ($s === '2') {
            return '审核中';
        }
        if ($s === '3') {
            return '未实名';
        }
        if ($s === '4') {
            return '过户中';
        }
        return $s === '' ? '' : "状态码{$s}";
    }

    private function dateOf(string $s): string
    {
        $t = strtotime($s);
        return $t ? date('Y-m-d', $t) : '';
    }

    private function call(string $action, array $params): array
    {
        return $this->rpc(
            'https://domain.aliyuncs.com/',
            '2018-01-29',
            $action,
            $params
        );
    }

    /** 通用 RPC 签名调用 */
    private function rpc(string $endpoint, string $version, string $action, array $params): array
    {
        $common = [
            'Format'            => 'JSON',
            'Version'           => $version,
            'AccessKeyId'       => $this->cfg['access_key_id'],
            'SignatureMethod'   => 'HMAC-SHA1',
            'SignatureVersion'  => '1.0',
            'SignatureNonce'    => uniqid('', true) . mt_rand(1000, 9999),
            'Timestamp'         => gmdate('Y-m-d\TH:i:s\Z'),
            'Action'            => $action,
        ];
        $all = array_merge($common, $params);
        ksort($all);

        $pairs = [];
        foreach ($all as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        $query = implode('&', $pairs);

        $stringToSign = 'GET&%2F&' . rawurlencode($query);
        $signature    = base64_encode(hash_hmac('sha1', $stringToSign, $this->cfg['access_key_secret'] . '&', true));

        $url = $endpoint . '?' . $query . '&Signature=' . rawurlencode($signature);
        $res = HttpClient::get($url);

        $body = (string) ($res['body'] ?? '');
        $status = (int) ($res['status'] ?? 0);
        $json = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('阿里云请求失败（HTTP ' . $status . '）');
        }
        if (!is_array($json) || substr(ltrim($body), 0, 1) !== '{') {
            throw new RuntimeException('阿里云返回无效的 JSON 对象');
        }
        // 错误响应同样带 RequestId；不能据此把错误当成成功。
        if (array_key_exists('Code', $json) &&
            (!is_scalar($json['Code']) || !in_array(strtolower((string) $json['Code']), ['success', 'ok', '200'], true))) {
            $code = is_scalar($json['Code']) ? (string) $json['Code'] : 'UnknownError';
            $message = isset($json['Message']) && is_string($json['Message']) ? $json['Message'] : '未知错误';
            throw new RuntimeException($code . ': ' . $message);
        }
        return $json;
    }

    /* ---------------- DNS 解析记录（alidns） ---------------- */

    private function dns(string $action, array $params): array
    {
        return $this->rpc('https://alidns.aliyuncs.com/', '2015-01-09', $action, $params);
    }

    public function listRecords(string $domain): array
    {
        $list = []; $seen = []; $page = 1;
        do {
            $json = $this->dns('DescribeDomainRecords', ['DomainName' => $domain, 'PageNumber' => $page, 'PageSize' => 500]);
            $batch = $json['DomainRecords']['Record'] ?? null;
            if (!is_array($batch)) throw new RuntimeException('阿里云 DNS 记录响应不完整');
            foreach ($batch as $record) {
                $id = (string) ($record['RecordId'] ?? '');
                if ($id === '' || isset($seen[$id])) throw new RuntimeException('DNS 分页记录重复或缺少标识，请重新刷新');
                $seen[$id] = true; $list[] = $record;
            }
            $total = isset($json['TotalCount']) ? (int) $json['TotalCount'] : null;
            if (!$batch && $total !== null && count($list) < $total) throw new RuntimeException('DNS 分页返回不完整');
            $more = $total !== null ? count($list) < $total : count($batch) === 500;
            if ($more && ++$page > 100) throw new RuntimeException('DNS 记录超过读取上限');
        } while ($more);
        $out = [];
        foreach ($list as $r) {
            $out[] = [
                'id'       => (string) ($r['RecordId'] ?? ''),
                'name'     => (string) ($r['RR'] ?? ''),
                'type'     => (string) ($r['Type'] ?? ''),
                'value'    => (string) ($r['Value'] ?? ''),
                'ttl'      => (int) ($r['TTL'] ?? 0),
                'priority' => (int) ($r['Priority'] ?? 0),
                'status'   => (string) ($r['Status'] ?? ''),
                'line'     => (string) ($r['Line'] ?? ''),
                'raw'      => $r,
            ];
        }
        return $out;
    }

    public function addRecord(string $domain, array $params): array
    {
        $json = $this->dns('AddDomainRecord', [
            'DomainName' => $domain,
            'RR'         => $params['name'] ?? '@',
            'Type'       => $params['type'] ?? 'A',
            'Value'      => $params['value'] ?? '',
            'TTL'        => (int) ($params['ttl'] ?? 600) ?: 600,
            'Priority'   => (int) ($params['priority'] ?? 0),
            'Line'       => $params['line'] ?? 'default',
        ]);
        return ['id' => (string) ($json['RecordId'] ?? ''), 'raw' => $json];
    }

    public function updateRecord(string $domain, string $recordId, array $params): bool
    {
        $this->dns('UpdateDomainRecord', [
            'RecordId' => $recordId,
            'RR'       => $params['name'] ?? '@',
            'Type'     => $params['type'] ?? 'A',
            'Value'    => $params['value'] ?? '',
            'TTL'      => (int) ($params['ttl'] ?? 600) ?: 600,
            'Priority' => (int) ($params['priority'] ?? 0),
            'Line'     => $params['line'] ?? 'default',
        ]);
        return true;
    }

    public function deleteRecord(string $domain, string $recordId): bool
    {
        $this->dns('DeleteDomainRecord', ['RecordId' => $recordId]);
        return true;
    }
}
