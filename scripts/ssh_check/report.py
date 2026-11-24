# -*- coding: utf-8 -*-
"""报告渲染：控制台彩色版与日志纯文本版共用同一版式（仅 color 开关不同）。"""

from __future__ import annotations

import os
import re
from datetime import datetime

from .models import (
    HostResult, summarize, AUTH_LABEL,
    ST_CONNECTED, ST_TCP_CLOSED, ST_AUTH_FAILED, ST_TIMEOUT,
    ST_SSH_ERROR, ST_TOOL_MISSING, ST_NO_AUTH_METHOD, ST_NO_CREDENTIAL, ST_ERROR,
)
from . import metrics as _metrics

_WIDTH = 72


def color_enabled() -> bool:
    """终端是否应该上色（tty 且未设置 NO_COLOR）。"""
    return sys_stdout_isatty() and not os.environ.get("NO_COLOR")


def sys_stdout_isatty() -> bool:
    try:
        import sys
        return sys.stdout.isatty()
    except Exception:  # pragma: no cover
        return False


class _Painter:
    """按 color 开关决定要不要包 ANSI 码的小助手。"""

    def __init__(self, color: bool):
        self.color = color

    def c(self, code: str, text: str) -> str:
        return f"\033[{code}m{text}\033[0m" if self.color else text

    def ok(self, text):   return self.c("32;1", "✔ " + text)
    def fail(self, text): return self.c("31", "✘ " + text)
    def warn(self, text): return self.c("33;1", "⚠ " + text)
    def info(self, text): return self.c("36", text)
    def dim(self, text):  return self.c("90", text)


STYLE_CODES = {"ok": "32;1", "fail": "31", "warn": "33;1"}


def _sep(char="─", width=_WIDTH):
    return char * width


# status → (进度行图标, 状态单元格样式)。状态单元格的 ✔/✘/⚠ 前缀
# 由 _Painter 的对应方法统一添加，这里只记 ANSI 颜色。
STATUS_STYLE = {
    ST_CONNECTED:   ("✔", "ok"),
    ST_TCP_CLOSED:  ("✘", "fail"),
    ST_AUTH_FAILED: ("⚠", "warn"),
    ST_NO_AUTH_METHOD: ("⚠", "warn"),
    ST_NO_CREDENTIAL: ("⚠", "warn"),
    ST_TIMEOUT:     ("⏱", "warn"),
    ST_SSH_ERROR:   ("✘", "fail"),
    ST_TOOL_MISSING: ("⚠", "warn"),
    ST_ERROR:       ("❌", "fail"),
}

STATUS_LABEL = {
    ST_CONNECTED: "成功",
    ST_TCP_CLOSED: "端口关闭",
    ST_AUTH_FAILED: "密码错误",
    ST_NO_AUTH_METHOD: "不接受密码",
    ST_NO_CREDENTIAL: "缺凭据",
    ST_TIMEOUT: "超时",
    ST_SSH_ERROR: "SSH异常",
    ST_TOOL_MISSING: "缺依赖",
    ST_ERROR: "错误",
}

# 非默认认证方式登录成功时在状态后附加标记（密码成功不额外标注）
_AUTH_MARK = {"publickey": "(密钥)", "agent": "(本机)"}


def status_text(r: HostResult) -> str:
    """状态单元格文案：成功且靠密钥/本机身份登录时明确标注，避免误读为密码有效。"""
    base = STATUS_LABEL.get(r.status, r.status)
    if r.status == ST_CONNECTED:
        base += _AUTH_MARK.get(r.auth_method, "")
    return base


def render(results: list[HostResult], color: bool = False) -> str:
    """渲染完整报告文本（表格 + 异常详情 + 汇总）。"""
    p = _Painter(color)
    lines: list[str] = []
    add = lines.append

    add(_sep("═"))
    add(p.info("SSH 连通性测试报告"))
    add(f"  时间: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}  "
        f"目标: {len(results)} 台")
    add(_sep())
    add("")

    if not results:
        add("  （无测试结果）")
        return "\n".join(lines) + "\n"

    # --- 总览表 ---
    add(f"  {'序号':>4}  {'名称':<20} {'地址':<30} {'用户':<10} {'状态':<10} 耗时")
    add(_sep())
    for idx, r in enumerate(results, 1):
        addr_port = r.server.display()
        if len(addr_port) > 30:
            addr_port = addr_port[:27] + "..."
        label = (r.label or "?")[:20]
        user = (r.server.user or "")[:10]
        elapsed = r.elapsed
        elapsed_s = f"{elapsed}s" if elapsed is not None else "-"
        style = STATUS_STYLE.get(r.status, ("?", "warn"))[1]
        cell = p.c(STYLE_CODES[style], status_text(r).ljust(12))
        add(f"  [{idx:>3}]  {label:<20} {addr_port:<30} {user:<10} "
            f"{cell} {elapsed_s}")

    # 靠密钥 / 本机身份登录成功的行必须点名：这并不代表 .env 里的密码有效
    alt = [(idx, r) for idx, r in enumerate(results, 1)
           if r.status == ST_CONNECTED and r.auth_method != "password"]
    if alt:
        add("")
        for idx, r in alt:
            add(p.dim(f"  ℹ [{idx}] {r.label} 靠{r.auth_desc}登录成功 —— "
                      "本行未验证密码（默认口径 ssh_auth = password，"
                      "要两条腿一起验用 --auth auto）"))

    # --- 异常详情 ---
    bad = [r for r in results if r.status != ST_CONNECTED]
    if bad:
        add("")
        add(_sep("═"))
        add(p.info("异常详情（可协助排查）"))
        add(_sep("═"))
        for idx, r in enumerate(bad, 1):
            add(_failure_block(p, idx, r))

    # --- 汇总 ---
    counts = summarize(results)
    total = counts["total"]
    add("")
    add(_sep())
    add(p.info("汇总"))
    add(_sep())
    add(f"  ✅ 正常:     {counts['ok']}/{total}")
    by_auth: dict = {}
    for r in results:
        if r.status == ST_CONNECTED:
            key = r.auth_method or "password"
            by_auth[key] = by_auth.get(key, 0) + 1
    if len(by_auth) > 1 or set(by_auth) - {"password"}:
        detail = "、".join(f"{AUTH_LABEL.get(k, k)} {v} 台" for k, v in sorted(by_auth.items()))
        add(f"     ├ 按认证方式: {detail}（密钥/本机身份登录成功的行未验证密码）")
    add(f"  🔑 登录失败: {counts['failed']}/{total}")
    add(f"  🚫 异常/跳过: {counts['other']}/{total}")
    if total:
        rate = counts["ok"] / total * 100
        bar_len = 30
        filled = int(rate / 100 * bar_len)
        cells = [p.c("32", "█") if i < filled else p.c("90", "░")
                 for i in range(bar_len)]
        add(f"  [{''.join(cells)}] {rate:.0f}%")
    add(_sep())
    return "\n".join(lines) + "\n"


def _failure_block(p: _Painter, idx: int, r: HostResult) -> str:
    """单台失败服务器的排查块（DNS / TCP / SSH 分层信息 + 建议）。"""
    out = [f"  ┌── 异常 [{idx}] {r.label}",
           f"  │ 地址    : {r.server.display()}",
           f"  │ 用户    : {r.server.user}",
           f"  │ 状态    : {p.fail(r.status)}"]
    if r.detail:
        out.append(f"  │ 摘要    : {r.detail}")

    d = r.debug
    if d.get("addr_type") == "hostname" and not d.get("dns_skip"):
        resolved = d.get("resolved_ip")
        if resolved:
            out.append(f"  │ DNS 解析: ✅ {resolved}")
        if d.get("error") and not d.get("tcp_ok"):
            out.append(f"  │ DNS 备注: {p.warn(d['error'])}")

    if "tcp_ok" in d:
        ms = d.get("elapsed")
        ms_txt = f" ({ms * 1000:.0f}ms)" if isinstance(ms, (int, float)) and ms else ""
        out.append(f"  │ TCP      : {'开放' if d['tcp_ok'] else 'closed/filtered'}{ms_txt}")
        if not d.get("tcp_ok") and d.get("error"):
            out.append(f"  │ TCP 备注: {p.warn(d['error'])}")

    if r.server.auth != "password" or r.server.key_path:
        out.append(f"  │ 清单认证: {r.server.auth_desc()}")
    for line in d.get("auth_tried") or []:
        out.append(f"  │   · {line}")
    if d.get("allowed_methods"):
        out.append(f"  │ 服务器允许: {d['allowed_methods']}")
    if d.get("key_skipped"):
        out.append(f"  │ 密钥跳过: {p.warn(d['key_skipped'])}")
    if d.get("ssh_connect_ms"):
        out.append(f"  │ SSH 建连: {d['ssh_connect_ms']}ms")
    if d.get("ssh_banner"):
        out.append(f"  │ SSH Banner: {d['ssh_banner'][:100]}")
    for key, name in (("os_name", "OS"), ("architecture", "架构"),
                      ("cpu_cores", "CPU 核心")):
        if d.get(key):
            out.append(f"  │ {name:<9}: {d[key]}")
    if d.get("hint"):
        out.append(f"  │ 💡 建议  : {p.info(d['hint'])}")
    if d.get("traceback"):
        tb = d["traceback"].strip().splitlines()
        out.append("  │ 堆栈     :")
        out.extend(f"  │   {line}" for line in tb[:5])
        if len(tb) > 5:
            out.append(f"  │   ... 另 {len(tb) - 5} 行")
    out.append(f"  └{_sep('─', 60)}")
    return "\n".join(out)


INVENTORY_HEADERS = [
    ("名称", "label"), ("地址", "addr"), ("用户", "user"), ("状态", "status"),
    ("系统", "os"), ("内核", "kernel"), ("架构", "arch"), ("CPU", "cpu"),
    ("内存", "mem"), ("Swap", "swap"), ("根分区", "disk"), ("负载", "load"),
    ("运行时长", "up"), ("内网IP", "ip"), ("登录数", "users"),
]


def _cell(text) -> str:
    return str(text if text not in (None, "") else "-").replace("|", "\\|")


def render_inventory_markdown(results: list[HostResult], env_path: str = "") -> str:
    """服务器配置运行清单（Markdown）：连通状态 + 整机配置两张表。"""
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    counts = summarize(results)
    lines = [
        "# SSH 服务器配置运行清单",
        "",
        f"- 生成时间：{now}",
        f"- 清单来源：{env_path}" if env_path else "- 清单来源：.env",
        f"- 规模：共 {counts['total']} 台（正常 {counts['ok']} / 登录失败 {counts['failed']} / 异常跳过 {counts['other']}）",
        f"- 采集方式：登录成功（密码或密钥，见下表「认证方式」）后在同一次会话里跑只读命令（hostname / os-release / /proc / df / uptime），不做任何写操作",
        "",
        "## 连通状态",
        "",
        "| 名称 | 地址 | 用户 | TCP | SSH 状态 | 认证方式 | 耗时 |",
        "| --- | --- | --- | --- | --- | --- | --- |",
    ]
    for r in results:
        lines.append(
            f"| {_cell(r.label)} | {_cell(r.server.display())} | {_cell(r.server.user)} "
            f"| {'开放' if r.tcp_ok else '不通'} | {_cell(status_text(r))} "
            f"| {_cell(r.auth_desc or '—')} "
            f"| {_cell(f'{r.elapsed}s' if r.elapsed is not None else None)} |"
        )

    lines += [
        "",
        "## 配置与运行（仅登录成功的服务器有数据）",
        "",
        "| " + " | ".join(h for h, _ in INVENTORY_HEADERS[4:]) + " |",
        "| " + " | ".join(["---"] * (len(INVENTORY_HEADERS) - 4)) + " |",
    ]
    for r in results:
        f = r.facts
        if not f:
            continue
        cpu = " / ".join(x for x in (f.get("cpu_cores") and f"{f['cpu_cores']} 核", f.get("cpu_model")) if x)
        lines.append("| " + " | ".join((
            _cell(f.get("os")), _cell(f.get("kernel")), _cell(f.get("arch")),
            _cell(cpu), _cell(f.get("mem_total")), _cell(f.get("swap")),
            _cell(f.get("disk_root")), _cell(f.get("load")),
            _cell(f"up {f.get('uptime')}" if f.get("uptime") else None),
            _cell(f.get("ip")), _cell(f.get("users")),
        )) + " |")

    bad = [r for r in results if r.status != ST_CONNECTED]
    if bad:
        lines += ["", "## 未能采集的服务器", ""]
        for r in bad:
            lines.append(f"- **{r.label}**（{r.server.display()}）：{STATUS_LABEL.get(r.status, r.status)}"
                         + (f" —— {r.detail}" if r.detail else ""))
    lines.append("")
    lines.append("> 说明：负载为 1/5/15 分钟均值；根分区为「总 / 已用 (使用率)」；内网IP 取 hostname -I 首值；数据为本次采集时刻快照。")
    return "\n".join(lines) + "\n"


# ---------------------------------------------------------------------------
# 服务器配置与运行报告（面向 AI / cron 消费：结论在前、异常在前、口径在尾）
# ---------------------------------------------------------------------------
_SEV_ORDER = ("critical", "warn", "info")
_SEV_NAME = {"critical": "🔴 严重", "warn": "🟠 警告", "info": "🔵 提示"}


def _epoch_txt(value) -> str:
    try:
        return datetime.fromtimestamp(int(value)).strftime("%Y-%m-%d %H:%M")
    except (TypeError, ValueError, OSError):
        return ""


def _per_core(f: dict):
    load1, cores = _metrics.num(f.get("load1")), _metrics.num(f.get("cpu_cores"))
    if load1 is None or not cores:
        return None
    return round(load1 / cores, 2)


def render_server_report(results: list[HostResult], findings: dict, changes: dict,
                         snapshot: dict, env_path: str = "", state_path: str = "",
                         color: bool = False) -> str:
    """Markdown 正文：同一份数据既给人看也给 AI 读，结论/异常/变化都在前半页。"""
    p = _Painter(color)
    counts = summarize(results)
    fc = _metrics.counts(findings)
    lines: list[str] = []
    add = lines.append

    add("# 服务器配置与运行报告")
    add("")
    add(f"- 生成时间：{snapshot['generated_at']}")
    add(f"- 清单来源：{env_path or '.env'}（{counts['total']} 台）")
    add(f"- 连通情况：正常 {counts['ok']} / 登录失败 {counts['failed']} / 异常跳过 {counts['other']}")
    auth_mix: dict = {}
    for r in results:
        if r.status == ST_CONNECTED:
            auth_mix[r.auth_desc or "密码"] = auth_mix.get(r.auth_desc or "密码", 0) + 1
    if auth_mix:
        add("- 认证方式：" + "、".join(f"{k} {v} 台" for k, v in sorted(auth_mix.items())))
    add(f"- 结论：{_SEV_NAME['critical']} {fc.get('critical', 0)} 项 · "
        f"{_SEV_NAME['warn']} {fc.get('warn', 0)} 项 · {_SEV_NAME['info']} {fc.get('info', 0)} 项"
        + ("" if changes.get("first_run") else
           f" · 变化 {len(changes.get('changes') or [])} 项（对比 {changes.get('since', '?')}）"))
    if changes.get("first_run"):
        add("- 对比：**首次运行，基线已建立**，下一次起这里开始出现「与上次对比」。")
    add(f"- 采集方式：登录后同一次会话内跑**只读**命令（df /proc ps uptime 等），不写远端任何文件；"
        "机器不可达或未采到的字段一律留空，不做推断（留空 ≠ 正常）")
    add("")

    # --- 阈值异常 ---
    add("## 需要关注（阈值异常，按严重度排序）")
    add("")
    rows = [(key, f) for key, items in findings.items() for f in items]
    rows.sort(key=lambda kv: _SEV_ORDER.index(kv[1]["severity"])
              if kv[1]["severity"] in _SEV_ORDER else 9)
    if not rows:
        add("全部在阈值内，无异常。")
    else:
        add("| 级别 | 服务器 | 指标 | 当前 | 阈值 | 为什么要紧 | 建议动作 |")
        add("| --- | --- | --- | --- | --- | --- | --- |")
        for key, f in rows:
            host = snapshot["hosts"].get(key, {}).get("label") or key
            add(f"| {_cell(_SEV_NAME.get(f['severity'], f['severity']))} "
                f"| {_cell(host)} | {_cell(f['metric'])} | {_cell(f['value'])} "
                f"| {_cell(f['threshold'])} | {_cell(f['why'])} | {_cell(f['action'])} |")
    add("")

    # --- 与上次对比 ---
    add("## 与上次对比" if not changes.get("first_run") else "## 与上次对比（首次运行）")
    add("")
    if changes.get("first_run"):
        add(f"基线已写入 `{state_path}`（不含任何凭据），下次运行开始给出变化。")
    elif not changes.get("changes"):
        add(f"与 {changes.get('since', '?')} 的快照一致：无新增、无下线、无重启，资源指标变化都在阈值内。")
    else:
        add(f"上次采集：{changes.get('since', '?')}")
        add("")
        add("| 级别 | 服务器 | 变化 | 详情 |")
        add("| --- | --- | --- | --- |")
        for c in changes["changes"]:
            add(f"| {_cell(_SEV_NAME.get(c['severity'], c['severity']))} | {_cell(c['host'])} "
                f"| {_cell(c['kind'])} | {_cell(c['text'])} |")
    add("")

    # --- 全量表 ---
    add("## 全量配置与运行")
    add("")
    add("| 名称 | 地址 | 连通 | 认证 | 系统 | CPU | 内存(用%) | 磁盘(用%) | inode | "
        "负载(每核) | Swap | 运行时长 | 进程/僵尸 | 内网IP | 耗时 |")
    add("| " + " | ".join(["---"] * 15) + " |")
    for r in results:
        f = r.facts or {}
        mem_pct = f.get("mem_used_pct")
        disk_pct = f.get("disk_used_pct")
        add("| " + " | ".join((
            _cell(r.label), _cell(r.server.display()),
            _cell(status_text(r)) + ("" if r.status == ST_CONNECTED else f"：{_cell(r.detail)}"),
            _cell(r.auth_desc or "—"),
            _cell(f.get("os")),
            _cell(" ".join(x for x in (f.get("cpu_cores") and f"{f['cpu_cores']}核",
                                       (f.get("cpu_model") or "").split(" CPU")[0]) if x)),
            _cell(f"{f.get('mem_total', '-')} ({mem_pct}%)" if mem_pct not in (None, "") else f.get("mem_total", "-")),
            _cell(f"{f.get('disk_used', '-')}/{f.get('disk_total', '-')}"
                  + (f" ({disk_pct}%)" if disk_pct not in (None, "") else "")),
            _cell(f.get("inode_used_pct") and f"{f['inode_used_pct']}%" or "-"),
            _cell(f"{f.get('load1', '-')}" + (f" ({_per_core(f)})" if _per_core(f) is not None else "")),
            _cell(f.get("swap", "-")),
            _cell(f"up {f.get('uptime')}" if f.get("uptime") and not str(f["uptime"]).startswith("up")
                  else f.get("uptime") or "-"),
            _cell(f"{f.get('procs', '-')}/{f.get('zombies', '-')}"
                  if f.get("procs") not in (None, "") else "-"),
            _cell(f.get("ip")),
            _cell(f"{r.elapsed}s" if r.elapsed is not None else "-"),
        )) + " |")
    add("")

    # --- 逐台明细 ---
    add("## 逐台明细")
    add("")
    for r in results:
        key = _metrics.host_key(r.server)
        f = r.facts or {}
        add(f"### {r.label} · {r.server.display()} · {r.server.user}")
        add("")
        add(f"- 连通：{r.status}（{r.auth_desc or r.server.auth}"
            + (f"，{r.elapsed}s" if r.elapsed is not None else "") + "）"
            + (f" —— {r.detail}" if r.status != ST_CONNECTED and r.detail else ""))
        if f.get("hostname"):
            add(f"- 主机名：{f['hostname']}")
        os_txt = " · ".join(x for x in (f.get("os"), f.get("kernel"), f.get("arch")) if x)
        if os_txt:
            add(f"- 系统：{os_txt}")
        if f.get("cpu_cores"):
            add(f"- CPU：{f['cpu_cores']} 核" + (f" · {f['cpu_model']}" if f.get("cpu_model") else ""))
        if f.get("mem_total"):
            add(f"- 内存：{f['mem_total']}"
                + (f"，实际已用 {f['mem_used_pct']}%" if f.get("mem_used_pct") not in (None, "") else "")
                + (f"；Swap {f['swap']}" + (f"（用 {f['swap_used_pct']}%）"
                                            if f.get("swap_used_pct") not in (None, "") else "")
                   if f.get("swap") not in (None, "", "0") else ""))
        if f.get("disk_total"):
            add(f"- 磁盘：已用 {f.get('disk_used', '?')} / 总 {f['disk_total']}"
                + (f"（{f['disk_used_pct']}%）" if f.get("disk_used_pct") not in (None, "") else "")
                + (f"；inode {f['inode_used_pct']}%" if f.get("inode_used_pct") not in (None, "") else ""))
        if f.get("load"):
            pc = _per_core(f)
            add(f"- 负载：{f['load']}" + (f"（1 分钟均摊每核 {pc}）" if pc is not None else ""))
        if f.get("uptime"):
            up = f["uptime"] if str(f["uptime"]).startswith("up") else f"up {f['uptime']}"
            boot = _epoch_txt(f.get("boot_time"))
            add(f"- 运行时长：{up}" + (f"（本次启动于 {boot}）" if boot else ""))
        if f.get("procs"):
            add(f"- 进程：{f['procs']}" + (f"，僵尸 {f['zombies']}" if f.get("zombies") not in (None, "", "0") else ""))
        if f.get("top_mem"):
            add(f"- 内存占用前 3：{f['top_mem'].strip()}")
        if f.get("ip"):
            add(f"- 内网 IP：{f['ip']}" + (f"；当前登录 {f['users']} 人" if f.get("users") else ""))
        items = findings.get(key) or []
        if items:
            parts = []
            for i in items:
                parts.append(f"{_SEV_NAME.get(i['severity'], i['severity'])} {i['metric']} "
                             f"{i['value']}（阈值 {i['threshold']}）→ {i['action']}")
            add("- ⚠ 异常：" + "；".join(parts))
        else:
            add("- 异常：无（在阈值内）")
        add("")

    # --- 口径说明 ---
    add("## 判定口径与阈值")
    add("")
    add("| 指标 | warn | critical | 说明 |")
    add("| --- | --- | --- | --- |")
    meaning = {
        "disk_pct": "根分区使用率 %（写满即服务异常）",
        "inode_pct": "根分区 inode 使用率 %（耗尽时磁盘有余也无法建文件）",
        "mem_pct": "内存实际使用率 %（按 MemAvailable，不按 free）",
        "load_per_core": "load1 ÷ CPU 核数（持续 >1 有排队）",
        "swap_pct": "Swap 使用率 %（无 Swap 的机器跳过此项）",
        "zombies": "僵尸进程数（父进程未回收）",
    }
    for key, (warn, crit) in snapshot.get("thresholds", {}).items():
        add(f"| `{key}` | {warn} | {crit} | {_cell(meaning.get(key, ''))} |")
    add("")
    add(f"- 上次快照：`{state_path}`（只有地址、用户与数字，无凭据）")
    add("- 采集为只读：不重启服务、不清理、不写远端文件；本报告只写本机 .logs/ 下的产出。")
    add("- 变化阈值：磁盘/inode ±5%、内存 ±10%、Swap ±20%、load1 ±1、启动时间变化即记「已重启」。")
    return "\n".join(lines) + "\n"


def strip_ansi(text: str) -> str:
    """去掉 ANSI 转义序列（防御式：日志文件永远应是纯文本）。"""
    return re.sub(r"\033\[[0-9;]*m", "", text)
