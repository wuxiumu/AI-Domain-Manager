<?php

/**
 * IP 资产端点：大屏聚合快照（会话 / assets:read 密钥）与手工登记 CRUD（assets:write）、
 * 归属地显式刷新（checks:run）。快照来自 IpScreenService；刷新走 IpLocationService 显式触发。
 */
class IpController
{
    public function screen(): void
    {
        Auth::requireLogin();
        // 台账、解析快照、手工 IP 或 5 分钟时间桶未变化时直接 304。
        // ?refresh=1 手动刷新：跳过快照 TTL 立即重建。
        conditional_get(asset_etag((string) ($_SERVER['QUERY_STRING'] ?? '')));
        $force = ($_GET['refresh'] ?? '') === '1';
        json_out(['ok' => true] + IpScreenService::snapshot(DATA_DIR, config('accounts'), $force));
    }

    public function markdown(): void
    {
        Auth::requireLogin();
        conditional_get(asset_etag((string) ($_SERVER['QUERY_STRING'] ?? '')));
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: inline; filename="ip-screen.md"');
        header('Cache-Control: no-store');
        echo IpScreenService::markdown(DATA_DIR, config('accounts'));
    }

    public function create(): void
    {
        Auth::requireLogin();
        json_out((new IpAssetService(DATA_DIR))->create(body_json()), 201);
    }

    public function update(string $id): void
    {
        Auth::requireLogin();
        json_out((new IpAssetService(DATA_DIR))->update($id, body_json()));
    }

    public function delete(string $id): void
    {
        Auth::requireLogin();
        json_out((new IpAssetService(DATA_DIR))->delete($id));
    }

    public function refreshLocation(string $ip): void
    {
        Auth::requireLogin();
        json_out((new IpLocationService(DATA_DIR))->refresh($ip));
    }

    /** 服务端并发探测 IP 访问状态（body: {ips: [...]}），结果全局缓存 */
    public function check(): void
    {
        Auth::requireLogin();
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); } // 探测期间不占用会话锁
        header('Cache-Control: no-store');
        @set_time_limit(300);
        $body = body_json();
        $ips = $body['ips'] ?? null;
        if (!is_array($ips) || !$ips) { json_out(['ok' => false, 'error' => 'ips 必须为非空数组'], 422); }
        json_out(['ok' => true] + IpScreenService::probeIps(DATA_DIR, $ips));
    }

    /** 大屏前端检查结果上报（body: {results: {ip: {ok, ms?}}}），全局缓存 */
    public function browserCheck(): void
    {
        Auth::requireLogin();
        header('Cache-Control: no-store');
        $body = body_json();
        $results = $body['results'] ?? null;
        if (!is_array($results)) { json_out(['ok' => false, 'error' => 'results 必须为对象'], 422); }
        foreach ($results as $ip => $item) {
            if (isset($item['ms']) && !is_int($item['ms'])) { json_out(['ok' => false, 'error' => 'ms 必须为整数'], 422); }
        }
        IpScreenService::recordIpAccess(DATA_DIR, $results);
        json_out(['ok' => true, 'checked_at' => date('Y-m-d H:i:s'), 'count' => count($results)]);
    }
}
