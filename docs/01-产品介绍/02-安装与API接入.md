# 产品配置与 AI API

## 首次启动

新安装不需要预先创建 `config/config.php`，也没有默认管理员密码。

1. 运行 `./start.sh`，或将网站根目录指向 `public/` 后直接访问站点。
2. 首次打开工作台时，页面自动进入管理员创建流程，只需填写管理员账号和密码。新密码为 12–72 字节，提交后自动保存配置并登录。
3. 初始化成功后进入“云账号”，添加阿里云、腾讯云或 GoDaddy 凭据；也可以先手工登记 IP。
4. 保存云账号后，点击测试连接，再明确点击同步域名。保存配置本身不会查询云商或修改 DNS。

无需通过服务器 CLI 生成初始化凭据。首次成功提交的账号成为管理员，初始化接口随即关闭；提交需要当前浏览器会话的 CSRF 验证，配置保存使用文件锁，避免并发初始化覆盖管理员。

已有 `config/config.php` 的安装会继续沿用账号、密码、云凭据和代理设置，直接进入登录。通过界面保存的字段存入 `data/settings.json`，原配置文件不被重写。

## 后续配置

- **云账号**：新增、编辑名称、更新凭据、启用 / 停用。编辑时凭据留空保留原值，云商类型与账号 ID 保持稳定。停用不删除原有域名和缓存。
- **产品设置 → 工作台配置**：维护云接口代理、请求 / 连接超时，以及管理员账号和密码。
- **产品设置 → AI API**：创建有有效期、分权限的 API 密钥，查看已有授权，随时撤销。
- **产品设置 → 数据与交付**：创建资产备份，导出 JSON / CSV，预览后恢复，查看环境自检与基础操作记录，下载交付摘要。使用步骤与升级边界见 [备份恢复与版本交付](../04-交付与维护/04-备份恢复与版本交付.md)。

管理员密码使用 `password_hash` 保存。云凭据保存在站点目录之外的私有 JSON 文件中，不通过查询接口回显。新配置、API 密钥哈希文件和锁文件使用 0600 权限；API 密钥明文只在创建响应中返回一次。

修改管理员登录信息必须验证当前密码，完成后需要重新登录；其他 Session 和旧 API 密钥同时失效。当前版本是单管理员工作台，API 密钥提供不同操作权限。

## AI 接入

给支持 OpenAPI 的 AI 工具提供本机工作台地址：

- 接口契约：`GET /api/openapi.json`（OpenAPI 3.1）
- 能力发现：`GET /.well-known/asset-workspace.json`，也可用 `/api/capabilities`
- AI 操作说明：`GET /llms.txt`

认证选择 Bearer Token，在产品设置创建并保存密钥。示例中的环境变量由调用方提供：

```sh
export WORKSPACE_URL='http://127.0.0.1:8080'
# WORKSPACE_API_TOKEN 使用你在产品设置创建的密钥。
curl "$WORKSPACE_URL/api/health" \
  -H "Authorization: Bearer $WORKSPACE_API_TOKEN"

python3 scripts/workspace-api.py GET /api/domains
python3 scripts/workspace-api.py GET /api/health
```

提供给云端 AI 的服务器地址需要能从该工具的运行环境访问；`127.0.0.1` 仅供运行在同一台机器的 AI / 脚本使用。远程部署应通过 HTTPS 提供服务。
反向代理或 FastCGI 部署需要将 `Authorization` 请求头转发给 PHP，供 Bearer 鉴权读取。

### 授权范围

| scope | 能力 |
| --- | --- |
| `assets:read` | 读取域名、IP、关联健康、统计和同步记录 |
| `assets:write` | 增改删本地资产和人工绑定 |
| `dns:read` | 读取并缓存 DNS 记录，显式刷新记录 |
| `dns:write` | 云端 DNS 增改删 |
| `checks:run` | 执行健康与访问检查 |
| `sync:run` | 同步云端域名及测试账号连接 |

默认勾选 `assets:read` 和 `dns:read`。密钥最长有效期 365 天。配置管理、登录信息修改和 API 密钥管理仅限管理员 Session，Bearer Token 不能创建更高权限的密钥。

`/api/maintenance` 及其子接口也只允许管理员 Session，写入需 CSRF。初始化后 `/api/env-check` 和 `/env-check` 同样需管理员会话；不会公开服务器路径。完整接口契约包含这些管理接口，但它们不向 Bearer 开放。

### 账号密码登录 API

先 `GET /api/login/captcha`，保留响应 Cookie，将 `image` 显示给使用者，取得 `captcha_id` 与 `csrf_token`。再 `POST /api/login`，携带相同 Cookie、`X-CSRF-Token` 和 JSON `{"user":"账号","pass":"密码","captcha_id":"图片标识","captcha":"人工输入的验证码"}`。

验证码 120 秒有效、不区分大小写，一次提交即失效，失败后需重新获取。成功返回新的 `csrf_token` 并轮换 Session Cookie；后续 Session 写请求携带新 Cookie 和 Token。超限返回 HTTP 429、`retry_after` 和 `Retry-After`，等待后重新获取图片。完整规则见 [登录防护与限流](../04-交付与维护/05-登录防护与限流.md)。Bearer 请求不需要验证码、Cookie 或 CSRF。

Python 客户端支持这个流程：

```sh
# 交互式读取密码，按提示打开临时图片文件并人工输入验证码。
python3 scripts/workspace-api.py GET /api/accounts --username admin

# 自动化配置 WORKSPACE_API_TOKEN；密码模式不支持无人值守登录。
# 修改请求体可从文件或 stdin 读取：
python3 scripts/workspace-api.py PUT /api/health/ips/ASSET_ID --json @asset-update.json
```

修改登录信息的接口是 `PUT /api/auth/credentials`，请求体为 `current_password`、`user`、`password`。成功后响应 `requires_login:true`，必须重新登录。密码没有读取接口。

### 查询和修改 DNS

先读取 `/api/domains` 获得域名和 `provider`（云账号 ID，例如 `aliyun_1`），再读取 `/api/domains/{provider}/{domain}/records` 获得记录 ID。`provider` 不能用 `aliyun` 这类云商类型代替。

1.2.0 起，编辑记录时调用 `PUT /api/domains/{provider}/{domain}/records/{recordId}`，提交目标记录的 `version` 为 `expected_version`，以及需要修改的字段。省略的字段保留原值；删除也需 JSON `expected_version`。新增需列表响应的 `revision` 作为 `expected_revision`。接口真正修改云端 DNS，需要 `dns:write`。

提交前展示差异，服务器读取云端核对旧版本，冲突返回 409。写入后回读；HTTP 202 或 `verified:false` 表示待核实，不能直接重复提交。仅 `verified:true` 确认云端回读一致，不能据此判断全球解析已传播。管理员在 `/api/dns/changes` 查看前后值，Bearer 不能读取历史。详细规则见 [DNS 变更与核实](../04-交付与维护/06-DNS变更与核实.md)。日常查询使用缓存；`refresh=1` 才显式刷新已有云端快照，写入的前后核对总是读取云端。

调用健康检查 `POST /api/health/check` 时，`asset_ids` 每批最多 5 项。IP 请求 `http://IP/`（IPv6 加方括号），HTTP 2xx / 3xx 视为正常，不继续跟随重定向；该结果不代表关联网站的业务一定正常。`unchecked` 和 `stale` 不能计为健康。DNS 观察线索与人工确认的源站绑定应分别解释。

所有客户端同时检查 HTTP 状态与响应 `ok:false`，部分历史云接口仍以 HTTP 200 返回业务失败。新增配置接口的验证错误为 422，无登录 / 无效 Token 为 401，权限不足或 CSRF 无效为 403。

## 验证

以下命令在源码仓库中执行。发行包不含合成测试夹具；客户部署验收按交付维护说明进行。

```sh
php tests/settings_test.php
php tests/maintenance_test.php
php tests/sync_state_test.php
php tests/auth_test.php
php tests/dns_changes_test.php
php tests/dns_pagination_test.php
python3 tests/auth_api_test.py
python3 tests/dns_changes_api_test.py
python3 tests/maintenance_api_test.py
python3 tests/product_api_test.py
node --test tests/api_test.mjs tests/client_cache_test.mjs tests/relationship_graph_test.mjs tests/settings_ui_test.mjs tests/setup_ui_test.mjs
node --test tests/maintenance_ui_test.mjs
node --test tests/dns_changes_ui_test.mjs tests/login_ui_test.mjs
python3 -m unittest discover -s tests -p 'package_release_test.py'
```

产品 HTTP 测试会创建独立安装、假凭据和临时服务，覆盖首次配置、配置编辑、密码登录、Bearer 权限、密钥撤销、密码修改后的失效、重启持久化和旧配置兼容，不访问真实云商。
