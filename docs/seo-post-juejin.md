# 自托管域名工作台接入 AI：OpenAPI 3.1 + llms.txt 实操

> 适用于：掘金。面向开发者，技术实操向，保留完整代码与接口细节。

---

## 痛点：几十个域名散在三个云账号，你还在手工点控制台

做运维/站长的都懂：域名在阿里云、腾讯云、GoDaddy 分了好几个账号，查解析要登三个控制台，改条记录来回切，批量看哪些快到期只能翻表格。

工具多了，人没变轻松。

现在的 AI Agent 都能写代码、调 API 了，为什么不能**直接帮你把域名管起来**？能做到——前提是给工作台开一个「AI 能读懂、能调用」的口子。

本文讲一个自托管方案：让 AI 用自然语言直接查 DNS、看健康、改解析。自托管、密钥本地保存、权限可细分、操作可审计。

---

## 为什么是「自托管 + OpenAPI 契约」，而不是「AI 去点网页」

两个原因：

**1. 云控制台没有统一、好用的程序化接口。** 各云商 API 授权模型复杂、按账号隔离，AI 很难跨账号聚合操作。

**2. AI 操作网页又慢又脆。** 正确做法是给 AI 一份**机器可读的接口契约（OpenAPI）**，像调普通 API 一样精确可靠。这也是 AI Agent / MCP 类工具的标准路径。

---

## 一个「AI 原生」的自托管工作台：域名管家

以开源的**域名管家（Domain Manager）**为例：PHP 全栈自托管，聚合阿里 / 腾讯 / GoDaddy 多账号，自带健康看板、资产关系图、DNS 管理、到期提醒。它从设计上就做了 AI 接入，不是事后补丁。

对 AI 暴露三个端点：

| 端点 | 作用 |
|---|---|
| `GET /api/openapi.json` | **OpenAPI 3.1 接口契约**，AI 据此知道能调哪些接口 |
| `GET /llms.txt` | 面向 AI 的自然语言操作说明 |
| `GET /.well-known/asset-workspace.json`（或 `/api/capabilities`） | **能力发现**，工具自动识别工作台能力 |

鉴权走 **Bearer Token + 分权限密钥**，AI 只能用被授权的 scope。

---

## 实操三步

### 第 1 步：生成分权限 API 密钥

「产品设置 → AI API」创建密钥，scope 细分：

| scope | 能力 |
|---|---|
| `assets:read` / `assets:write` | 读 / 增改删本地资产与人工绑定 |
| `dns:read` / `dns:write` | 读缓存 / 云端 DNS 增改删 |
| `checks:run` | 执行健康与访问检查 |
| `sync:run` | 同步云端域名及测试账号 |

默认只勾 `assets:read` + `dns:read`——**AI 默认只读**。密钥最长 365 天，随时撤销。

### 第 2 步：配置并调用

```sh
export WORKSPACE_URL='http://127.0.0.1:8080'
export WORKSPACE_API_TOKEN='你在产品设置创建的密钥'

# 查所有域名
python3 scripts/workspace-api.py GET /api/domains
# 查健康状态
python3 scripts/workspace-api.py GET /api/health
```

或标准 Bearer curl：

```sh
curl "$WORKSPACE_URL/api/health" -H "Authorization: Bearer $WORKSPACE_API_TOKEN"
```

> 远程部署须走 HTTPS，并把 `Authorization` 头正确转发给 PHP；`127.0.0.1` 仅供同机调用。

### 第 3 步：让 AI 按「先查后改」规范操作

写入 `llms.txt` 的约定：

1. 先读 `/api/domains` 拿域名和 `provider`（云账号 ID，如 `aliyun_1`，不能用 `aliyun` 这类类型代替）。
2. 再读 `/api/domains/{provider}/{domain}/records` 拿记录 ID。
3. 改记录 `PUT /api/domains/{provider}/{domain}/records/{recordId}`，传 `type/name/value/ttl/priority/line`，**保留不需要改的字段**。此接口真正改云端 DNS，需 `dns:write`。

每次操作有据可查、可审计。

---

## 安全边界：AI 能操作资产，凭什么放心

- **最小权限 + 可撤销 + 有有效期**：默认只读，写操作显式授权。
- **密钥本地保存、不回显**：云凭据在站点目录外的私有 JSON，查询接口不回显；明文仅创建时返回一次。
- **写操作全程校验**：登录 Session + `X-CSRF-Token`；Bearer 权限受 scope 严格约束。
- **结果不误判**：约定 `unchecked` / `stale` 不计健康；客户端同时查 HTTP 状态与 `ok:false`（历史云接口可能 200 但业务失败）。

这套「最小权限 + 可撤销 + 可审计」，正是 AI 接入该有的样子。

---

## 收益与适合谁

- 查询秒回，不用登三个控制台
- 批量改多账号解析
- 结合资产关系图，让 AI 告诉你「这台上服务器挂了影响哪些网站」
- OpenAPI 契约人/程序/CI 都能复用

适合：个人站长、中小团队运维、代运维/建站服务商。

---

## 相关链接

- 项目仓库 / README：见仓库
- AI 接入完整配置：`docs/product-and-api.md`
- 要「定制部署 / 多人权限 / 通知」但不想自己折腾：见项目定制服务说明
