<?php

/**
 * 本地任务队列：让全量健康检查、访问检测、域名同步这类长任务在关闭浏览器后继续。
 * Web 进程只入队与查询，不做网络操作；执行由 scripts/task-worker.php（cron 驱动）逐个认领。
 * 任务记录 data/tasks.json 原子替换 + 独立锁；心跳超过 15 分钟的执行中任务视为进程中断，
 * 在下次认领/入队时标记为失败，可重新入队，不会重复写入。
 */
class TaskQueueService
{
    public const TYPES = ['health-check', 'access-check', 'sync'];
    public const TYPE_LABELS = ['health-check' => '全量健康检查', 'access-check' => '域名访问检测', 'sync' => '域名同步'];

    private const MAX_TASKS = 100;      // 历史记录保留上限
    private const MAX_PENDING = 10;     // 未完成任务上限，防止队列堆积
    private const STALE_SECONDS = 900;  // 心跳超时判定

    private string $file;
    private $clock;

    public function __construct(?string $dataDir = null, ?callable $clock = null)
    {
        $this->file = ($dataDir ?? DATA_DIR) . '/tasks.json';
        $this->clock = $clock ?? static function () { return time(); };
    }

    /** 入队；返回任务记录。入队前先回收心跳过期的任务。 */
    public function enqueue(string $type, array $params = [], string $actor = 'admin'): array
    {
        if (!in_array($type, self::TYPES, true)) { throw new InvalidArgumentException('未知任务类型：' . $type); }
        $now = date('Y-m-d H:i:s', (int) call_user_func($this->clock));
        $task = ['id' => bin2hex(random_bytes(12)), 'type' => $type, 'params' => $params,
            'status' => 'pending', 'progress' => ['done' => 0, 'total' => 0, 'message' => ''],
            'result' => null, 'error' => null, 'actor' => $actor,
            'created_at' => $now, 'started_at' => null, 'heartbeat_at' => null, 'finished_at' => null];
        $this->update(function (array $state) use ($task) {
            $state['tasks'] = $this->reapStale($state['tasks'] ?? []);
            $pending = count(array_filter($state['tasks'], static function ($t) { return in_array($t['status'] ?? '', ['pending', 'running'], true); }));
            if ($pending >= self::MAX_PENDING) { throw new DomainException('后台排队任务过多，请等待当前任务完成'); }
            $state['tasks'][] = $task;
            if (count($state['tasks']) > self::MAX_TASKS) { $state['tasks'] = array_slice($state['tasks'], -self::MAX_TASKS); }
            return $state;
        });
        return $task;
    }

    /** 认领最旧的待执行任务（pending→running，独占）；无任务返回 null。 */
    public function claim(): ?array
    {
        $claimed = null;
        $this->update(function (array $state) use (&$claimed) {
            $state['tasks'] = $this->reapStale($state['tasks'] ?? []);
            $now = date('Y-m-d H:i:s', (int) call_user_func($this->clock));
            foreach ($state['tasks'] as $i => $task) {
                if (($task['status'] ?? '') !== 'pending') { continue; }
                $state['tasks'][$i]['status'] = 'running';
                $state['tasks'][$i]['started_at'] = $now;
                $state['tasks'][$i]['heartbeat_at'] = $now;
                $claimed = $state['tasks'][$i];
                break;
            }
            return $state;
        });
        return $claimed;
    }

    /** 执行进度心跳：worker 每批后调用，同时是中断判定依据。 */
    public function heartbeat(string $id, int $done, int $total, string $message = ''): void
    {
        $this->update(function (array $state) use ($id, $done, $total, $message) {
            foreach (($state['tasks'] ?? []) as $i => $task) {
                if (($task['id'] ?? '') !== $id || ($task['status'] ?? '') !== 'running') { continue; }
                $state['tasks'][$i]['progress'] = ['done' => $done, 'total' => $total, 'message' => $message];
                $state['tasks'][$i]['heartbeat_at'] = date('Y-m-d H:i:s', (int) call_user_func($this->clock));
                break;
            }
            return $state;
        });
    }

    public function finish(string $id, array $result): void
    {
        $this->close($id, 'done', null, $result);
    }

    public function fail(string $id, string $error): void
    {
        $this->close($id, 'failed', $error, null);
    }

    /** 最近任务（新→旧）与当前执行摘要；只读出口。 */
    public function recent(int $limit = 20): array
    {
        $state = (new PrivateJsonStore($this->file))->read();
        $tasks = is_array($state['tasks'] ?? null) ? $state['tasks'] : [];
        return array_slice(array_reverse($tasks), 0, $limit);
    }

    public function activeSummary(): ?array
    {
        foreach ($this->recent(self::MAX_TASKS) as $task) {
            if (in_array($task['status'] ?? '', ['pending', 'running'], true)) {
                return ['id' => $task['id'], 'type' => $task['type'], 'label' => self::TYPE_LABELS[$task['type']] ?? (string) $task['type'],
                    'status' => $task['status'], 'progress' => $task['progress'] ?? [], 'created_at' => $task['created_at'] ?? null];
            }
        }
        return null;
    }

    /** 执行任务本体：worker 与离线测试共用；$progress($done, $total, $message) 可选。 */
    public function execute(array $task, ?callable $progress = null): array
    {
        $notify = static function (int $done, int $total, string $message) use ($progress) { if ($progress) { $progress($done, $total, $message); } };
        switch ($task['type'] ?? '') {
            case 'health-check':
                $service = new HealthService();
                $kind = (string) ($task['params']['kind'] ?? '');
                if ($kind !== '' && !in_array($kind, ['domain', 'ip'], true)) { throw new InvalidArgumentException('kind 仅支持 domain 或 ip'); }
                $ids = array_column(array_filter($service->dashboard()['assets'] ?? [], static function ($a) use ($kind) {
                    return in_array($a['kind'] ?? '', ['domain', 'ip'], true) && ($kind === '' || ($a['kind'] ?? '') === $kind);
                }), 'id');
                $total = count($ids);
                $checked = 0; $unhealthy = 0;
                foreach (array_chunk($ids, 5) as $batch) {
                    $outcome = $service->check($batch);
                    $checked += (int) ($outcome['checked'] ?? 0);
                    foreach (($outcome['results'] ?? []) as $r) {
                        if (($r['health']['status'] ?? '') === 'unhealthy') { $unhealthy++; }
                    }
                    $notify($checked, $total, "已检查 {$checked}/{$total} 项");
                }
                return ['ok' => true, 'checked' => $checked, 'unhealthy' => $unhealthy];
            case 'access-check':
                $notify(0, 0, '正在并发探测全部有效域名');
                $summary = AccessService::runCheck();
                $notify(1, 1, '访问检测完成');
                return ['ok' => true, 'total' => $summary['total'] ?? null, 'fail' => $summary['fail'] ?? null];
            case 'sync':
                $notify(0, 0, '正在同步全部云账号域名');
                $summary = SyncService::syncAll();
                $notify(1, 1, '同步完成');
                return ['ok' => true, 'status' => $summary['status'] ?? null, 'stats' => $summary['stats'] ?? null];
        }
        throw new InvalidArgumentException('未知任务类型');
    }

    private function close(string $id, string $status, ?string $error, ?array $result): void
    {
        $this->update(function (array $state) use ($id, $status, $error, $result) {
            foreach (($state['tasks'] ?? []) as $i => $task) {
                if (($task['id'] ?? '') !== $id || ($task['status'] ?? '') !== 'running') { continue; }
                $state['tasks'][$i]['status'] = $status;
                $state['tasks'][$i]['error'] = $error;
                $state['tasks'][$i]['result'] = $result;
                $state['tasks'][$i]['finished_at'] = date('Y-m-d H:i:s', (int) call_user_func($this->clock));
                break;
            }
            return $state;
        });
    }

    /** 心跳过期的执行中任务标记为失败；任务可重新入队。 */
    private function reapStale(array $tasks): array
    {
        $now = (int) call_user_func($this->clock);
        foreach ($tasks as $i => $task) {
            if (($task['status'] ?? '') !== 'running') { continue; }
            $heartbeat = strtotime((string) ($task['heartbeat_at'] ?? ''));
            if ($heartbeat === false || $now - $heartbeat > self::STALE_SECONDS) {
                $tasks[$i]['status'] = 'failed';
                $tasks[$i]['error'] = '执行进程已中断（心跳超时），可重新入队';
                $tasks[$i]['finished_at'] = date('Y-m-d H:i:s', $now);
            }
        }
        return $tasks;
    }

    private function update(callable $change): array
    {
        return (new PrivateJsonStore($this->file))->update(function (array $state) use ($change) {
            return $change(is_array($state) ? $state : ['tasks' => []]);
        });
    }
}
