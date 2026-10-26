# 域名管家 · Domain Manager

[文档目录](docs/00-目录.md)：产品介绍、推广文案、计划方案、交付与维护。所有示例均使用保留示例地址。

PHP 全栈域名管理工具：聚合阿里云 / 腾讯云 / GoDaddy 多账号域名数据，统一管理。

支持首次启动配置、界面维护云账号 / 登录信息、分权限 AI API 密钥与 OpenAPI 3.1。[产品配置与 AI 接入说明](docs/01-产品介绍/02-安装与API接入.md)。新安装运行 `./start.sh` 后，在首次配置页创建管理员；已有配置自动沿用。

**1.1.0 交付基础已实现**：产品设置 → 数据与交付，可创建服务器备份、导出 JSON / CSV、预览恢复资产、查看基础操作记录和部署自检，并下载交付摘要。恢复前自动留底；资产文件不包含云凭据、登录配置、API 密钥或检查缓存。[备份恢复与版本交付](docs/04-交付与维护/04-备份恢复与版本交付.md) · [版本记录](CHANGELOG.md)。

**1.1.1 登录防护**：本地图形验证码、一次性校验、登录及图片获取限流、失败锁定和等待倒计时。密码登录 API 同样需要验证码，自动化使用 API 密钥。[登录防护与限流](docs/04-交付与维护/05-登录防护与限流.md)。

```sh
python3 scripts/package-release.py --output-dir dist
```

打包脚本仅收集明确允许的程序、示例配置和公开文档，生成版本 ZIP、包内文件 SHA-256 清单与 ZIP 校验值；不读取 `data/`、实际配置、`.local/` 或 Git 历史。发行包默认不覆盖已有版本文件，重复构建请使用新的输出目录。

前端已按功能拆分 CSS / JS，并优化笔记本与大屏布局：支持舒适 / 紧凑密度、侧栏收起、固定表头与操作列、关系图随容器宽度重排。[前端文件职责、断点与维护约定](docs/04-交付与维护/02-前端维护说明.md)。

## 第一批优化：资产健康工作台

### 资产关系与缓存优化

“资产关系”页提供关联覆盖率环图、IP 承载排行、可点击关系图和明细表。快捷 Tab 查询全部、人工确认、仅 DNS、未关联和异常域名；查询条件可保存在页面 URL。点击节点可以查看健康证据、编辑域名或服务器资料、管理绑定及进入 DNS。

人工绑定保存在 `data/health.json` 的 `bindings` 字段。DNS 线索与人工确认分别绘制为虚线与实线；人工绑定的 IP 被保留为台账资产，不随 DNS 变化消失。IP 资产保留稳定标识，名称、供应商、到期日期、用途与备注及网站链接均可编辑。网站链接按“网站名称 + URL”维护，支持添加、删除和点击跳转。云账号支持删除、启用开关和备注，卡片展示已填写的备注。

缓存策略：

- `data/cache/` 保存健康关系汇总与 DNS 记录快照。健康汇总按源文件内容指纹更新，并最多每分钟重算一次时间相关分类；不触发云商或网络检查。
- DNS 记录首次打开时拉取一次，此后复用文件快照；“刷新云端记录”或 DNS 增改删后才重新拉取。页面显示快照时间。其他控制台修改后需手动刷新。
- 浏览器内存和会话缓存复用已加载的列表，最长 30 分钟；域名筛选在本地完成。用户身份隔离，退出清理，其他窗口保存后广播失效。
- 资产、绑定、检测或 DNS 写入成功后使浏览器缓存失效，下次读取从最新文件构建；健康结果超过 24 小时仍标记过时。
- 刷新本地快照只读取当前文件；同步域名、检查资产、刷新云端 DNS 是独立的显式操作。

额外验证：`php tests/relationships_test.php`、`node --test tests/client_cache_test.mjs`。

### 子域名、IP 反查与解析快捷编辑

- 健康看板显示 DNS 缓存覆盖率、每个域名的子域名数量；没有快照时显示“待缓存”。
- 域名详情包含主域名/子域名列表、所有类型的缓存解析记录、IP、逐项及批量网站检查。主机名去重后计数，泛解析不作为具体网站发起探测，TXT/MX 等非网站主机不做 HTTPS 检查。
- IP 页明确显示 IPv4/IPv6、关联主域名和子域名数量；“查看 / 修改解析”按主机名进入对应解析记录。
- 快捷编辑保留记录类型、主机名、优先级和线路，保存到云端后刷新 DNS 快照，并使原健康结果过时。GoDaddy 修改读取并保留同组其他值。
- 关联图支持自定义每页数量、展示全部、全屏；分页偏好保存在本机。全屏时节点详情仍可操作。
- `php scripts/cache-dns.php` 仅补齐缺失的 DNS 文件缓存；跳过已有快照，不修改云端 DNS。刷新现有快照使用界面按钮。

补充测试：`php tests/subdomain_test.php`、`php tests/dns_pagination_test.php`。

适配依据：[阿里云 DNS 分页参数](https://api.alibabacloud.com/api/Alidns/2015-01-09/DescribeDomainRecords)、[GoDaddy 记录组接口](https://classic-developer.godaddy.com/doc/endpoint/domains)。

- 首页提供域名与 IP 健康指标、状态分布、搜索筛选、检查进度和详情。
- 域名执行 DNS 与 HTTPS 检查，验证证书；IP 检查 `http://IP/`（IPv6 使用方括号），HTTP 2xx / 3xx 为正常，不跟随重定向；HEAD 不受支持时改用有界 GET。DNS 发现的 IP 自动收录并展示解析关联，可能是 CDN 入口。
- 支持手工添加公网 IPv4/IPv6、用途与备注及网站链接；检查状态、时间与最近 10 次记录持久化到服务器 `data/health.json`。点击 IP 状态可单次检查，“检查全部 IP”分批检查。超过 24 小时的结果显示为过时，旧 TCP 结果需重新执行 HTTP 检查。
- “检查全部资产”会继续检查本轮新发现的 IP；检查分批执行，需保持页面打开，可停止后续批次。
- 新数据保存在 `data/health.json`，使用独立文件锁和原子替换。该批尚未迁移数据库或引入定时任务。
- DNS 检查使用本机解析器，需启用 `proc_open` 并安装可执行的 PHP CLI；DNS 查询总预算 4 秒，HTTP / HTTPS 每项请求总预算 4 秒（连接超时最多 2 秒）。环境不支持时会显示具体原因。

接口：`GET /api/health`、`POST /api/health/check`（`asset_ids` 每批最多 5 项）、`POST /api/health/ips`（`ip`、可选 `name`、`note`、`links: [{name, url}]`；旧 `port` 参数保留兼容，不影响 HTTP 检查）、`DELETE /api/health/ips/{id}`。

Cookie 会话的写 API（登录除外）需要 `X-CSRF-Token`；已授权的 Bearer 调用按 scope 校验。配置、诊断、备份与恢复只允许管理员 Session。登录及 `GET /api/me` 返回 `csrf_token`，前端自动传入。各项目实例的 Session 已隔离，升级后需重新登录。

验证命令：

```bash
php tests/health_test.php
php tests/provider_test.php
node --test tests/api_test.mjs
```

## 环境要求

- PHP 7.4+（推荐 8.x，需 curl、mbstring 扩展，`php -m` 可查）

## 启动

```bash
php -S 127.0.0.1:8080 -t public public/index.php
```

> 注意：必须传入路由脚本 `public/index.php`（作为 php -S 的 router），否则带点的域名路径（如 `app.example.com`）会被内置服务器误判为静态文件返回 404。

浏览器打开 http://localhost:8080 。新安装直接在页面设置管理员账号和密码，提交后自动完成初始化并登录，无需运行额外命令；已有安装使用原管理员账号密码登录。

也可直接运行：`./start.sh`

## 目录结构

```
domain-manager/
├── public/            # 入口 index.php + 静态资源 + 页面视图
├── app/
│   ├── core/          # Router / JsonStore / Auth / HttpClient / Logger
│   ├── providers/     # 云商对接：阿里云 / 腾讯云 / GoDaddy
│   ├── services/      # 业务服务（同步等）
│   └── controllers/   # API 控制器
├── config/config.php  # 云商密钥 + 登录账号（已 gitignore）
├── data/              # JSON 文件数据库（运行时生成，已 gitignore）
└── start.sh           # 一键启动脚本
```

## API 一览

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | /api/login/captcha | 获取一次性图形验证码和登录 CSRF Token |
| POST | /api/login | 账号、密码与图形验证码登录，需保留 Cookie 并传 CSRF |
| POST | /api/logout | 登出 |
| GET  | /api/me | 当前登录态 |
| GET  | /api/accounts | 账号列表（密钥脱敏，含备注和启用状态） |
| DELETE | /api/accounts/{id} | 删除云账号配置，保留已有资产与缓存 |
| POST | /api/accounts/{id}/test | 测试单个账号密钥 |
| POST | /api/test-all | 测试全部账号 |
| POST | /api/sync | 同步全部账号域名（增量合并） |
| GET  | /api/sync/logs?limit=50 | 同步日志 |
| GET  | /api/domains?provider=&q=&valid=&sort=&expiring= | 域名列表（支持过滤/搜索/排序/到期筛选） |
| GET  | /api/domains/stats | 仪表盘统计 |
| POST | /api/domains | 新增域名（手动添加） |
| PUT  | /api/domains | 编辑域名（备注/状态/到期时间等） |
| DELETE | /api/domains | 删除域名（仅本地记录） |
| GET  | /api/domains/{provider}/{domain}/records | 列出 DNS 解析记录 |
| POST | /api/domains/{provider}/{domain}/records | 新增解析记录 |
| PUT  | /api/domains/{provider}/{domain}/records/{id} | 修改解析记录 |
| DELETE | /api/domains/{provider}/{domain}/records/{id} | 删除解析记录 |
| POST | /api/access-check | 执行全量访问检测（并发探测，结果持久化） |
| GET  | /api/access-check | 上次访问检测结果（正常/异常汇总） |
| GET  | /api/domains?access=fail | "无法访问"专题（基于最近一次检测） |
| GET  | /env-check | 环境自检页；初始化后仅管理员会话 |
| GET  | /api/env-check | 初始化前仅基础安装提示，初始化后需管理员会话 |
| GET  | /api/maintenance | 版本、备份列表、资产数量、交付自检与基础操作记录 |
| POST | /api/maintenance/backups | 创建资产备份，最多保留 30 份 |
| GET / DELETE | /api/maintenance/backups/{id} | 下载 / 删除指定服务器备份 |
| GET  | /api/maintenance/export?format=json | 导出资产；format 支持 json / csv |
| POST | /api/maintenance/preview | 从 backup_id 或 JSON content 预览全量恢复 |
| POST | /api/maintenance/restore | 验证密码和预览后恢复，自动创建留底备份 |

## 虚拟主机部署

1. 将整个项目上传到主机（用二进制模式，保证 `config/config.php` 完整）
2. 网站根目录指向项目的 `public` 目录（或把 `public` 下的文件放到主机网站根目录，并保持 `../app`、`../config`、`../data` 的相对结构不变）
3. 初始化前的环境检查仅提供 PHP 最低要求与扩展提示；初始化后登录管理员，再进入“产品设置 → 数据与交付”查看部署自检。无法登录时由维护人员检查服务器错误日志。

## 安全提醒

- `config/config.php` 包含真实密钥，已被 `.gitignore` 排除，请勿提交或外发
- 建议定期轮换云商 AccessKey
- 初始化后的环境诊断、备份、导出和恢复仅管理员 Session 可用；Bearer 密钥不能调用，写入还需 CSRF。生产配置参考 [Nginx 示例](deploy/nginx.conf.example)，网站根目录严格指向 public，不能公开整个项目。

## 网络问题（GoDaddy 连不上）

如果测试 GoDaddy 时提示"网络请求失败 / Connection timed out"，通常是本地网络屏蔽了 `api.godaddy.com`（可先 `curl https://api.github.com` 验证境外连通性）。解决方式：

1. 在 `config/config.php` 的 `http.proxy` 中填入本地代理地址，例如 `'http://127.0.0.1:7890'`（Clash / V2Ray 等工具的默认端口）
2. 或换一个可正常访问 GoDaddy 的网络环境运行

### 从健康看板进入选定域名关系图

在健康列表勾选域名，点击“查看选中域名关系图”。关联统计和图表仅包含选中的域名，选定范围保存在 URL 的 `domains` 参数。点击域名节点或“关系详情”，在原关系图中展开“主域名 → 主机/子域名 → IP/服务器”；可返回选定域名总图，或打开子域名检查与解析编辑。点击“返回全部资产”解除范围限制。筛选和图表展开复用已加载快照。

### 域名管理彩色 Tab 与多选

域名列表的云账号、资产状态、到期、健康状态和排序均改为可组合的彩色点击 Tab，保留文本搜索。筛选在浏览器缓存内完成。列表支持逐项或选择当前结果中的全部有效域名；切换筛选时保留选择，点击“查看选中域名关系图”进入同一套限定范围关系图。已失效台账不参与当前承载关系图。

### 全站搜索与筛选样式

健康看板、资产关系和 IP 资产沿用域名管理的搜索框及彩色选项样式。紧凑选项不超过 8 项时直接显示为点击 Tab，超过 8 项保留下拉；动态弹窗中的服务商、DNS 记录类型同样适配。IP 列表可组合筛选 IPv4 / IPv6、健康状态和关键词。筛选复用已加载缓存，不触发云端同步。

### 默认视图与弹窗

健康看板、域名与服务器、域名管理和 IP 资产均默认展示紧凑操作栏、搜索与列表。更多筛选在“筛选”弹窗中选择；健康统计、域名统计、关联统计及资产关联图从右上角打开，统计数字加大显示。IP 新增使用弹窗，保存后自动返回列表。弹窗复用已加载数据，不请求云商。
