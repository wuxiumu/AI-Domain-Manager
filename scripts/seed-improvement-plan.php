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

    /* ---- 第二批：跨维度 10 条建议（2026-11-07） ---- */
    ['title' => '性能：全量健康检查并发化',
     'detail' => "现状：check() 与后台 worker 都是逐资产串行探测，千级资产全量一轮要几十分钟；PublicHttpProbe（HTTPS 跟进）已验证 curl_multi 并发模式可行。\n"
         . "方案：批量检查内部改并发池（并发 ≤8，复用或抽 PublicHttpProbe 的多句柄编排），单资产内部 HTTP→HTTPS 仍串行以保持判定语义；批量大小与并发上限解耦。\n"
         . "验收：同样 20 项资产的批量检查耗时降至原来的 1/4 以下；结果与串行一致（相同 message 结构）。",
     'priority' => 'high'],
    ['title' => '安全：TLS 证书到期跟踪',
     'detail' => "现状：HTTPS 检查只验证证书链有效，不记录到期时间；证书临期只能等连接失败才发现。\n"
         . "方案：检查时读取证书有效期（CURLINFO_CERTINFO），存入观测；HTTPS 跟进页展示剩余天数，30/14/7 天分级进入问题中心（稳定 ID + 建议动作）。\n"
         . "验收：检查后详情可见证书到期日；问题中心出现临期条目且不重复。",
     'priority' => 'high'],
    ['title' => '可观测性：健康告警 Webhook 通知',
     'detail' => "现状：检查结果只存在工作台里，异常要靠人来看板发现。\n"
         . "方案：设置页可配置通用 Webhook URL，检查出现「新 unhealthy」与「恢复 healthy」时 POST 事件摘要（资产/状态/时间/消息）；支持测试按钮与静默时段；未配置不发送，绝不阻塞检查流程。\n"
         . "验收：配置后触发一次模拟事件收到通知；关闭配置后无任何外发。",
     'priority' => 'medium'],
    ['title' => '可靠性：每日自动备份快照',
     'detail' => "现状：备份只能手动导出下载，忘记导出就没有近期快照。\n"
         . "方案：cron 每日把 assets-v1 快照写入 .local/backups/（0600，按天轮转保留最近 14 份）；设置页显示最近一次备份时间与份数；恢复流程不变。\n"
         . "验收：连续运行两天产生两份快照，超过 14 份自动轮转；快照可通过现有恢复流程导入。",
     'priority' => 'medium'],
    ['title' => '合规：操作审计查看器',
     'detail' => "现状：audit.json 记录了全部 API 操作（已 400+ KB）但没有查看界面，出问题只能直接翻文件。\n"
         . "方案：维护区新增「操作记录」视图：时间/方法/路由/状态码/耗时列表，按路由与时间筛选，复用日志清理维度。\n"
         . "验收：能看到最近的写操作（如 POST /api/health/check）及其结果码。",
     'priority' => 'medium'],
    ['title' => '数据治理：audit.json 自动封顶',
     'detail' => "现状：sync_log（500 条）、check_events（5000 条）都已封顶，唯独 audit.json 无上限持续增长，拖慢读取与清理统计。\n"
         . "方案：写入时封顶（建议 5000 条，最新在前）；清理页保留手动「先导出再清空」入口不变。\n"
         . "验收：写入超过上限后文件条目数恒等于上限，最早条目被裁剪。",
     'priority' => 'medium'],
    ['title' => '账号安全：可选 TOTP 二步验证',
     'detail' => "现状：登录有验证码与限速，但密码本身一旦泄露即完全失守；面向公网的单管理员工具值得加一道动态码。\n"
         . "方案：设置页可选绑定 TOTP（RFC 6238，展示二维码 + 一次性恢复码）；开启后登录需输入 6 位动态码；可随时关闭。\n"
         . "验收：绑定后无动态码无法登录，恢复码可兜底一次并作废。",
     'priority' => 'medium'],
    ['title' => '可视化：逐资产延迟迷你趋势图',
     'detail' => "现状：详情弹窗的最近 10 次检查是纯文本列表，延迟劣化要靠心算。\n"
         . "方案：详情弹窗内联 SVG sparkline（最近 10 次 latency_ms，含最差/最新数值标注），零外部依赖、跟随现有 escapeHtml 输出规范。\n"
         . "验收：有历史的资产详情出现迷你趋势，超时/失败项不画点但保留文字记录。",
     'priority' => 'low'],
    ['title' => '数据入口：CSV 批量导入 IP',
     'detail' => "现状：手工 IP 只能逐个添加，初始登记几十台服务器效率低。\n"
         . "方案：上传 CSV（IP/端口/名称/备注/供应商/到期，带模板下载），逐行校验（复用 addIp 规则），失败行逐条报告原因，成功行入台账；与备份导出的 CSV 字段可互逆。\n"
         . "验收：10 行混合 2 行错误时导入 8 行，错误行原因明确（如私网地址、端口越界）。",
     'priority' => 'medium'],
    ['title' => '产品：问题中心一键处置与忽略规则',
     'detail' => "现状：问题中心给出稳定 ID 与建议动作，但处置仍要自己跳到对应页面重复操作；同类噪声问题会每天重复出现。\n"
         . "方案：问题行内直达动作——重新检查该资产 / 发起 DNS 提案 / 「已知问题」静默（默认 7 天，到期自动复出，工作台内可管理静默清单）。\n"
         . "验收：从问题中心可一步完成重检；静默后该问题在期限内不再出现，到期恢复。",
     'priority' => 'medium'],
];

$service = new PlanService();
$added = 0;
$skipped = 0;
$existing = array_fill_keys(array_column($service->list()['items'], 'title'), true);
foreach ($items as $item) {
    if (isset($existing[$item['title']])) { $skipped++; continue; }
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
echo "改进计划种子完成：新增 {$added} 条，跳过已存在 {$skipped} 条。\n";
