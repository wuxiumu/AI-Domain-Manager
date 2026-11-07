<?php

/**
 * 改进计划：功能点条目的提出、审核与执行跟踪。
 * 状态流：proposed（待审核）→ approved（已通过）→ in_progress（执行中）→ done（已完成）；
 * rejected（已驳回）可重新提交。approved/rejected 只能经 review() 设置，执行推进走 update()。
 * 存储于 data/improvement_plan.json（PrivateJsonStore：原子替换 + 0600 + 锁内变更）。
 */
class PlanService
{
    public const STATUSES = ['proposed', 'approved', 'in_progress', 'done', 'rejected'];
    /** update() 允许直接设置的状态：审核结论必须走 review()，避免绕过审核意见。 */
    public const EXECUTION_STATUSES = ['proposed', 'in_progress', 'done'];
    public const PRIORITIES = ['high', 'medium', 'low'];
    public const STATUS_LABELS = ['proposed' => '待审核', 'approved' => '已通过', 'in_progress' => '执行中', 'done' => '已完成', 'rejected' => '已驳回'];
    public const PRIORITY_LABELS = ['high' => '高', 'medium' => '中', 'low' => '低'];
    public const SOURCES = ['agent', 'admin'];

    private const MAX_ITEMS = 200;
    private const MAX_TITLE = 120;
    private const MAX_DETAIL = 4000;
    private const MAX_NOTE = 500;

    private string $file;
    private $clock;

    public function __construct(?string $dataDir = null, ?callable $clock = null)
    {
        $this->file = ($dataDir ?? DATA_DIR) . '/improvement_plan.json';
        $this->clock = $clock ?? static function () { return time(); };
    }

    public function list(?string $status = null): array
    {
        if ($status !== null && $status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('状态筛选无效：仅支持 proposed / approved / in_progress / done / rejected');
        }
        $items = $this->read();
        // 待审核优先，其后按执行中 / 已通过 / 已完成 / 已驳回；同状态内高优先级、新条目靠前。
        $statusWeight = array_flip(['proposed', 'in_progress', 'approved', 'done', 'rejected']);
        $priorityWeight = array_flip(['high', 'medium', 'low']);
        usort($items, static function (array $a, array $b) use ($statusWeight, $priorityWeight) {
            // 状态 → 优先级 → 创建时间（新在前）；created_at 反序靠左右互换实现。
            return [$statusWeight[$a['status']] ?? 9, $priorityWeight[$a['priority']] ?? 9, (string) $b['created_at']]
                <=> [$statusWeight[$b['status']] ?? 9, $priorityWeight[$b['priority']] ?? 9, (string) $a['created_at']];
        });
        if ($status !== null && $status !== '') {
            $items = array_values(array_filter($items, static function (array $item) use ($status) { return ($item['status'] ?? '') === $status; }));
        }
        return ['ok' => true, 'items' => $items];
    }

    public function add(array $input, string $source = 'admin'): array
    {
        if (!in_array($source, self::SOURCES, true)) { $source = 'admin'; }
        $item = ['id' => 'plan_' . bin2hex(random_bytes(8)),
            'title' => self::text($input['title'] ?? '', self::MAX_TITLE, '标题', true),
            'detail' => self::text($input['detail'] ?? '', self::MAX_DETAIL, '详情'),
            'priority' => self::priority($input['priority'] ?? 'medium'),
            'source' => $source, 'status' => 'proposed',
            'review_note' => '', 'result_note' => '',
            'created_at' => $this->now(), 'updated_at' => $this->now(), 'reviewed_at' => null];
        $this->update(function (array $state) use ($item) {
            $items = $state['items'] ?? [];
            foreach ($items as $existing) {
                if (($existing['title'] ?? '') === $item['title'] && !in_array($existing['status'] ?? '', ['done', 'rejected'], true)) {
                    throw new DomainException('已有同名待处理条目：' . $item['title']);
                }
            }
            $items[] = $item;
            $state['items'] = $this->trim($items);
            return $state;
        });
        return $item;
    }

    /** 审核结论：通过 / 驳回（可附意见）；done 为终态不可再审，其余状态可改判。 */
    public function review(string $id, string $decision, array $input = []): array
    {
        if (!in_array($decision, ['approve', 'reject'], true)) { throw new InvalidArgumentException('审核操作无效'); }
        $note = self::text($input['note'] ?? '', self::MAX_NOTE, '审核意见');
        if ($decision === 'reject' && $note === '') { throw new InvalidArgumentException('驳回时请填写原因，便于修订后重新提交'); }
        $updated = null;
        $this->update(function (array $state) use ($id, $decision, $note, &$updated) {
            foreach (($state['items'] ?? []) as $i => $item) {
                if (($item['id'] ?? '') !== $id) { continue; }
                if (($item['status'] ?? '') === 'done') {
                    throw new DomainException('该条目已完成，不能再审核');
                }
                $state['items'][$i]['status'] = $decision === 'approve' ? 'approved' : 'rejected';
                $state['items'][$i]['review_note'] = $note;
                $state['items'][$i]['reviewed_at'] = $this->now();
                $state['items'][$i]['updated_at'] = $this->now();
                $updated = $state['items'][$i];
                return $state;
            }
            throw new OutOfBoundsException('计划条目不存在，请刷新后重试');
        });
        return $updated;
    }

    /** 执行状态允许的迁移：待审核必须经审核接口，done 为终态，执行中可退回修订。 */
    private const STATUS_TRANSITIONS = [
        'proposed' => [],
        'rejected' => ['proposed'],
        'approved' => ['in_progress', 'done'],
        'in_progress' => ['done', 'proposed'],
        'done' => [],
    ];

    /** 编辑条目或推进执行状态；审核结论（approved/rejected）必须走 review()。 */
    public function updateItem(string $id, array $input): array
    {
        $edit = [];
        if (array_key_exists('title', $input)) { $edit['title'] = self::text($input['title'], self::MAX_TITLE, '标题', true); }
        if (array_key_exists('detail', $input)) { $edit['detail'] = self::text($input['detail'], self::MAX_DETAIL, '详情'); }
        if (array_key_exists('priority', $input)) { $edit['priority'] = self::priority($input['priority']); }
        if (array_key_exists('result_note', $input)) { $edit['result_note'] = self::text($input['result_note'], self::MAX_NOTE, '执行结果'); }
        if (isset($input['status'])) {
            if (!in_array($input['status'], self::EXECUTION_STATUSES, true)) {
                throw new InvalidArgumentException('该状态需经审核设置，执行状态仅支持 proposed / in_progress / done');
            }
            $edit['status'] = $input['status'];
        }
        if (!$edit) { throw new InvalidArgumentException('没有需要更新的字段'); }
        $updated = null;
        $this->update(function (array $state) use ($id, $edit, &$updated) {
            foreach (($state['items'] ?? []) as $i => $item) {
                if (($item['id'] ?? '') !== $id) { continue; }
                if (isset($edit['status']) && $edit['status'] !== ($item['status'] ?? '')) {
                    $allowed = self::STATUS_TRANSITIONS[$item['status'] ?? ''] ?? [];
                    if (!in_array($edit['status'], $allowed, true)) {
                        throw new DomainException('计划状态不能从「' . (self::STATUS_LABELS[$item['status']] ?? $item['status'])
                            . '」直接变更为「' . (self::STATUS_LABELS[$edit['status']] ?? $edit['status']) . '」');
                    }
                }
                foreach ($edit as $key => $value) { $state['items'][$i][$key] = $value; }
                $state['items'][$i]['updated_at'] = $this->now();
                $updated = $state['items'][$i];
                return $state;
            }
            throw new OutOfBoundsException('计划条目不存在，请刷新后重试');
        });
        return $updated;
    }

    public function remove(string $id): array
    {
        $this->update(function (array $state) use ($id) {
            $kept = [];
            $found = false;
            foreach (($state['items'] ?? []) as $item) {
                if (($item['id'] ?? '') === $id) { $found = true; continue; }
                $kept[] = $item;
            }
            if (!$found) { throw new OutOfBoundsException('计划条目不存在，请刷新后重试'); }
            $state['items'] = $kept;
            return $state;
        });
        return ['ok' => true];
    }

    /** 条目数量护栏：超限时优先裁掉最早的已完成 / 已驳回条目，不丢弃待处理条目。 */
    private function trim(array $items): array
    {
        while (count($items) > self::MAX_ITEMS) {
            $index = null;
            foreach ($items as $i => $item) {
                if (in_array($item['status'] ?? '', ['done', 'rejected'], true)) { $index = $i; break; }
            }
            if ($index === null) { break; }
            array_splice($items, $index, 1);
        }
        return $items;
    }

    private function read(): array
    {
        $state = (new PrivateJsonStore($this->file))->read();
        $items = is_array($state['items'] ?? null) ? $state['items'] : [];
        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || !in_array($item['status'] ?? '', self::STATUSES, true)) { continue; }
            $clean[] = $item;
        }
        return $clean;
    }

    private function update(callable $change): void
    {
        (new PrivateJsonStore($this->file))->update(function (array $state) use ($change) {
            return $change(is_array($state) ? $state : ['items' => []]);
        });
    }

    private function priority($value): string
    {
        if (!in_array($value, self::PRIORITIES, true)) { throw new InvalidArgumentException('优先级仅支持 high / medium / low'); }
        return $value;
    }

    private static function text($value, int $max, string $label, bool $required = false): string
    {
        if (!is_string($value) || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException($label . '需为不超过 ' . $max . ' 个字符的文本');
        }
        $value = trim($value);
        if ($required && $value === '') { throw new InvalidArgumentException('请填写' . $label); }
        return $value;
    }

    private function now(): string { return date('Y-m-d H:i:s', (int) call_user_func($this->clock)); }
}
