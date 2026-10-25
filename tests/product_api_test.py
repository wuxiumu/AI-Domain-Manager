"""Offline HTTP integration: disposable installations, no real cloud credentials/calls."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

PROJECT = Path(__file__).resolve().parents[1]
ASSERTIONS = 0


def check(condition, message):
    global ASSERTIONS
    ASSERTIONS += 1
    if not condition:
        raise AssertionError(message)


class Installation:
    def __init__(self, legacy=False):
        self.directory = tempfile.TemporaryDirectory(prefix="workspace-product-test-")
        self.root = Path(self.directory.name)
        for folder in ["app", "public", "scripts"]:
            shutil.copytree(PROJECT / folder, self.root / folder)
        (self.root / "config").mkdir()
        (self.root / "data").mkdir()
        (self.root / "data/domains.json").write_text(json.dumps([
            {"domain": "example.com", "provider": "manual", "valid": True, "note": "offline fixture"}
        ]))
        if legacy:
            (self.root / "config/config.php").write_text("<?php return ['auth'=>['user'=>'legacy','pass'=>'legacy-password-test'],'accounts'=>[]];")
        with socket.socket() as listener:
            listener.bind(("127.0.0.1", 0))
            self.port = listener.getsockname()[1]
        self.base = f"http://127.0.0.1:{self.port}"
        self.process = None
        self.log = tempfile.TemporaryFile()
        self.cookies = http.cookiejar.CookieJar()
        self.browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))
        self.start()

    def start(self):
        self.process = subprocess.Popen([shutil.which("php"), "-S", f"127.0.0.1:{self.port}", "-t", "public", "public/index.php"],
                                        cwd=self.root, stdout=self.log, stderr=self.log)
        for _ in range(200):
            try:
                self.request("GET", "/api/setup/status")
                return
            except urllib.error.URLError:
                if self.process.poll() is not None:
                    raise RuntimeError("PHP test server exited")
                time.sleep(.05)
        raise RuntimeError("PHP test server did not become ready")

    def stop(self):
        if self.process and self.process.poll() is None:
            self.process.terminate()
            self.process.wait(timeout=10)

    def close(self):
        self.stop()
        self.log.close()
        self.directory.cleanup()

    def request(self, method, path, body=None, csrf=None, token=None, anonymous=False):
        headers = {"Content-Type": "application/json"}
        if csrf is not None:
            headers["X-CSRF-Token"] = csrf
        if token is not None:
            headers["Authorization"] = "Bearer " + token
        request = urllib.request.Request(self.base + path, data=None if body is None else json.dumps(body).encode(), headers=headers, method=method)
        opener = urllib.request.build_opener() if anonymous else self.browser
        try:
            response = opener.open(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        raw = response.read().decode()
        try:
            result = json.loads(raw)
        except json.JSONDecodeError:
            result = raw
        return response.status, result, response.headers


fresh = legacy = None
try:
    fresh = Installation()
    status, setup, _ = fresh.request("GET", "/api/setup/status")
    check(status == 200 and setup["needs_setup"], "Missing config starts first-run flow")
    csrf = setup["csrf_token"]
    check(fresh.request("GET", "/api/health")[0] == 401, "Anonymous health is protected")
    check(fresh.request("GET", "/api/settings")[0] == 401, "Anonymous settings are protected")
    subprocess.run([shutil.which("php"), "scripts/setup-key.php"], cwd=fresh.root, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    key = (fresh.root / "config/setup.key").read_text().strip()
    admin = {"user": "preview", "password": "preview-password-test", "setup_key": key}
    check(fresh.request("POST", "/api/setup", admin)[0] == 403, "Setup requires CSRF and session cookie")
    check(fresh.request("POST", "/api/setup", {**admin, "setup_key": "wrong"}, csrf=csrf)[0] == 422, "Setup requires server-side owner key")
    status, identity, headers = fresh.request("POST", "/api/setup", admin, csrf=csrf)
    check(status == 200 and identity["user"] == "preview", "Setup creates and logs in administrator")
    check(headers.get("Cache-Control") == "no-store", "Setup credentials response is not cached")
    csrf = identity["csrf_token"]
    check(not (fresh.root / "config/setup.key").exists(), "Setup key consumed")
    check(fresh.request("POST", "/api/setup", admin, csrf=csrf)[0] == 409, "Completed installation cannot be claimed again")
    check(fresh.request("GET", "/api/setup/status")[1]["needs_setup"] is False, "First-run prompt stops after setup")
    settings = fresh.request("GET", "/api/settings")[1]
    check(settings["admin_user"] == "preview" and len(settings["providers"]) == 3, "Supported config fields are discoverable")
    check("preview-password-test" not in json.dumps(settings), "Password is not returned by settings")
    cloud = {"provider": "aliyun", "name": "Offline fixture", "access_key_id": "fake-access-id-1234", "access_key_secret": "fake-private-secret-1234", "enabled": False}
    check(fresh.request("POST", "/api/accounts", cloud)[0] == 403, "Cloud configuration writes require CSRF")
    status, result, _ = fresh.request("POST", "/api/accounts", cloud, csrf=csrf)
    check(status == 201, "Cloud account can be added after initialization")
    cloud_id = result["id"]
    check(fresh.request("PUT", "/api/accounts/" + cloud_id, {"name": "Renamed", "access_key_secret": ""}, csrf=csrf)[0] == 200, "Blank secret keeps saved credential")
    check(fresh.request("PUT", "/api/accounts/" + cloud_id, {"provider": "tencent"}, csrf=csrf)[0] == 422, "Vendor changes cannot break existing account identity")
    status, accounts, _ = fresh.request("GET", "/api/accounts")
    check(status == 200 and accounts[0]["id"] == cloud_id and accounts[0]["name"] == "Renamed", "Account ID remains stable")
    check(cloud["access_key_secret"] not in json.dumps(accounts), "Cloud account query is redacted")
    private_config = json.loads((fresh.root / "data/settings.json").read_text())
    check(private_config["config"]["accounts"][0]["access_key_secret"] == cloud["access_key_secret"], "Editing metadata preserves private credential")
    check(os.stat(fresh.root / "data/settings.json").st_mode & 0o777 == 0o600, "Config file is owner-readable only")
    status, _, _ = fresh.request("PUT", "/api/settings/http", {"timeout": 40, "connect_timeout": 8}, csrf=csrf)
    check(status == 200, "Network configuration can be saved")
    check(fresh.request("POST", "/api/tokens", {"name": "AI", "current_password": "wrong"}, csrf=csrf)[0] == 422, "Token creation requires current password")

    def issue(name, scopes):
        status, result, headers = fresh.request("POST", "/api/tokens", {"name": name, "scopes": scopes, "current_password": admin["password"]}, csrf=csrf)
        check(status == 201 and headers.get("Cache-Control") == "no-store", "One-time token issued without caching")
        return result

    reader = issue("AI reader", ["assets:read", "dns:read"])
    token = reader["token"]
    check(fresh.request("GET", "/api/health", token=token, anonymous=True)[0] == 200, "Bearer health read needs neither cookie nor CSRF")
    check(fresh.request("GET", "/api/domains", token=token, anonymous=True)[0] == 200, "AI can query inventory")
    check(fresh.request("GET", "/api/access-check", token=token, anonymous=True)[0] == 200, "Reading historical checks uses read scope")
    client_env = {key: value for key, value in os.environ.items() if not key.startswith('WORKSPACE_')}
    client_env.update(WORKSPACE_URL=fresh.base, WORKSPACE_API_TOKEN=token)
    client = subprocess.run([sys.executable, 'scripts/workspace-api.py', 'GET', '/api/health'], cwd=fresh.root, env=client_env, capture_output=True, text=True, timeout=30)
    check(client.returncode == 0 and 'assets' in json.loads(client.stdout), 'Bundled AI client performs a bearer-authenticated read')
    session_env = {key: value for key, value in client_env.items() if key != 'WORKSPACE_API_TOKEN'}
    session_env.update(WORKSPACE_PASSWORD=admin['password'])
    client = subprocess.run([sys.executable, 'scripts/workspace-api.py', 'GET', '/api/accounts', '--username', admin['user']], cwd=fresh.root, env=session_env, capture_output=True, text=True, timeout=30)
    check(client.returncode == 0 and json.loads(client.stdout)[0]['id'] == cloud_id, 'Bundled AI client also supports password login and cookies')
    check(fresh.request("POST", "/api/health/ips", {"ip": "8.8.8.8"}, token=token)[0] == 403, "Read token cannot create assets even with admin cookie")
    check(fresh.request("PUT", "/api/domains/aliyun_1/example.com/records/record1", {"type": "A", "name": "@", "value": "8.8.8.8"}, token=token)[0] == 403, "Read token cannot edit DNS")
    for private_endpoint in ["/api/settings", "/api/tokens"]:
        check(fresh.request("GET", private_endpoint, token=token)[0] == 403, "Bearer cannot access administrator configuration")
    check(fresh.request("GET", "/api/health", token="invalid")[0] == 401, "Invalid bearer never falls back to a valid browser session")
    writer = issue("AI asset editor", ["assets:read", "assets:write", "checks:run"])
    status, created, _ = fresh.request("POST", "/api/health/ips", {"ip": "8.8.8.8", "name": "Offline IP"}, token=writer["token"], anonymous=True)
    check(status == 200 and created["ok"], "Write token can perform allowed local mutation without CSRF")
    asset_id = created["asset"]["id"]
    check(fresh.request("PUT", "/api/health/ips/" + asset_id, {"note": "retain", "server_provider": "fixture", "expires_at": "2030-01-01"}, token=writer["token"], anonymous=True)[0] == 200, "API supports metadata updates")
    fresh.request("PUT", "/api/health/ips/" + asset_id, {"name": "Renamed IP"}, token=writer["token"], anonymous=True)
    ip = next(item for item in fresh.request("GET", "/api/health", token=writer["token"], anonymous=True)[1]["assets"] if item["id"] == asset_id)
    check(ip["note"] == "retain" and ip["server_provider"] == "fixture", "Partial metadata updates preserve unrelated fields")
    check(fresh.request("POST", "/api/health/check", {"asset_ids": []}, token=writer["token"], anonymous=True)[0] == 422, "Check scope passes through to batch validation without network work")
    check(fresh.request("POST", "/api/accounts", cloud, token=writer["token"])[0] == 403, "Asset write scope cannot grant cloud credentials")
    check(fresh.request("POST", "/api/tokens", {"name": "escalate"}, token=writer["token"])[0] == 403, "Bearer cannot mint new tokens")
    listed = fresh.request("GET", "/api/tokens")[1]
    check(token not in json.dumps(listed) and "auth_fingerprint" not in json.dumps(listed), "Token listing never returns secret material")
    check(token not in (fresh.root / "data/api_tokens.json").read_text(), "Token storage contains only hashes")
    status, spec, _ = fresh.request("GET", "/api/openapi.json", anonymous=True)
    check(status == 200 and spec["openapi"] == "3.1.0", "OpenAPI is available to AI tools")
    routes = re.findall(r"\$router->(get|post|put|delete)\('(/api/[^']+)'", (PROJECT / "public/index.php").read_text())
    check(all(method in spec["paths"].get(path, {}) for method, path in routes), "Every registered API operation has an OpenAPI contract")
    check(spec["paths"]["/api/domains/{provider}/{domain}/records/{recordId}"]["put"]["x-required-scopes"] == ["dns:write"], "Cloud writes declare their permission")
    check(fresh.request("GET", "/.well-known/asset-workspace.json", anonymous=True)[1]["openapi_url"] == "/api/openapi.json", "Machine-readable discovery works")
    check("Authorization: Bearer" in fresh.request("GET", "/llms.txt", anonymous=True)[1], "AI instructions describe authentication")
    check(fresh.request("DELETE", "/api/tokens/" + writer["metadata"]["id"], csrf=csrf)[0] == 200, "Administrator can revoke an API token")
    check(fresh.request("GET", "/api/health", token=writer["token"], anonymous=True)[0] == 401, "Revocation immediately rejects requests")
    check(fresh.request("PUT", "/api/auth/credentials", {"user": "admin2", "current_password": "wrong", "password": "updated-password-test"}, csrf=csrf)[0] == 422, "Credential changes verify the current password")
    check(fresh.request("PUT", "/api/auth/credentials", {"user": "admin2", "current_password": admin["password"], "password": "updated-password-test"}, csrf=csrf)[1]["requires_login"], "Credential update requests reauthentication")
    check(fresh.request("GET", "/api/settings")[0] == 401, "Old browser session is invalidated")
    check(fresh.request("GET", "/api/health", token=token, anonymous=True)[0] == 401, "Changing admin credentials invalidates prior API tokens")
    check(fresh.request("POST", "/api/login", {"user": "preview", "pass": admin["password"]})[0] == 401, "Old credentials stop working")
    check(fresh.request("POST", "/api/login", {"user": "admin2", "pass": "updated-password-test"})[1]["ok"], "Password login API accepts updated credentials")
    fresh.stop(); fresh.start()
    check(fresh.request("GET", "/api/settings")[1]["accounts"][0]["id"] == cloud_id, "Configuration survives a server restart")
    check(fresh.request("GET", "/api/tokens")[1]["tokens"][0]["invalidated"], "Invalidated tokens are labeled in the management API")
    legacy = Installation(legacy=True)
    original = (legacy.root / "config/config.php").read_bytes()
    check(legacy.request("GET", "/api/setup/status")[1]["configured"], "Existing deployments skip first-run initialization")
    identity = legacy.request("POST", "/api/login", {"user": "legacy", "pass": "legacy-password-test"})[1]
    check(identity["ok"], "Legacy login remains usable")
    legacy.request("POST", "/api/accounts", cloud, csrf=identity["csrf_token"])
    check((legacy.root / "config/config.php").read_bytes() == original, "Existing config file is never rewritten")
    check("legacy-password-test" not in (legacy.root / "data/settings.json").read_text(), "New configuration does not duplicate legacy plaintext password")
    check(legacy.request("GET", "/api/me")[1]["user"] == "legacy", "Saving cloud settings preserves existing login")
    print(f"Product HTTP integration: {ASSERTIONS} assertions passed (no cloud calls)")
finally:
    if legacy: legacy.close()
    if fresh: fresh.close()
