# -*- coding: utf-8 -*-
"""网络层探测：DNS 解析与 TCP 连通性（仅标准库 socket，可独立复用）。"""

from __future__ import annotations

import re
import socket
import time

from .models import Server

_IPV4_RE = re.compile(r"^\d{1,3}(\.\d{1,3}){3}$")

# connect_ex 常见返回码 → 人话解释（Linux / macOS errno 都覆盖）
ERRNO_HINTS = {
    111: "Connection refused（端口未监听或服务未启动）",
    61:  "Connection refused（端口未监听或服务未启动）",
    113: "No route to host（不可达）",
    65:  "No route to host（不可达）",
    110: "Connection timed out（防火墙拦截/丢包）",
    60:  "Connection timed out（防火墙拦截/丢包）",
    35:  "EINPROGRESS：到超时仍未完成 TCP 握手（防火墙丢包或主机不可达）",
}


def is_ipv4(text: str) -> bool:
    return bool(_IPV4_RE.match(text))


def resolve_ipv4(hostname: str) -> tuple[str | None, str]:
    """解析域名为 IPv4；失败时返回 (None, 错误说明)。"""
    try:
        return socket.gethostbyname(hostname), ""
    except socket.gaierror as e:
        return None, f"DNS 解析失败: {e}（域名不存在或 DNS 服务器不可达）"
    except Exception as e:  # pragma: no cover - 兜底，永不崩
        return None, f"DNS 解析异常: {type(e).__name__}: {e}"


def tcp_connect(ip: str, port: int, timeout: float = 5.0) -> tuple[bool, str, float]:
    """TCP 连接探测；返回 (是否可达, 错误说明, 耗时秒)。"""
    start = time.monotonic()
    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.settimeout(timeout)
    try:
        rc = sock.connect_ex((ip, port))
        elapsed = time.monotonic() - start
        if rc == 0:
            return True, "", elapsed
        hint = ERRNO_HINTS.get(rc)
        error = f"TCP connect 返回码 {rc}" + (f" → {hint}" if hint else "")
        return False, error, elapsed
    except socket.timeout:
        return False, "TCP 连接超时（防火墙可能拦截了该端口）", time.monotonic() - start
    except ConnectionRefusedError:
        return False, "连接被拒绝（目标端口无 SSH 服务监听）", time.monotonic() - start
    except OSError as e:
        return False, f"TCP 检查失败: {e}", time.monotonic() - start
    except Exception as e:  # pragma: no cover - 兜底，永不崩
        return False, f"TCP 检查异常: {type(e).__name__}: {e}", time.monotonic() - start
    finally:
        sock.close()


def probe(server: Server, timeout: float = 5.0) -> dict:
    """对一台服务器做「DNS（按需）→ TCP」探测，返回 debug 字典。

    字段与原单文件版保持一致（addr_type / dns_skip / resolved_ip /
    tcp_ok / error / elapsed），方便报告渲染复用。
    """
    debug: dict = {"tcp_ok": False, "error": "", "elapsed": 0}
    addr = server.address

    if is_ipv4(addr):
        debug["addr_type"] = "IPv4"
        debug["dns_skip"] = True
        ip = addr
    else:
        debug["addr_type"] = "hostname"
        ip, dns_err = resolve_ipv4(addr)
        if not ip:
            debug["error"] = dns_err
            return debug
        debug["resolved_ip"] = ip

    ok, tcp_err, elapsed = tcp_connect(ip, server.port, timeout)
    debug["tcp_ok"] = ok
    debug["elapsed"] = round(elapsed, 3)
    if not ok:
        debug["error"] = (
            f"DNS 解析成功 ({ip})，但 {tcp_err}" if debug.get("resolved_ip") else tcp_err
        )
    return debug
