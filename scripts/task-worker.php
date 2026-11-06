<?php
/**
 * 后台任务执行器：由 cron 驱动，每次认领并执行一个任务后退出。
 * crontab 示例（每分钟）：* * * * * php /path/to/www-ip-work/scripts/task-worker.php >> /path/to/data/task-worker.log 2>&1
 * 开发调试可加 --loop 连续执行直到队列为空。
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../app/bootstrap.php';
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

$queue = new TaskQueueService();
do {
    $task = $queue->claim();
    if (!$task) { exit(0); }
    $label = TaskQueueService::TYPE_LABELS[$task['type']] ?? (string) $task['type'];
    echo '[' . date('Y-m-d H:i:s') . "] 开始后台任务 {$task['id']}（{$label}）\n";
    try {
        $result = $queue->execute($task, static function (int $done, int $total, string $message) use ($queue, $task) {
            $queue->heartbeat($task['id'], $done, $total, $message);
        });
        $queue->finish($task['id'], $result);
        echo '[' . date('Y-m-d H:i:s') . '] ' . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        $queue->fail($task['id'], $e->getMessage());
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] 任务失败：' . $e->getMessage() . "\n");
        exit(1);
    }
} while (in_array('--loop', $argv ?? [], true));
