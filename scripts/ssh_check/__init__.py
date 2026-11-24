# -*- coding: utf-8 -*-
"""ssh_check — SSH 账号池连通性测试工具包（批量 / 并发 / 日志 / 可复用）。

模块分工（依赖方向自上而下，无环）:
    models     数据模型与状态常量（Server / HostResult / 认证模式 / 状态分类）
    envfile    .env 服务器清单解析（纯文本，不触网）
    keys       私钥定位与可用性校验（纯本地文件，不触网）
    metrics    资源阈值判定与跨次对比（--report 的判定内核，不触网）
    checks     DNS / TCP 层探测（socket，不依赖 paramiko）
    sshclient  SSH 登录测试（密码 / 密钥 / agent 多方式尝试，paramiko 优先，ssh+sshpass 兜底）
    batch      单台全链路编排 + 并发批量执行器
    report     报告渲染（控制台彩色 / 纯文本同一版式，另有面向 AI 的服务器运行报告）
    runlog     运行日志（logging 事件流 + 报告落盘）
    cli        命令行入口（argparse 装配以上模块）

作为库复用:
    from ssh_check import parse_env, attach_keys, run_batch, render
    servers, warns = parse_env(".env")
    attach_keys(servers, env_path=".env")     # 用了 ssh_key / ssh_auth 就必须先挂密钥
    results = run_batch(servers, workers=8, timeout=15, collect=True)
    print(render(results, color=False))

给其他 AI / 定时任务（纯命令行，不依赖本项目 Web 端）:
    python3 -m ssh_check --report            # 阈值异常 + 与上次对比 + 全量明细
    python3 -m ssh_check --report --json     # 同一数据的机器可读输出
"""

__version__ = "2.2.0"

from .models import Server, HostResult
from .envfile import parse_env
from .keys import attach as attach_keys
from .checks import probe
from .sshclient import ssh_login_test
from .batch import test_one, run_batch
from .report import render

__all__ = [
    "Server",
    "HostResult",
    "parse_env",
    "attach_keys",
    "probe",
    "ssh_login_test",
    "test_one",
    "run_batch",
    "render",
    "__version__",
]
