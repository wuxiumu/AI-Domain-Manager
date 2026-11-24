# AGENTS.md — AI 编码助手协作指南

本文件面向在本仓库工作的 AI 编码助手（也适用于人类贡献者）。项目对外提供 AI 友好的 API，同时有一个给人看的大屏；两类「AI」（协作编码助手 / 通过 API 接入的外部 AI）不要混淆。

## 项目是什么

可自行部署的 PHP 资产大屏（单管理员、无框架、无数据库）：人看——把云账号域名与解析记录、手工登记的公网 IP 汇总为深色大屏（`/screen`，域名 + IP 双视图），并可在屏上直接批量管理 DNS（改 IP / 单条与批量删除 / 批量添加（全类型）/ 暂停恢复）；AI 用——对外提供 OpenAPI 3.1、`/llms.txt` 与带权限范围的 API 密钥，AI 可读大屏 Markdown 快照、提交 DNS 变更提案，管理员批准后才执行。数据保存在 `data/*.json`，凭据保存在 `config/` 与私有 JSON 存储，二者都不能公开或提交。

## 架构地图

| 位置 | 职责 |
| --- | --- |
| `public/index.php` | 唯一入口与全部路由注册；新增 API 必须同步登记路由与 OpenAPI 契约。 |
| `app/controllers/` | 解析请求、鉴权、组织响应；业务规则不写在这里。页面控制器（Screen/Settings/Calendar/Docs 的 `page()`）渲染自包含独立页，未登录一律 `Auth::requirePageSession()` 302 回登录壳，绝不裸吐 JSON。 |
| `app/services/` | 业务规则核心：`ScreenService`（域名大屏聚合）、`IpScreenService`（IP 大屏聚合）、`IpAssetService`（手工 IP 台账）、`DnsChangeService`（DNS 写入护栏，含暂停 / 恢复）、`SyncService`、`AiProposalService`（提案）、`CalendarService`、`ApiDescriptionService`（契约）等。 |
| `app/providers/` | 阿里云 / 腾讯云 / GoDaddy 适配器，隔离云商差异。 |
| `app/core/` | 路由、认证（会话 + Bearer 密钥 + CSRF）、HTTP、JSON 存储与缓存。 |
| `public/views/` + `public/assets/` | 自包含独立页：`screen.php`（大屏，`screen.js/css` + `screen-modal.js` 弹窗模块 + `screen-ip.js`）、`settings.php`（`settings-page.js/css`）、`calendar.php`（`calendar-page.js/css`）、`docs.php`（`docs-page.js/css`，文档浏览与编辑）；共享 `admin-page.js/css` 基础层（统一头部 / Markdown 管线 / 统一退出，页面经 `window.__ADMIN__` 取 CSRF）；`vendor/` 为本地自托管开源库（marked / DOMPurify / highlight.js / mermaid）。`app.php` 是初始化与登录的壳：首次安装先过环境检查清单（`setup.js` 两阶段，全过才出现创建管理员），登录后跳 `/screen`。无构建步骤。 |
| `scripts/` | AI 客户端与 MCP Server（`workspace-api.py` / `mcp-server.py`）、屏幕报告生成（cron）、契约防漂移与数据清点检查、密码重置 / 迁移 / 打包等运维脚本；SSH 连通性批量测试为模块包 `scripts/ssh_check/`（`cd scripts && python3 -m ssh_check`，用法与 `.env` 格式见包内 README；认证口径 `ssh_auth = password|key|auto|agent`，默认只验密码，私钥放 `scripts/ssh_check/key/<地址>.pem` 自动识别，`--list` 先自检再实连；`--report` 另出「服务器配置与运行报告」（阈值异常 + 与上次对比 + 逐台明细，正文固定写 `.logs/ssh-report/report.md`、可 `--json`，供外部 AI / cron 消费，与 Web 端无关）。 |
| `deploy/` | 生产部署参考（`nginx.conf.example`：front-controller 统一回退 + 仅 `index.php` 可执行 + 敏感目录 404），新路由无需改 nginx。 |

## 必须遵守的约定

- **新增 API 路由的三件事**：`public/index.php` 注册路由、`app/core/Auth.php::authorizeRoute` 声明 Bearer 权限范围（若允许密钥）、`app/services/ApiDescriptionService.php` 补 OpenAPI 契约。三者必须一致。
- **读写分离**：列表读取复用文件快照（`SnapshotCache`，大屏快照 120 秒 TTL）；同步、检查、DNS 刷新必须由用户或 API 显式触发，禁止为展示列表而发起网络请求。
- **安全边界**：云凭据、密码、API 密钥明文只出现在创建/登录响应中，永不回显；**私钥与本地凭据产物一律不提交**（`.gitignore` 已排除 `config/`、`.env`、`scripts/ssh_check/key/`、`*.pem`、`*.ppk`、`.logs/`），工具的输出只允许出现密钥**文件名**与掩码后的凭据；不得使用真实凭据或真实云账号；对外文档（README、OpenAPI、`llms.txt`）不得出现真实资产、真实 IP 或密钥，文档 IP 用 TEST-NET 段，测试可用公共 DNS 地址（如 8.8.8.8）但不得真正发起连接。`config/dev-unlock.flag` 是回环免登录开发开关（仅 127.0.0.1/::1 生效），生产环境部署后必须删除。
- **DNS 写入原则**：只写一次并回读核实；版本冲突返回 409；未确认返回 202 + `verified:false`，绝不自动重试写操作。暂停 / 恢复走 `DnsChangeService::applyStatus`（同套护栏 + 操作留档）。
- **快照解释**：未检查 ≠ 正常；结果超过 24 小时为过时；DNS 解析 IP 可能是 CDN。HTTPS 结果语义：HTTPS 通过（证书有效）≈ 已上线运营，仅 HTTP 可达 = 未启用 HTTPS，未检查不做推断。UI 与报告文案必须保留这些限定（页面脚注、screen.md / report 的语义说明）。
- **存储写入**：私有 JSON 走 `PrivateJsonStore`（原子替换 + 0600 权限），核心台账走 `WorkspaceData`（锁内变更），网络操作不得持有数据锁。
- **前端**：无构建、运行时不外链（第三方开源库下载到 `public/assets/vendor/` 本地自托管：marked + DOMPurify + highlight.js + mermaid）；独立页自包含（各自 CSS/JS，共享 `admin-page.*` 基础层）；用户内容渲染统一走 `AdminPage.renderMarkdown`（marked 解析 + DOMPurify 清洗）与 `AdminPage.enhancePreview`（hljs 高亮 + mermaid 图）；用户内容一律经 `escapeHtml` 输出；`[hidden]{display:none!important}` 是面板切换的硬规则。
- **文档即页面**：仓库核心文档（README / AGENTS / CHANGELOG / tasks.md）与 docs/ Markdown 通过 `/docs` 页面与 `GET /api/docs*` 展示与维护；写操作路径白名单校验并进入操作审计。新增功能必须同步更新 CHANGELOG、tasks.md 与本文件。
- **MVP 阶段不写测试**：当前为 MVP 快速迭代期，改动以手动验收为主（浏览器全链路走查），不新增测试目录。

## 验证命令

MVP 阶段改动以手动验收为主；静态回归：

```bash
# PHP 语法全量
find app public/index.php public/views scripts -name "*.php" -exec php -l {} \; | grep -v "No syntax" || true
# 前端语法全量
for f in public/assets/js/*.js; do node --check "$f"; done
# 悬空引用扫描（删除类改动后必须为零）
grep -rn "HealthService\|TrendService\|MaintenanceService\|AssetSnapshot" app/ public/ scripts/ --include="*.php" --include="*.js" || true
# 公开文档规范（命名 / 链接 / 地址策略）
python3 scripts/check-public-docs.py
# API 三件事防漂移（路由 ↔ scope 声明 ↔ 契约；新增/删除路由后必须为零）
python3 scripts/check-api-contracts.py
```

浏览器手动验收基线：首次安装（环境检查清单全过 → 创建管理员 → 自动登录）→ 登录（含记住密码与验证码失败回填）→ `/screen` 工具条（搜索面板开合与关键词回显、状态 tab 切换、更多面板的排序/条数/检查、URL hash 还原）+ 域名 / IP 双视图与弹窗批量操作（单条删除需二次确认，非 A 记录弹窗可添加全类型）→ `/settings` 四个面板（云账号 / AI API / 修改记录与文档 / 通用设置）→ `/calendar` 打卡与待办 → `/docs` 预览与编辑。

## 产品的 AI 接入点（供功能开发参考）

实例对外提供 `/api/openapi.json`、`/.well-known/asset-workspace.json`、`/llms.txt`、**`/api/ai/bootstrap.md`（AI 一次请求完成接入的自包含文档：说明 + 能力清单 + 写护栏速查 + 双视图快照，`assets:read`）** 与带权限范围和有效期的 API 密钥（`assets:read` / `assets:write` / `dns:read` / `dns:write` / `checks:run` / `sync:run` / `proposals:write`）。核心数据入口：

- `GET /api/domains/screen` 与 `GET /api/domains/screen.md`：域名大屏只读快照及其自包含 Markdown 文档（含可照做的 CRUD 指引）。
- `GET /api/domains/screen/report`：每日异常报告（`?format=markdown` 直出正文；`php scripts/generate-screen-report.php` 供 cron）。
- `GET /api/ips/screen` 与 `GET /api/ips/screen.md`：IP 视图快照（DNS 自动发现 + 手工登记合并、归属地缓存、访问状态）。
- `POST /api/ips` / `PUT|DELETE /api/ips/{id}`（`assets:write`）：手工登记公网 IP；`POST /api/ips/{ip}/location`（`checks:run`）显式刷新归属地（ipwho.is）。
- `POST /api/domains/{provider}/{domain}/records/{recordId}/status`（`dns:write`，body `{paused, expected_version}`）：暂停 / 恢复单条解析记录；阿里云与腾讯云支持，GoDaddy 明确报错；暂停态在快照记录行以 `status` 字段透出。
- `POST /api/proposals`（`proposals:write`）让 AI 提交 DNS 变更提案；管理员以会话调用 `POST /api/proposals/{id}/approve|reject`，批准后才经 `DnsChangeService` 执行（读云端最新版本 → 单次写入 → 回读核实）。
- **官方客户端与 MCP**：`scripts/workspace-api.py`（零依赖 CLI：`bootstrap` 一键接入自检、`screen` / `ips` / `records` / `report` / `propose` 语义子命令）与 `scripts/mcp-server.py`（stdio MCP Server，六工具：双视图快照 / DNS 记录 / 日报 / 文档清单 / 提案提交）；配置经 `WORKSPACE_URL` / `WORKSPACE_API_TOKEN` 环境变量。
- `POST /api/domains/{provider}/{domain}/records/access-check`（`checks:run`）检查访问状态；可选 `record_ids` 只探测指定记录。
- `POST /api/accounts/{id}/sync`（`sync:run`，`refresh_dns:true`）刷新台账与解析缓存；DNS 读写走 `/api/domains/{provider}/{domain}/records`（`dns:read` / `dns:write`）。
- 会话级只读：`GET /api/dns/changes`（DNS 变更留档）、`GET /api/audit`（操作审计）、`GET /api/docs` 与 `GET /api/docs/read?path=`（仓库文档白名单读取，越界 404）、`GET /api/stats`（代码规模与运行时内存）。

修改这些契约时，同步更新 `ApiDescriptionService`、`Auth::authorizeRoute` 与 README 的 API 章节。
