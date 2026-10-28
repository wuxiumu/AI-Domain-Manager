"""Regression checks for public documentation privacy rules; synthetic data only."""

import importlib.util
from pathlib import Path
import tempfile
import unittest


spec = importlib.util.spec_from_file_location(
    "public_docs", Path(__file__).resolve().parents[1] / "scripts/check-public-docs.py"
)
docs = importlib.util.module_from_spec(spec)
spec.loader.exec_module(docs)


class PublicDocumentationTest(unittest.TestCase):
    def rules(self, content):
        return {rule for _, rule in docs.text_findings(content)}

    def test_reserved_examples_and_local_addresses_are_allowed(self):
        content = "app.example.com 192.0.2.10 198.51.100.2 203.0.113.2 2001:db8::10 ::1 http://127.0.0.1:8080 https://demo.test 0.0.0.0"
        self.assertEqual(self.rules(content), set())

    def test_live_and_private_addresses_are_rejected(self):
        for address in ["8.8.8.8", "10.0.0.1", "192.168.0.1", "2606:4700:4700::1111"]:
            with self.subTest(address=address):
                self.assertIn("non_example_ip", self.rules(address))

    def test_bare_domains_and_unreviewed_urls_are_rejected(self):
        for content in ["demo.customer.xyz", "https://demo.customer.xyz/panel", "https://真实客户.example.cn"]:
            self.assertTrue(self.rules(content) & {"non_example_domain", "unreviewed_url_host"})

    def test_official_references_and_code_files_are_allowed(self):
        self.assertEqual(self.rules("https://docs.dnscontrol.org/ https://github.com/louislam/uptime-kuma config.example.php app.js health.json http.proxy"), set())

    def test_credentials_and_images_are_detected_without_returning_values(self):
        secret = "x" * 32
        findings = docs.text_findings("Authorization: Bearer " + secret + "\n![preview](preview.png)")
        self.assertIn((1, "possible_credential"), findings)
        self.assertIn((2, "unreviewed_image"), findings)
        self.assertNotIn(secret, repr(findings))

    def test_personal_paths_are_rejected(self):
        self.assertIn("personal_absolute_path", self.rules("/Users/demo/work/project"))

    def test_naming_attachments_and_links_are_checked(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "README.md").write_text("[Docs](docs/00-目录.md)")
            (root / "docs").mkdir()
            (root / "docs/00-目录.md").write_text("# 目录\n")
            self.assertEqual(docs.check(root), [])
            (root / "docs/preview.png").write_bytes(b"synthetic")
            (root / "docs/01-说明.md").write_text("[missing](missing.md)\n[anchor](00-目录.md#missing)")
            rules = {rule for _, _, rule in docs.check(root)}
            self.assertTrue({"numbered_name_required", "unreviewed_attachment", "broken_local_link", "broken_heading_link"} <= rules)

    def test_private_file_links_are_rejected_without_reading_the_file(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "README.md").write_text("[private](data/snapshot.md#private)")
            (root / "docs").mkdir()
            (root / "docs/00-目录.md").write_text("# 目录\n")
            self.assertIn("private_target_link", {rule for _, _, rule in docs.check(root)})

    def test_only_reviewed_readme_images_are_allowed(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'docs').mkdir()
            (root / 'docs/00-目录.md').write_text('# 目录\n')
            image = root / 'public/assets/screenshots/dashboard.png'
            image.parent.mkdir(parents=True)
            image.write_bytes(b'synthetic')
            (root / 'README.md').write_text('![演示](public/assets/screenshots/dashboard.png)\n')
            self.assertEqual(docs.check(root), [])
            (root / 'README.md').write_text('![其他](public/assets/screenshots/other.png)\n')
            self.assertIn('unreviewed_image', {rule for _, _, rule in docs.check(root)})


if __name__ == "__main__":
    unittest.main()
