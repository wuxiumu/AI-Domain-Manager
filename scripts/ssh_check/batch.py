# -*- coding: utf-8 -*-
"""批量执行器：单台全链路（DNS/TCP → SSH）+ 线程池并发跑整池。"""

from __future__ import annotations

import concurrent.futures
import time
import traceback

from .models import Server, HostResult, ST_TCP_CLOSED, ST_ERROR
from .checks import probe
from .sshclient import ssh_login_test


def test_one(server: Server, timeout: float = 15,
             trust_host_key: bool = False, collect: bool = False) -> HostResult:
    """对单台服务器执行完整测试链，永不抛异常（异常落入 result）。"""
    res = HostResult(server=server)
    start = time.monotonic()
    try:
        # Step 1: DNS（域名时）→ TCP 可达性
        res.debug.update(probe(server, timeout))
        if not res.tcp_ok:
            # Step 2: 端口不通则跳过 SSH
            res.status = ST_TCP_CLOSED
            res.detail = res.debug.get("error") or "端口不通"
            res.debug["skip_ssh"] = True
            return res

        # Step 3: SSH 登录测试（collect=True 时附带整机配置采集）
        ssh = ssh_login_test(server, timeout, trust_host_key, collect)
        res.status = ssh["status"]
        res.os = ssh.get("os", "")
        res.uptime = ssh.get("uptime", "")
        res.detail = ssh.get("detail", "")
        res.debug.update(ssh.get("debug", {}))
        res.facts = ssh.get("facts", {})
    except Exception as e:  # 最后一道保险
        res.status = ST_ERROR
        res.detail = f"{type(e).__name__}: {e}"
        res.debug["traceback"] = traceback.format_exc()
    finally:
        res.debug["elapsed_s"] = round(time.monotonic() - start, 2)
    if isinstance(res.detail, str):
        res.detail = " / ".join(x.strip() for x in res.detail.splitlines() if x.strip())
    return res


def run_batch(servers: list[Server], workers: int = 8, timeout: float = 15,
              trust_host_key: bool = False, collect: bool = False,
              on_done=None) -> list[HostResult]:
    """并发执行整池测试，按输入顺序返回结果。

    on_done(result, done_count, total, elapsed_total) 每台完成时回调一次
    （控制台进度与事件日志都挂在它上面）。
    """
    total = len(servers)
    results: list[HostResult | None] = [None] * total
    overall_start = time.monotonic()

    with concurrent.futures.ThreadPoolExecutor(
        max_workers=max(1, min(workers, total or 1))
    ) as executor:
        future_to_idx = {
            executor.submit(test_one, s, timeout, trust_host_key, collect): i
            for i, s in enumerate(servers)
        }
        done = 0
        for future in concurrent.futures.as_completed(future_to_idx):
            idx = future_to_idx[future]
            try:
                result = future.result()
            except Exception as e:  # test_one 已兜底，这里只防御线程池本身
                result = HostResult(server=servers[idx], status=ST_ERROR,
                                    detail=f"{type(e).__name__}: {e}",
                                    debug={"traceback": traceback.format_exc()})
            results[idx] = result
            done += 1
            if on_done:
                on_done(result, done, total, time.monotonic() - overall_start)

    return [r for r in results if r is not None]
