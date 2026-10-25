<?php

class SyncController
{
    /** 同步全部账号 */
    public function sync(): void
    {
        Auth::requireLogin();
        try {
            json_out(SyncService::syncAll());
        } catch (Throwable $e) {
            json_out(['error' => '同步失败: ' . $e->getMessage()], 500);
        }
    }

    /** 同步日志 */
    public function logs(): void
    {
        Auth::requireLogin();
        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
        json_out(Logger::recent($limit));
    }
}
