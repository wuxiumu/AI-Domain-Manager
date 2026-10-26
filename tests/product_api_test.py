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


from http_fixture import Installation

fresh = legacy = None
try:
    fresh = Installation()
    status, setup, _ = fresh.request("GET", "/api/setup/status")
    check(status == 200 and setup["needs_setup"], "Missing config starts first-run flow")
    check(not (fresh.root / "config/setup.key").exists(), "Fresh browser setup needs no server-generated key")
    status, page, _ = fresh.request("GET", "/")
    check(status == 200 and 'id="setup-user"' in page and 'id="setup-password"' in page, "Page offers administrator account and password")
    check('id="setup-key"' not in page and 'id="setup-confirm"' not in page, "First-run form has no extra credential fields")
    csrf = setup["csrf_token"]
    check(fresh.request("GET", "/api/health")[0] == 401, "Anonymous health is protected")
    check(fresh.request("GET", "/api/settings")[0] == 401, "Anonymous settings are protected")
    admin = {"user": "preview", "password": "preview-password-test"}
    check(fresh.request("POST", "/api/setup", admin)[0] == 403, "Setup requires CSRF and session cookie")
    check(fresh.request("POST", "/api/setup", admin, csrf=csrf, anonymous=True)[0] == 403, "CSRF token alone cannot initialize without its browser session")
    check(fresh.request("POST", "/api/setup", {**admin, "password": "short"}, csrf=csrf)[0] == 422, "Setup validates the administrator password")
    check(not (fresh.root / "data/settings.json").exists(), "Failed setup does not save configuration")
    status, identity, headers = fresh.request("POST", "/api/setup", admin, csrf=csrf)
    check(status == 200 and identity["user"] == "preview", "Setup creates and logs in administrator")
    check(headers.get("Cache-Control") == "no-store", "Setup credentials response is not cached")
    csrf = identity["csrf_token"]
    check(fresh.request("GET", "/api/me")[1]["user"] == admin["user"], "Browser is immediately logged in after setup")
    check(fresh.request("POST", "/api/setup", admin, csrf=csrf)[0] == 409, "Completed installation cannot be claimed again")
    check(fresh.request("GET", "/api/setup/status")[1]["needs_setup"] is False, "First-run prompt stops after setup")
    settings = fresh.request("GET", "/api/settings")[1]
    check(settings["admin_user"] == "preview" and len(settings["providers"]) == 3, "Supported config fields are discoverable")
    check("preview-password-test" not in json.dumps(settings), "Password is not returned by settings")
    cloud = {"provider": "aliyun", "name": "Offline fixture", "access_key_id": "fake-access-id-1234", "access_key_secret": "fake-private-secret-1234", "enabled": False, "note": "生产账号备注"}
    check(fresh.request("POST", "/api/accounts", cloud)[0] == 403, "Cloud configuration writes require CSRF")
    status, result, _ = fresh.request("POST", "/api/accounts", cloud, csrf=csrf)
    check(status == 201, "Cloud account can be added after initialization")
    cloud_id = result["id"]
    check(fresh.request("PUT", "/api/accounts/" + cloud_id, {"name": "Renamed", "access_key_secret": ""}, csrf=csrf)[0] == 200, "Blank secret keeps saved credential")
    check(fresh.request("PUT", "/api/accounts/" + cloud_id, {"provider": "tencent"}, csrf=csrf)[0] == 422, "Vendor changes cannot break existing account identity")
    status, accounts, _ = fresh.request("GET", "/api/accounts")
    check(status == 200 and accounts[0]["id"] == cloud_id and accounts[0]["name"] == "Renamed", "Account ID remains stable")
    check(cloud["access_key_secret"] not in json.dumps(accounts), "Cloud account query is redacted")
    check(accounts[0]["note"] == "生产账号备注", "Account list includes note")
    check(fresh.request("PUT", "/api/accounts/" + cloud_id, {"enabled": True}, csrf=csrf)[0] == 200, "Account switch enables using a partial update")
    check(fresh.request("GET", "/api/accounts")[1][0]["enabled"] is True, "Enabled state is returned by API")
    fresh.request("PUT", "/api/accounts/" + cloud_id, {"enabled": False}, csrf=csrf)
    check(fresh.request("POST", "/api/test-all", {}, csrf=csrf)[1] == [], "Disabled accounts are skipped by test-all")
    extra_id = fresh.request("POST", "/api/accounts", {**cloud, "name": "Delete me"}, csrf=csrf)[1]["id"]
    check(fresh.request("DELETE", "/api/accounts/" + extra_id)[0] == 403, "Account deletion requires CSRF")
    check(fresh.request("DELETE", "/api/accounts/" + extra_id, csrf=csrf)[0] == 200, "Account deletion works")
    check(fresh.request("DELETE", "/api/accounts/" + extra_id, csrf=csrf)[0] == 404, "Deleting missing account returns not found")
    check(len(fresh.request("GET", "/api/accounts")[1]) == 1, "Deleted account is removed from list")
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
    check(client.returncode != 0 and 'interactive image captcha' in client.stderr, 'Password automation cannot bypass captcha; bearer client remains available')
    check(fresh.request("POST", "/api/health/ips", {"ip": "8.8.8.8"}, token=token)[0] == 403, "Read token cannot create assets even with admin cookie")
    check(fresh.request("PUT", "/api/domains/aliyun_1/example.com/records/record1", {"type": "A", "name": "@", "value": "8.8.8.8"}, token=token)[0] == 403, "Read token cannot edit DNS")
    for private_endpoint in ["/api/settings", "/api/tokens"]:
        check(fresh.request("GET", private_endpoint, token=token)[0] == 403, "Bearer cannot access administrator configuration")
    check(fresh.request("GET", "/api/health", token="invalid")[0] == 401, "Invalid bearer never falls back to a valid browser session")
    writer = issue("AI asset editor", ["assets:read", "assets:write", "checks:run"])
    status, created, _ = fresh.request("POST", "/api/health/ips", {"ip": "8.8.8.8", "name": "Offline IP"}, token=writer["token"], anonymous=True)
    check(status == 200 and created["ok"], "Write token can perform allowed local mutation without CSRF")
    asset_id = created["asset"]["id"]
    links = [{"name": "管理后台", "url": "https://example.com/admin"}]
    check(fresh.request("PUT", "/api/health/ips/" + asset_id, {"links": links}, token=writer["token"], anonymous=True)[0] == 200, "Website links can be edited")
    check(fresh.request("PUT", "/api/health/ips/" + asset_id, {"links": [{"name": "bad", "url": "javascript:alert(1)"}]}, token=writer["token"], anonymous=True)[0] == 422, "Unsafe website URL rejected")
    check(fresh.request("DELETE", "/api/accounts/" + cloud_id, token=writer["token"])[0] == 403, "Asset writer cannot delete cloud accounts")
    check(fresh.request("PUT", "/api/health/ips/" + asset_id, {"note": "retain", "server_provider": "fixture", "expires_at": "2030-01-01"}, token=writer["token"], anonymous=True)[0] == 200, "API supports metadata updates")
    fresh.request("PUT", "/api/health/ips/" + asset_id, {"name": "Renamed IP"}, token=writer["token"], anonymous=True)
    ip = next(item for item in fresh.request("GET", "/api/health", token=writer["token"], anonymous=True)[1]["assets"] if item["id"] == asset_id)
    check(ip["note"] == "retain" and ip["server_provider"] == "fixture" and ip["links"] == links, "Partial metadata updates preserve unrelated fields")
    check(fresh.request("POST", "/api/health/check", {"asset_ids": []}, token=writer["token"], anonymous=True)[0] == 422, "Check scope passes through to batch validation without network work")
    check(fresh.request("POST", "/api/accounts", cloud, token=writer["token"])[0] == 403, "Asset write scope cannot grant cloud credentials")
    check(fresh.request("POST", "/api/tokens", {"name": "escalate"}, token=writer["token"])[0] == 403, "Bearer cannot mint new tokens")
    listed = fresh.request("GET", "/api/tokens")[1]
    check(token not in json.dumps(listed) and "auth_fingerprint" not in json.dumps(listed), "Token listing never returns secret material")
    check(token not in (fresh.root / "data/api_tokens.json").read_text(), "Token storage contains only hashes")
    status, spec, _ = fresh.request("GET", "/api/openapi.json", anonymous=True)
    check(status == 200 and spec["openapi"] == "3.1.0", "OpenAPI is available to AI tools")
    setup_schema = spec["paths"]["/api/setup"]["post"]["requestBody"]["content"]["application/json"]["schema"]
    check(set(setup_schema["required"]) == {"user", "password"} and "setup_key" not in setup_schema["properties"], "Setup API documents only administrator account and password")
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
    check(fresh.login("preview", admin["password"])[0] == 401, "Old credentials stop working")
    check(fresh.login("admin2", "updated-password-test")[1]["ok"], "Password login API accepts updated credentials")
    fresh.stop(); fresh.start()
    check(fresh.request("GET", "/api/setup/status")[1]["needs_setup"] is False, "Restart keeps initialization complete")
    check(fresh.request("POST", "/api/setup", admin)[0] == 409, "Restart cannot reopen administrator creation")
    check(fresh.request("GET", "/api/settings")[1]["accounts"][0]["id"] == cloud_id, "Configuration survives a server restart")
    check(fresh.request("GET", "/api/tokens")[1]["tokens"][0]["invalidated"], "Invalidated tokens are labeled in the management API")
    legacy = Installation(legacy=True)
    original = (legacy.root / "config/config.php").read_bytes()
    check(legacy.request("GET", "/api/setup/status")[1]["configured"], "Existing deployments skip first-run initialization")
    check(legacy.request("POST", "/api/setup", admin)[0] == 409, "Legacy administrator cannot be overwritten through setup")
    identity = legacy.login("legacy", "legacy-password-test")[1]
    check(identity["ok"], "Legacy login remains usable")
    legacy.request("POST", "/api/accounts", cloud, csrf=identity["csrf_token"])
    check((legacy.root / "config/config.php").read_bytes() == original, "Existing config file is never rewritten")
    check("legacy-password-test" not in (legacy.root / "data/settings.json").read_text(), "New configuration does not duplicate legacy plaintext password")
    check(legacy.request("GET", "/api/me")[1]["user"] == "legacy", "Saving cloud settings preserves existing login")
    print(f"Product HTTP integration: {ASSERTIONS} assertions passed (no cloud calls)")
finally:
    if legacy: legacy.close()
    if fresh: fresh.close()
