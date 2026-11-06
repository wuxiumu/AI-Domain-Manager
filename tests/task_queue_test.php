<?php
/** 后台任务队列离线回归：队列机制（入队/认领/心跳/收尾/中断回收/护栏），不触发网络。Run: php tests/task_queue_test.php */
require_once __DIR__ . '/../app/core/PrivateJsonStore.php';
require_once __DIR__ . '/../app/services/TaskQueueService.php';

$root = sys_get_temp_dir() . '/task-queue-test-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$n = 0;
function check(bool $pass, string $message): void
{
    global $n;
    $n++;
    if (!$pass) { throw new RuntimeException('FAIL: ' . $message); }
}
function throwsClass(callable $work, string $class, string $message): void
{
    try { $work(); } catch (Throwable $e) { check($e instanceof $class, $message . '（实际 ' . get_class($e) . '）'); return; }
    check(false, $message);
}

$t = 1700000000; // 固定时钟起点
$clock = static function () use (&$t) { return $t; };
try {
    $queue = new TaskQueueService($root, $clock);

    // 入队校验
    throwsClass(static function () use ($queue) { $queue->enqueue('unknown-type'); }, InvalidArgumentException::class, '未知任务类型被拒绝');

    // 入队 → 认领独占 → 心跳 → 完成
    $task = $queue->enqueue('health-check', ['kind' => 'domain'], 'fixture-admin');
    check(preg_match('/^[a-f0-9]{24}$/', $task['id']) === 1, '任务 ID 为 24 位十六进制');
    check($task['status'] === 'pending' && $task['actor'] === 'fixture-admin', '入队返回 pending 任务与操作者');
    $claimed = $queue->claim();
    check($claimed && $claimed['id'] === $task['id'] && $claimed['status'] === 'running', '认领后任务转为 running');
    check($queue->claim() === null, '无待执行任务时认领返回 null（running 不重复认领）');
    $t += 30;
    $queue->heartbeat($task['id'], 5, 20, '已检查 5/20 项');
    $listed = $queue->recent();
    check($listed[0]['progress'] === ['done' => 5, 'total' => 20, 'message' => '已检查 5/20 项'], '心跳写入进度');
    $active = $queue->activeSummary();
    check($active && $active['id'] === $task['id'] && $active['label'] === '全量健康检查', 'activeSummary 输出执行摘要与标签');
    $t += 10;
    $queue->finish($task['id'], ['ok' => true, 'checked' => 20]);
    $done = $queue->recent()[0];
    check($done['status'] === 'done' && $done['result']['checked'] === 20 && $done['finished_at'] !== null, '完成后记录结果与时间');
    check($queue->activeSummary() === null, '完成后无活动任务');

    // 失败路径
    $t += 10;
    $failed = $queue->enqueue('sync');
    $queue->claim();
    $queue->fail($failed['id'], '云端不可达');
    $row = $queue->recent()[0];
    check($row['status'] === 'failed' && $row['error'] === '云端不可达', '失败任务记录错误原因');

    // 心跳超时回收：执行中任务失联后被标记失败，队列不被永久卡死
    $t += 2000; // 超过 900 秒阈值
    $stale = $queue->enqueue('access-check');
    $reaped = $queue->claim();
    check($reaped && $reaped['id'] === $stale['id'], '超时后新任务可正常认领');
    $all = $queue->recent();
    $staleRow = null;
    foreach ($all as $row) { if ($row['id'] === $task['id']) { $staleRow = $row; } }
    // 上一个 running（sync 失败那条已结束）；再造一个 running 失联场景
    $t += 10;
    $orphan = $queue->enqueue('sync');
    $queue->claim();
    $t += 1800;
    $next = $queue->enqueue('health-check');
    $claimedNext = $queue->claim();
    check($claimedNext && $claimedNext['id'] === $next['id'], '回收失联任务后队列继续推进');
    $orphanRow = null;
    foreach ($queue->recent() as $row) { if ($row['id'] === $orphan['id']) { $orphanRow = $row; } }
    check($orphanRow['status'] === 'failed' && strpos((string) $orphanRow['error'], '中断') !== false, '失联任务被标记为中断可重入队');

    // 未完成任务上限护栏
    $guard = new TaskQueueService($root, $clock);
    $thrown = false;
    try {
        for ($i = 0; $i < 12; $i++) { $guard->enqueue('sync'); }
    } catch (DomainException $e) { $thrown = true; }
    check($thrown, '未完成任务达到上限后拒绝继续入队');

    // 历史记录封顶（队列此时已满，不再入队，仅验证历史上限）
    check(count($guard->recent(500)) <= 100, '任务历史不超过 100 条');

    echo "后台任务队列：$n assertions passed（入队/认领/心跳/收尾/中断回收/护栏）\n";
} finally {
    foreach (glob($root . '/*') ?: [] as $file) { @unlink($file); }
    @rmdir($root);
}
