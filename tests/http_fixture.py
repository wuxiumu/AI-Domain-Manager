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
class Installation:
    def __init__(self, legacy=False):
        self.directory = tempfile.TemporaryDirectory(prefix="workspace-product-test-")
        self.root = Path(self.directory.name)
        for folder in ["app", "public", "scripts"]:
            shutil.copytree(PROJECT / folder, self.root / folder)
        (self.root / "config").mkdir()
        (self.root / "data").mkdir()
        (self.root / "sessions").mkdir(mode=0o700)
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
        self.browser = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(self.cookies))
        self.start()

    def start(self):
        self.process = subprocess.Popen([shutil.which("php"), "-d", "session.save_path=" + str(self.root / "sessions"), "-S", f"127.0.0.1:{self.port}", "-t", "public", "public/index.php"],
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

    def request(self, method, path, body=None, csrf=None, token=None, anonymous=False, extra_headers=None):
        headers = {"Content-Type": "application/json", **(extra_headers or {})}
        if csrf is not None:
            headers["X-CSRF-Token"] = csrf
        if token is not None:
            headers["Authorization"] = "Bearer " + token
        request = urllib.request.Request(self.base + path, data=None if body is None else json.dumps(body).encode(), headers=headers, method=method)
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({})) if anonymous else self.browser
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


    def challenge(self, expires_at=None):
        """Set a known answer in this disposable fixture's private PHP session only.

        Production has no test flag, answer endpoint, or validation bypass.
        """
        status, challenge, _ = self.request("GET", "/api/login/captcha")
        if status != 200:
            raise AssertionError(challenge)
        session_id = next(cookie.value for cookie in self.cookies if cookie.name.startswith("AW_"))
        script = """$p=json_decode(stream_get_contents(STDIN),true);
            session_save_path($p['directory']); session_id($p['session_id']); session_start();
            $_SESSION['login_captcha']['hash']=hash('sha256',$p['id'].':AC234');
            if ($p['expires_at'] !== null) $_SESSION['login_captcha']['expires_at']=$p['expires_at'];
            session_write_close();"""
        subprocess.run([shutil.which("php"), "-r", script], input=json.dumps({
            "directory": str(self.root / "sessions"), "session_id": session_id,
            "id": challenge["captcha_id"], "expires_at": expires_at}), text=True, check=True, capture_output=True)
        return challenge

    def login(self, user, password):
        challenge = self.challenge()
        return self.request("POST", "/api/login", {"user": user, "pass": password,
            "captcha_id": challenge["captcha_id"], "captcha": "ac234"}, csrf=challenge["csrf_token"])
