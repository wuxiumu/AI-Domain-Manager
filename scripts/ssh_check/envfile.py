# -*- coding: utf-8 -*-
""".env 服务器清单解析（纯文本解析，不触网、不读密钥内容）。

格式（每台服务器一个块，块间以空行或 ### 分隔）:

    # 全局默认（出现在任何块之前）
    ssh_user = aiadmin
    ssh_port = 22
    ssh_auth = password

    Txy 4H4G-70GB            ← 第 1 行：名称（label）
    192.0.2.10               ← 第 2 行：地址（IP 或 域名[:端口]）
    P@ssw0rd!                ← 第 3 行：密码（纯密钥登录时写 `-`，key/agent 模式可整行省略）
    ssh_port = 2222          ← 可选覆盖：本块端口
    ssh_user = root          ← 可选覆盖：本块用户
    ssh_key = ~/keys/id_ed25519   ← 可选：本块私钥（~ / 绝对 / 相对清单或模块目录）
    ssh_key_pass = 口令      ← 可选：私钥口令（被加密保护的私钥必需）
    ssh_auth = key           ← 可选：password（默认）/ key / auto / agent

规则:
    - `#` 开头整行为注释；`###` 为块分隔线。
    - 块内位置行按 label → address → password 顺序填充；
      `ssh_user =` / `ssh_port =` / `ssh_auth =` / `ssh_key =` / `ssh_key_pass =`
      行随时可作为本块覆盖，不必固定在末尾。
    - 地址行写成 `host:port` 时自动拆分出端口（冒号后须为纯数字，避免误拆 IPv6）。
    - `ssh_auth = key` / `agent` 的块允许只有两行（无需密码行）；
      其余模式不足三行的块会被跳过并返回告警。
    - 密码行写 `-` 表示「无密码」，用于纯密钥登录的机器。
    - 未写 `ssh_key` 时，密钥由 keys.attach 按地址在 key/ 目录里自动识别。
"""

from __future__ import annotations

import re

from .models import (
    Server, DEFAULT_USER, DEFAULT_PORT, DEFAULT_AUTH,
    AUTH_KEY, AUTH_AGENT, AUTH_AUTO, AUTH_PASSWORD, AUTH_ALIASES,
)

_OVERRIDE_RE = re.compile(r"^ssh_(user|port|auth|key_pass|key)\s*=\s*(.+)$")
_PORTABLE_HOST_RE = re.compile(r"^(?P<host>[0-9a-zA-Z._-]+):(?P<port>\d+)$")
_NO_PASSWORD = {"-", "--", "none", "null", "(none)"}


def _norm_auth(value: str) -> tuple[str, str]:
    """归一化 ssh_auth 取值；返回 (模式 或 "", 告警)。"""
    key = value.strip().lower().replace("_", "-")
    mode = AUTH_ALIASES.get(key, "")
    if not mode:
        return "", (f"ssh_auth 取值无法识别，已忽略: {value!r}"
                    f"（可用: {AUTH_PASSWORD}/{AUTH_KEY}/{AUTH_AUTO}/{AUTH_AGENT}）")
    return mode, ""


def _apply_override(store: dict, key: str, value: str) -> list[str]:
    """把 `ssh_user = X` / `ssh_port = N` / `ssh_auth = M` / `ssh_key = 路径` 等写入 store。"""
    value = value.strip()
    if key == "user":
        store["user"] = value
        return []
    if key == "port":
        try:
            store["port"] = int(value)
        except ValueError:
            return [f"ssh_port 取值无法解析为整数，已忽略: {value!r}"]
        return []
    if key == "auth":
        mode, warn = _norm_auth(value)
        if warn:
            return [warn]
        store["auth"] = mode
        return []
    if key == "key":
        store["key"] = value
        return []
    if key == "key_pass":
        store["key_pass"] = value
        return []
    return [f"未知覆盖项 ssh_{key}，已忽略: {value!r}"]


def parse_env(filepath: str) -> tuple[list[Server], list[str]]:
    """解析清单文件，返回 (服务器列表, 告警列表)。"""
    with open(filepath, "r", encoding="utf-8") as f:
        lines = [l.rstrip("\n\r") for l in f]

    defaults: dict = {"user": DEFAULT_USER, "port": DEFAULT_PORT,
                      "auth": DEFAULT_AUTH, "key": "", "key_pass": ""}
    servers: list[Server] = []
    warnings: list[str] = []

    pos: list[str] = []   # 当前块的按位行（label/address/password）
    ov: dict = {}         # 当前块的覆盖
    in_block = False

    def finish() -> None:
        nonlocal in_block, pos, ov
        if not in_block:
            return
        in_block, block_pos, block_ov = False, pos, ov
        pos, ov = [], {}

        mode = block_ov.get("auth", defaults["auth"])
        has_key = bool(block_ov.get("key", defaults["key"]))
        # key / agent 模式不需要密码行；auto 且显式给了密钥也可省略
        need = 2 if mode in (AUTH_KEY, AUTH_AGENT) or (mode == AUTH_AUTO and has_key) else 3
        if len(block_pos) < need:
            name = block_pos[0] if block_pos else "(空)"
            hint = ""
            if len(block_pos) == 2 and mode == AUTH_PASSWORD:
                hint = ("；若这台是纯密钥登录，请在本块内（地址行之后）补一行 ssh_auth = key —— "
                        "写在上一块末尾会被当作上一块的覆盖，不作用于本块")
            warnings.append(f"块「{name}」不足 label/地址"
                            + ("/密码" if need == 3 else "") + f" {need} 行，已跳过{hint}")
            return
        password = ""
        if len(block_pos) >= 3:
            raw_pass = block_pos[2].strip()
            password = "" if raw_pass.lower() in _NO_PASSWORD else block_pos[2]
        servers.append(Server(
            label=block_pos[0],
            address=block_pos[1],
            password=password,
            user=block_ov.get("user", defaults["user"]),
            port=int(block_ov.get("port", defaults["port"])),
            auth=mode,
            key_raw=block_ov.get("key", defaults["key"]),
            key_passphrase=block_ov.get("key_pass", defaults["key_pass"]),
        ))

    for lineno, raw_line in enumerate(lines, 1):
        raw = raw_line.strip()

        if raw == "###":
            finish()
            continue
        if not raw:
            finish()          # 空行终止当前块（注释行仅跳过，不打断块）
            continue
        if raw.startswith("#"):
            continue

        m = _OVERRIDE_RE.match(raw)
        if m:
            target = ov if in_block else defaults
            warns = _apply_override(target, m.group(1), m.group(2))
            warnings.extend(f"第 {lineno} 行: {w}" for w in warns)
            continue

        if len(pos) >= 3:
            finish()          # 第 4 条位置行 → 新块开始
        if not pos and not in_block:
            in_block = True

        if len(pos) == 1:
            # 地址行：支持 host:port 简写（仅当冒号后为纯数字端口，
            # 避免误拆 IPv6 地址）
            hp = _PORTABLE_HOST_RE.match(raw)
            if hp:
                pos.append(hp.group("host"))
                ov["port"] = int(hp.group("port"))
                continue
        pos.append(raw)

    finish()
    return servers, warnings
