<?php

/** Daily notes, tasks and completed global-check summaries. */
class CalendarService
{
    private PrivateJsonStore $store;
    private string $dir;
    public function __construct(?string $dir = null) { $this->dir = $dir ?? DATA_DIR; $this->store = new PrivateJsonStore($this->dir . '/calendar.json'); }
    private static function dateValue($date): string
    {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) { throw new InvalidArgumentException('日期格式应为 YYYY-MM-DD'); }
        $d = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) { throw new InvalidArgumentException('日期无效'); }
        return $date;
    }
    private static function value($value, int $max, string $label, bool $required = false): string
    {
        if (!is_string($value) || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) { throw new InvalidArgumentException($label . '格式或长度无效'); }
        $value = trim($value);
        if ($required && $value === '') { throw new InvalidArgumentException('请填写' . $label); }
        return $value;
    }
    public function listing(string $month): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) { throw new InvalidArgumentException('月份格式应为 YYYY-MM'); }
        $data = $this->store->read();
        $health = WorkspaceData::locked($this->dir, function () { return WorkspaceData::read($this->dir . '/health.json'); });
        return ['ok' => true, 'month' => $month,
            'tasks' => array_values(array_filter($data['tasks'] ?? [], static function ($task) use ($month) { return substr($task['date'], 0, 7) === $month; })),
            'checkins' => array_values(array_filter($data['checkins'] ?? [], static function ($item) use ($month) { return substr($item['date'], 0, 7) === $month; })),
            'runs' => array_values(array_filter($data['runs'] ?? [], static function ($run) use ($month) { return substr($run['date'], 0, 7) === $month; })),
            'events' => array_values(array_filter($health['check_events'] ?? [], static function ($event) use ($month) { return substr($event['checked_at'] ?? '', 0, 7) === $month; }))];
    }
    public function saveCheckin(string $date, array $body): array
    {
        $date = self::dateValue($date);
        $mood = $body['mood'] ?? null;
        if (!is_string($mood) || !in_array($mood, ['great', 'good', 'calm', 'tired', 'low'], true)) { throw new InvalidArgumentException('请选择心情'); }
        $text = self::value($body['text'] ?? '', 280, '一句话', true);
        $item = ['date' => $date, 'mood' => $mood, 'text' => $text, 'updated_at' => date('c')];
        $this->store->update(static function ($data) use ($date, $item) {
            $data['checkins'] = $data['checkins'] ?? [];
            $data['checkins'][$date] = $item;
            return $data;
        });
        return ['ok' => true, 'checkin' => $item];
    }
    public function deleteCheckin(string $date): array
    {
        $date = self::dateValue($date);
        $this->store->update(static function ($data) use ($date) {
            if (!isset($data['checkins'][$date])) { throw new OutOfBoundsException('打卡记录不存在'); }
            unset($data['checkins'][$date]); return $data;
        });
        return ['ok' => true];
    }
    public function saveTask(array $body, ?string $id = null): array
    {
        $date = self::dateValue($body['date'] ?? null);
        $title = self::value($body['title'] ?? '', 120, '待办标题', true);
        $description = self::value($body['description'] ?? '', 2000, '描述');
        $done = $body['done'] ?? false;
        if (!is_bool($done)) { throw new InvalidArgumentException('完成状态必须为布尔值'); }
        $task = ['id' => $id ?? bin2hex(random_bytes(12)), 'date' => $date, 'title' => $title, 'description' => $description, 'done' => $done, 'updated_at' => date('c')];
        $this->store->update(static function ($data) use ($task, $id) {
            $data['tasks'] = $data['tasks'] ?? [];
            if ($id !== null && !isset($data['tasks'][$id])) { throw new OutOfBoundsException('待办不存在'); }
            if ($id === null && count($data['tasks']) >= 10000) { throw new DomainException('待办数量已达上限'); }
            $data['tasks'][$task['id']] = $task; return $data;
        });
        return ['ok' => true, 'task' => $task];
    }
    public function deleteTask(string $id): array
    {
        $this->store->update(static function ($data) use ($id) {
            if (!isset($data['tasks'][$id])) { throw new OutOfBoundsException('待办不存在'); }
            unset($data['tasks'][$id]); return $data;
        });
        return ['ok' => true];
    }
    public function addRun(array $body): array
    {
        $counts = $body['counts'] ?? null;
        if (!is_array($counts)) { throw new InvalidArgumentException('检查统计格式无效'); }
        $clean = [];
        foreach (['domain', 'ip', 'subdomain'] as $kind) {
            $part = $counts[$kind] ?? null;
            if (!is_array($part) || !isset($part['healthy'], $part['unhealthy']) || !is_int($part['healthy']) || !is_int($part['unhealthy']) || $part['healthy'] < 0 || $part['unhealthy'] < 0 || $part['healthy'] + $part['unhealthy'] > 100000) { throw new InvalidArgumentException('检查统计格式无效'); }
            $clean[$kind] = ['healthy' => $part['healthy'], 'unhealthy' => $part['unhealthy']];
        }
        $run = ['id' => bin2hex(random_bytes(12)), 'date' => date('Y-m-d'), 'completed_at' => date('c'), 'counts' => $clean];
        $this->store->update(static function ($data) use ($run) { $data['runs'] = $data['runs'] ?? []; array_unshift($data['runs'], $run); $data['runs'] = array_slice($data['runs'], 0, 5000); return $data; });
        return ['ok' => true, 'run' => $run];
    }
}
