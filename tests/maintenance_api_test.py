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
    def test_cleanup_endpoints(self):
        """数据清理：孤儿账号记录删除/转手工 + 日志按维度删除，仅管理员会话。"""
        import hashlib
        with tempfile.TemporaryDirectory(prefix="workspace-cleanup-http-") as directory:
            root = Path(directory)
            for folder in ["app", "public"]:
                shutil.copytree(ROOT / folder, root / folder)
            (root / "config").mkdir()
            (root / "data" / "cache").mkdir(parents=True)
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
                    csrf = setup["csrf_token"]
                    identity = request("POST", "/api/setup", {"user": "cleanup", "password": "cleanup-test-password-1"})[1]
                    csrf = identity["csrf_token"]

                    # 直接种入孤儿数据：已删除账号 aliyun_gone 的域名、缓存与健康残留
                    gone_id = "domain_" + hashlib.sha256("aliyun_gone|orphan.com".encode()).hexdigest()[:24]
                    gone_host = "host_" + hashlib.sha256("aliyun_gone|www.orphan.com".encode()).hexdigest()[:24]
                    (root / "data" / "domains.json").write_text(json.dumps([
                        {"domain": "orphan.com", "provider": "aliyun_gone", "provider_name": "阿里云-已删", "valid": True, "note": "孤儿样例"},
                        {"domain": "keep.com", "provider": "manual", "provider_name": "手动添加", "valid": True},
                    ]), encoding="utf-8")
                    (root / "data" / "cache" / f"dns-{hashlib.sha256('aliyun_gone|orphan.com'.encode()).hexdigest()}.json").write_text(json.dumps(
                        {"records": [{"id": "r1", "type": "A", "fqdn": "www.orphan.com", "value": "192.0.2.10", "ttl": 600}], "snapshot_at": "2026-01-01 00:00:00"}), encoding="utf-8")
                    (root / "data" / "health.json").write_text(json.dumps({
                        "manual_ips": {}, "bindings": {gone_id: {"ip_x": {"ip": "192.0.2.10"}}},
                        "observations": {
                            gone_id: {"health": {"status": "healthy", "checked_at": "2026-01-01 00:00:00", "checks": [], "resolved_ips": []}, "history": []},
                            gone_host: {"health": {"status": "healthy", "checked_at": "2026-01-01 00:00:00", "checks": [], "resolved_ips": []}, "history": []}},
                        "check_events": [
                            {"id": "e1", "asset_id": gone_id, "kind": "domain", "name": "orphan.com", "provider": "aliyun_gone", "domain": "orphan.com", "checked_at": "2026-01-01 00:00:00", "status": "healthy"},
                        ]}), encoding="utf-8")
                    (root / "data" / "dns_changes.json").write_text(json.dumps({"entries": [
                        {"id": "c1", "domain": "orphan.com", "account": "aliyun_gone", "started_at": "2026-01-01T00:00:00+08:00", "outcome": "verified"},
                        {"id": "c2", "domain": "keep.com", "account": "manual", "started_at": "2026-06-01T00:00:00+08:00", "outcome": "verified"},
                    ]}), encoding="utf-8")
                    (root / "data" / "sync_log.json").write_text(json.dumps([
                        {"time": "2026-01-01 00:00:00", "type": "sync", "data": {}},
                        {"time": "2026-06-01 00:00:00", "type": "domain_create", "data": {}},
                    ]), encoding="utf-8")

                    self.assertEqual(request("GET", "/api/maintenance/cleanup", client=anonymous)[0], 401)
                    self.assertEqual(request("POST", "/api/maintenance/cleanup/logs", {"store": "sync_log", "all": True}, use_csrf=False)[0], 403)
                    token = request("POST", "/api/tokens", {"name": "cleanup probe", "days": 1, "scopes": ["assets:write"], "current_password": "cleanup-test-password-1"})[1]["token"]
                    self.assertEqual(request("GET", "/api/maintenance/cleanup", token=token, client=anonymous)[0], 403)

                    status, overview, _ = request("GET", "/api/maintenance/cleanup")
                    self.assertEqual(status, 200)
                    self.assertEqual([g["provider"] for g in overview["groups"]], ["aliyun_gone"])
                    self.assertEqual(overview["logs"]["check_events"]["total"], 1)
                    self.assertEqual(overview["logs"]["dns_changes"]["total"], 2)
                    self.assertEqual(overview["logs"]["sync_log"]["total"], 2)

                    self.assertEqual(request("POST", "/api/maintenance/cleanup/logs", {"store": "nope", "all": True})[0], 422)
                    self.assertEqual(request("POST", "/api/maintenance/cleanup/logs", {"store": "dns_changes"})[0], 422)
                    self.assertEqual(request("POST", "/api/maintenance/cleanup/orphans", {"provider": "manual", "action": "delete"})[0], 422)
                    self.assertEqual(request("POST", "/api/maintenance/cleanup/orphans", {"provider": "aliyun_gone", "action": "manual", "domains": ["keep.com"]})[0], 404)

                    status, removed, _ = request("POST", "/api/maintenance/cleanup/logs", {"store": "dns_changes", "domain": "orphan.com"})
                    self.assertEqual((status, removed["removed"]), (200, 1))
                    status, removed, _ = request("POST", "/api/maintenance/cleanup/logs", {"store": "sync_log", "type": "sync"})
                    self.assertEqual(removed["removed"], 1)
                    status, removed, _ = request("POST", "/api/maintenance/cleanup/logs", {"store": "check_events", "all": True})
                    self.assertEqual(removed["removed"], 1)

                    status, result, _ = request("POST", "/api/maintenance/cleanup/orphans", {"provider": "aliyun_gone", "action": "manual"})
                    self.assertEqual(status, 200)
                    self.assertEqual(result["domains"], 1)
                    self.assertEqual(result["caches_removed"], 1)
                    self.assertEqual(result["observations_removed"], 2)
                    rows = json.loads((root / "data" / "domains.json").read_text())
                    self.assertEqual([r["provider"] for r in rows], ["manual", "manual"])
                    self.assertEqual(rows[0]["provider_name"], "手动添加")
                    self.assertFalse((root / "data" / "cache" / f"dns-{hashlib.sha256('aliyun_gone|orphan.com'.encode()).hexdigest()}.json").exists())
                    health_after = json.loads((root / "data" / "health.json").read_text())
                    self.assertNotIn(gone_id, health_after["observations"])
                    self.assertNotIn(gone_host, health_after["observations"])
                    self.assertEqual(health_after["check_events"], [])
                    _, overview_after, _ = request("GET", "/api/maintenance/cleanup")
                    self.assertEqual(overview_after["groups"], [])
                    self.assertEqual(overview_after["logs"]["dns_changes"]["total"], 1)
                    _, audit_log, _ = request("GET", "/api/maintenance")
                    self.assertTrue(any(entry["route"] == "/api/maintenance/cleanup/orphans" and entry["outcome"] == "success" for entry in audit_log["audit"]))
                finally:
                    process.terminate()
                    process.wait(timeout=10)

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
                    self.assertEqual(maintenance["version"], "1.2.0")
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
