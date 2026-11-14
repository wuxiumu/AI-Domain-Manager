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

    /**
     * 暂停 / 恢复单条解析记录（记录保留，仅停止解析）。
     * 并非所有云商支持：基类默认抛出异常，支持的子类覆盖。
     */
    public function setRecordStatus(string $domain, string $recordId, bool $paused): bool
    {
        throw new RuntimeException('该云商不支持暂停 / 恢复解析记录，请在云商控制台操作');
    }
}
