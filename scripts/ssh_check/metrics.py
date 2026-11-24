# -*- coding: utf-8 -*-
"""资源阈值判定与「与上次对比」（纯本地计算 + 一个状态文件，不额外触网）。

为什么需要它：整机采集已经能拿到 CPU / 内存 / 磁盘 / 负载，但一张平铺的表
要人逐行扫——磁盘 100% 和磁盘 25% 混在一起，等于没有告警。这里把采集事实
变成两类可直接消费的输出：

    findings  按阈值的异常清单（critical / warn / info，带建议动作）
    changes   与上一次采集的差异（新增/下线主机、状态翻转、重启、资源变化）

上一次的结果落在 `state.json`（默认 .logs/ssh-report/state.json），
文件本身不含任何凭据，只有主机地址、用户与采集到的数字。

阈值单位：百分比用整数，负载按「每核 load1」计（1 核跑满 1.0 与 8 核跑满 1.0 完全不同）。
"""

from __future__ import annotations

import json
import re
import time
from pathlib import Path

from .models import HostResult, ST_CONNECTED

# 指标 → (warn 阈值, critical 阈值)
DEFAULT_THRESHOLDS = {
    "disk_pct": (85, 95),          # 根分区使用率 %
    "inode_pct": (85, 95),         # 根分区 inode 使用率 %
    "mem_pct": (85, 95),           # 内存实际使用率 %（按 MemAvailable）
    "load_per_core": (1.0, 2.0),   # load1 / CPU 核数
    "swap_pct": (50, 80),          # Swap 使用率（无 Swap 时跳过）
    "zombies": (1, 10),            # 僵尸进程数
}
SEVERITY_RANK = {"critical": 0, "warn": 1, "info": 2}
SEVERITY_ICON = {"critical": "🔴", "warn": "🟠", "info": "🔵"}

_NUM_RE = re.compile(r"(\d+(?:\.\d+)?)")


def num(text) -> float | None:
    """从 "39G" / "(100%)" / "0.07, 0.03, 0.01" 这类串里取第一个数字。"""
    if text is None:
        return None
    if isinstance(text, (int, float)):
        return float(text)
    m = _NUM_RE.search(str(text))
    return float(m.group(1)) if m else None


def host_key(server) -> str:
    """跨次对比用的稳定主机标识（名称可能被改掉，地址+用户更可靠）。"""
    return f"{server.user}@{server.address}:{server.port}"


def findings_for(facts: dict, thresholds: dict | None = None) -> list[dict]:
    """单台机器的阈值异常列表。"""
    th = dict(DEFAULT_THRESHOLDS)
    th.update(thresholds or {})
    out: list[dict] = []

    def add(metric: str, value, key: str, unit: str, why: str, action: str) -> None:
        warn, crit = th[key]
        sev = "critical" if value >= crit else ("warn" if value >= warn else "")
        if not sev:
            return
        line = crit if sev == "critical" else warn
        out.append({"severity": sev, "metric": metric, "value": f"{value:g}{unit}",
                    "threshold": f"{line:g}{unit}", "raw_value": value,
                    "why": why, "action": action})

    cores = num(facts.get("cpu_cores")) or 1
    pct = num(facts.get("disk_used_pct"))
    if pct is not None:
        add("根分区", pct, "disk_pct", "%", "磁盘写满会导致服务写入失败、日志截断",
            f"清理或扩容根分区（总 {facts.get('disk_total', '?')} / 已用 {facts.get('disk_used', '?')}）")
    ino = num(facts.get("inode_used_pct"))
    if ino is not None:
        add("inode", ino, "inode_pct", "%", "inode 耗尽时即使磁盘还有空间也无法建文件",
            "排查碎文件（find / -xdev -type f | wc -l 目录定位，删缓存 / 邮件队列 / 会话文件）")
    mem = num(facts.get("mem_used_pct"))
    if mem is not None:
        add("内存", mem, "mem_pct", "%", f"实际占用 {mem:.0f}%（总量 {facts.get('mem_total', '?')}）",
            f"看占用前几的进程：{facts.get('top_mem', '未采集')}")
    load1 = num(facts.get("load1"))
    if load1 is not None:
        add("负载(每核)", round(load1 / cores, 2), "load_per_core", "",
            f"load1={facts.get('load1')} / {cores} 核，持续高于核数说明有排队",
            "确认是 CPU 密集、IO 等待还是被限流（云主机常见 burst 核用完限速）")
    swap = num(facts.get("swap_used_pct"))
    if swap is not None and num(facts.get("swap")) not in (None, 0):
        add("Swap", swap, "swap_pct", "%", "Swap 被吃满通常紧跟内存压力", "检查是否内存不足触发换页")
    zomb = num(facts.get("zombies"))
    if zomb is not None:
        add("僵尸进程", zomb, "zombies", " 个", "父进程未回收，长期堆积可能是守护脚本卡死",
            "定位父进程并重启对应服务")
    return out


def findings_all(results: list[HostResult], thresholds: dict | None = None) -> dict:
    """全池异常清单：{host_key: [finding...]}，外加不可达机器（采集不到也必须显式列出）。"""
    per_host: dict[str, list[dict]] = {}
    for r in results:
        key = host_key(r.server)
        items = list(findings_for(r.facts, thresholds)) if r.facts else []
        if r.status != ST_CONNECTED:
            sev = "critical" if r.status in ("tcp_closed", "ssh_error", "timeout", "error") else "warn"
            items.append({"severity": sev, "metric": "连通性", "value": r.status,
                          "threshold": "应为 connected",
                          "why": r.detail or "该机本次未能登录，配置与运行数据缺失（未检查 ≠ 正常）",
                          "action": "先修复登录或 ssh_auth 口径，再看待采的数据"})
        if items:
            per_host[key] = sorted(items, key=lambda f: SEVERITY_RANK.get(f["severity"], 9))
    return per_host


def counts(findings: dict) -> dict:
    """异常汇总计数：{critical, warn, info, hosts}。"""
    c = {"critical": 0, "warn": 0, "info": 0, "hosts": len(findings)}
    for items in findings.values():
        for f in items:
            c[f["severity"]] = c.get(f["severity"], 0) + 1
    return c


def collect_snapshot(results: list[HostResult], findings: dict, env_path: str = "",
                     thresholds: dict | None = None) -> dict:
    """本次采集的结构化快照（写 state.json，也是 --json 的主体）。"""
    hosts = {}
    for r in results:
        key = host_key(r.server)
        hosts[key] = {
            "label": r.label,
            "addr": r.server.address,
            "port": r.server.port,
            "user": r.server.user,
            "status": r.status,
            "detail": r.detail,
            "auth": r.auth_desc,
            "auth_config": r.server.auth,
            "tcp_ok": r.tcp_ok,
            "elapsed_s": r.elapsed,
            "key_file": r.debug.get("auth_key", "").rsplit("/", 1)[-1] if r.debug.get("auth_key") else "",
            "facts": r.facts,
            "findings": findings.get(key, []),
        }
    return {
        "schema": 1,
        "generated_at": time.strftime("%Y-%m-%d %H:%M:%S"),
        "generated_epoch": int(time.time()),
        "env": env_path,
        "thresholds": {**DEFAULT_THRESHOLDS, **(thresholds or {})},
        "total": len(results),
        "summary": counts(findings),
        "hosts": hosts,
    }


def load_state(path: str) -> dict:
    """读取上一次快照；文件缺失或损坏一律视为「首次运行」（不阻断本次报告）。"""
    try:
        data = json.loads(Path(path).read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}
    return data if isinstance(data, dict) and isinstance(data.get("hosts"), dict) else {}


def save_state(path: str, snapshot: dict) -> None:
    """状态落盘（先写临时文件再替换，避免半截文件把下次对比打挂）。"""
    p = Path(path)
    p.parent.mkdir(parents=True, exist_ok=True)
    tmp = p.with_suffix(p.suffix + ".tmp")
    tmp.write_text(json.dumps(snapshot, ensure_ascii=False, indent=1), encoding="utf-8")
    tmp.replace(p)
    try:
        p.chmod(0o600)          # 状态里只有地址与数字，但权限一律收紧
    except OSError:
        pass


def _delta_pct(cur, prev):
    if cur is None or prev is None:
        return None
    return round(cur - prev, 1)


def diff(previous: dict, snapshot: dict) -> dict:
    """与上次对比：{"changes": [...], "new": [...], "gone": [...], "since": 时间}。

    只报「有意义的变化」：重启、规格变更、资源位移、状态翻转、主机增减。
    """
    changes: list[dict] = []
    if not previous:
        return {"changes": [], "new": [], "gone": [], "since": "", "first_run": True}

    prev_hosts = previous.get("hosts", {})
    since = previous.get("generated_at", "?")
    cur_keys, prev_keys = set(snapshot["hosts"]), set(prev_hosts)

    for key in sorted(cur_keys - prev_keys):
        h = snapshot["hosts"][key]
        changes.append({"severity": "info", "host": h["label"] or key, "kind": "新增主机",
                        "text": f"{key} 首次出现在清单（{h['status']}）"})
    for key in sorted(prev_keys - cur_keys):
        h = prev_hosts[key]
        changes.append({"severity": "warn", "host": h.get("label") or key, "kind": "已下线",
                        "text": f"{key} 本次清单里没有（上次状态 {h.get('status')}），请确认是否遗漏"})

    for key in sorted(cur_keys & prev_keys):
        cur, prev = snapshot["hosts"][key], prev_hosts[key]
        name = cur["label"] or key
        if cur["status"] != prev.get("status"):
            sev = "critical" if cur["status"] != ST_CONNECTED else "info"
            changes.append({"severity": sev, "host": name, "kind": "状态翻转",
                            "text": f"{prev.get('status')} → {cur['status']}"
                                    + (f"（{cur['detail']}）" if cur.get("detail") else "")})
        cf, pf = cur.get("facts") or {}, prev.get("facts") or {}
        if cf.get("boot_time") and pf.get("boot_time") and cf["boot_time"] != pf["boot_time"]:
            changes.append({"severity": "info", "host": name, "kind": "已重启",
                            "text": f"启动时间由 {pf['boot_time']} 变为 {cf['boot_time']}"
                                    f"（当前 up {cf.get('uptime', '?')}）"})
        for field, kind, unit, step in (
            ("disk_used_pct", "磁盘使用率", "%", 5),
            ("mem_used_pct", "内存使用率", "%", 10),
            ("inode_used_pct", "inode 使用率", "%", 5),
            ("swap_used_pct", "Swap 使用率", "%", 20),
        ):
            d = _delta_pct(num(cf.get(field)), num(pf.get(field)))
            if d is not None and abs(d) >= step:
                sev = "warn" if d > 0 and num(cf.get(field)) >= 70 else ("info" if d else "")
                if sev:
                    changes.append({"severity": sev, "host": name, "kind": kind,
                                    "text": f"{pf.get(field)}{unit} → {cf.get(field)}{unit}（{'+' if d > 0 else ''}{d}{unit}）"})
        d = _delta_pct(num(cf.get("load1")), num(pf.get("load1")))
        if d is not None and abs(d) >= 1:
            changes.append({"severity": "info", "host": name, "kind": "负载变化",
                            "text": f"load1 {pf.get('load1')} → {cf.get('load1')}"})
        for field, kind in (("kernel", "内核"), ("os", "系统"), ("cpu_cores", "CPU 核数"),
                            ("mem_total", "内存总量")):
            if cf.get(field) and pf.get(field) and cf[field] != pf[field]:
                changes.append({"severity": "warn" if field in ("cpu_cores", "mem_total") else "info",
                                "host": name, "kind": f"{kind}变更",
                                "text": f"{pf[field]} → {cf[field]}"})
        if num(cf.get("zombies")) and not num(pf.get("zombies")):
            changes.append({"severity": "warn", "host": name, "kind": "出现僵尸进程",
                            "text": f"当前 {cf.get('zombies')} 个"})

    order = sorted(changes, key=lambda c: SEVERITY_RANK.get(c["severity"], 9))
    return {"changes": order, "new": sorted(cur_keys - prev_keys),
            "gone": sorted(prev_keys - cur_keys), "since": since, "first_run": False}


def prune(report_dir: Path, keep: int) -> list[Path]:
    """归档只保留最近 keep 份（keep <= 0 表示全部保留），固定路径的 report.md 永不删。"""
    if not keep or keep <= 0:
        return []
    files = sorted(list(Path(report_dir).glob("report-*.md")) +
                   list(Path(report_dir).glob("report-*.json")))
    # 时间戳倒序：留最近 keep 份。曾经写成升序取前 keep 个，结果把刚生成的那份删了、
    # 留下最旧的两份——归档语义正好相反。
    stamps = sorted({f.stem.split("report-", 1)[1] for f in files}, reverse=True)
    alive = set(stamps[:keep])
    removed = []
    for f in files:
        if f.stem.split("report-", 1)[1] not in alive:
            try:
                f.unlink()
                removed.append(f)
            except OSError:
                pass
    return removed


def parse_thresholds(pairs: list[str]) -> tuple[dict, list[str]]:
    """命令行阈值：`--threshold disk_pct=90`（只挪 warn 线，critical 取更高者）
    或 `--threshold disk_pct=88:96`（warn:critical 一起给）。返回 (覆盖表, 错误列表)。"""
    out: dict = {}
    errors: list[str] = []
    for raw in pairs or []:
        key, _, value = raw.partition("=")
        key, value = key.strip(), value.strip()
        if key not in DEFAULT_THRESHOLDS:
            errors.append(f"未知阈值 {key}（可用: {', '.join(sorted(DEFAULT_THRESHOLDS))}）")
            continue
        parts = value.split(":")
        nums = [num(p) for p in parts]
        if not nums or any(n is None for n in nums):
            errors.append(f"阈值取值无法解析: {raw}")
            continue
        warn = float(nums[0])
        crit = float(nums[1]) if len(nums) > 1 else DEFAULT_THRESHOLDS[key][1]
        out[key] = (warn, max(warn, crit))
    return out, errors
