<?php
/** Offline regressions for the improvement plan workflow. Run: php tests/plans_test.php */
require_once __DIR__ . '/../app/core/PrivateJsonStore.php';
require_once __DIR__ . '/../app/services/PlanService.php';

$assertions = 0;
function expect($condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}
function throwsClass(callable $work, string $class, string $message): void
{
    try { $work(); } catch (Throwable $e) { expect($e instanceof $class, $message . ' (' . get_class($e) . ')'); return; }
    expect(false, $message);
}

$root = sys_get_temp_dir() . '/workbench-plans-test-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
try {
    $now = strtotime('2026-11-07 10:00:00');
    $tick = 0;
    $clock = static function () use (&$now, &$tick) { return $now + ($tick++); };
    $service = new PlanService($root, $clock);

    // 新增与校验
    $item = $service->add(['title' => '  分页渲染  ', 'detail' => "现状：…\n方案：…", 'priority' => 'high'], 'agent');
    expect(str_starts_with($item['id'], 'plan_') && $item['title'] === '分页渲染', 'add trims title and assigns plan id');
    expect($item['status'] === 'proposed' && $item['source'] === 'agent' && $item['reviewed_at'] === null, 'new items start as proposed');
    throwsClass(static fn () => $service->add(['title' => '']), InvalidArgumentException::class, 'title is required');
    throwsClass(static fn () => $service->add(['title' => str_repeat('字', 121)]), InvalidArgumentException::class, 'title length is capped');
    throwsClass(static fn () => $service->add(['title' => 'x', 'priority' => 'urgent']), InvalidArgumentException::class, 'priority whitelist');
    throwsClass(static fn () => $service->add(['title' => "bad\ncontrol\x00"]), InvalidArgumentException::class, 'control characters rejected');

    // 同名待处理条目去重；done/rejected 不拦
    throwsClass(static fn () => $service->add(['title' => '分页渲染']), DomainException::class, 'duplicate pending titles rejected');
    $service->review($item['id'], 'reject', ['note' => '范围太大，请拆分']);
    $dup = $service->add(['title' => '分页渲染', 'detail' => '拆分后重新提交', 'priority' => 'medium'], 'agent');
    expect($dup['id'] !== $item['id'], 'rejected titles can be resubmitted');

    // 审核流：驳回 → 编辑后直接再审通过；驳回必须附原因；已通过可改判驳回；done 为终态
    throwsClass(static fn () => $service->review($item['id'], 'reject', []), InvalidArgumentException::class, 'reject requires a note');
    throwsClass(static fn () => $service->review($dup['id'], 'maybe', []), InvalidArgumentException::class, 'unknown decision rejected');
    $reapproved = $service->review($item['id'], 'approve', ['note' => '修订后通过']);
    expect($reapproved['status'] === 'approved' && $reapproved['reviewed_at'] !== null, 'rejected items can be re-reviewed to approved');
    $revoked = $service->review($item['id'], 'reject', ['note' => '依赖检查目标变更，暂缓']);
    expect($revoked['status'] === 'rejected' && $revoked['review_note'] === '依赖检查目标变更，暂缓', 'approved items can be revoked via review');
    $resubmitted = $service->updateItem($item['id'], ['status' => 'proposed']);
    expect($resubmitted['status'] === 'proposed', 'rejected items can be resubmitted');
    $approved = $service->review($item['id'], 'approve', ['note' => '通过，先做高优先级']);
    expect($approved['status'] === 'approved' && $approved['reviewed_at'] !== null, 'approve stamps review time');

    // 状态机：审核结论只能走 review()；待审核必须先通过才能执行；done 为终态
    throwsClass(static fn () => $service->updateItem($dup['id'], ['status' => 'approved']), InvalidArgumentException::class, 'update cannot bypass review');
    throwsClass(static fn () => $service->updateItem($dup['id'], ['status' => 'in_progress']), DomainException::class, 'proposed must be approved before execution');
    throwsClass(static fn () => $service->updateItem($dup['id'], ['status' => 'done']), DomainException::class, 'proposed cannot jump to done');
    $running = $service->updateItem($item['id'], ['status' => 'in_progress']);
    expect($running['status'] === 'in_progress', 'approved items can start execution');
    $done = $service->updateItem($item['id'], ['status' => 'done', 'result_note' => '已上线，分页 50 条']);
    expect($done['status'] === 'done' && $done['result_note'] === '已上线，分页 50 条', 'execution finishes with a result note');
    throwsClass(static fn () => $service->review($item['id'], 'approve'), DomainException::class, 'done is terminal for review');
    throwsClass(static fn () => $service->updateItem($item['id'], ['status' => 'in_progress']), DomainException::class, 'done items cannot restart');
    throwsClass(static fn () => $service->updateItem($item['id'], ['result_note' => str_repeat('长', 501)]), InvalidArgumentException::class, 'result note length is capped');

    // 排序：待审核优先（同状态高优先级在前、新条目在前），done 殿后
    $low = $service->add(['title' => '低优先级条目', 'priority' => 'low'], 'admin');
    $mid = $service->add(['title' => '中优先级条目', 'priority' => 'medium'], 'admin');
    $list = $service->list()['items'];
    expect($list[0]['title'] === '中优先级条目' && $list[1]['title'] === '分页渲染' && $list[2]['title'] === '低优先级条目', 'proposed first, priority then newest ordering');
    expect($list[count($list) - 1]['status'] === 'done', 'done items sort after proposed');

    // 删除与不存在
    $service->remove($mid['id']);
    throwsClass(static fn () => $service->remove($mid['id']), OutOfBoundsException::class, 'removing twice fails');
    throwsClass(static fn () => $service->review('plan_missing', 'approve'), OutOfBoundsException::class, 'review unknown id fails');
    throwsClass(static fn () => $service->updateItem('plan_missing', ['title' => 'x']), OutOfBoundsException::class, 'update unknown id fails');

    // 数量护栏：超限裁掉最早的 done/rejected，不丢弃待处理条目
    $guard = new PlanService($root . '-guard', $clock);
    mkdir($root . '-guard', 0700, true);
    $first = $guard->add(['title' => '最早的已完成', 'priority' => 'low'], 'admin');
    $guard->review($first['id'], 'approve');
    $guard->updateItem($first['id'], ['status' => 'in_progress']);
    $guard->updateItem($first['id'], ['status' => 'done']);
    for ($i = 0; $i < 199; $i++) { $guard->add(['title' => '填充条目 ' . $i, 'priority' => 'low'], 'admin'); }
    expect(count($guard->list()['items']) === 200, 'cap reached at 200 items');
    $kept = $guard->add(['title' => '第 201 条待审核', 'priority' => 'high'], 'admin');
    $remaining = $guard->list()['items'];
    expect(count($remaining) === 200, 'cap trims to 200 after overflow');
    expect(in_array('第 201 条待审核', array_column($remaining, 'title'), true), 'pending items survive trimming');
    expect(!in_array('最早的已完成', array_column($remaining, 'title'), true), 'oldest done item trimmed first');

    // 状态筛选：AI 只读已通过条目；非法状态拒绝
    $aiItem = $service->add(['title' => 'AI 待执行条目', 'priority' => 'high'], 'agent');
    $service->review($aiItem['id'], 'approve');
    $approvedTitles = array_column($service->list('approved')['items'], 'title');
    expect(in_array('AI 待执行条目', $approvedTitles, true) && count($approvedTitles) === 1, 'approved filter returns exactly the approved item');
    expect(!in_array('AI 待执行条目', array_column($service->list('proposed')['items'], 'title'), true), 'approved item leaves the proposed list');
    throwsClass(static fn () => $service->list('bogus'), InvalidArgumentException::class, 'invalid status filter rejected');

    // 损坏文件不写入（与资产数据同一保护哲学）
    $corrupt = $root . '-corrupt'; mkdir($corrupt);
    file_put_contents($corrupt . '/improvement_plan.json', '{broken');
    throwsClass(static fn () => (new PlanService($corrupt))->add(['title' => 'x']), RuntimeException::class, 'corrupt storage must not be overwritten');
    expect(file_get_contents($corrupt . '/improvement_plan.json') === '{broken', 'preserve corrupt source');

    echo 'PASS: ' . $assertions . " improvement plan assertions\n";
} finally {
    foreach (glob($root . '*') ?: [] as $path) {
        if (is_dir($path)) { foreach (glob($path . '/*') ?: [] as $file) { @unlink($file); } @rmdir($path); }
        else { @unlink($path); }
    }
}
