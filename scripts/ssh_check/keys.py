# -*- coding: utf-8 -*-
"""私钥定位与本地校验（纯文件操作，不触网、不登录）。

职责：
    - 解析 .env 里 `ssh_key =` 的路径（支持 ~ / 绝对 / 相对清单目录 / 相对模块目录）；
    - 未显式声明时按地址在密钥目录里自动识别（`192.0.2.10`、`192.0.2.10.pem`、…）；
    - 事先拦下三类「一定登不上」的情况，给出可直接照做的修复文案：
        文件不存在 / 权限过宽（OpenSSH 拒绝加载）/ 私钥被口令保护而未提供 ssh_key_pass；
    - 顺带提醒：私钥若落在仓库里且没被 git 忽略，绝不应当提交。

一切结论写回 Server.key_path / key_origin / key_problem / key_note，
由 sshclient 决定如何呈现，本模块不产生测试状态。
"""

from __future__ import annotations

import base64
import os
import subprocess
from pathlib import Path

from .models import Server, AUTH_PASSWORD, AUTH_KEY, AUTH_AGENT

_MODULE_DIR = Path(__file__).resolve().parent
_REPO_ROOT = _MODULE_DIR.parents[1]

# 自动识别时依次尝试的文件名（{addr} 为 .env 里的地址，不含端口）
_NAME_TEMPLATES = ("{addr}", "{addr}.pem", "{addr}.key", "{addr}.private", "{addr}-key",
                   "id_{addr}")


def search_dirs(env_path: str = "", extra: list[str] | None = None) -> list[Path]:
    """密钥搜索目录：命令行 --key-dir → 清单同级 key/ → 模块内 key/ → 清单目录。"""
    dirs: list[Path] = []
    env_dir = Path(env_path).resolve().parent if env_path else None

    for raw in (extra or []):
        p = Path(raw).expanduser()
        dirs.append(p if p.is_absolute() else (env_dir or _MODULE_DIR) / p)
    if env_dir:
        dirs.append(env_dir / "key")
    dirs.append(_MODULE_DIR / "key")
    if env_dir:
        dirs.append(env_dir)

    seen: list[Path] = []
    for d in dirs:
        try:
            real = d.resolve()
        except OSError:
            continue
        if real not in seen and real.is_dir():
            seen.append(real)
    return seen


def _dir_tags(dirs: list[Path], env_dir: Path | None) -> dict:
    """给每个搜索目录一个人类可读的来源标签（报告与 --list 里说明密钥从哪来）。"""
    module_key = _MODULE_DIR / "key"
    env_key = (env_dir / "key") if env_dir else None
    tags = {}
    for d in dirs:
        if d == module_key:
            tags[str(d)] = "模块 key/ 自动识别"
        elif env_key and d == env_key:
            tags[str(d)] = "清单同级 key/ 自动识别"
        elif env_dir and d == env_dir:
            tags[str(d)] = "清单目录自动识别"
        else:
            tags[str(d)] = "--key-dir 自动识别"
    return tags


def resolve_path(raw: str, env_dir: Path | None) -> str:
    """把声明的密钥路径解析为绝对路径；找不到文件返回 ""。"""
    if not raw:
        return ""
    p = Path(raw).expanduser()
    if p.is_absolute():
        return str(p) if p.is_file() else ""
    bases = [env_dir, _MODULE_DIR, Path.cwd(), _REPO_ROOT]
    for base in bases:
        if not base:
            continue
        cand = (base / p).resolve()
        if cand.is_file():
            return str(cand)
    return ""


def discover(address: str, dirs: list[Path], tags: dict | None = None) -> tuple[str, str]:
    """按地址在目录里找密钥；返回 (绝对路径 或 "", 来源说明 或 "")。

    文件名模板优先（先在所有目录找 `192.0.2.10.pem`，再找 `192.0.2.10`），
    这样清单目录与模块目录里同名文件的行为可预测。
    """
    name = (address or "").split("%")[0].replace(":", "_")
    if not name:
        return "", ""
    tags = tags or {}
    for tmpl in _NAME_TEMPLATES:
        for d in dirs:
            cand = d / tmpl.format(addr=name)
            try:
                if cand.is_file():
                    return str(cand.resolve()), tags.get(str(d), "目录自动识别")
            except OSError:
                continue
    return "", ""


def _openssh_cipher(data: bytes) -> str:
    """OpenSSH 新格式私钥（openssh-key-v1）的首个字段即 ciphername；"none" 表示未加密。"""
    magic = b"openssh-key-v1\x00"
    if not data.startswith(magic):
        return ""
    body = data[len(magic):]
    if len(body) < 4:
        return ""
    n = int.from_bytes(body[:4], "big")
    if n <= 0 or n > len(body) - 4 or n > 64:
        return ""
    return body[4:4 + n].decode("utf-8", errors="replace")


def _pem_payload(data: bytes) -> bytes:
    """取 PEM 信封内的原始字节（macOS / OpenSSH 常把 openssh-key-v1 再包一层 PEM）。"""
    body, inside = [], False
    for line in data.decode("latin-1").splitlines():
        line = line.strip()
        if line.startswith("-----BEGIN"):
            inside = True
            continue
        if line.startswith("-----END"):
            break
        if inside:
            if not body and ":" in line:     # RFC1421 头（Proc-Type / DEK-Info）不是 base64
                continue
            body.append(line)
    if not body:
        return b""
    try:
        return base64.b64decode("".join(body))
    except Exception:
        return b""


def is_encrypted(path: str) -> tuple[bool, str]:
    """私钥是否被口令保护；返回 (是否加密, 算法名)。"""
    try:
        with open(path, "rb") as f:
            data = f.read(65536)
    except OSError:
        return False, ""
    cipher = _openssh_cipher(data) or _openssh_cipher(_pem_payload(data))
    if cipher:
        return cipher != "none", cipher
    head = data.decode("latin-1").upper()
    if "ENCRYPTED" in head or "PROC-TYPE: 4,ENCRYPTED" in head:
        return True, "PEM 旧式加密"
    return False, ""


def _perms_bad(path: str) -> bool:
    """组/其他用户可读（OpenSSH 会直接拒绝加载这类私钥）。"""
    try:
        return bool(os.stat(path).st_mode & 0o077)
    except OSError:
        return False


def repo_exposure_warning(path: str) -> str:
    """私钥落在仓库内且没被 git 忽略时给出提醒（外部目录 / 非 git 仓库返回空）。"""
    try:
        Path(path).relative_to(_REPO_ROOT)
    except ValueError:
        return ""
    if not (_REPO_ROOT / ".git").exists():
        return ""
    try:
        proc = subprocess.run(
            ["git", "-C", str(_REPO_ROOT), "check-ignore", "-q", "--", path],
            capture_output=True, timeout=5,
        )
    except (OSError, subprocess.TimeoutExpired):
        return ""
    if proc.returncode == 0:
        return ""                      # 已被 .gitignore 覆盖
    if proc.returncode == 1:
        return (f"私钥位于仓库内且未被 git 忽略，切勿提交：{path}"
                "（建议移到仓库外，或在 .gitignore 补路径）")
    return ""


def attach(servers: list[Server], env_path: str = "", extra_dirs: list[str] | None = None,
           default_key: str = "", force_auth: str = "") -> list[str]:
    """解析并校验每台服务器的私钥，写回 key_path / key_origin / key_problem / key_note。

    纯本地文件操作：不触网、不登录、不读取私钥内容之外的信息；返回告警列表。
    """
    warnings: list[str] = []
    env_dir = Path(env_path).resolve().parent if env_path else None
    dirs = search_dirs(env_path, extra_dirs)
    tags = _dir_tags(dirs, env_dir)
    dir_hint = "、".join(str(d) for d in dirs) or "（无可用密钥目录）"

    for s in servers:
        if force_auth:
            s.auth = force_auth
        s.key_note = ""
        s.key_problem = ""

        if s.auth == AUTH_AGENT:          # 用本机默认身份，清单里的私钥不参与
            continue

        raw = s.key_raw or default_key
        if raw:
            origin = "显式声明" if s.key_raw else "命令行 --key"
            path = resolve_path(raw, env_dir)
            if not path:
                if s.auth != AUTH_PASSWORD:     # 密码模式根本不碰密钥，不报问题
                    s.key_problem = f"ssh_key 指向的文件不存在或不可读：{raw}"
                    warnings.append(f"{s.label}: {s.key_problem}")
                continue
            s.key_path, s.key_origin = path, origin
        else:
            path, origin = discover(s.address, dirs, tags)
            if path:
                s.key_path, s.key_origin = path, origin
            elif s.auth == AUTH_KEY:
                s.key_problem = f"未找到密钥文件（已搜索 {dir_hint}）"
                warnings.append(f"{s.label}: {s.key_problem}")
                continue

        # password 模式只解析不做校验（供 --list 展示），key / auto 才真正要用
        if not s.key_path or s.auth == AUTH_PASSWORD:
            continue
        warnings.extend(_check_usable(s))

    return warnings


def _check_usable(s: Server) -> list[str]:
    """私钥可用性校验：权限过宽 / 被口令保护却无口令 / 落在仓库里没被忽略。"""
    warnings = []
    if _perms_bad(s.key_path):
        s.key_problem = f"私钥权限过宽，OpenSSH 会拒绝加载：{s.key_path}"
        s.key_note = f"修复：chmod 600 '{s.key_path}'"
        warnings.append(f"{s.label}: {s.key_problem}")
        return warnings
    enc, cipher = is_encrypted(s.key_path)
    if enc and not s.key_passphrase:
        s.key_problem = f"私钥被口令保护（{cipher}），但未提供口令：{s.key_path}"
        s.key_note = "在本块补一行 ssh_key_pass = 私钥口令"
        warnings.append(f"{s.label}: {s.key_problem}")
        return warnings
    note = repo_exposure_warning(s.key_path)
    if note:
        s.key_note = note
        warnings.append(f"{s.label}: {note}")
    return warnings
