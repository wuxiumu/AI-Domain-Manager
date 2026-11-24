# -*- coding: utf-8 -*-
"""数据模型与状态常量：Server（清单项）与 HostResult（单台测试结果）。"""

from __future__ import annotations

from dataclasses import dataclass, field

# 清单默认值（可被 .env 全局默认与块内覆盖修改）
DEFAULT_USER = "aiadmin"
DEFAULT_PORT = 22
DEFAULT_AUTH = "password"

# ---------------------------------------------------------------------------
# 认证模式（.env 里 ssh_auth = 逐块覆盖；命令行 --auth 强制全局覆盖）
# ---------------------------------------------------------------------------
AUTH_PASSWORD = "password"  # 默认：只验证 .env 里的密码（账号池审计语义）
AUTH_KEY = "key"            # 只用密钥（ssh_key = 路径，或 key/ 目录按地址自动识别）
AUTH_AUTO = "auto"          # 先密码、密码不通再密钥；报告标注最终生效的方式
AUTH_AGENT = "agent"        # 用本机默认身份（ssh-agent；CLI 兜底时含 ~/.ssh/config）

VALID_AUTH = (AUTH_PASSWORD, AUTH_KEY, AUTH_AUTO, AUTH_AGENT)
AUTH_ALIASES = {
    "pwd": AUTH_PASSWORD, "pass": AUTH_PASSWORD, "password": AUTH_PASSWORD,
    "pubkey": AUTH_KEY, "publickey": AUTH_KEY, "key": AUTH_KEY,
    "auto": AUTH_AUTO, "both": AUTH_AUTO,
    "agent": AUTH_AGENT, "default": AUTH_AGENT, "local": AUTH_AGENT,
}

# 认证方式展示名（报告与 JSON 用）
AUTH_LABEL = {
    "password": "密码",
    "publickey": "密钥",
    "agent": "本机身份",
    "none": "未认证",
}

# ssh_auth 模式的一行说明（--list 与异常详情用）
AUTH_MODE_DESC = {
    AUTH_PASSWORD: "只验密码",
    AUTH_KEY: "只用密钥",
    AUTH_AUTO: "先密码、不通再密钥",
    AUTH_AGENT: "本机默认身份(agent/config)",
}

# ---------------------------------------------------------------------------
# 测试状态
# ---------------------------------------------------------------------------
ST_CONNECTED = "connected"        # SSH 登录成功
ST_AUTH_FAILED = "auth_failed"    # 密码错误
ST_NO_AUTH_METHOD = "no_auth_method"  # 服务端不接受密码认证
ST_NO_CREDENTIAL = "no_credential"    # 本模式所需凭据缺失/不可用（无密码、无密钥文件、权限过宽、被口令保护）
ST_TCP_CLOSED = "tcp_closed"      # 端口不通（跳过 SSH）
ST_TIMEOUT = "timeout"            # 连接超时
ST_SSH_ERROR = "ssh_error"        # SSH 协议层异常（主机密钥 / 协商等）
ST_TOOL_MISSING = "tool_missing"  # 缺少 paramiko / sshpass
ST_ERROR = "error"                # 其他异常
ST_UNKNOWN = "unknown"

# 汇总分类：正常 / 登录层失败 / 异常与跳过
_OK_STATUSES = {ST_CONNECTED}
_FAILED_STATUSES = {ST_AUTH_FAILED, ST_NO_AUTH_METHOD, ST_NO_CREDENTIAL, ST_TOOL_MISSING}


def classify(status: str) -> str:
    """状态 → 汇总分组："ok" 正常、"failed" 登录失败、"other" 异常/跳过。"""
    if status in _OK_STATUSES:
        return "ok"
    if status in _FAILED_STATUSES:
        return "failed"
    return "other"


def summarize(results: list[HostResult]) -> dict:
    """按 classify 汇总一组结果。"""
    counts = {"ok": 0, "failed": 0, "other": 0}
    for r in results:
        counts[classify(r.status)] += 1
    counts["total"] = len(results)
    return counts


@dataclass
class Server:
    """一台待测服务器（来自 .env 清单）。

    凭据三件套：`password`（密码）、`key_path`（私钥，已解析为绝对路径）、
    `key_passphrase`（私钥口令，可选）。`auth` 决定用哪条腿登录，见 AUTH_* 常量；
    `key_origin` 记录密钥来源（显式声明 / 目录自动识别 / 命令行），供报告与 --list 展示。
    """

    label: str
    address: str
    user: str = DEFAULT_USER
    port: int = DEFAULT_PORT
    password: str = ""
    auth: str = DEFAULT_AUTH
    key_raw: str = ""
    key_path: str = ""
    key_origin: str = ""
    key_passphrase: str = ""
    key_problem: str = ""   # 阻断性问题（文件不存在 / 权限过宽 / 被口令保护），由 keys.attach 填入
    key_note: str = ""      # 非阻断提醒（如私钥位于仓库内且未被 git 忽略）

    def display(self) -> str:
        """地址展示：非默认端口时附带端口。"""
        return self.address if self.port == DEFAULT_PORT else f"{self.address}:{self.port}"

    def auth_desc(self) -> str:
        """清单声明的认证模式 + 密钥来源（--list 与异常详情用，不触网）。"""
        text = f"{self.auth}（{AUTH_MODE_DESC.get(self.auth, self.auth)}）"
        if self.key_path:
            name = self.key_path.rsplit("/", 1)[-1]
            tail = f" · 密钥 {name}" + (f"（{self.key_origin}）" if self.key_origin else "")
            text += tail if self.auth != AUTH_PASSWORD else tail + "·未参与"
        elif self.auth == AUTH_KEY:
            text += " · 未找到密钥"
        return text


@dataclass
class HostResult:
    """单台服务器的完整测试结果。debug 承载排查细节（DNS/TCP/SSH 各层）。"""

    server: Server
    status: str = ST_UNKNOWN
    detail: str = ""
    os: str = ""
    uptime: str = ""
    debug: dict = field(default_factory=dict)
    facts: dict = field(default_factory=dict)  # --inventory 采集的整机配置

    # -- 便捷访问器（report / cli 共用，避免到处 dict.get） --
    @property
    def label(self) -> str:
        return self.server.label

    @property
    def tcp_ok(self) -> bool:
        return bool(self.debug.get("tcp_ok"))

    @property
    def elapsed(self) -> float | None:
        return self.debug.get("elapsed_s")

    @property
    def group(self) -> str:
        return classify(self.status)

    @property
    def auth_method(self) -> str:
        """实际生效的认证方式："password" / "publickey" / "agent"，未登录成功为 ""。"""
        if self.status != ST_CONNECTED:
            return ""
        return self.debug.get("auth_method", "password")

    @property
    def auth_desc(self) -> str:
        """认证方式中文展示：密码 / 密钥(文件名) / 本机身份；失败的行返回空。"""
        method = self.auth_method
        if not method:
            return ""
        base = AUTH_LABEL.get(method, method)
        if method == "publickey":
            key_name = self.debug.get("auth_key", "")
            if key_name:
                return f"{base}({key_name.rsplit('/', 1)[-1]})"
        return base
