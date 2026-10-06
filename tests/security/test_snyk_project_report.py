# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Admission and omission controls for the pinned legacy Snyk report contract.

Fixtures model the official v1.1307.4 per-project producer fields, not a server
scan. The upstream cli-json-file-output acceptance fixture establishes depGraph
inclusion; issue-grouping JSON fixtures establish optional targetFile, shared
path/projectName and distinct displayTargetFile. No real package data is used.
"""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import textwrap
import unittest

import verify_snyk_project_report as guard


def complete_report():
    return [
        {
            "ok": True,
            "targetFile": name,
            "displayTargetFile": name,
            "path": ".",
            "projectName": "owned-offline-fixture",
            "packageManager": manager,
            "severityThreshold": "high",
            "vulnerabilities": [],
            "dependencyCount": 1,
            "depGraph": {
                "schemaVersion": "1.2.0",
                "pkgManager": {"name": manager},
                "pkgs": [
                    {"id": "root@1", "info": {"name": "owned-root", "version": "1"}},
                    {"id": "child@1", "info": {"name": "owned-child", "version": "1"}},
                ],
            },
        }
        for name, manager in guard.EXPECTED_PROJECTS.items()
    ]


class SnykProjectReportTest(unittest.TestCase):
    def setUp(self):
        self.checkout = Path("/owned/checkout")

    def test_complete_report_preserves_counts_and_policy_ignore_history(self):
        report = complete_report()
        report[0]["filtered"] = {"ignore": [{"id": "owned-ignored-issue"}], "patch": []}
        self.assertEqual(guard.verify_report(report, self.checkout),
                         {name: 1 for name in guard.EXPECTED_PROJECTS})

    def test_identity_uses_manifest_fields_instead_of_shared_project_names_or_paths(self):
        report = complete_report()
        for record in report:
            del record["targetFile"]
            record["displayTargetFile"] = "./" + record["displayTargetFile"]
        self.assertEqual(len(guard.verify_report(report, self.checkout)), 5)

    def test_checkout_bound_absolute_and_relative_manifest_identities_agree(self):
        report = complete_report()
        for record in report:
            record["targetFile"] = str(self.checkout / record["targetFile"])
        self.assertEqual(len(guard.verify_report(report, self.checkout)), 5)

    def test_incomplete_duplicate_unexpected_and_nonarray_reports_fail(self):
        report = complete_report()
        bad = [None, {}, [], report[0], report[:-1], report + [report[0]],
               [report[0]] * 5, [*report[:-1], {"ok": False, "error": "DO_NOT_PRINT"}]]
        for candidate in bad:
            with self.subTest(candidate_type=type(candidate).__name__):
                with self.assertRaises(guard.ReportError):
                    guard.verify_report(candidate, self.checkout)

    def test_invalid_project_fields_fail_before_admission(self):
        replacements = {
            "ok": [None, False, 1, "true"],
            "severityThreshold": [None, "low", "critical"],
            "vulnerabilities": [None, {}, [{"severity": "high"}]],
            "packageManager": [None, "npm"],
            "dependencyCount": [None, True, 0, -1, 1.0, "1", float("nan")],
            "depGraph": [None, {}, {"pkgManager": {"name": "wrong"}, "pkgs": [{}, {}]},
                         {"pkgManager": {"name": "composer"}, "pkgs": []},
                         {"pkgManager": {"name": "composer"}, "pkgs": [None, None]},
                         {"pkgManager": {"name": "composer"}, "pkgs": [{}, {}]}],
        }
        for field, values in replacements.items():
            for value in values:
                report = complete_report()
                report[0][field] = value
                with self.subTest(field=field, value_type=type(value).__name__):
                    with self.assertRaises(guard.ReportError):
                        guard.verify_report(report, self.checkout)
        for field in replacements:
            report = complete_report()
            del report[0][field]
            with self.subTest(missing=field):
                with self.assertRaises(guard.ReportError):
                    guard.verify_report(report, self.checkout)

    def test_manifest_conflicts_foreign_roots_traversal_and_unproven_aliases_fail(self):
        for value in ["tests/composer.lock", "../composer.lock", "/foreign/composer.lock",
                      "composer.json", "package.json", "tests/e2e/package.json",
                      "https://example.invalid/composer.lock", "composer.lock\x00", 1]:
            report = complete_report()
            report[0]["targetFile"] = value
            with self.subTest(identity_type=type(value).__name__):
                with self.assertRaises(guard.ReportError):
                    guard.verify_report(report, self.checkout)
        report = complete_report()
        del report[0]["targetFile"]
        del report[0]["displayTargetFile"]
        with self.assertRaises(guard.ReportError):
            guard.verify_report(report, self.checkout)

    def test_error_key_never_passes_even_when_other_fields_look_successful(self):
        report = complete_report()
        report[0]["error"] = "DO_NOT_PRINT_PROVIDER_DETAILS"
        with self.assertRaises(guard.ReportError):
            guard.verify_report(report, self.checkout)

    def test_file_missing_empty_truncated_invalid_encoding_and_duplicate_keys_fail(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "report.json"
            with self.assertRaises(guard.ReportError):
                guard.read_report(path, self.checkout)
            for content in [b"", b"[", b"\xff", b'{"ok":true,"ok":false}',
                            b'[{"ok":true}]\n{"other":"stdout tree"}']:
                path.write_bytes(content)
                with self.subTest(bytes=len(content)):
                    with self.assertRaises(guard.ReportError):
                        guard.read_report(path, self.checkout)

    def test_cli_status_cannot_be_replaced_by_complete_report_and_receipt_is_safe(self):
        with tempfile.TemporaryDirectory() as directory:
            report = Path(directory) / "report.json"
            receipt = Path(directory) / "receipt.json"
            scan_log = Path(directory) / "scan.log"
            scan_log.write_text("Owned offline scan output")
            command = [sys.executable, str(Path(guard.__file__)), str(report),
                       "--checkout", str(self.checkout), "--receipt", str(receipt),
                       "--scan-log", str(scan_log)]
            report.write_text(json.dumps(complete_report()))
            for code in [0, 1, 2, 3, 137]:
                result = subprocess.run(command + ["--cli-exit", str(code)],
                                        capture_output=True, text=True, check=False)
                parsed = json.loads(receipt.read_text())
                self.assertEqual(parsed["complete"], code == 0)
                self.assertEqual(result.returncode, 0 if code == 0 else 1)
                self.assertEqual(parsed["cli_exit"], code)
            bad = complete_report()
            bad[0]["error"] = "DO_NOT_PRINT_PROVIDER_DETAILS"
            report.write_text(json.dumps(bad))
            result = subprocess.run(command + ["--cli-exit", "0"],
                                    capture_output=True, text=True, check=False)
            self.assertEqual(result.returncode, 1)
            self.assertNotIn("DO_NOT_PRINT_PROVIDER_DETAILS", result.stdout + result.stderr + receipt.read_text())

    def test_safe_classification_never_copies_provider_details(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "scan.log"
            path.write_text("Enrichment of Test Failed SNYK-0003 HTTP 504 DO_NOT_PRINT")
            self.assertEqual(guard.classify_scan_log(path),
                             ["remote_enrichment_failed", "snyk_0003", "http_504"])
            for text in ["Failed to resolve dependencies", "Failed to get dependencies",
                         "Skipping unsupported ecosystem", "Project could not be scanned"]:
                path.write_text(text)
                self.assertIn("project_unavailable", guard.classify_scan_log(path))

    def test_project_resolution_warning_rejects_otherwise_complete_report(self):
        with tempfile.TemporaryDirectory() as directory:
            report = Path(directory) / "report.json"
            report.write_text(json.dumps(complete_report()))
            scan_log = Path(directory) / "scan.log"
            scan_log.write_text("Failed to resolve dependencies: DO_NOT_PRINT")
            receipt = Path(directory) / "receipt.json"
            result = subprocess.run(
                [sys.executable, str(Path(guard.__file__)), str(report),
                 "--checkout", str(self.checkout), "--receipt", str(receipt),
                 "--scan-log", str(scan_log), "--cli-exit", "0"],
                capture_output=True, text=True, check=False)
            self.assertEqual(result.returncode, 1)
            self.assertIn("project_unavailable", json.loads(receipt.read_text())["classification"])
            self.assertNotIn("DO_NOT_PRINT", result.stdout + result.stderr + receipt.read_text())

    def test_actual_workflow_shell_keeps_scan_status_receipt_and_private_cleanup(self):
        workflow = Path(__file__).resolve().parents[2] / ".github/workflows/snyk.yml"
        content = workflow.read_text()
        shell = textwrap.dedent(content.split("        id: dependency_scan\n        run: |\n", 1)[1]
                                .split("        env:\n", 1)[0])
        for status, complete, warning in [(0, True, False), (1, True, False),
                                          (2, True, False), (0, False, False),
                                          (0, True, True)]:
            with self.subTest(status=status, complete=complete, warning=warning):
                with tempfile.TemporaryDirectory() as directory:
                    owned = Path(directory)
                    tools = owned / "bin"
                    tools.mkdir()
                    report = complete_report() if complete else complete_report()[:-1]
                    fixture = owned / "fixture.json"
                    fixture.write_text(json.dumps(report))
                    (tools / "snyk").write_text(
                        "#!" + sys.executable + "\n"
                        "import os,sys,pathlib\n"
                        "pathlib.Path(os.environ['OWNED_ARGV']).write_text(__import__('json').dumps(sys.argv[1:]))\n"
                        "output=next(a.split('=',1)[1] for a in sys.argv if a.startswith('--json-file-output='))\n"
                        "pathlib.Path(output).write_bytes(pathlib.Path(os.environ['OWNED_FIXTURE']).read_bytes())\n"
                        "print('DO_NOT_PRINT_PRIVATE_TREE')\n"
                        "if os.environ['OWNED_WARNING']=='1': print('Failed to resolve dependencies: DO_NOT_PRINT')\n"
                        "raise SystemExit(int(os.environ['OWNED_STATUS']))\n")
                    (tools / "mise").write_text(
                        "#!/bin/sh\nshift 4\nexec " + sys.executable + " \"$@\"\n")
                    for tool in tools.iterdir():
                        tool.chmod(0o700)
                    outputs = owned / "github-output"
                    outputs.touch()
                    env = {"PATH": str(tools) + os.pathsep + os.defpath,
                           "RUNNER_TEMP": str(owned), "GITHUB_OUTPUT": str(outputs),
                           "GITHUB_WORKSPACE": str(self.checkout),
                           "OWNED_FIXTURE": str(fixture), "OWNED_ARGV": str(owned / "argv.json"),
                           "OWNED_STATUS": str(status), "OWNED_WARNING": "1" if warning else "0"}
                    result = subprocess.run(["/bin/bash", "-e", "-c", shell], env=env,
                                            cwd=workflow.parents[2], capture_output=True,
                                            text=True, check=False)
                    self.assertEqual(result.returncode, status if status else (0 if complete and not warning else 1))
                    receipt_path = Path(outputs.read_text().strip().split("=", 1)[1])
                    receipt = json.loads(receipt_path.read_text())
                    self.assertEqual(receipt["cli_exit"], status)
                    self.assertEqual(receipt["complete"], status == 0 and complete and not warning)
                    self.assertFalse((receipt_path.parent / "scan.log").exists())
                    self.assertFalse((receipt_path.parent / "report.json").exists())
                    self.assertNotIn("DO_NOT_PRINT", result.stdout + result.stderr + receipt_path.read_text())
                    argv = json.loads((owned / "argv.json").read_text())
                    self.assertEqual(argv[:5], ["test", "--all-projects", "--dev",
                                                "--severity-threshold=high", "--print-deps"])


if __name__ == "__main__":
    unittest.main()
