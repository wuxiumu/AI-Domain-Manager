# -*- coding: utf-8 -*-
"""SSH 登录测试：多认证方式尝试引擎（paramiko 优先，ssh + sshpass 兜底）。

两条依赖路径共用同一份「尝试计划」（`_plan`）与同一套失败归因（`_resolve_failure`）：

    ssh_auth = password  只验证 .env 密码（默认，保持账号池审计语义：
                            密钥能登录绝不算密码有效）
    ssh_auth = key       只用私钥（`ssh_key =` 声明，或 key/ 目录按地址自动识别）
    ssh_auth = auto      先密码、密码不通再私钥
    ssh_auth = agent     用本机默认身份（ssh-agent；CLI 兜底时含 ~/.ssh/config）

返回统一结构 {status, banner, os, uptime, detail, debug, facts}，status 取 models
的 ST_* 常量；调用方（batch.test_one）不再抛异常。
debug.auth_method 记录真正生效的方式，debug.auth_tried 逐条记录尝试结果
（报告、--json、日志都直接呈现，便于区分「密码错」与「服务器不收密码」）。
"""

from __future__ import annotations

import re
import shutil
import socket
import subprocess
import time
import traceback

from .models import (
    Server,
    ST_CONNECTED, ST_AUTH_FAILED, ST_NO_AUTH_METHOD, ST_NO_CREDENTIAL, ST_TIMEOUT,
    ST_SSH_ERROR, ST_TOOL_MISSING, ST_ERROR, ST_UNKNOWN,
    AUTH_PASSWORD, AUTH_KEY, AUTH_AUTO, AUTH_AGENT,
)


# 只读配置/运行信息采集脚本（POSIX sh，Linux 优先、macOS 兜底）。
# 每台服务器一条命令跑完，逐行输出 __F__key=value，本地解析。
# 除了展示用的字符串字段，还额外产出「可判定」的纯数字字段（mem_used_pct /
# disk_used_pct / inode_used_pct / load1 / boot_time / zombies），
# 阈值异常与跨次对比全靠这些数字，不再靠人扫表。
FACTS_SH = r"""
h=$(hostname 2>/dev/null || uname -n)
os=$(. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME")
[ -z "$os" ] && os=$(sw_vers 2>/dev/null | tr '\n' ' ')
k=$(uname -r)
a=$(uname -m)
cores=$(nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null)
model=$(grep -m1 'model name' /proc/cpuinfo 2>/dev/null | cut -d: -f2- | sed 's/^ //')
mem=$(awk '/MemTotal/{printf "%.1fG", $2/1048576}' /proc/meminfo 2>/dev/null)
[ -z "$mem" ] && mem=$(echo "$(sysctl -n hw.memsize 2>/dev/null)/1073741824" | bc 2>/dev/null)G
mem_pct=$(awk '/MemTotal/{t=$2} /MemAvailable/{a=$2} END{if(t>0)printf "%.0f",(t-a)*100/t}' /proc/meminfo 2>/dev/null)
swap=$(awk '/SwapTotal/{if ($2>0) printf "%.1fG", $2/1048576; else printf "0"}' /proc/meminfo 2>/dev/null)
swap_pct=$(awk '/SwapTotal/{t=$2} /SwapFree/{f=$2} END{if(t>0)printf "%.0f",(t-f)*100/t; else printf "0"}' /proc/meminfo 2>/dev/null)
d=$(df -hP / 2>/dev/null | awk 'NR==2{print $2" "$3" "$5}')
dt=$(echo "$d" | cut -d' ' -f1); du=$(echo "$d" | cut -d' ' -f2); dp=$(echo "$d" | cut -d' ' -f3 | tr -d '%')
disk="$dt 总 / 已用 $du${dp:+ ($dp%)}"
ip_=$(df -iP / 2>/dev/null | awk 'NR==2{gsub("%","",$5);print $5}')
la=$(cat /proc/loadavg 2>/dev/null)
set -- $la
l1=$1; l5=$2; l15=$3
if [ -z "$l1" ]; then l1=$(uptime | sed 's/.*load average:\? *//' | cut -d, -f1 | tr -d ' '); fi
# 非数字一律丢弃：宁缺不假（macOS 的 uptime 文案会把时钟当成负载解析出来）
case "$l1" in ''|*[!0-9.]*) l1='' ;; esac
case "$l5" in ''|*[!0-9.]*) l5='' ;; esac
case "$l15" in ''|*[!0-9.]*) l15='' ;; esac
load=${l1:-}${l5:+, $l5}${l15:+, $l15}
bt=$(awk '/btime/{print $2}' /proc/stat 2>/dev/null)
up=$(uptime -p 2>/dev/null | sed 's/^up //')
[ -z "$up" ] && up=$(uptime | sed 's/^ *//;s/.* up /up /;s/,.*//')
ip=$(hostname -I 2>/dev/null | awk '{print $1}')
[ -z "$ip" ] && ip=$(ip -4 addr show scope global 2>/dev/null | awk '/inet /{print $2}' | head -1 | cut -d/ -f1)
users=$(who 2>/dev/null | wc -l | tr -d ' ')
procs=$(ps -e 2>/dev/null | tail -n +2 | wc -l | tr -d ' ')
zom=$(ps -eo stat= 2>/dev/null | grep -c '^Z'); zom=${zom:-0}
top=$(ps -eo comm=,rss= --sort=-rss 2>/dev/null | head -3 | awk '{printf "%s %.0fM ", $1, $2/1024}')
echo "__F__hostname=$h"
echo "__F__os=$os"
echo "__F__kernel=$k"
echo "__F__arch=$a"
echo "__F__cpu_cores=$cores"
echo "__F__cpu_model=$model"
echo "__F__mem_total=$mem"
echo "__F__mem_used_pct=$mem_pct"
echo "__F__swap=$swap"
echo "__F__swap_used_pct=$swap_pct"
echo "__F__disk_root=$disk"
echo "__F__disk_total=$dt"
echo "__F__disk_used=$du"
echo "__F__disk_used_pct=$dp"
echo "__F__inode_used_pct=$ip_"
echo "__F__load=$load"
echo "__F__load1=$l1"
echo "__F__load5=$l5"
echo "__F__load15=$l15"
echo "__F__boot_time=$bt"
echo "__F__uptime=$up"
echo "__F__ip=$ip"
echo "__F__users=$users"
echo "__F__procs=$procs"
echo "__F__zombies=$zom"
echo "__F__top_mem=$top"
"""

FACT_KEYS = ["hostname", "os", "kernel", "arch", "cpu_cores", "cpu_model",
             "mem_total", "mem_used_pct", "swap", "swap_used_pct",
             "disk_root", "disk_total", "disk_used", "disk_used_pct", "inode_used_pct",
             "load", "load1", "load5", "load15", "boot_time", "uptime",
             "ip", "users", "procs", "zombies", "top_mem"]
_FACT_PREFIX = "__F__"
_PASSWORD_METHODS = {"password", "keyboard-interactive"}
_METHOD_NAME = {AUTH_PASSWORD: "密码", AUTH_KEY: "密钥", AUTH_AGENT: "本机身份"}
# 失败归因优先级：本地凭据问题最该先看见，其次传输/协议，再次服务器不收方式
_PRIORITY = (ST_TOOL_MISSING, ST_NO_CREDENTIAL, ST_TIMEOUT, ST_SSH_ERROR, ST_ERROR)

_KEY_HINT = ("纯密钥登录的机器在该块加一行 ssh_auth = key（私钥放 scripts/ssh_check/key/<地址>.pem "
             "会被自动识别，或 ssh_key = 指定路径）；密码与密钥都要验就写 ssh_auth = auto；"
             "临时改口径用命令行 --auth")


def _parse_facts(text: str) -> dict:
    """解析 FACTS_SH 输出为 dict（仅取 __F__key=value 行）。"""
    facts = {}
    for line in text.splitlines():
        line = line.strip()
        if line.startswith(_FACT_PREFIX):
            k, _, v = line[len(_FACT_PREFIX):].partition("=")
            if k in FACT_KEYS:
                facts[k] = v.strip()
    return facts


def _new_out() -> dict:
    return {"status": ST_UNKNOWN, "banner": "", "os": "", "uptime": "",
            "detail": "", "debug": {}, "facts": {}}


def _result(line: str, offered=None, status: str = "", detail: str = "", hint: str = "",
            methods: str = "") -> dict:
    """单次尝试的结论（offered: 服务器是否提供本次尝试的认证方式，None = 无从判断）。"""
    return {"line": line, "offered": offered, "status": status, "detail": detail,
            "hint": hint, "methods": methods}


# ---------------------------------------------------------------------------
# 尝试计划
# ---------------------------------------------------------------------------
def _plan(server: Server) -> list[dict] | dict:
    """按 ssh_auth 生成尝试序列；返回 dict 表示本地即可定论（不触网）。"""
    mode = server.auth or AUTH_PASSWORD
    key_att = {
        "kind": AUTH_KEY,
        "label": f"密钥 {server.key_path.rsplit('/', 1)[-1]}" if server.key_path else "密钥",
        "path": server.key_path,
        "passphrase": server.key_passphrase,
    }

    if mode == AUTH_AGENT:
        return [{"kind": AUTH_AGENT, "label": "本机身份(ssh-agent/config)"}]

    if mode == AUTH_KEY:
        if server.key_problem or not server.key_path:
            return _blocked(server.key_problem or "未配置也找不到私钥",
                            server.key_note or _KEY_HINT)
        return [key_att]

    if mode == AUTH_AUTO:
        attempts = []
        if server.password:
            attempts.append({"kind": AUTH_PASSWORD, "label": "密码"})
        if server.key_path and not server.key_problem:
            attempts.append(key_att)
        if not attempts:
            return _blocked(server.key_problem or "清单未提供密码，也没有可用私钥",
                            server.key_note or _KEY_HINT)
        return attempts

    if not server.password:
        return _blocked("清单未提供密码（密码行写 - 表示无密码）", _KEY_HINT)
    return [{"kind": AUTH_PASSWORD, "label": "密码"}]


def _blocked(detail: str, hint: str) -> dict:
    """凭据层面的阻断：一条连接都不发起，先把结论与修复方式说清。"""
    out = _new_out()
    out["status"] = ST_NO_CREDENTIAL
    out["detail"] = detail
    out["debug"] = {"hint": hint, "skip_ssh": True}
    return out


def ssh_login_test(server: Server, timeout: float = 15,
                   trust_host_key: bool = False, collect: bool = False) -> dict:
    """按 server.auth 的计划登录 SSH 并收集系统信息；collect=True 时附带整机配置采集。"""
    plan = _plan(server)
    if isinstance(plan, dict):        # 本地即可定论，不触网
        return plan
    # agent 模式必须走真实 ssh：paramiko 不读 ~/.ssh/config，语义会缺一大块
    # （config 里的 IdentityFile / User / Port 都是"本机默认身份"的一部分）。
    use_cli = any(att["kind"] == AUTH_AGENT for att in plan)
    if not use_cli:
        try:
            import paramiko  # noqa: F401  仅探测依赖是否可用
        except ImportError:
            use_cli = True
    if use_cli:
        out = _cli_login(server, plan, timeout, collect)
    else:
        out = _paramiko_login(server, plan, timeout, trust_host_key, collect)
    if server.auth == AUTH_AUTO and server.key_problem:
        out["debug"]["key_skipped"] = server.key_problem
    return out


def _finish(out: dict, results: list[dict], attempts: list[dict], start: float) -> dict:
    """收尾：并入逐条尝试结果，失败时统一归因。"""
    methods = next((r.get("methods") for r in results if r.get("methods")), "")
    if methods:
        out["debug"].setdefault("allowed_methods", methods)
    out["debug"]["auth_tried"] = [r["line"] for r in results]
    out["debug"]["auth_attempts"] = len(attempts)
    if out["status"] != ST_CONNECTED:
        if out["status"] in (ST_UNKNOWN, ST_AUTH_FAILED):
            _resolve_failure(out, results, attempts)
        if not out["detail"] and out["debug"]["auth_tried"]:
            out["detail"] = "；".join(out["debug"]["auth_tried"])
        out["debug"].setdefault("hint", _KEY_HINT)
    out["debug"]["elapsed_s"] = round(time.monotonic() - start, 2)
    return out


def _resolve_failure(out: dict, results: list[dict], attempts: list[dict]) -> None:
    """全部尝试都失败后的状态归因（两条依赖路径共用）。"""
    if not results:
        return
    # 1) 服务器自报了方式列表，且本次尝试的方式一个都不在它允许的范围内
    known = [r for r in results if r["offered"] is not None]
    if known and all(r["offered"] is False for r in known) \
            and not any(r["status"] == ST_NO_CREDENTIAL for r in results):
        allowed = out["debug"].get("allowed_methods", "")
        names = "、".join(_METHOD_NAME.get(a["kind"], a["kind"]) for a in attempts)
        out["status"] = ST_NO_AUTH_METHOD
        out["detail"] = f"服务器不接受{names}认证" + (f"（仅允许 {allowed}）" if allowed else "")
        out["debug"]["hint"] = (
            f"这台机器只允许 {allowed or '其他认证方式'}，你本机 ssh 能通与本工具测的凭据无关。"
            + _KEY_HINT
        )
        return
    # 2) 本地/传输层问题优先于「凭据被拒」
    for want in _PRIORITY:
        hit = next((r for r in results if r["status"] == want), None)
        if hit:
            out["status"] = want
            out["detail"] = hit["detail"] or hit["line"]
            if hit["hint"]:
                out["debug"]["hint"] = hit["hint"]
            return
    # 3) 服务器收这个方式，只是凭据不对
    out["status"] = ST_AUTH_FAILED
    allowed = out["debug"].get("allowed_methods", "")
    kinds = {a["kind"] for a in attempts}
    if kinds == {AUTH_PASSWORD}:
        out["detail"] = "密码错误" + (f"（服务器允许密码认证，此密码被拒；允许方式: {allowed}）"
                                     if allowed else "")
    elif kinds == {AUTH_KEY}:
        out["detail"] = "密钥被拒（服务器允许 publickey，但这把密钥未被该账号授权）"
    else:
        out["detail"] = "；".join(r["line"] for r in results)
    out["debug"]["hint"] = _failure_hint(kinds)


def _failure_hint(kinds: set) -> str:
    if kinds == {AUTH_PASSWORD}:
        return ("确认 .env 里这台机器的密码是否已过期/抄错。注意：本机 ssh 能登不等于这个密码对——"
                "很多机器是免密（密钥）登录。要一次看清两条腿，本块写 ssh_auth = auto。"
                "另：连续错密码可能触发服务器 fail2ban，把 IP 关进小黑屋。")
    if kinds == {AUTH_KEY}:
        return ("确认这把公钥是否在该账号的 authorized_keys 里（可用同一条 ssh 命令加 -vv "
                "查看服务器拒绝的具体原因），以及用户名是否正确。")
    if kinds == {AUTH_AGENT}:
        return "确认 ssh-agent 已加载身份（ssh-add -l），且 ~/.ssh/config 对该主机没有额外限制。"
    return _KEY_HINT


# ---------------------------------------------------------------------------
# paramiko 路径
# ---------------------------------------------------------------------------
def _exec(client, command: str, timeout: float) -> tuple[str, str]:
    """在已建立的 SSH 会话里执行命令；返回 (stdout, 错误说明)。"""
    try:
        _, stdout, _ = client.exec_command(command, timeout=timeout)
        return stdout.read().decode("utf-8", errors="replace").strip(), ""
    except Exception as e:
        return "", f"{type(e).__name__}: {e}"


def _allowed_types(exc) -> list[str]:
    """从 paramiko 异常里取服务器自报的认证方式列表（拿不到返回空）。"""
    raw = getattr(exc, "allowed_types", None) or []
    if isinstance(raw, (str, bytes)):
        raw = [raw]
    items = []
    for item in raw:
        if isinstance(item, bytes):
            item = item.decode("utf-8", errors="replace")
        item = str(item).strip()
        if item:
            items.append(item)
    return items


def _method_offered(kind: str, allowed: list[str]):
    """服务器自报方式里是否包含本次尝试的方式；无从判断返回 None。"""
    if not allowed:
        return None
    if kind == AUTH_PASSWORD:
        return bool(_PASSWORD_METHODS & set(allowed))
    return "publickey" in allowed


def _gather(client, out: dict, timeout: float, collect: bool) -> None:
    """登录成功后的信息采集（全部只读，单项失败不影响主状态）。"""
    try:
        out["banner"] = (client.banner or "(none)")[:200]
        out["debug"]["ssh_banner"] = out["banner"]
    except Exception:
        pass

    cmd_timeout = min(max(timeout - 2, 2), 10)
    out["os"], err = _exec(client, "uname -a", cmd_timeout)
    if err:
        out["debug"]["cmd_uname_error"] = err
    out["uptime"], err = _exec(client, "uptime", cmd_timeout)
    if err:
        out["debug"]["cmd_uptime_error"] = err

    pretty, _ = _exec(
        client,
        "cat /etc/os-release 2>/dev/null | grep PRETTY_NAME | cut -d'=' -f2 | tr -d '\\\"'",
        5,
    )
    if pretty:
        out["debug"]["os_name"] = pretty
    arch, _ = _exec(client, "arch || uname -m", 5)
    if arch:
        out["debug"]["architecture"] = arch
    cpu, _ = _exec(client, "nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo unknown", 5)
    if cpu:
        out["debug"]["cpu_cores"] = cpu

    if collect:
        facts_text, ferr = _exec(client, FACTS_SH, min(max(timeout - 2, 5), 15))
        out["facts"] = _parse_facts(facts_text)
        if ferr:
            out["debug"]["cmd_facts_error"] = ferr


def _paramiko_login(server: Server, attempts: list[dict], timeout: float,
                    trust_host_key: bool, collect: bool) -> dict:
    import paramiko

    out = _new_out()
    start = time.monotonic()
    results: list[dict] = []

    for att in attempts:
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(
            paramiko.AutoAddPolicy() if trust_host_key else paramiko.RejectPolicy()
        )
        kw = {
            "hostname": server.address, "port": server.port, "username": server.user,
            "timeout": min(timeout, 10), "banner_timeout": min(timeout, 10),
            "auth_timeout": min(timeout, 10),
        }
        if att["kind"] == AUTH_PASSWORD:
            kw.update(password=server.password, allow_agent=False, look_for_keys=False)
        elif att["kind"] == AUTH_KEY:
            kw.update(key_filename=att["path"], allow_agent=False, look_for_keys=False)
            if att["passphrase"]:
                kw["passphrase"] = att["passphrase"]
        else:                                    # agent：paramiko 只用 ssh-agent（不读 ~/.ssh/config）
            kw.update(allow_agent=True, look_for_keys=False)

        attempt_start = time.monotonic()
        stop = False
        try:
            client.connect(**kw)
            out["debug"]["ssh_connect_ms"] = round((time.monotonic() - attempt_start) * 1000, 1)
            out["debug"]["auth_method"] = {AUTH_PASSWORD: "password", AUTH_KEY: "publickey",
                                           AUTH_AGENT: "agent"}[att["kind"]]
            out["debug"]["auth_label"] = att["label"]
            if att["kind"] == AUTH_KEY:
                out["debug"]["auth_key"] = att["path"]
            results.append(_result(f"{att['label']} ✔", offered=True))
            _gather(client, out, timeout, collect)
            out["status"] = ST_CONNECTED
        except paramiko.AuthenticationException as e:
            allowed = _allowed_types(e)
            if allowed:
                out["debug"]["allowed_methods"] = ", ".join(allowed)
            results.append(_result(
                f"{att['label']} ✘ 未被接受（服务器允许: {', '.join(allowed)}）" if allowed
                else f"{att['label']} ✘ {str(e) or type(e).__name__}",
                offered=_method_offered(att["kind"], allowed),
                methods=", ".join(allowed)))
        except paramiko.PasswordRequiredException as e:
            results.append(_result(f"{att['label']} ✘ 私钥被口令保护",
                                   offered=True, status=ST_NO_CREDENTIAL,
                                   detail="私钥需要口令",
                                   hint=f"在本块补一行 ssh_key_pass = 私钥口令（{e}）"))
            stop = True
        except paramiko.SSHException as e:
            results.append(_paramiko_ssh_exception(out, att, e))
            stop = True
        except (socket.timeout, TimeoutError):
            results.append(_result(f"{att['label']} ✘ 超时", status=ST_TIMEOUT,
                                   detail="SSH 连接超时",
                                   hint="SSH 层超时 —— 可能是中间链路拥塞、SSH 处理慢、或连接被半关闭"))
            stop = True
        except Exception as e:
            results.append(_result(f"{att['label']} ✘ {type(e).__name__}: {e}",
                                   status=ST_ERROR, detail=f"{type(e).__name__}: {e}",
                                   hint=""))
            out["debug"]["traceback"] = traceback.format_exc()
            stop = True
        finally:
            try:
                client.close()
            except Exception:
                pass
        if stop or out["status"] == ST_CONNECTED:
            break

    return _finish(out, results, attempts, start)


def _paramiko_ssh_exception(out: dict, att: dict, e: Exception) -> dict:
    msg = str(e).lower()
    out["debug"]["ssh_exception"] = f"{type(e).__name__}: {e}"
    if "reject" in msg or "policy" in msg:
        return _result(f"{att['label']} ✘ 主机密钥被拒绝", status=ST_SSH_ERROR, detail=str(e),
                       hint="SSH 主机密钥被拒绝，首次连接请加参数 --trust-host-key")
    if "banner" in msg:
        return _result(f"{att['label']} ✘ 未取得 SSH banner", status=ST_SSH_ERROR, detail=str(e),
                       hint="无法获取 SSH banner，可能不是标准 SSH 服务或被 WAF/探针拦截")
    if "key" in msg or "decrypt" in msg or "parse" in msg:
        return _result(f"{att['label']} ✘ 私钥无法加载", status=ST_NO_CREDENTIAL,
                       detail=f"私钥无法加载: {e}",
                       hint="确认私钥格式（OpenSSH / PEM）与 ssh_key_pass 是否正确")
    return _result(f"{att['label']} ✘ {e}", status=ST_SSH_ERROR, detail=str(e))


# ---------------------------------------------------------------------------
# ssh / sshpass CLI 兜底路径
# ---------------------------------------------------------------------------
_DENIED_RE = re.compile(r"Permission denied \(([^)]*)\)")


def _parse_permission_denied(output: str) -> dict:
    """解析 OpenSSH 的 `Permission denied (publickey,password,...)` 行。"""
    m = _DENIED_RE.search(output)
    if not m:
        return {"methods": "", "list": []}
    methods = [x.strip() for x in m.group(1).split(",") if x.strip()]
    return {"methods": ", ".join(methods) if methods else "none", "list": methods}


def _cli_base(server: Server) -> list[str]:
    return [
        "ssh",
        "-o", "ConnectTimeout=8",
        # CLI 兜底路径不校验主机密钥（paramiko 路径才受 --trust-host-key 控制），
        # 且不回写本机 known_hosts。
        "-o", "StrictHostKeyChecking=no",
        "-o", "UserKnownHostsFile=/dev/null",
        "-p", str(server.port),
    ]


def _cli_cmd(server: Server, att: dict, remote: str) -> list[str]:
    """按尝试方式拼 ssh argv：密码走 sshpass，密钥/agent 走 BatchMode，绝不卡交互。"""
    if att["kind"] == AUTH_PASSWORD:
        opts = [
            # 只测密码认证，禁 publickey/agent：否则密钥可登录的机器会「假性通过」，
            # .env 里的密码根本没被验证。
            "-o", "PreferredAuthentications=password,keyboard-interactive",
            "-o", "PubkeyAuthentication=no",
            "-o", "IdentitiesOnly=yes",
            "-o", "NumberOfPasswordPrompts=1",
        ]
        return ["sshpass", "-p", server.password] + _cli_base(server) + opts + \
            ["-l", server.user, server.address, remote]

    if att["kind"] == AUTH_KEY:
        opts = [
            "-o", "PreferredAuthentications=publickey",
            "-o", "PubkeyAuthentication=yes",
            "-o", "IdentitiesOnly=yes",
            "-i", att["path"],
        ]
        if att["passphrase"]:
            # 有口令：交给 sshpass 应答 "password:" 与 "passphrase" 两类提示
            head = ["sshpass", "-P", "assword|assphrase", "-p", att["passphrase"]]
        else:
            head, opts = [], opts + ["-o", "BatchMode=yes"]
        return head + _cli_base(server) + opts + ["-l", server.user, server.address, remote]

    # agent：不带 -i / IdentitiesOnly，交给 ssh-agent 与 ~/.ssh/config 的默认身份
    opts = ["-o", "PreferredAuthentications=publickey", "-o", "BatchMode=yes"]
    return _cli_base(server) + opts + ["-l", server.user, server.address, remote]


def _cli_mask(text: str, server: Server, att: dict) -> str:
    """屏蔽 stdout/stderr 里可能回显的密码与私钥口令。"""
    if server.password:
        text = text.replace(server.password, "<密码已掩码>")
    if att.get("passphrase"):
        text = text.replace(att["passphrase"], "<私钥口令已掩码>")
    return text


def _cli_classify(att: dict, proc, output: str) -> dict:
    """把一次失败的 CLI 尝试归一成结论（含 suggested status / 可照做的修复）。"""
    low = output.lower()
    if "unprotected private key file" in low or "bad owner or permissions" in low:
        return _result(f"{att['label']} ✘ 权限过宽（OpenSSH 拒绝加载）", offered=True,
                       status=ST_NO_CREDENTIAL, detail="私钥权限过宽，OpenSSH 拒绝加载",
                       hint=f"修复：chmod 600 '{att.get('path', '')}'")
    if "invalid format" in low or "error in libcrypto" in low or "not a valid private" in low:
        return _result(f"{att['label']} ✘ 格式无法解析", offered=True,
                       status=ST_NO_CREDENTIAL, detail="私钥格式无法解析",
                       hint="确认 ssh_key 指向私钥本体（不是 .pub），且与本机 OpenSSH 版本兼容")
    if "no such identity" in low or "identity file" in low:
        return _result(f"{att['label']} ✘ 文件不可读", offered=True,
                       status=ST_NO_CREDENTIAL,
                       detail=f"私钥文件不可读: {att.get('path', '')}",
                       hint="确认 ssh_key 路径与读取权限")
    if "passphrase" in low and ("required" in low or "enter" in low):
        return _result(f"{att['label']} ✘ 需要口令", offered=True,
                       status=ST_NO_CREDENTIAL, detail="私钥需要口令",
                       hint="在本块补一行 ssh_key_pass = 私钥口令")

    denied = _parse_permission_denied(output)
    if denied["list"]:
        return _result(f"{att['label']} ✘ 未被接受（服务器允许: {denied['methods']}）",
                       offered=_method_offered(att["kind"], denied["list"]),
                       methods=denied["methods"])
    if "permission denied" in low or "authentication failure" in low:
        return _result(f"{att['label']} ✘ 认证被拒")
    if "host key verification failed" in low or "host key changed" in low:
        return _result(f"{att['label']} ✘ 主机密钥校验失败", status=ST_SSH_ERROR,
                       detail="主机密钥校验失败（密钥可能已变更）",
                       hint="确认服务器指纹是否变化；确认可信后用 --trust-host-key")
    if "timed out" in low:
        return _result(f"{att['label']} ✘ 超时", status=ST_TIMEOUT, detail="SSH 连接超时",
                       hint="SSH 层超时 —— 可能是中间链路拥塞、SSH 处理慢、或连接被半关闭")
    if "connection refused" in low:
        return _result(f"{att['label']} ✘ 连接被拒", status=ST_SSH_ERROR, detail="端口拒绝连接")
    return _result(f"{att['label']} ✘ {output.strip()[:120] or f'退出码 {proc.returncode}'}")


def _cli_success(out: dict, att: dict, output: str, collect: bool, sep: str) -> bool:
    """判定并解析一次成功的登录。"""
    out["debug"]["auth_method"] = {AUTH_PASSWORD: "password", AUTH_KEY: "publickey",
                                   AUTH_AGENT: "agent"}[att["kind"]]
    out["debug"]["auth_label"] = att["label"]
    if att["kind"] == AUTH_KEY:
        out["debug"]["auth_key"] = att["path"]
    if collect:
        out["status"] = ST_CONNECTED
        if _FACT_PREFIX in output:
            out["facts"] = _parse_facts(output)
            f = out["facts"]
            out["os"] = " / ".join(
                x for x in (f.get("os"), f"{f.get('kernel', '')} {f.get('arch', '')}".strip()) if x)
            up, load = f.get("uptime", ""), f.get("load", "")
            out["uptime"] = (f"up {up}" if up and not up.startswith("up") else up) + \
                            (f" | load {load}" if load else "")
        else:
            out["debug"]["hint"] = "登录成功但采集脚本无输出（远端登录 shell 可能非 POSIX sh）"
        return True
    if sep in output:
        parts = output.split(sep)
        out["os"] = parts[0].strip()
        out["uptime"] = parts[1].strip() if len(parts) > 1 else ""
        out["status"] = ST_CONNECTED
        return True
    if output.strip():
        out["os"] = output.strip().splitlines()[0]
        out["status"] = ST_CONNECTED
        return True
    return False


def _cli_login(server: Server, attempts: list[dict], timeout: float, collect: bool) -> dict:
    out = _new_out()
    start = time.monotonic()
    results: list[dict] = []
    sep = "---SEPARATOR---"
    remote = FACTS_SH if collect else f"uname -a && echo {sep} && uptime"
    have_sshpass = bool(shutil.which("sshpass"))

    for att in attempts:
        needs_sshpass = att["kind"] == AUTH_PASSWORD or (
            att["kind"] == AUTH_KEY and bool(att["passphrase"]))
        if needs_sshpass and not have_sshpass:
            results.append(_result(
                f"{att['label']} ✘ 缺 sshpass", status=ST_TOOL_MISSING,
                detail="需要 paramiko 或 sshpass 依赖（pip install paramiko）",
                hint="brew install sshpass 或 pip install paramiko 后重跑"))
            continue

        cmd = _cli_cmd(server, att, remote)
        attempt_start = time.monotonic()
        try:
            proc = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
        except subprocess.TimeoutExpired:
            results.append(_result(f"{att['label']} ✘ 超时", status=ST_TIMEOUT,
                                   detail="SSH 操作超时",
                                   hint="SSH 层超时 —— 可能是中间链路拥塞、SSH 处理慢、或连接被半关闭"))
            break
        except FileNotFoundError:
            results.append(_result("ssh ✘ 命令不可用", status=ST_TOOL_MISSING,
                                   detail="ssh / sshpass 命令不可用"))
            break
        except Exception as e:
            out["debug"]["traceback"] = traceback.format_exc()
            results.append(_result(f"{att['label']} ✘ {type(e).__name__}: {e}",
                                   status=ST_ERROR, detail=f"{type(e).__name__}: {e}"))
            break

        output = _cli_mask(proc.stdout + proc.stderr, server, att)
        if proc.returncode == 0 and _cli_success(out, att, output, collect, sep):
            out["debug"]["ssh_connect_ms"] = round((time.monotonic() - attempt_start) * 1000, 1)
            results.append(_result(f"{att['label']} ✔", offered=True))
            break

        info = _cli_classify(att, proc, output)
        results.append(info)
        if info["status"] in (ST_SSH_ERROR, ST_ERROR):
            break

    return _finish(out, results, attempts, start)
