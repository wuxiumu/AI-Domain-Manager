<?php

/**
 * 改进计划种子：把 #ips（IP 资产页）的改进点写入工作台计划页，供逐条审核。
 * 幂等：同名待处理条目已存在时跳过（PlanService 会拒绝重复，脚本捕获后继续）。
 * 用法：php scripts/seed-improvement-plan.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../app/bootstrap.php';
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

$items = [
    ['title' => '改进计划：计划 / 审核 / 执行工作流',
     'detail' => "现状：改进想法散落在对话里，无法逐条审核与跟踪。\n"
         . "方案：工作台内置计划页（#plans）与 /api/plans 接口：条目状态流 proposed（待审核）→ approved（已通过）→ in_progress（执行中）→ done（已完成），驳回需附原因；AI 提出与手动新增同源管理。\n"
         . "验收：计划页可新增、逐条审核、推进状态；执行者只读取「已通过」条目实施，完成后回填执行结果。",
     'priority' => 'high',
     'status' => 'done',
     'result_note' => '已实现：GET/POST /api/plans、PUT/DELETE /api/plans/{id}、POST /api/plans/{id}/review + 计划页；plans_test 33 断言。'],
    ['title' => 'IP 资产：HTTP 检查遵循登记端口',
     'detail' => "现状：probeIp 与浏览器探测固定访问 http://IP/，登记端口（默认 443）只是资产标识、不参与检查；同一 IP 登记多个端口会出现多条资产、结果完全相同且具有误导性。\n"
         . "方案：检查目标改为 http://IP:port/（默认端口可省略），检查返回的 url 与 message 带上实际端口；详情「检查目标」同步显示。\n"
         . "验收：登记 port=8443 的 IP，检查后详情显示 http://IP:8443/，结果与 80 端口相互独立。",
     'priority' => 'high'],
    ['title' => 'IP 资产：列表分页渲染',
     'detail' => "现状：约 977 个 IP 一次性拼进 tbody，每次搜索（防抖后）/切筛选/切页签都全量重建 DOM，长列表输入与滚动卡顿。\n"
         . "方案：每页 50 条分页（页码 + 上下页），筛选与搜索变化时重置页码；计数徽标与导出仍基于全量筛选结果。\n"
         . "验收：千级 IP 下搜索输入无可感知卡顿，单次渲染 DOM 行数 ≤ 50。",
     'priority' => 'high'],
    ['title' => 'IP 资产：检查全部 IP 转后台执行',
     'detail' => "现状：「检查全部 IP」在页面内逐批同步执行，千级 IP 需要保持页面打开几十分钟，误关即中断。\n"
         . "方案：接入已有后台任务队列（POST /api/tasks，type=health-check、kind=ip），IP 页新增「转后台执行」；顶栏胶囊已展示进度。\n"
         . "验收：入队后关闭浏览器任务继续，完成后刷新列表可见新状态。",
     'priority' => 'high'],
    ['title' => 'IP 资产：多选批量操作',
     'detail' => "现状：域名页有多选批量（选择栏 + 全选），IP 页只能逐个操作。\n"
         . "方案：复用域名选择栏模式——全选当前筛选结果、批量检查（≤5 走同步接口）、批量刷新归属地。\n"
         . "验收：勾选 3 个 IP 一次完成检查；超过 5 个提示分批或转后台。",
     'priority' => 'medium'],
    ['title' => 'IP 资产：排序与到期提醒',
     'detail' => "现状：表格无排序，到期日期只是文本，临近到期不突出。\n"
         . "方案：名称 / 检查时间 / 到期日期三列点击排序（默认到期临近优先），30 天内到期的行加徽标；与看板 expiring_30 口径一致。\n"
         . "验收：点击表头切换升降序，到期<30 天的行有可见标记。",
     'priority' => 'medium'],
    ['title' => 'IP 资产：筛选结果导出 CSV',
     'detail' => "现状：备份导出含全部 IP，但无法按当前筛选导出一份清单。\n"
         . "方案：IP 面板加「导出 CSV」：当前筛选结果（名称/IP/端口/供应商/到期/状态/检查时间/归属地/关联域名），带 BOM，公式注入防护同 AssetSnapshot::csvRow。\n"
         . "验收：导出文件 Excel 直接打开不乱码，=+- 开头单元格已转义。",
     'priority' => 'medium'],
    ['title' => 'IP 资产：供应商与归属地筛选',
     'detail' => "现状：筛选只有地址类型与健康状态；供应商、归属地只能靠关键词搜索。\n"
         . "方案：筛选行增加「供应商」下拉（按数据聚合）与「归属地：已查询 / 未查询」切换。\n"
         . "验收：按供应商筛选结果正确，未查询归属地可一键补查（已有按钮联动）。",
     'priority' => 'low'],
];

$service = new PlanService();
$added = 0;
$skipped = 0;
foreach ($items as $item) {
    try {
        $created = $service->add(['title' => $item['title'], 'detail' => $item['detail'], 'priority' => $item['priority']], 'agent');
        if (($item['status'] ?? '') === 'done') {
            // 工作流条目沿合法状态流落到完成态：通过 → 执行 → 完成（种子自证流程可用）。
            $service->review($created['id'], 'approve', ['note' => '基础设施条目，随本轮实现直接验收']);
            $service->updateItem($created['id'], ['status' => 'in_progress']);
            $service->updateItem($created['id'], ['status' => 'done', 'result_note' => $item['result_note'] ?? '']);
        }
        $added++;
    } catch (DomainException) { $skipped++; }
}
echo "改进计划种子完成：新增 {$added} 条，跳过重复 {$skipped} 条。\n";
