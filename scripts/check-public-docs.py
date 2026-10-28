#!/usr/bin/env python3
"""Check public documentation without reading runtime data or echoing findings."""

from __future__ import annotations

import ipaddress
from pathlib import Path
import re
import sys
from urllib.parse import unquote, urlsplit


PROJECT = Path(__file__).resolve().parents[1]
NUMBERED = re.compile(r"^\d{2}-.+")
IPV4 = re.compile(r"(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])")
IPV6 = re.compile(r"(?<![\w:])(?:[0-9a-fA-F]{0,4}:){2,}[0-9a-fA-F:.]*(?![\w:])")
DOMAIN = re.compile(r"(?<![\w.-])(?:\w(?:[\w-]*\w)?\.)+[a-zA-Z]{2,63}(?![\w.-])")
URL = re.compile(r"https?://[^\s<>`\"')]+", re.I)
LINK = re.compile(r"!?\[[^\]]*\]\(([^)]+)\)")
DOCUMENT_NETWORKS = tuple(ipaddress.ip_network(value) for value in (
    "192.0.2.0/24", "198.51.100.0/24", "203.0.113.0/24", "2001:db8::/32",
))
EXAMPLE_ROOTS = {"example.com", "example.net", "example.org"}
# Only public references used by this repository; adding a host requires review.
PUBLIC_HOSTS = {
    "api.alibabacloud.com", "api.github.com", "classic-developer.godaddy.com",
    "github.com", "betterstack.com", "docs.dnscontrol.org", "api.godaddy.com",
    "help.aliyun.com", "cloud.tencent.com",
}
FILE_SUFFIXES = {
    "md", "php", "js", "mjs", "css", "json", "py", "sh", "txt", "lock",
    "png", "jpg", "jpeg", "webp", "svg", "gif", "pdf", "zip", "yaml", "yml",
}
# Reviewed screenshots captured from an isolated demo installation with synthetic data.
REVIEWED_IMAGES = {
    'public/assets/screenshots/login.png',
    'public/assets/screenshots/dashboard.png',
    'public/assets/screenshots/domains.png',
}
SECRET_PATTERNS = (
    re.compile(r"\b(?:AKIA|ASIA)[A-Z0-9]{16}\b"),
    re.compile(r"\bLTAI[A-Za-z0-9]{12,}\b"),
    re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----"),
    re.compile(r"\bBearer\s+[A-Za-z0-9_.~+-]{20,}", re.I),
    re.compile(r"https?://[^\s/]+:[^\s/]+@", re.I),
)


def example_ip(value: str) -> bool:
    try:
        address = ipaddress.ip_address(value)
    except ValueError:
        return False
    return str(address) in {"0.0.0.0", "127.0.0.1", "::1"} or any(
        address.version == network.version and address in network
        for network in DOCUMENT_NETWORKS
    )


def public_host(value: str) -> bool:
    host = value.lower().rstrip(".")
    return (
        host in PUBLIC_HOSTS | EXAMPLE_ROOTS | {"localhost", "ip"}
        or any(host.endswith("." + root) for root in EXAMPLE_ROOTS)
        or host.endswith((".test", ".invalid", ".localhost"))
        or example_ip(host)
    )


def text_findings(content: str) -> list[tuple[int, str]]:
    findings = []
    for number, line in enumerate(content.splitlines(), 1):
        rules = set()
        for match in IPV4.finditer(line):
            try:
                ipaddress.ip_address(match.group())
            except ValueError:
                continue
            if not example_ip(match.group()):
                rules.add("non_example_ip")
        for match in IPV6.finditer(line):
            candidate = match.group().rstrip(".")
            try:
                ipaddress.ip_address(candidate)
            except ValueError:
                continue
            if not example_ip(candidate):
                rules.add("non_example_ip")
        for match in URL.finditer(line):
            try:
                host = urlsplit(match.group()).hostname
            except ValueError:
                host = None
            if not host or not public_host(host):
                rules.add("unreviewed_url_host")
        for match in DOMAIN.finditer(line):
            candidate = match.group()
            if candidate in {"http.proxy", "nginx.conf.example"}:  # Known config key / repository example filename, not hosts.
                continue
            if candidate.rsplit(".", 1)[-1].lower() in FILE_SUFFIXES:
                continue
            if not public_host(candidate):
                rules.add("non_example_domain")
        if any(pattern.search(line) for pattern in SECRET_PATTERNS):
            rules.add("possible_credential")
        if re.search(r"/(?:Users|home)/[^\s/]+/", line):
            rules.add("personal_absolute_path")
        if re.search(r"!\[[^\]]*\]\(|<img\b|data:image/", line, re.I):
            rules.add("unreviewed_image")
        findings.extend((number, rule) for rule in sorted(rules))
    return findings


def heading_ids(content: str) -> set[str]:
    counts: dict[str, int] = {}
    anchors = set()
    for title in re.findall(r"^#{1,6}\s+(.+)$", content, re.M):
        anchor = re.sub(r"[^\w\-\s]", "", title.lower()).replace(" ", "-")
        occurrence = counts.get(anchor, 0)
        counts[anchor] = occurrence + 1
        anchors.add(anchor + (f"-{occurrence}" if occurrence else ""))
    return anchors


def check(root: Path) -> list[tuple[str, int, str]]:
    root = root.resolve()
    docs = root / "docs"
    errors: list[tuple[str, int, str]] = []
    if not (docs / "00-目录.md").is_file():
        errors.append(("docs", 0, "missing_document_index"))
    files = [root / "README.md"]
    for path in sorted(docs.rglob("*")):
        relative = path.relative_to(root).as_posix()
        if path.is_symlink():
            errors.append((relative, 0, "symlink_not_allowed"))
            continue
        if not NUMBERED.match(path.name):
            errors.append((relative, 0, "numbered_name_required"))
        if path.is_dir():
            if not (path / "00-目录.md").is_file():
                errors.append((relative, 0, "missing_category_index"))
        elif path.suffix != ".md":
            errors.append((relative, 0, "unreviewed_attachment"))
        else:
            files.append(path)
    for path in files:
        relative = path.relative_to(root).as_posix()
        if not path.is_file():
            errors.append((relative, 0, "missing_document"))
            continue
        try:
            content = path.read_text(encoding="utf-8")
        except UnicodeError:
            errors.append((relative, 0, "invalid_utf8"))
            continue
        lines = content.splitlines()
        for line, rule in text_findings(content):
            if rule == 'unreviewed_image' and relative == 'README.md':
                image = re.fullmatch(r'!\[[^\]]*\]\(([^)]+)\)', lines[line - 1].strip())
                if image and image.group(1) in REVIEWED_IMAGES:
                    continue
            errors.append((relative, line, rule))
        if content.count("```") % 2:
            errors.append((relative, 0, "unclosed_code_fence"))
        for match in LINK.finditer(content):
            target = match.group(1)
            if re.match(r"[\w+.-]+:", target):
                continue
            name, _, fragment = target.partition("#")
            destination = (path.parent / unquote(name)).resolve() if name else path
            line = content[:match.start()].count("\n") + 1
            if root not in destination.parents:
                errors.append((relative, line, "link_outside_repository"))
            elif destination.relative_to(root).parts[0] in {"data", "config", ".local", ".git"}:
                errors.append((relative, line, "private_target_link"))
            elif not destination.exists():
                errors.append((relative, line, "broken_local_link"))
            elif fragment and destination.suffix == ".md":
                if unquote(fragment) not in heading_ids(destination.read_text(encoding="utf-8")):
                    errors.append((relative, line, "broken_heading_link"))
    return errors


def main() -> int:
    findings = check(PROJECT)
    if findings:
        for path, line, rule in findings:
            print(f"{path}:{line}: {rule}")
        print(f"Public documentation check failed: {len(findings)} finding(s). Values are redacted.")
        return 1
    print("Public documentation check passed: naming, links, address policy and attachment policy.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
