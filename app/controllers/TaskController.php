<?php

class TaskController
{
    /** 后台任务列表与当前执行摘要（仅管理员会话；执行由服务器 cron 的 worker 完成）。 */
    public function index(): void
    {
        Auth::requireSession();
        $queue = new TaskQueueService();
        json_out(['ok' => true, 'active' => $queue->activeSummary(), 'tasks' => $queue->recent()]);
    }

    /** 入队长任务：health-check / access-check / sync；入队后关闭浏览器也会继续。 */
    public function create(): void
    {
        Auth::requireSession();
        $body = body_json();
        $type = (string) ($body['type'] ?? '');
        $params = $body['params'] ?? [];
        if (!is_array($params)) {
            json_out(['ok' => false, 'error' => 'params 必须是对象'], 422);
        }
        if ($type === 'health-check' && isset($params['kind'])) {
            $kind = (string) $params['kind'];
            if ($kind !== '' && !in_array($kind, ['domain', 'ip'], true)) {
                json_out(['ok' => false, 'error' => 'kind 仅支持 domain 或 ip'], 422);
            }
            $params['kind'] = $kind;
        }
        try {
            $task = (new TaskQueueService())->enqueue($type, $params, (string) Auth::user());
        } catch (InvalidArgumentException $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (DomainException $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()], 409);
        }
        json_out(['ok' => true, 'task' => $task]);
    }
}
