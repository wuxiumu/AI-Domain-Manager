# tasks.md — 项目任务看板与路线图

> 本文件是项目的**持续任务清单**：新任务进来先记在这里，做完划掉，决策变化就地更新。
> 维护规则见文末。最近更新：2026-11-14。

## 产品定位（终态）

**AI 可自主托管的资产运维面（Agent-Ready Asset Console）**——自部署 PHP 资产大屏：

- 人看：`/screen` 域名 + IP 双视图大屏、`/settings`、`/calendar`、`/docs` 四个自包含页面
- AI 用：OpenAPI 3.1 + `llms.txt` + 单文件 Markdown 快照 + 作用域密钥 + 提案审批流；
  任何 Agent 拿到一个 token + 一个文件即可读懂台账，并在护栏内执行变更
- 两条产品纪律（不可破坏）：**读永远是缓存快照（不为展示发起云请求）；写必须版本护栏 + 单次 + 回读核实**

---

## ✅ 已完成里程碑（2026-11-14 收敛）

- [x] 产品收缩为「大屏 + 独立管理页 + AI API」，删除健康/分析/计划/提示词等全部未投产栈（v1.3.0）
- [x] `/screen` 域名 + IP 双视图：卡片交互、批量检查、DNS 暂停恢复、关联域名弹窗
- [x] 弹窗能力：批量添加/修改/删除、单条记录完整编辑弹窗（全字段 + GoDaddy 限制）、筛选行（关键词 + 状态 tab + 反选）
- [x] IP 访问检查体系：前端浏览器直连 / 后端服务端探测，结果全局缓存，响应时间展示
- [x] 每个 IP 可编辑：自动发现 IP 一键登记为手工（卡片标注「手工登记」）；修复编辑按钮无反应 bug
- [x] 弹窗记录列表表格化：固定五列 + 表头 + 行分割线 + 悬停高亮 + 单行省略
- [x] 独立 `/settings`、`/calendar`、`/docs` 页面；全站统一头部；文档在线预览编辑
- [x] 本地自托管 vendor 管线（marked + DOMPurify + hljs + mermaid），运行时零外链
- [x] 代码干净度审计：零死类、路由↔契约 67:67 零漂移、零调试残留
- [x] T1 孤儿数据清理：data/ 18M → 3.9M（健康栈删除后的运行时残渣）
- [x] T3 AI Bootstrap 单文件接入：`GET /api/ai/bootstrap.md`（assets:read，一次请求 = 说明 + 契约 + 护栏 + 双视图快照）
- [x] T4 密钥签发附 AI 接入材料：签发响应 `ai_usage` + /settings 弹窗「复制接入材料」按钮
- [x] T5 workspace-api.py 语义化客户端：bootstrap/screen/ips/records/report/propose 子命令 + 一键接入自检
- [x] T6 MCP Server：scripts/mcp-server.py（stdio 零依赖，六工具，护栏经 scope 校验）
- [x] T7 文档同步：llms.txt MCP 节、README「接入三步」+「MCP Server」小节
- [x] T8 README「扩展开发」章节：云商适配器路径 + API 三件事
- [x] T9 发布包补齐新交付物（mcp-server.py / AGENTS.md / tasks.md）+ FORBIDDEN_PREFIXES 硬校验
- [x] T10 契约防漂移脚本 check-api-contracts.py（首跑修复 ips/check 真实 scope 漂移）+ 纳入 AGENTS 验证命令
- [x] T11 数据清点脚本 cleanup-data.php（只读报告，正负双向验证；抓到 3 项真孤儿留管理员决定）

**看板状态：T1–T11 全部完成（P0 / P1 / P2 清零）。**

---

## 🔥 P0 — 立即执行

### T1 · 清理孤儿数据（~13MB）
- **内容**：删除代码零引用的运行时残渣——
  `data/cache/` 非 `dns-` 前缀文件（8.4MB 旧健康快照）、`data/history/`（4.2MB 旧同步历史）、
  `data/ratelimit.json`、`data/improvement_plan.json`、`scripts/__pycache__/`
- **注意**：`data/health.json`、`access_check.json` 保留至迁移脚本 `migrate-ip-assets.php` 确认不再需要后再删；
  删前逐项列出清单确认
- **验收**：`data/` 只剩在用文件；`php -l` 全绿；大屏/设置/日历/文档四页走查正常
- **状态**：✅ 完成（2026-11-14 22:26）——删除 6 个非 dns- 缓存文件（8.4MB）+ history/（4.2MB）+ ratelimit/improvement_plan 及锁文件 + __pycache__；data/ 18M → 3.9M；四页 200、双快照 API 正常、lint 全绿

### T2 · Git 提交还原点（最高优先级）
- **内容**：183 个文件未提交（整轮产品收敛 + 本周全部 UI/接口改动）。分批或一次提交均可，先落盘
- **验收**：`git status` 干净；commit message 按里程碑分段
- **状态**：✅ 完成（2026-11-14 22:22）——提交 88023ff，工作区清零

### T3 · `GET /api/ai/bootstrap.md`（AI 一次请求完成接入）
- **内容**：单文件打包 = `llms.txt` + 能力清单 + 域名视图 screen.md + IP 视图 screen.md + 写护栏要点
  （版本冲突 / 202 未核实 / 提案流）；由 `ApiDescriptionService` 新增组装方法 + 控制器路由；
  同步登记契约与 `assets:read` scope（三件事）
- **验收**：一个请求返回完整自包含 Markdown；AI 仅凭此文件能完成「读台账 + 提交提案」全流程；
  README / llms.txt 链接此入口
- **状态**：✅ 完成（2026-11-14 22:36）——六部分结构（说明/能力/scope/护栏速查/双视图快照），88KB 单文件 200；
  无效密钥 401；llms.txt、能力发现、README 均已链接；三件事核对通过

### T4 · 密钥签发响应附带「AI 使用说明」
- **内容**：`POST /api/tokens` 响应（与 /settings 创建成功弹窗）追加现成可复制块：
  `Authorization: Bearer …` + bootstrap URL + 三条核心 curl 示例
- **验收**：管理员复制粘贴即可把接入材料交给任何 AI
- **状态**：✅ 完成（2026-11-14 22:44）——签发响应新增 `ai_usage`（认证头 / bootstrap 地址 / 含注释的 curl 示例，
  示例 IP 用 TEST-NET 占位）；/settings 弹窗新增「AI 接入材料」块与「复制接入材料」按钮；
  浏览器已验证渲染与复制（真实签发链路需管理员密码，留人工验收：创建→复制→交给 AI→AI 完成读台账+提案）

### T5 · `workspace-api.py` 升级为语义化官方客户端
- **内容**：新增 `bootstrap()` / `screen()` / `ips()` / `records()` / `propose()` / `report()` 语义方法
  与 `--bootstrap` 自描述命令；保持零依赖、单文件
- **验收**：`python3 scripts/workspace-api.py bootstrap` 一条命令完成接入自检
- **状态**：✅ 完成（2026-11-14 22:50）——语义子命令 bootstrap / screen / ips / records / report / propose
  （raw 模式保留兼容，/api/health 过期示例一并修正）；`bootstrap` 一键自检 9 项全 ✓（bootstrap 文档、
  OpenAPI 53 路径、能力发现、双视图快照），经服务层临时 token（创建→自检→撤销，净零）真实链路验证

---

## 🚀 P1 — AI 生态产品化

### T6 · MCP Server（stdio，零依赖）
- **内容**：`scripts/mcp-server.py` 复用 workspace-api.py，封装 tools：
  `get_domain_screen` / `get_ip_screen` / `list_dns_records` / `propose_dns_change` / `get_daily_report` / `list_docs`；
  配置经环境变量（服务地址 + token）
- **验收**：任一 MCP 客户端配置即用；只读 tools 不携带写 scope 时拒绝写操作
- **状态**：✅ 完成（2026-11-14 22:55）——stdio JSON-RPC（initialize / tools/list / tools/call / ping，协议 2025-03-26）；
  六工具真实链路验证（60 域名 / 25 IP / 21 条 DNS 记录 / 日报 Markdown / 文档表）；propose 客户端预校验
  （缺 record_id 即拒绝，未触真实提案）；无 token 与未知工具均返回清晰 isError；临时 token 创建→验证→撤销净零

### T7 · 文档同步
- **内容**：`llms.txt` 增 MCP 一节；README 新增「AI 接入」章节（bootstrap / token / 提案流 / MCP）；
  CHANGELOG 记录
- **验收**：AI 与人类都能从 README 一路走到可用
- **状态**：✅ 完成（2026-11-14 22:56）——llms.txt 新增 MCP 节（工具清单 / 环境变量 / scope 门禁 / 提案不直写），
  bootstrap.md 内嵌内容随之同步；README「AI 接入」章节新增「接入三步」与「MCP Server」小节；
  check-public-docs 通过

---

## 🧩 P2 — 二开友好

### T8 · README「扩展」章节
- **内容**：新增云商 / API 的标准三步（路由注册 / `authorizeRoute` scope / 契约登记），以 AliyunProvider 为范例
- **状态**：✅ 完成（2026-11-14 22:54）——README 新增「扩展开发」章节：云商适配器四步（CloudProvider 抽象方法
  / provider_factory 注册 / providerSchema 凭据 / DnsRecordCache + DnsChangeService 纪律）+ API 三件事 + 文档联动说明

### T9 · `package-release.py --clean-data`
- **内容**：发布包排除 `data/`、缓存与本地配置，交付物开箱即干净
- **状态**：✅ 完成（2026-11-14 22:56）——审计确认发布包本为显式白名单制（data/ 结构性排除），
  无需 --clean-data 开关；落地为：FILES 补齐新增交付物（mcp-server.py / AGENTS.md / tasks.md）+
  新增 `FORBIDDEN_PREFIXES` 硬校验（data/ / .local/ / .git/ / config 真实凭据出现即构建失败）+
  实测构建 96 文件零违规、新文件齐全

### T10 · `scripts/check-api-contracts.py` 契约防漂移
- **内容**：diff「index.php 路由 ↔ Auth scope ↔ ApiDescriptionService 契约」三方，不一致退出非零；
  纳入 AGENTS.md 验证命令
- **验收**：删路由不删契约时脚本报错
- **状态**：✅ 完成（2026-11-14 22:58）——脚本支持 $dnsPath 变量展开与别名折叠，正反向各三重检查；
  **首跑即抓到一处真实漂移并修复**：`POST /api/ips/check` 与 `/api/ips/browser-check` 契约声明
  `checks:run` 但 authorizeRoute 未声明（Bearer 调用被 403），已补上并用 checks:run 临时 token 实测
  （422 验证拒绝 TEST-NET 地址而非 403）；负向测试（临时删路由）精确报错 exit=1；已纳入 AGENTS 验证命令

### T11 · `scripts/cleanup-data.php --dry-run`
- **内容**：按代码引用白名单清点 `data/`，报告孤儿文件；防下次删功能再留渣
- **状态**：✅ 完成（2026-11-14 23:10）——只读报告脚本（永不代删），分类覆盖锁附属文件 / SnapshotCache 快照
  （按 remember key 哈希）/ dns-* 结构化缓存 / dns-locks 运行锁 / 未知目录；正负双向验证（fake 孤儿 → 报告并 exit 1）。
  **两个额外发现**：① T1 判为孤儿的 6 个非 dns 缓存中有 2 个（domain-screen-v1 / ip-screen-v1）实为
  SnapshotCache 在用缓存（删除后自动重建，无损失），脚本已正确归类；② 抓到 3 项真孤儿（`.write_test`、
  `tasks.json` + 锁——旧任务队列存储，日历待办已迁入 calendar.json），均在 T1 白名单外，留管理员决定删除

---

## 🧹 持续优化（随手做，不排期）

- [x] screen.js 模块化拆分（2026-11-14）：视图域 876 行 + `screen-modal.js` 弹窗模块 930 行（ScreenBoard 桥接共享 state 与工具）；顺带修复非 A 记录编辑后 `reload()` 未定义的隐藏 bug。
- [ ] `data/cache` 单 zone 文件可达 4.7MB：DNS 缓存可剥离 raw 字段瘦身（改 provider 返回结构，需回归测试）
- [ ] 大屏自动刷新周期与 SnapshotCache TTL 联动提示（刷新快于缓存时给出说明）
- [ ] `AGENTS.md` 架构地图随 P0–P2 落地同步刷新

## 🚫 明确不做（防止 scope 蔓延）

- Webhook / 消息推送（单管理员无此需求）
- 多租户 / 角色权限（单管理员产品）
- per-token 限流（内网自托管，登录限流已够）
- 数据库 / 框架迁移（JSON 存储是产品特性）

---

## 维护规则

1. **新任务**：先进本文件对应分区，写清「内容 / 验收」，标「未开始」
2. **开工**：状态改「进行中」；**完成**：改「✅ 完成（日期）」并移一条到已完成里程碑
3. **决策变化**：直接改任务描述，不另开讨论记录；放弃的任务移入「明确不做」并写原因
4. **每次功能提交**：本文件 + `CHANGELOG.md` + （涉及契约时）`AGENTS.md` 三处同步更新
5. 任务编号（T1…）递增不复用；引用任务号时写 `T<编号>`
