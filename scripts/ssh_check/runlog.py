# -*- coding: utf-8 -*-
"""运行日志：标准库 logging 事件流 + 末尾追加纯文本报告，落一个文件。

日志文件结构:
    <事件行>  时间戳 + 级别 + 每台服务器完成事件、告警、汇总
    <空行>
    <报告块>  render(results, color=False) 的纯文本报告
"""

from __future__ import annotations

import logging
import os
from datetime import datetime
from pathlib import Path

logger = logging.getLogger("ssh_check")

_DEFAULT_DIRNAME = ".logs"
_PREFIX = "ssh-test"


def default_log_path(start_dir: Path | None = None) -> str:
    """默认日志路径：<仓库根>/.logs/ssh-test-YYYYMMDD_HHMMSS.log。"""
    root = start_dir or Path(__file__).resolve().parents[2]
    ts = datetime.now().strftime("%Y%m%d_%H%M%S")
    return str(root / _DEFAULT_DIRNAME / f"{_PREFIX}-{ts}.log")


def default_inventory_path() -> str:
    """默认配置清单路径：<仓库根>/.logs/inventory-YYYYMMDD_HHMMSS.md。"""
    root = Path(__file__).resolve().parents[2]
    ts = datetime.now().strftime("%Y%m%d_%H%M%S")
    return str(root / _DEFAULT_DIRNAME / f"inventory-{ts}.md")


def default_report_dir(start_dir: Path | None = None) -> Path:
    """服务器报告目录：<仓库根>/.logs/ssh-report/（report.md 固定路径 + 时间戳归档 + state.json）。"""
    root = start_dir or Path(__file__).resolve().parents[2]
    return root / _DEFAULT_DIRNAME / "ssh-report"


def setup(log_path: str, verbose: bool = False) -> logging.FileHandler:
    """初始化文件日志（DEBUG 或 INFO 级），返回 handler 供收尾。"""
    log_dir = os.path.dirname(log_path)
    if log_dir:
        os.makedirs(log_dir, exist_ok=True)
    handler = logging.FileHandler(log_path, mode="w", encoding="utf-8")
    handler.setLevel(logging.DEBUG if verbose else logging.INFO)
    handler.setFormatter(logging.Formatter(
        "%(asctime)s %(levelname)-7s %(message)s", datefmt="%Y-%m-%d %H:%M:%S"
    ))
    logger.handlers.clear()
    logger.addHandler(handler)
    logger.setLevel(logging.DEBUG)
    logger.propagate = False
    return handler


def append_report(handler: logging.FileHandler, text: str) -> None:
    """事件日志写完后，把纯文本报告追加进同一文件。"""
    handler.flush()
    with open(handler.baseFilename, "a", encoding="utf-8") as f:
        f.write("\n" + text)
