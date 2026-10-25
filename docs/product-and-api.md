# 产品配置与 AI API

## 首次启动

新安装不需要预先创建 `config/config.php`，也没有默认管理员密码。

1. 运行 `./start.sh`，终端会显示一次性初始化凭据；也可单独运行 `php scripts/setup-key.php` 获取。
2. 打开工作台，首次配置页提示输入初始化凭据、管理员账号和密码。新密码为 12–72 字节。
3. 初始化成功后进入“云账号”，添加阿里云、腾讯云或 GoDaddy 凭据；也可以先手工登记 IP。
4. 保存云账号后，点击测试连接，再明确点击同步域名。保存配置本身不会查询云商或修改 DNS。

初始化凭据证明操作者可以访问安装服务器，HTTP 不会返回它。初始化成功后凭据失效，初始化接口关闭。虚拟主机部署时，网站根目录仍须指向 `public/`；在服务器 CLI 中执行上述初始化命令。

已有 `config/config.php` 的安装会继续沿用账号、密码、云凭据和代理设置，直接进入登录。通过界面保存的字段存入 `data/settings.json`，原配置文件不被重写。

## 后续配置

- **云账号**：新增、编辑名称、更新凭据、启用 / 停用。编辑时凭据留空保留原值，云商类型与账号 ID 保持稳定。停用不删除原有域名和缓存。
- **产品设置 → 工作台配置**：维护云接口代理、请求 / 连接超时，以及管理员账号和密码。
- **产品设置 → AI API**：创建有有效期、分权限的 API 密钥，查看已有授权，随时撤销。

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

### 账号密码登录 API

`POST /api/login` 支持 JSON `{"user":"账号","pass":"密码"}`，返回登录 Cookie 与 `csrf_token`。后续 Session 写请求携带 Cookie 和 `X-CSRF-Token`。Bearer 请求不需要 Cookie 或 CSRF。

Python 客户端支持这个流程：

```sh
# 交互式读取密码，不把密码放在命令参数或 URL 中。
python3 scripts/workspace-api.py GET /api/accounts --username admin

# 自动化也可通过 WORKSPACE_USER / WORKSPACE_PASSWORD 环境变量提供凭据。
# 修改请求体可从文件或 stdin 读取：
python3 scripts/workspace-api.py PUT /api/health/ips/ASSET_ID --json @asset-update.json
```

修改登录信息的接口是 `PUT /api/auth/credentials`，请求体为 `current_password`、`user`、`password`。成功后响应 `requires_login:true`，必须重新登录。密码没有读取接口。

### 查询和修改 DNS

先读取 `/api/domains` 获得域名和 `provider`（云账号 ID，例如 `aliyun_1`），再读取 `/api/domains/{provider}/{domain}/records` 获得记录 ID。`provider` 不能用 `aliyun` 这类云商类型代替。

编辑记录时调用 `PUT /api/domains/{provider}/{domain}/records/{recordId}`，提供 `type`、`name`、`value`、`ttl`、`priority`、`line`，保留不需要修改的字段。该接口真正修改云端 DNS，需要 `dns:write`。查询使用缓存；`refresh=1` 才显式刷新已有云端快照。

调用健康检查 `POST /api/health/check` 时，`asset_ids` 每批最多 5 项。IP 端口可达不代表应用正常；`unchecked` 和 `stale` 不能计为健康。DNS 观察线索与人工确认的源站绑定应分别解释。

所有客户端同时检查 HTTP 状态与响应 `ok:false`，部分历史云接口仍以 HTTP 200 返回业务失败。新增配置接口的验证错误为 422，无登录 / 无效 Token 为 401，权限不足或 CSRF 无效为 403。

## 验证

```sh
php tests/settings_test.php
python3 tests/product_api_test.py
node --test tests/api_test.mjs tests/client_cache_test.mjs tests/relationship_graph_test.mjs tests/settings_ui_test.mjs
```

产品 HTTP 测试会创建独立安装、假凭据和临时服务，覆盖首次配置、配置编辑、密码登录、Bearer 权限、密钥撤销、密码修改后的失效、重启持久化和旧配置兼容，不访问真实云商。
