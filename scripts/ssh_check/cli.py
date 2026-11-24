# -*- coding: utf-8 -*-
"""命令行入口：装配 envfile / batch / report / runlog。"""

from __future__ import annotations

import argparse
import json
import logging
import sys
import time
from datetime import datetime
from pathlib import Path

from . import __version__, metrics
from .batch import run_batch
from .envfile import parse_env
from .keys import attach as attach_keys, search_dirs
from .models import ST_CONNECTED, VALID_AUTH, summarize
from .report import color_enabled, render, render_inventory_markdown, render_server_report
from .runlog import (append_report, default_inventory_path, default_log_path,
                     default_report_dir, logger, setup)

EPILOG = """\
示例（在 scripts/ 目录下，或任意目录用 python3 scripts/ssh_check/__main__.py）:
  python3 -m ssh_check                      # 默认 .env，8 并发（只验证密码）
  python3 -m ssh_check .env.prod            # 指定 .env 文件
  python3 -m ssh_check --list               # 只解析清单（含认证方式与密钥来源），不发起连接
  python3 -m ssh_check -j 20                # 20 并发
  python3 -m ssh_check --log report.log     # 指定日志路径
  python3 -m ssh_check --no-console         # 静默模式，只写日志
  python3 -m ssh_check --json               # 机器可读结果（供脚本复用）
  python3 -m ssh_check --inventory          # 采集整机配置，输出 Markdown 运行清单
  python3 -m ssh_check --timeout 20         # SSH 超时调到 20 秒
  python3 -m ssh_check --trust-host-key     # 首次连接信任主机密钥
  python3 -m ssh_check --auth auto          # 本次全池：先密码、不通再密钥
  python3 -m ssh_check --auth key --key ~/keys/id_ed25519   # 本次全池只用指定私钥
  python3 -m ssh_check --auth agent        # 用本机 ssh-agent / ~/.ssh/config 的默认身份

给其他 AI / 定时任务用（纯 python，不依赖本项目 PHP）:
  python3 -m ssh_check --report                    # 阈值异常 + 与上次对比 + 全量明细
  python3 -m ssh_check --report --json             # 同一数据的机器可读输出（findings / changes）
  cat .logs/ssh-report/report.md                   # 固定路径正文（每次覆盖，另有时间戳归档）
  crontab 示例: 15 6 * * * cd <仓库>/scripts && python3 -m ssh_check --report --no-console >> ../.logs/ssh-report/cron.out 2>&1
"""


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="python3 -m ssh_check",
        description="SSH 账号池连通性测试（并发批量 · 模块化）",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=EPILOG,
    )
    parser.add_argument("env_file", nargs="?", default=None,
                        help=".env 文件路径（默认: 仓库根 .env）")
    parser.add_argument("-j", "--workers", type=int, default=8,
                        help="最大并发数（默认: 8）")
    parser.add_argument("--log", type=str, default=None,
                        help="日志文件输出路径（默认: .logs/ssh-test-YYYYMMDD_HHMMSS.log）")
    parser.add_argument("--no-log", action="store_true",
                        help="不写日志文件")
    parser.add_argument("--no-console", action="store_true",
                        help="仅输出到日志文件，不打印控制台")
    parser.add_argument("--timeout", type=int, default=15,
                        help="SSH 登录超时秒数（默认: 15）")
    parser.add_argument("--trust-host-key", action="store_true",
                        help="自动接受新主机密钥（适合首次连接），替代默认 RejectPolicy")
    parser.add_argument("--auth", choices=VALID_AUTH, default=None,
                        help="强制覆盖全池认证方式（默认沿用 .env 的 ssh_auth，缺省 password）："
                             "password 只验密码 / key 只用私钥 / auto 先密码再私钥 / "
                             "agent 用本机 ssh-agent 与 ~/.ssh/config 的默认身份")
    parser.add_argument("--key", type=str, default=None, metavar="PATH",
                        help="默认私钥路径（块内 ssh_key 优先）；支持 ~ 与相对清单目录/模块目录")
    parser.add_argument("--key-dir", type=str, action="append", default=[], metavar="DIR",
                        help="额外的密钥自动识别目录（可重复）；缺省搜 ssh_check/key/ 与清单同级 key/")
    parser.add_argument("--list", action="store_true",
                        help="只解析并打印服务器清单（不发起任何连接）")
    parser.add_argument("--json", action="store_true",
                        help="以 JSON 输出结果（stdout，不含密码），适合被其他脚本调用")
    parser.add_argument("--inventory", action="store_true",
                        help="登录成功的机器额外采集整机配置与运行信息，输出 Markdown 清单")
    parser.add_argument("--md", type=str, default=None, metavar="PATH",
                        help="配置清单落盘路径（默认: .logs/inventory-<时间戳>.md，配合 --inventory）")
    parser.add_argument("--report", action="store_true",
                        help="生成「服务器配置与运行报告」：阈值异常 + 与上次对比 + 全量明细，"
                             "写 .logs/ssh-report/report.md（固定路径，给 AI / 定时任务读）"
                             "与 report-<时间戳>.md 归档，隐含 --inventory 的采集")
    parser.add_argument("--report-dir", type=str, default=None, metavar="DIR",
                        help="报告目录（默认: 仓库根 .logs/ssh-report）")
    parser.add_argument("--state", type=str, default=None, metavar="PATH",
                        help="上次快照文件路径（默认 <报告目录>/state.json，只有地址与数字，无凭据）")
    parser.add_argument("--keep", type=int, default=14, metavar="N",
                        help="归档保留份数（默认 14，超出自动清理最早的 report-*.md/.json）")
    parser.add_argument("--threshold", type=str, action="append", default=[],
                        metavar="指标=warn[:critical]",
                        help="覆盖阈值，可重复：--threshold disk_pct=90 --threshold load_per_core=1.5:3 "
                             f"（可用: {', '.join(sorted(metrics.DEFAULT_THRESHOLDS))}）")
    parser.add_argument("--fail-on-alert", action="store_true",
                        help="报告存在 🔴 严重项时退出码改为 3（默认仍只看连通性：0 全通 / 1 有失败）")
    parser.add_argument("-v", "--verbose", action="store_true",
                        help="日志写入 DEBUG 级细节")
    parser.add_argument("--version", action="version",
                        version=f"ssh_check {__version__}")
    return parser


def _default_env_path() -> str:
    return str(Path(__file__).resolve().parents[2] / ".env")


def _print_inventory(servers, env_path: str = "", args=None) -> None:
    print(f"解析到 {len(servers)} 台服务器（未发起连接）:\n")
    for i, s in enumerate(servers, 1):
        flag = "  ⚠ " if s.key_problem else "    "
        print(f"{flag}[{i:>2}] {s.label:<24} {s.display():<32} 用户 {s.user:<10} {s.auth_desc()}")
    if args is None:
        return
    dirs = search_dirs(env_path, getattr(args, "key_dir", None))
    scope = getattr(args, "auth", None)
    print(f"\n  认证口径: " + (f"--auth {scope}（覆盖全池）" if scope
                              else "沿用 .env 的 ssh_auth（缺省 password = 只验密码）"))
    print("  密钥自动识别目录: " + ("、".join(str(d) for d in dirs)
                                    or "（不存在；可新建 scripts/ssh_check/key/ 并放 <地址>.pem）"))


def _emit_report(results, env_path: str, args, thresholds: dict) -> dict:
    """生成「服务器配置与运行报告」并落盘：正文（固定路径 + 归档）+ 机器可读快照 + 对比基线。

    返回渲染与判定所需的一切，供 stdout / --json / 日志三个出口共用同一份数据。
    """
    report_dir = Path(args.report_dir).expanduser() if args.report_dir else default_report_dir()
    report_dir.mkdir(parents=True, exist_ok=True)
    ts = datetime.now().strftime("%Y%m%d_%H%M%S")
    state_path = str(Path(args.state).expanduser()) if args.state else str(report_dir / "state.json")
    latest, archive = report_dir / "report.md", report_dir / f"report-{ts}.md"
    json_path = report_dir / f"report-{ts}.json"

    findings = metrics.findings_all(results, thresholds)
    previous = metrics.load_state(state_path)
    snapshot = metrics.collect_snapshot(results, findings, env_path, thresholds)
    changes = metrics.diff(previous, snapshot)
    md = render_server_report(results, findings, changes, snapshot, env_path, state_path)

    latest.write_text(md, encoding="utf-8")
    archive.write_text(md, encoding="utf-8")
    json_path.write_text(
        json.dumps({"report": snapshot, "changes": changes}, ensure_ascii=False, indent=1),
        encoding="utf-8")
    metrics.save_state(state_path, snapshot)
    removed = metrics.prune(report_dir, args.keep)

    paths = {"dir": report_dir, "latest": latest, "archive": archive,
             "json": json_path, "state": Path(state_path), "pruned": [str(p) for p in removed]}
    return {"md": md, "summary": snapshot["summary"], "findings": findings,
            "changes": changes, "hosts": snapshot["hosts"], "thresholds": snapshot["thresholds"],
            "generated_at": snapshot["generated_at"], "paths": paths}


def _result_to_dict(r, elapsed_total: float) -> dict:
    return {
        "label": r.label,
        "address": r.server.address,
        "port": r.server.port,
        "user": r.server.user,
        "status": r.status,
        "detail": r.detail,
        "os": r.os,
        "uptime": r.uptime,
        "tcp_ok": r.tcp_ok,
        "elapsed_s": r.elapsed,
        "ssh_auth": r.server.auth,                 # 清单/命令行声明的口径
        "auth_config": r.server.auth_desc(),       # 声明口径 + 密钥来源（人类可读）
        "auth_method": r.auth_method,              # 实际生效的方式（失败为空）
        "auth_desc": r.auth_desc,                  # 生效方式的人类可读文案
        "auth_tried": r.debug.get("auth_tried", []),
        "allowed_methods": r.debug.get("allowed_methods", ""),
        "hint": r.debug.get("hint", ""),
        "batch_elapsed_s": round(elapsed_total, 1),
    }


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)

    env_path = args.env_file or _default_env_path()
    env_path = str(Path(env_path).expanduser())
    if not Path(env_path).is_file():
        print(f"错误: {env_path} 不存在", file=sys.stderr)
        return 2

    servers, warns = parse_env(env_path)
    if not servers:
        print(f"错误: 未解析到任何服务器记录（{env_path}）", file=sys.stderr)
        for w in warns:
            print(f"  告警: {w}", file=sys.stderr)
        return 1
    thresholds, th_errors = metrics.parse_thresholds(args.threshold)
    for w in warns + th_errors:
        print(f"告警: {w}", file=sys.stderr)

    # 密钥：解析 ssh_key / 自动识别 / 可用性校验（纯本地文件，不触网）
    key_warns = attach_keys(servers, env_path=env_path, extra_dirs=args.key_dir,
                            default_key=args.key or "", force_auth=args.auth or "")
    for w in key_warns:
        print(f"密钥告警: {w}", file=sys.stderr)

    if args.list:
        _print_inventory(servers, env_path, args)
        return 0

    # -- 日志 --
    log_path = None if args.no_log else (args.log or default_log_path())
    if log_path:
        handler = setup(log_path, verbose=args.verbose)
    else:
        handler = None
        logger.addHandler(logging.NullHandler())
    for w in warns + th_errors + key_warns:
        logger.warning("清单告警: %s", w)

    console = not args.no_console and not args.json
    if console:
        print(f"加载中: {env_path}")
        mode_txt = args.auth or "沿用 .env 的 ssh_auth"
        print(f"✓ 解析到 {len(servers)} 台服务器，{args.workers} 并发，认证口径: {mode_txt}\n")
        _print_inventory(servers, env_path, args)
        print()

    logger.info("清单 %s：%d 台，并发 %d，超时 %ds，trust_host_key=%s，认证口径=%s",
                env_path, len(servers), args.workers, args.timeout,
                args.trust_host_key, args.auth or ".env ssh_auth(默认 password)")

    state = {"start": time.monotonic()}

    def on_done(result, done, total, elapsed_total):
        logger.info("[%d/%d] %s %s → %s (%.2fs)",
                    done, total, result.label, result.server.display(),
                    result.status, result.elapsed or 0)
        if console:
            icons = {ST_CONNECTED: "✔", "tcp_closed": "✘", "auth_failed": "⚠",
                     "timeout": "⏱", "error": "❌", "ssh_error": "❌"}
            icon = icons.get(result.status, "?")
            remaining = (total - done) * (elapsed_total / max(done, 1))
            print(f"\r  [{icon}] {done}/{total} — {result.label:20s} "
                  f"{result.status:<15s} ({elapsed_total:.1f}s 已过, "
                  f"约 {remaining:.1f}s 剩余)  ", end="", flush=True)

    results = run_batch(
        servers, workers=args.workers, timeout=args.timeout,
        trust_host_key=args.trust_host_key, collect=args.inventory or args.report,
        on_done=on_done,
    )
    elapsed_total = time.monotonic() - state["start"]

    counts = summarize(results)
    logger.info("完成 %d/%d：正常 %d，登录失败 %d，异常/跳过 %d，总耗时 %.1fs",
                counts["total"], counts["total"], counts["ok"],
                counts["failed"], counts["other"], elapsed_total)

    report = _emit_report(results, env_path, args, thresholds) if args.report else None
    if report:
        logger.info("报告异常：🔴%d 🟠%d 🔵%d；变化 %d 项；正文 %s",
                    report["summary"]["critical"], report["summary"]["warn"],
                    report["summary"]["info"], len(report["changes"]["changes"]),
                    report["paths"]["latest"])

    if args.json:
        payload = {
            "env": env_path,
            "workers": args.workers,
            "timeout": args.timeout,
            "elapsed_s": round(elapsed_total, 1),
            "summary": counts,
            "results": [_result_to_dict(r, elapsed_total) for r in results],
        }
        if report:
            payload["report"] = {
                "generated_at": report["generated_at"],
                "thresholds": report["thresholds"],
                "summary": report["summary"],
                "findings": report["findings"],
                "changes": report["changes"],
                "hosts": report["hosts"],
                "paths": {k: (v if isinstance(v, list) else str(v))
                          for k, v in report["paths"].items()},
            }
        print(json.dumps(payload, ensure_ascii=False, indent=2))
    elif console and report:
        print()
        print(report["md"])
        paths = report["paths"]
        print(f"📄 报告正文: {paths['latest']}")
        print(f"   归档: {paths['archive']} · 机器可读: {paths['json']} · 基线: {paths['state']}")
    elif console:
        print()
        print()
        print(render(results, color=color_enabled()))

    if args.inventory and not args.report:      # --report 的正文已含全量表，不再重复产出
        md = render_inventory_markdown(results, env_path)
        md_path = args.md or default_inventory_path()
        Path(md_path).parent.mkdir(parents=True, exist_ok=True)
        Path(md_path).write_text(md, encoding="utf-8")
        if console or args.json:
            print(md)
            print(f"📋 配置清单已写入: {md_path}")
        logger.info("配置清单已写入: %s", md_path)

    if handler:
        append_report(handler, render(results, color=False))
        if report:
            append_report(handler, report["md"])
        if console:
            print(f"\n📄 日志已写入: {handler.baseFilename}")
    if console:
        print(f"⏱ 总耗时: {elapsed_total:.1f}s")

    if all(r.status == ST_CONNECTED for r in results):
        if report and args.fail_on_alert and report["summary"]["critical"]:
            print(f"⚠ 存在 {report['summary']['critical']} 项严重异常（--fail-on-alert）", file=sys.stderr)
            return 3
        return 0
    return 1
