#!/usr/bin/env python3
"""契约防漂移检查：index.php 路由 ↔ Auth 鉴权声明 ↔ ApiDescriptionService 契约 三方一致。

不一致（删路由未删契约、加契约未声明 scope 等）时输出差异并以非零码退出。
"""
import argparse
import re
import sys
from pathlib import Path

DEFAULT_ROOT = Path(__file__).resolve().parents[1]
# 双入口别名：两者共用同一个 discovery 契约，契约侧只登记 /api/capabilities。
ALIAS_ROUTES = {"/.well-known/asset-workspace.json": "/api/capabilities"}


def routes_from_index(index_path):
    text = index_path.read_text()
    routes = set()
    for method, path in re.findall(r"\$router->(get|post|put|delete)\('([^']+)'", text):
        if not path.startswith(("/api/", "/.well-known")):
            continue
        routes.add(method.upper() + " " + ALIAS_ROUTES.get(path, path))
    return routes


def contracts_from_service(service_path):
    text = service_path.read_text()
    variables = dict(re.findall(r"\$(\w+)\s*=\s*'(/api[^']*)'", text))
    contracts = set()
    scoped = []
    # $add 调用以 8 空格缩进开头、彼此相邻；按该边界切段后逐段解析。
    segments = re.split(r"\n        \$add\(", text)[1:]
    for segment in segments:
        head = re.match(r"'(GET|POST|PUT|DELETE)',\s*([^,]+),\s*'([^']*)'", segment)
        if not head:
            continue
        method, raw_path, op_id = head.groups()
        path = ""
        for literal, variable in re.findall(r"'([^']*)'|\$(\w+)", raw_path):
            path += literal or variables.get(variable, "")
        if not path.startswith("/api"):
            continue
        access = re.findall(r"'(public|session|api)'", segment)
        contracts.add(method.upper() + " " + path)
        scoped.append((method.upper() + " " + path, access[-1] if access else "api", op_id))
    return contracts, scoped


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=DEFAULT_ROOT, help="仓库根目录（默认脚本所在仓库）")
    args = parser.parse_args()
    root = args.root

    routes = routes_from_index(root / "public/index.php")
    contracts, scoped = contracts_from_service(root / "app/services/ApiDescriptionService.php")
    auth_text = (root / "app/core/Auth.php").read_text()

    problems = []
    # 1) 路由必须都有契约、契约必须都有路由（删一侧漏另一侧即漂移）。
    missing_contracts = sorted(routes - contracts)
    missing_routes = sorted(contracts - routes)
    for entry in missing_contracts:
        problems.append("路由缺少 OpenAPI 契约：" + entry)
    for entry in missing_routes:
        problems.append("契约缺少实际路由：" + entry)

    # 2) scope 门禁（access=api）的契约必须在 authorizeRoute 有对应声明，
    #    /records 系列由 strpos('/records') 分支统一覆盖。
    for path, access, op_id in scoped:
        if access != "api":
            continue
        route_path = path.split(" ", 1)[1]
        covered = route_path in auth_text or ("/records" in route_path and "'/records'" in auth_text)
        if not covered:
            problems.append("契约声明的 scope 门禁在 authorizeRoute 缺失：" + path + " (" + op_id + ")")

    # 3) authorizeRoute 里的具体路由也应存在契约（反向漂移；strpos 分支不参与）。
    for route in re.findall(r"\$route === '([^']+)'", auth_text):
        if not route.startswith("/api"):
            continue
        if route not in contracts and not any(entry.split(" ", 1)[1] == route for entry in contracts):
            problems.append("authorizeRoute 声明的路由缺少契约：" + route)

    total = len(routes)
    print("路由 " + str(total) + " 条 · 契约 " + str(len(contracts)) + " 条（含别名折叠与 $dnsPath 展开）")
    if problems:
        print("发现 " + str(len(problems)) + " 处漂移：")
        for problem in problems:
            print("  - " + problem)
        return 1
    print("三方一致：路由 ↔ scope 声明 ↔ OpenAPI 契约 无漂移。")
    return 0


if __name__ == "__main__":
    sys.exit(main())
