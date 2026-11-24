# -*- coding: utf-8 -*-
"""SSH 账号池连通性测试 — 包入口（原 scripts/test-ssh-connect.py 已并入本包）。

用法（三种调用方式等价）:
    cd scripts && python3 -m ssh_check                      # 推荐
    python3 scripts/ssh_check/__main__.py                    # 任意目录直接执行
    PYTHONPATH=scripts python3 -m ssh_check                  # 仓库根免 cd

    python3 -m ssh_check /path/to/.env                       # 指定 .env
    python3 -m ssh_check --list                              # 只看清单，不触网
    python3 -m ssh_check --log report.log                    # 指定日志路径
    python3 -m ssh_check --no-console                        # 仅写日志不打印终端
    python3 -m ssh_check --json                              # JSON 结果（供脚本复用）

模块分工、.env 格式与设计说明见 ssh_check/README.md。
"""

import sys

if __package__ in (None, ""):
    # 以 `python3 ssh_check/__main__.py` 直接执行时补上包路径
    import pathlib

    sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))
    from ssh_check.cli import main
else:
    from .cli import main

if __name__ == "__main__":
    sys.exit(main())
