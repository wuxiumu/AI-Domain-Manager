#!/usr/bin/env python3
"""Small dependency-free client for AI tools, scripts and administrator sessions.

Semantic commands (automation-friendly):
  bootstrap          one-shot onboarding self-check (instructions + contract + both view snapshots)
  screen             GET /api/domains/screen           domain wall-display snapshot (JSON)
  ips                GET /api/ips/screen               IP view snapshot (JSON)
  records            GET /api/domains/{provider}/{domain}/records
  report             GET /api/domains/screen/report?format=markdown  (raw Markdown to stdout)
  propose            POST /api/proposals               submit a change proposal for approval

Raw mode (kept for compatibility): workspace-api.py <METHOD> </api/path> [--json ...]
"""
import argparse
import base64
import getpass
import http.cookiejar
import json
import os
from pathlib import Path
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, new_url):
        raise urllib.error.HTTPError(request.full_url, code, "API redirect refused; use the final server origin", headers, fp)


class Client:
    """Minimal origin-locked JSON client; token auth via WORKSPACE_API_TOKEN, or interactive password login."""

    def __init__(self, origin, parser, username=None):
        base = urllib.parse.urlsplit(origin)
        if base.scheme not in ["http", "https"] or not base.netloc or base.username or base.password or base.query or base.fragment or base.path not in ["", "/"]:
            parser.error("--base-url must be an HTTP(S) origin without embedded credentials")
        self.origin = origin.rstrip("/")
        self.parser = parser
        self.opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.authorization = {}
        if username:
            if not sys.stdin.isatty():
                parser.error("Password login requires an interactive image captcha; set WORKSPACE_API_TOKEN for automation")
            password = os.environ.get("WORKSPACE_PASSWORD") or getpass.getpass("Workspace password: ")
            challenge = self.request("GET", "/api/login/captcha")
            with tempfile.TemporaryDirectory(prefix="workspace-captcha-") as directory:
                image = Path(directory) / "captcha.svg"
                image.write_bytes(base64.b64decode(challenge["image"].split(",", 1)[1], validate=True))
                image.chmod(0o600)
                print(f"Open the local captcha image (valid for 2 minutes): {image}", file=sys.stderr)
                answer = input("Captcha: ").strip()
            login = self.request("POST", "/api/login", {"user": username, "pass": password,
                "captcha_id": challenge["captcha_id"], "captcha": answer}, {"X-CSRF-Token": challenge["csrf_token"]})
            self.authorization["X-CSRF-Token"] = login["csrf_token"]
        elif os.environ.get("WORKSPACE_API_TOKEN"):
            self.authorization["Authorization"] = "Bearer " + os.environ["WORKSPACE_API_TOKEN"]

    def require_token(self, purpose):
        if not self.authorization.get("Authorization"):
            self.parser.error(f"{purpose} requires WORKSPACE_API_TOKEN (assets:read or wider)")

    def request(self, method, path, body=None, headers=None):
        response = self.opener.open(urllib.request.Request(self.origin + path,
            data=None if body is None else json.dumps(body).encode("utf-8"),
            headers={"Accept": "application/json", "Content-Type": "application/json", **(headers or {})}, method=method), timeout=120)
        data = json.load(response)
        if isinstance(data, dict) and data.get("ok") is False:
            raise RuntimeError(data.get("error", "API rejected the operation"))
        return data

    def text(self, method, path):
        response = self.opener.open(urllib.request.Request(self.origin + path, method=method), timeout=120)
        return response.read().decode("utf-8")


def cmd_bootstrap(client, parser):
    """One-shot onboarding self-check: every AI entry point must answer with the expected content."""
    client.require_token("bootstrap")
    checks = []
    try:
        document = client.text("GET", "/api/ai/bootstrap.md")
        for marker, label in [("AI Bootstrap", "bootstrap 文档头"), ("使用说明（与 GET /llms.txt 内容一致）", "llms.txt 部分"),
                              ("写护栏速查", "写护栏速查"), ("域名资产大屏 · Markdown 快照", "域名视图快照"),
                              ("IP 资产大屏 · Markdown 快照", "IP 视图快照")]:
            checks.append((label, marker in document))
        openapi = client.request("GET", "/api/openapi.json")
        paths = len(openapi.get("paths") or {})
        checks.append((f"OpenAPI 契约（{paths} 条路径）", paths > 0))
        discovery = client.request("GET", "/api/capabilities")
        checks.append((f"能力发现（版本 {discovery.get('version', '?')}）", bool(discovery.get("name"))))
        screen = client.request("GET", "/api/domains/screen")
        checks.append((f"域名视图快照（{len(screen.get('domains', []))} 个域名）", screen.get("ok") is True))
        ips = client.request("GET", "/api/ips/screen")
        checks.append((f"IP 视图快照（{len(ips.get('ips', []))} 个 IP）", ips.get("ok") is True))
    except (urllib.error.HTTPError, RuntimeError, OSError) as error:
        checks.append(("请求执行", False))
        print(f"  最后错误：{error}", file=sys.stderr)
    print("Asset Workspace 接入自检：")
    ok = True
    for label, passed in checks:
        ok = ok and passed
        print(f"  [{'✓' if passed else '✗'}] {label}")
    print(f"  文档总大小：{len(document) if 'document' in dir() else 0} 字节")
    print("接入完成。将上方 ✓ 清单对应的入口交给 AI 即可开始使用（写操作请走提案流 proposals:write）。")
    return 0 if ok else 1


def main():
    parser = argparse.ArgumentParser(description="Call Asset Workspace using an API token or password session.")
    parser.add_argument("command", help="语义命令（bootstrap/screen/ips/records/report/propose）或 HTTP 方法（GET/POST/PUT/DELETE 原始模式）")
    parser.add_argument("path", nargs="?", help="原始模式：相对 API 路径，如 /api/domains/screen")
    parser.add_argument("--base-url", default=os.environ.get("WORKSPACE_URL", "http://127.0.0.1:8080"))
    parser.add_argument("--username", default=os.environ.get("WORKSPACE_USER"), help="Interactive password login with a human-entered image captcha; automation should use an API token")
    parser.add_argument("--json", dest="payload", help="JSON string, @file, or - for stdin")
    parser.add_argument("--provider", help="records 子命令：云账号 ID（GET /api/accounts 可查）")
    parser.add_argument("--domain", help="records 子命令：域名")
    args = parser.parse_args()
    client = Client(args.base_url, parser, args.username)
    method = args.command.upper()
    if method in ["GET", "POST", "PUT", "DELETE"]:
        if not args.path or not args.path.startswith("/api/") or urllib.parse.urlsplit(args.path).netloc or "#" in args.path:
            parser.error("原始模式的 path 必须以 /api/ 开头且 stay on the configured server")
        if not client.authorization.get("Authorization") and not client.authorization.get("X-CSRF-Token") \
                and args.path.split("?")[0] not in ["/api/openapi.json", "/api/capabilities", "/api/setup/status", "/api/ai/bootstrap.md"]:
            parser.error("Set WORKSPACE_API_TOKEN, or use --username for interactive password and captcha login")
        body = None
        if args.payload is not None:
            raw = sys.stdin.read() if args.payload == "-" else Path(args.payload[1:]).read_text() if args.payload.startswith("@") else args.payload
            body = json.loads(raw)
        print(json.dumps(client.request(method, args.path, body, client.authorization), ensure_ascii=False, indent=2))
        return
    if args.command == "bootstrap":
        sys.exit(cmd_bootstrap(client, parser))
    if args.command in ["screen", "ips", "report", "records", "propose"]:
        read_only = args.command != "propose"
        if read_only:
            client.require_token({"screen": "screen", "ips": "ips", "report": "report", "records": "records"}[args.command])
        if args.command == "screen":
            print(json.dumps(client.request("GET", "/api/domains/screen"), ensure_ascii=False, indent=2))
        elif args.command == "ips":
            print(json.dumps(client.request("GET", "/api/ips/screen"), ensure_ascii=False, indent=2))
        elif args.command == "report":
            sys.stdout.write(client.text("GET", "/api/domains/screen/report?format=markdown"))
        elif args.command == "records":
            if not args.provider or not args.domain:
                parser.error("records 需要 --provider 与 --domain（provider 是云账号 ID，非厂商名）")
            print(json.dumps(client.request("GET", "/api/domains/" + urllib.parse.quote(args.provider, safe="")
                + "/" + urllib.parse.quote(args.domain, safe="") + "/records"), ensure_ascii=False, indent=2))
        elif args.command == "propose":
            if args.payload is None:
                parser.error('propose 需要 --json，形如 {"account":"<provider>","domain":"example.com","action":"add|update|delete",'
                    '"record_id":"<update/delete 必填>","record":{"type":"A","name":"@","value":"192.0.2.10"},'
                    '"rationale":"4-500 字变更原因"}')
            raw = sys.stdin.read() if args.payload == "-" else Path(args.payload[1:]).read_text() if args.payload.startswith("@") else args.payload
            print(json.dumps(client.request("POST", "/api/proposals", json.loads(raw), client.authorization), ensure_ascii=False, indent=2))
        return
    parser.error("command 必须是语义命令（bootstrap/screen/ips/records/report/propose）或 HTTP 方法")


if __name__ == "__main__":
    try:
        main()
    except urllib.error.HTTPError as error:
        try:
            detail = json.load(error).get("error", str(error))
        except (ValueError, AttributeError):
            detail = str(error)
        print(f"HTTP {error.code}: {detail}", file=sys.stderr)
        sys.exit(1)
    except (ValueError, OSError, RuntimeError, KeyError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
