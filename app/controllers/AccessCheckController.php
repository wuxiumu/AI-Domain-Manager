<?php

class AccessCheckController
{
    /** 执行全量访问检测 */
    public function check(): void
    {
        Auth::requireLogin();
        try {
            json_out(AccessService::runCheck());
        } catch (Throwable $e) {
            json_out(['error' => '检测失败: ' . $e->getMessage()], 500);
        }
    }

    /** 上次检测结果（未检测过返回 ok:false） */
    public function index(): void
    {
        Auth::requireLogin();
        $last = AccessService::lastResult();
        if (!$last) {
            json_out(['ok' => false, 'checked_at' => null, 'message' => '尚未执行过访问检测']);
            return;
        }
        json_out(['ok' => true] + $last);
    }
}
