"""HTTP regressions on a disposable installation. No cloud requests or private project data."""
import http.cookiejar
import json
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]


class MaintenanceAPI(unittest.TestCase):
    def test_delivery_flow(self):
        with tempfile.TemporaryDirectory(prefix="workspace-delivery-http-") as directory:
            root = Path(directory)
            for folder in ["app", "public"]:
                shutil.copytree(ROOT / folder, root / folder)
            (root / "config").mkdir()
            (root / "data").mkdir()
            with socket.socket() as listener:
                listener.bind(("127.0.0.1", 0))
                port = listener.getsockname()[1]
            browser = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            anonymous = urllib.request.build_opener(urllib.request.ProxyHandler({}))
            csrf = None

            def request(method, path, body=None, *, token=None, client=browser, use_csrf=True):
                headers = {"Content-Type": "application/json"}
                if use_csrf and csrf:
                    headers["X-CSRF-Token"] = csrf
                if token:
                    headers["Authorization"] = "Bearer " + token
                req = urllib.request.Request(f"http://127.0.0.1:{port}" + path, method=method, headers=headers,
                                             data=None if body is None else json.dumps(body).encode())
                try:
                    response = client.open(req, timeout=20)
                except urllib.error.HTTPError as error:
                    response = error
                raw = response.read().decode()
                try:
                    data = json.loads(raw)
                except ValueError:
                    data = raw
                return response.status, data, response.headers

            with tempfile.TemporaryFile() as log:
                process = subprocess.Popen([shutil.which("php"), "-S", f"127.0.0.1:{port}", "-t", "public", "public/index.php"],
                                           cwd=root, stdout=log, stderr=log)
                try:
                    for _ in range(100):
                        try:
                            setup = request("GET", "/api/setup/status")[1]
                            break
                        except urllib.error.URLError:
                            time.sleep(.05)
                    else:
                        self.fail("Disposable PHP server did not start")
                    public_diagnostic = request("GET", "/api/env-check")[1]
                    self.assertNotIn("php_version", public_diagnostic)
                    self.assertNotIn(str(root), json.dumps(public_diagnostic))
                    csrf = setup["csrf_token"]
                    password = "delivery-test-password-123"
                    identity = request("POST", "/api/setup", {"user": "delivery", "password": password})[1]
                    csrf = identity["csrf_token"]
                    self.assertEqual(request("GET", "/api/maintenance", client=anonymous)[0], 401)
                    self.assertEqual(request("GET", "/api/env-check", client=anonymous)[0], 401)
                    self.assertEqual(request("GET", "/env-check", client=anonymous)[0], 401)
                    self.assertEqual(request("POST", "/api/maintenance/backups", {}, use_csrf=False)[0], 403)
                    self.assertEqual(request("POST", "/api/sync", {})[1]["status"], "skipped")
                    self.assertIsNone(request("GET", "/api/domains/stats")[1]["last_sync"])
                    domain = {"provider": "manual", "domain": "example.com", "note": "交付样例", "expire_at": "2030-01-01"}
                    self.assertEqual(request("POST", "/api/domains", domain)[0], 200)
                    ip = request("POST", "/api/health/ips", {"ip": "8.8.8.8", "name": "Fixture IP", "note": "用途备注",
                                 "links": [{"name": "示例入口", "url": "https://app.example.com"}]})[1]["asset"]
                    health = request("GET", "/api/health")[1]
                    domain_id = next(asset["id"] for asset in health["assets"] if asset["kind"] == "domain")
                    self.assertEqual(request("PUT", f"/api/health/domains/{domain_id}/bindings", {"ip_ids": [ip["id"]]})[0], 200)
                    status, backup, headers = request("POST", "/api/maintenance/backups", {})
                    self.assertEqual(status, 201)
                    self.assertEqual(headers.get("Cache-Control"), "no-store")
                    backup_id = backup["backup"]["id"]
                    exported = request("GET", "/api/maintenance/export?format=json")[1]
                    payload = json.loads(exported["content"])
                    self.assertEqual(payload["scope"], "assets-only")
                    self.assertEqual(len(payload["domains"]), 1)
                    self.assertNotIn("observations", payload["health"])
                    self.assertNotIn(password, exported["content"])
                    self.assertIn("用途与备注", request("GET", "/api/maintenance/export?format=csv")[1]["content"])
                    self.assertEqual(request("GET", "/api/maintenance/export?format=php")[0], 422)
                    self.assertEqual(request("POST", "/api/maintenance/preview", {"content": "bad json"})[0], 422)
                    self.assertEqual(request("POST", "/api/maintenance/restore", {"confirmation": "恢复资产", "password": password, "token": "../settings"})[0], 422)
                    self.assertEqual(request("POST", "/api/maintenance/preview", {"backup_id": "../settings"})[0], 422)

                    token = request("POST", "/api/tokens", {"name": "offline token", "days": 1,
                                  "scopes": ["assets:read", "assets:write"], "expires_in_days": 1, "current_password": password})[1]["token"]
                    self.assertEqual(request("GET", "/api/maintenance", token=token, client=anonymous)[0], 403)
                    self.assertEqual(request("GET", "/api/env-check", token=token, client=anonymous)[0], 403)
                    self.assertEqual(request("POST", "/api/maintenance/backups", {}, token=token, client=anonymous)[0], 403)

                    preview = request("POST", "/api/maintenance/preview", {"backup_id": backup_id})[1]["preview"]
                    self.assertEqual(preview["before"], {"domains": 1, "ips": 1, "bindings": 1})
                    self.assertEqual(preview["mode"], "replace")
                    restore_body = {"token": preview["token"], "confirmation": "恢复资产", "password": "wrong-password"}
                    self.assertEqual(request("POST", "/api/maintenance/restore", restore_body)[0], 403)
                    self.assertEqual(request("POST", "/api/maintenance/restore", {**restore_body, "confirmation": "", "password": password})[0], 422)
                    self.assertEqual(request("PUT", "/api/domains", {**domain, "note": "newer edit"})[0], 200)
                    self.assertEqual(request("POST", "/api/maintenance/restore", {**restore_body, "password": password})[0], 409)
                    self.assertEqual(request("GET", "/api/domains")[1]["list"][0]["note"], "newer edit")
                    preview = request("POST", "/api/maintenance/preview", {"content": exported["content"]})[1]["preview"]
                    restored = request("POST", "/api/maintenance/restore", {"token": preview["token"], "confirmation": "恢复资产", "password": password})
                    self.assertEqual(restored[0], 200)
                    self.assertEqual(restored[1]["safety_backup"]["reason"], "before-restore")
                    self.assertEqual(request("GET", "/api/domains")[1]["list"][0]["note"], "交付样例")
                    health = request("GET", "/api/health")[1]
                    domain_asset = next(asset for asset in health["assets"] if asset["kind"] == "domain")
                    self.assertEqual(domain_asset["bound_ips"][0], ip["id"])
                    self.assertEqual(domain_asset["health"]["status"], "unchecked")
                    self.assertEqual(request("POST", "/api/maintenance/restore", {"token": preview["token"], "confirmation": "恢复资产", "password": password})[0], 409)
                    status, maintenance, headers = request("GET", "/api/maintenance")
                    self.assertEqual(status, 200)
                    self.assertEqual(maintenance["version"], "1.1.1")
                    self.assertEqual(len(maintenance["backups"]), 2)
                    self.assertEqual(headers.get("Cache-Control"), "no-store")
                    self.assertNotIn(password, json.dumps(maintenance))
                    self.assertNotIn(token, json.dumps(maintenance))
                    self.assertNotIn(str(root), json.dumps(maintenance))
                    self.assertTrue(any(entry["outcome"] == "failed" for entry in maintenance["audit"]))
                    self.assertTrue(any(entry["route"] == "/api/sync" and entry["outcome"] == "skipped" for entry in maintenance["audit"]))
                    self.assertTrue(any(entry["route"] == "/api/maintenance/restore" and entry["outcome"] == "success" for entry in maintenance["audit"]))
                    self.assertEqual(request("DELETE", f"/api/maintenance/backups/{backup_id}", use_csrf=False)[0], 403)
                    self.assertEqual(request("DELETE", f"/api/maintenance/backups/{backup_id}")[0], 200)
                    self.assertEqual(request("GET", f"/api/maintenance/backups/{backup_id}")[0], 404)
                finally:
                    process.terminate()
                    process.wait(timeout=10)


if __name__ == "__main__":
    unittest.main()
