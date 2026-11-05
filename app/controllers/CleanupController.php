<?php

/** 数据清理：孤儿账号记录与日志按维度删除，仅管理员会话（写入自动获得工作区锁与审计留痕）。 */
class CleanupController
{
    private function service(): CleanupService
    {
        Auth::requireSession();
        header('Cache-Control: no-store');
        return new CleanupService(DATA_DIR, config('accounts') ?? []);
    }

    public function overview(): void
    {
        json_out($this->service()->overview());
    }

    public function orphans(): void
    {
        $body = body_json();
        $domains = $body['domains'] ?? [];
        if (!is_array($domains)) { throw new InvalidArgumentException('domains 必须是域名数组'); }
        json_out($this->service()->cleanOrphans((string) ($body['provider'] ?? ''), (string) ($body['action'] ?? ''), $domains));
    }

    public function logs(): void
    {
        $body = body_json();
        $store = (string) ($body['store'] ?? '');
        $filter = [];
        foreach (['domain', 'provider', 'type'] as $field) {
            if (isset($body[$field]) && is_string($body[$field]) && $body[$field] !== '') { $filter[$field] = $body[$field]; }
        }
        if (!empty($body['all'])) { $filter['all'] = true; }
        if (isset($body['before']) && is_string($body['before'])) { $filter['before'] = $body['before']; }
        json_out($this->service()->cleanLogs($store, $filter));
    }
}
