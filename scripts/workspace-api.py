#!/usr/bin/env python3
"""Small dependency-free client for AI tools, scripts and administrator sessions."""
import argparse
import getpass
import http.cookiejar
import json
import os
from pathlib import Path
import sys
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, new_url):
        raise urllib.error.HTTPError(request.full_url, code, "API redirect refused; use the final server origin", headers, fp)


def main():
    parser = argparse.ArgumentParser(description="Call Asset Workspace using an API token or password session.")
    parser.add_argument("method", choices=["GET", "POST", "PUT", "DELETE"])
    parser.add_argument("path", help="Relative API path, such as /api/health")
    parser.add_argument("--base-url", default=os.environ.get("WORKSPACE_URL", "http://127.0.0.1:8080"))
    parser.add_argument("--username", default=os.environ.get("WORKSPACE_USER"), help="Use password login instead of bearer auth")
    parser.add_argument("--json", dest="payload", help="JSON string, @file, or - for stdin")
    args = parser.parse_args()
    base = urllib.parse.urlsplit(args.base_url)
    if base.scheme not in ["http", "https"] or not base.netloc or base.username or base.password or base.query or base.fragment or base.path not in ["", "/"]:
        parser.error("--base-url must be an HTTP(S) origin without embedded credentials")
    if not args.path.startswith("/api/") or urllib.parse.urlsplit(args.path).netloc or "#" in args.path:
        parser.error("path must start with /api/ and stay on the configured server")
    origin = args.base_url.rstrip("/")
    opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(method, path, body=None, headers=None):
        response = opener.open(urllib.request.Request(origin + path,
            data=None if body is None else json.dumps(body).encode("utf-8"),
            headers={"Accept": "application/json", "Content-Type": "application/json", **(headers or {})}, method=method), timeout=120)
        data = json.load(response)
        if isinstance(data, dict) and data.get("ok") is False:
            raise RuntimeError(data.get("error", "API rejected the operation"))
        return data

    authorization = {}
    if args.username:
        password = os.environ.get("WORKSPACE_PASSWORD") or getpass.getpass("Workspace password: ")
        login = request("POST", "/api/login", {"user": args.username, "pass": password})
        authorization["X-CSRF-Token"] = login["csrf_token"]
    elif os.environ.get("WORKSPACE_API_TOKEN"):
        authorization["Authorization"] = "Bearer " + os.environ["WORKSPACE_API_TOKEN"]
    elif args.path.split("?")[0] not in ["/api/openapi.json", "/api/capabilities", "/api/setup/status"]:
        parser.error("Set WORKSPACE_API_TOKEN, or provide WORKSPACE_USER / WORKSPACE_PASSWORD for session login")
    body = None
    if args.payload is not None:
        raw = sys.stdin.read() if args.payload == "-" else Path(args.payload[1:]).read_text() if args.payload.startswith("@") else args.payload
        body = json.loads(raw)
    print(json.dumps(request(args.method, args.path, body, authorization), ensure_ascii=False, indent=2))


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
