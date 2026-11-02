# AGENTS.md — AI 编码助手协作指南

本文件面向在本仓库工作的 AI 编码助手（也适用于人类贡献者）。项目本身是一个面向管理员的资产工作台，同时对外提供 AI 友好的 API；两类“AI”不要混淆。

## 项目是什么

可自行部署的 PHP 资产管理工具（单管理员、无框架、无数据库）：把云账号域名与手工登记的公网 IP 汇总为台账，提供 DNS 管理、资产关系、健康检查与备份导出。数据保存在 `data/*.json`，凭据保存在 `config/` 与私有 JSON 存储，二者都不能公开或提交。

## 架构地图

| 位置 | 职责 |
| --- | --- |
| `public/index.php` | 唯一入口与全部路由注册；新增 API 必须同步登记路由与 OpenAPI 契约。 |
| `app/controllers/` | 解析请求、鉴权、组织响应；业务规则不写在这里。 |
| `app/services/` | 业务规则核心（健康检查、DNS 变更、日历、备份、API 描述等）。 |
| `app/providers/` | 阿里云 / 腾讯云 / GoDaddy 适配器，隔离云商差异。 |
| `app/core/` | 路由、认证（会话 + Bearer 密钥 + CSRF）、HTTP、JSON 存储与缓存。 |
| `public/assets/js/` | 单页应用前端，按模块拆分（`health.js`、`records.js` 等），无构建步骤。 |
| `tests/` | 离线回归：PHP 脚本、Node UI 测试（vm 沙箱）、Python HTTP 集成（`http_fixture.py` 启动一次性 PHP 服务器）。 |

## 必须遵守的约定

- **新增 API 路由的三件事**：`public/index.php` 注册路由、`app/core/Auth.php::authorizeRoute` 声明 Bearer 权限范围（若允许密钥）、`app/services/ApiDescriptionService.php` 补 OpenAPI 契约。集成测试会强制校验三者一致。
- **读写分离**：列表读取复用文件快照（`SnapshotCache`）；同步、检查、DNS 刷新必须由用户或 API 显式触发，禁止为展示列表而发起网络请求。
- **安全边界**：云凭据、密码、API 密钥明文只出现在创建/登录响应中，永不回显；测试不得使用真实凭据或真实云账号；对外文档（README、docs、OpenAPI、`llms.txt`）不得出现真实资产、真实 IP 或密钥，文档 IP 用 TEST-NET 段，测试可用公共 DNS 地址（如 8.8.8.8）但不得真正发起连接。
- **DNS 写入原则**：只写一次并回读核实；版本冲突返回 409；未确认返回 202 + `verified:false`，绝不自动重试写操作。
- **健康数据解释**：未检查 ≠ 正常；结果超过 24 小时为过时；DNS 解析 IP 可能是 CDN。UI 与报告文案必须保留这些限定。
- **存储写入**：私有 JSON 走 `PrivateJsonStore`（原子替换 + 0600 权限），业务状态在锁内变更，网络操作不得持有数据锁。
- **前端**：无构建、无外部 CDN；用户内容一律经 `escapeHtml` 输出；样式与交互跟随现有模块模式（modal overlay、`toast`、`api()`）。

## 验证命令

改动后至少运行受影响模块的测试；发布前全量运行：

```bash
php tests/health_test.php          # 健康检查与 IP 资产
php tests/ai_report_test.php       # AI 分析报告
php tests/settings_test.php        # 配置与 API 密钥
php tests/dns_changes_test.php     # DNS 变更流程
python3 tests/product_api_test.py  # 端到端集成（含 OpenAPI 契约一致性）
node --test tests/login_ui_test.mjs
python3 -m unittest discover -s tests -p 'public_docs_test.py'
python3 scripts/check-public-docs.py
```

## 产品的 AI 接入点（供功能开发参考）

实例对外提供 `/api/openapi.json`、`/.well-known/asset-workspace.json`、`/llms.txt` 与带权限范围和有效期的 API 密钥；`GET /api/health/report` 输出可直接交给 AI 分析的 Markdown 健康报告；`GET /api/handover` 是 AI 接管的交接简报（统计、权限、行动序列）；`GET /api/issues` 是与首页问题中心同源的分级问题清单（稳定 ID + 建议动作）；`POST /api/proposals`（`proposals:write`）让 AI 提交 DNS 变更提案，管理员批准后才经 `DnsChangeService` 执行；`GET /api/trends` 供看板图表渲染，`GET /api/metrics` 以 Prometheus 格式供监控平台抓取。修改这些契约时，同步更新 `ApiDescriptionService`、`Auth::authorizeRoute` 与 README 的 API 章节。
