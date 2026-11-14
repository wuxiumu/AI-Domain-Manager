#!/usr/bin/env python3
"""Asset Workspace MCP server (stdio transport, zero-dependency).

Exposes the wall-display snapshots, cached DNS records, the daily report and the
doc whitelist as MCP tools, plus the guarded proposal flow. Configuration via
environment: WORKSPACE_URL (server origin), WORKSPACE_API_TOKEN (bearer token;
read tools need assets:read, propose_dns_change needs proposals:write — the
server rejects calls without the matching scope).
"""
import importlib.util
import json
import os
import sys
import urllib.error
import urllib.parse

# 复用同目录的 workspace-api.py（连字符文件名，需显式按路径加载其最小 HTTP 客户端）
_spec = importlib.util.spec_from_file_location("workspace_api",
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "workspace-api.py"))
_workspace_api = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_workspace_api)
Client = _workspace_api.Client

PROTOCOL_VERSION = "2025-03-26"
KNOWN_VERSIONS = ["2025-03-26", "2024-11-05"]


class McpClient(Client):
    """workspace_api.Client whose missing-token case becomes a tool error instead of a CLI exit."""

    def require_token(self, purpose):
        if not self.authorization.get("Authorization"):
            raise RuntimeError(f"缺少 WORKSPACE_API_TOKEN（{purpose} 需要相应 scope，请在 /settings 创建并配置环境变量）")


class ShimParser:
    """workspace_api.Client calls parser.error on invalid config; surface it as a runtime error."""

    @staticmethod
    def error(message):
        raise RuntimeError(message)


TOOLS = [
    {"name": "get_domain_screen", "description": "读取域名资产大屏快照：未过期域名、子域名 A 记录、访问状态、到期与账号分组。缓存快照，不触发云请求。",
     "inputSchema": {"type": "object", "properties": {}}},
    {"name": "get_ip_screen", "description": "读取 IP 视图快照：公网 IP（DNS 自动发现 + 手工登记）、归属地、访问状态、关联域名。",
     "inputSchema": {"type": "object", "properties": {}}},
    {"name": "list_dns_records", "description": "读取某域名的缓存 DNS 记录与记录 ID（provider 是云账号 ID，不是厂商名）。",
     "inputSchema": {"type": "object", "properties": {
         "provider": {"type": "string", "description": "云账号 ID"},
         "domain": {"type": "string", "description": "域名，如 example.com"}},
         "required": ["provider", "domain"]}},
    {"name": "propose_dns_change", "description": "提交 DNS 变更提案（需要 proposals:write scope；管理员批准后才执行，不直接写云端）。重复待审提案会被拒绝。",
     "inputSchema": {"type": "object", "properties": {
         "account": {"type": "string", "description": "云账号 ID"},
         "domain": {"type": "string", "description": "域名"},
         "action": {"type": "string", "enum": ["add", "update", "delete"]},
         "record_id": {"type": "string", "description": "update / delete 必填；来自 list_dns_records"},
         "record": {"type": "object", "description": '目标记录，如 {"type":"A","name":"@","value":"192.0.2.10"}'},
         "rationale": {"type": "string", "description": "4–500 字变更原因"},
         "evidence": {"type": "string", "description": "可选：报告行或检查引用"}},
         "required": ["account", "domain", "action", "rationale"]}},
    {"name": "get_daily_report", "description": "读取每日异常报告（Markdown）：临期域名、访问失败、冷缓存、非公网 A 值、共享 IP。",
     "inputSchema": {"type": "object", "properties": {}}},
    {"name": "list_docs", "description": "列出仓库文档白名单（README / AGENTS / CHANGELOG / tasks.md 等）的路径、标题与更新时间。",
     "inputSchema": {"type": "object", "properties": {}}},
]

DOC_LIST = "/api/docs"


def http_detail(error):
    try:
        payload = json.load(error)
        detail = payload.get("error", str(error)) if isinstance(payload, dict) else str(error)
    except (ValueError, AttributeError):
        detail = str(error)
    return f"HTTP {error.code}: {detail}"


def call_tool(name, arguments, client):
    """执行一个工具；返回 (payload, is_text)。抛出异常时由调用方转为 isError 结果。"""
    if name == "get_domain_screen":
        client.require_token("get_domain_screen")
        return client.request("GET", "/api/domains/screen"), False
    if name == "get_ip_screen":
        client.require_token("get_ip_screen")
        return client.request("GET", "/api/ips/screen"), False
    if name == "list_dns_records":
        client.require_token("list_dns_records")
        provider = (arguments or {}).get("provider")
        domain = (arguments or {}).get("domain")
        if not provider or not domain:
            raise RuntimeError("缺少参数：provider（云账号 ID）与 domain 必填")
        return client.request("GET", "/api/domains/" + urllib.parse.quote(str(provider), safe="")
            + "/" + urllib.parse.quote(str(domain), safe="") + "/records"), False
    if name == "propose_dns_change":
        client.require_token("propose_dns_change")
        args = dict(arguments or {})
        missing = [field for field in ["account", "domain", "action", "rationale"] if not args.get(field)]
        if missing:
            raise RuntimeError("缺少必填参数：" + "、".join(missing)
                + '；形如 {"account":"<provider>","domain":"example.com","action":"update",'
                '"record_id":"<id>","record":{"type":"A","name":"@","value":"192.0.2.10"},"rationale":"原因"}')
        if args.get("action") in ["update", "delete"] and not args.get("record_id"):
            raise RuntimeError("action 为 update / delete 时 record_id 必填（来自 list_dns_records）")
        payload = {"account": args["account"], "domain": args["domain"], "action": args["action"],
            "rationale": args["rationale"]}
        if args.get("record_id"):
            payload["record_id"] = args["record_id"]
        if args.get("record"):
            payload["record"] = args["record"]
        if args.get("evidence"):
            payload["evidence"] = args["evidence"]
        return client.request("POST", "/api/proposals", payload), False
    if name == "get_daily_report":
        client.require_token("get_daily_report")
        return client.text("GET", "/api/domains/screen/report?format=markdown"), True
    if name == "list_docs":
        client.require_token("list_docs")
        data = client.request("GET", DOC_LIST)
        docs = data.get("docs") or []
        lines = ["| 路径 | 标题 | 更新时间 |", "| --- | --- | --- |"]
        lines += ["| " + str(d.get("path", "")) + " | " + str(d.get("title", "")) + " | " + str(d.get("updated_at", "")) + " |" for d in docs]
        return "\n".join(lines), True
    raise KeyError(name)


def main():
    try:
        client = McpClient(os.environ.get("WORKSPACE_URL", "http://127.0.0.1:8080"), ShimParser(),
            os.environ.get("WORKSPACE_USER") or None)
    except RuntimeError as error:
        print(f"配置错误：{error}", file=sys.stderr)
        return 1

    def send(message):
        sys.stdout.write(json.dumps(message, ensure_ascii=False) + "\n")
        sys.stdout.flush()

    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        try:
            message = json.loads(line)
        except ValueError:
            send({"jsonrpc": "2.0", "id": None, "error": {"code": -32700, "message": "Parse error"}})
            continue
        request_id = message.get("id")
        method = message.get("method", "")
        if request_id is None:
            continue  # 通知：无需应答（如 notifications/initialized）
        if method == "initialize":
            requested = message.get("params", {}).get("protocolVersion")
            send({"jsonrpc": "2.0", "id": request_id, "result": {
                "protocolVersion": requested if requested in KNOWN_VERSIONS else PROTOCOL_VERSION,
                "capabilities": {"tools": {"listChanged": False}},
                "serverInfo": {"name": "asset-workspace", "version": "1.0.0"}}})
        elif method == "ping":
            send({"jsonrpc": "2.0", "id": request_id, "result": {}})
        elif method == "tools/list":
            send({"jsonrpc": "2.0", "id": request_id, "result": {"tools": TOOLS}})
        elif method == "tools/call":
            params = message.get("params", {})
            name = params.get("name", "")
            try:
                payload, is_text = call_tool(name, params.get("arguments") or {}, client)
                text = payload if is_text else json.dumps(payload, ensure_ascii=False, indent=2)
                send({"jsonrpc": "2.0", "id": request_id, "result": {"content": [{"type": "text", "text": text}]}})
            except urllib.error.HTTPError as error:
                send({"jsonrpc": "2.0", "id": request_id, "result": {"content": [{"type": "text", "text": http_detail(error)}], "isError": True}})
            except (RuntimeError, KeyError, OSError, ValueError) as error:
                send({"jsonrpc": "2.0", "id": request_id, "result": {"content": [{"type": "text", "text": str(error)}], "isError": True}})
        else:
            send({"jsonrpc": "2.0", "id": request_id, "error": {"code": -32601, "message": "Method not found: " + method}})
    return 0


if __name__ == "__main__":
    sys.exit(main())
