<?php

/**
 * 云商 Provider 抽象接口。
 * testConnection(): 验证密钥可用性，返回 ['ok'=>bool, 'count'=>int, 'samples'=>string[], 'latency_ms'=>int]
 * listDomains():    拉取全部域名，返回归一化数组
 *   [ ['domain','status','expire_at','registration_date','registrar','raw'], ... ]
 */
abstract class CloudProvider
{
    /** 归一化域名数据（子类复用） */
    protected function normalizeCommon(array $d, string $registrar): array
    {
        return [
            'domain'            => $d['domain'] ?? '',
            'status'            => $d['status'] ?? '',
            'expire_at'         => $d['expire_at'] ?? '',
            'registration_date' => $d['registration_date'] ?? '',
            'registrar'         => $registrar,
            'raw'               => $d['raw'] ?? $d,
        ];
    }

    abstract public function testConnection(): array;

    abstract public function listDomains(): array;

    /** 解析记录统一格式：{id, name, type, value, ttl, priority, status, raw} */

    abstract public function listRecords(string $domain): array;

    /** 新增解析记录，$params: {type, name, value, ttl?, priority?}，返回新记录数组 */
    abstract public function addRecord(string $domain, array $params): array;

    /** 修改解析记录，返回是否成功 */
    abstract public function updateRecord(string $domain, string $recordId, array $params): bool;

    /** 删除解析记录，返回是否成功 */
    abstract public function deleteRecord(string $domain, string $recordId): bool;
}
