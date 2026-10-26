"""Login HTTP regression using synthetic data and isolated, private PHP sessions."""
import json
from pathlib import Path
import time
import unittest

from http_fixture import Installation


class LoginApiTest(unittest.TestCase):
    def setUp(self):
        self.site = Installation(legacy=True)

    def tearDown(self):
        self.site.close()

    def submit(self, challenge, **overrides):
        body = {"user": "legacy", "pass": "legacy-password-test", "captcha_id": challenge["captcha_id"], "captcha": "AC234", **overrides}
        return self.site.request("POST", "/api/login", body, csrf=challenge["csrf_token"])

    def test_challenge_validation_cookie_rotation_and_replay(self):
        site = self.site
        challenge = site.challenge()
        self.assertTrue(challenge["image"].startswith("data:image/svg+xml;base64,"))
        self.assertNotIn("answer", challenge)
        self.assertEqual(site.request("GET", "/api/login/captcha")[2].get("Cache-Control"), "no-store")
        self.assertEqual(self.submit(challenge)[0], 422)  # refreshed ID is now invalid
        challenge = site.challenge()
        self.assertEqual(site.request("POST", "/api/login", {"user": "legacy", "pass": "legacy-password-test"}, csrf=challenge["csrf_token"])[1]["code"], "captcha_invalid")
        challenge = site.challenge()
        self.assertEqual(self.submit(challenge, captcha="ZZZZZ")[1]["code"], "captcha_invalid")
        self.assertEqual(self.submit(challenge)[0], 422)  # even failed answers are single use

    def test_login_success_wrong_password_expiry_and_csrf(self):
        site = self.site
        challenge = site.challenge()
        self.assertEqual(self.submit(challenge, **{"pass": "wrong"})[0], 401)
        challenge = site.challenge(expires_at=int(time.time()) - 1)
        self.assertEqual(self.submit(challenge)[0], 422)
        challenge = site.challenge()
        self.assertEqual(site.request("POST", "/api/login", {"captcha_id": challenge["captcha_id"], "captcha": "AC234"})[0], 403)
        challenge = site.challenge()
        old_cookie = next(iter(site.cookies)).value
        status, login, headers = self.submit(challenge, captcha="ac234")
        self.assertEqual(status, 200)
        self.assertEqual(login["user"], "legacy")
        self.assertNotEqual(login["csrf_token"], challenge["csrf_token"])
        self.assertNotEqual(next(iter(site.cookies)).value, old_cookie)
        self.assertEqual(headers.get("Cache-Control"), "no-store")
        self.assertEqual(site.request("GET", "/api/me")[1]["user"], "legacy")
        self.assertEqual(self.submit(challenge)[1]["code"], "csrf_invalid")

    def test_limit_survives_new_sessions_and_spoofed_proxy_headers(self):
        site = self.site
        for attempt in range(5):
            status, data, headers = site.request("POST", "/api/login", {"user": f"varied-{attempt}", "pass": "wrong"},
                anonymous=True, extra_headers={"X-Forwarded-For": f"192.0.2.{attempt+1}"})
            self.assertEqual(status, 429 if attempt == 4 else 403)
        self.assertEqual(data["code"], "rate_limited")
        self.assertEqual(headers.get("Retry-After"), str(data["retry_after"]))
        self.assertGreaterEqual(data["retry_after"], 299)
        challenge = site.challenge()
        self.assertEqual(self.submit(challenge)[0], 429)
        # Simulate time passing in the disposable private store; production has no clock bypass.
        state_file = site.root / "data/login_limits.json"
        state = json.loads(state_file.read_text())
        for entry in state["entries"].values():
            entry["blocked_until"] = int(time.time()) - 1
            entry["window_start"] = int(time.time()) - 61
        state_file.write_text(json.dumps(state))
        self.assertEqual(site.login("legacy", "legacy-password-test")[0], 200)

    def test_captcha_budget_and_another_sessions_challenge(self):
        site = self.site
        challenge = site.challenge()
        status, _, _ = site.request("POST", "/api/login", {"user": "legacy", "pass": "legacy-password-test",
            "captcha_id": challenge["captcha_id"], "captcha": "AC234"}, csrf=challenge["csrf_token"], anonymous=True)
        self.assertEqual(status, 403)
        self.assertEqual(self.submit(challenge)[0], 200)
        for _ in range(29):
            self.assertEqual(site.request("GET", "/api/login/captcha", anonymous=True)[0], 200)
        status, data, headers = site.request("GET", "/api/login/captcha", anonymous=True)
        self.assertEqual(status, 429)
        self.assertEqual(headers.get("Retry-After"), str(data["retry_after"]))


if __name__ == "__main__":
    unittest.main()
