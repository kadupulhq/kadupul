#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Exercise Sonar applicability with actual Git trees and the workflow command."""

import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import textwrap
import unittest

import sonar_change_scope as scope

ROOT = Path(__file__).resolve().parents[2]


class SonarChangeScopeTest(unittest.TestCase):
    def setUp(self):
        self.owned = tempfile.TemporaryDirectory(prefix="kadupul-sonar-scope-")
        self.addCleanup(self.owned.cleanup)
        self.directory = Path(self.owned.name)
        self.repo = self.directory / "checkout"
        self.repo.mkdir()
        self.git("init", "-q")
        self.git("config", "user.name", "Owned fixture")
        self.git("config", "user.email", "fixture@example.invalid")
        self.git("config", "commit.gpgsign", "false")
        self.git("config", "core.hooksPath", "/dev/null")
        self.write("README.md", "Original documentation\n")
        self.base = self.commit()

    def git(self, *arguments):
        return subprocess.check_output([shutil.which("git"), "-C", str(self.repo), *arguments],
                                       stderr=subprocess.DEVNULL).decode().strip()

    def write(self, name, content="Owned fixture\n"):
        path = self.repo / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)
        return path

    def commit(self):
        self.git("add", "-A")
        self.git("commit", "-qm", "Owned scope fixture")
        return self.git("rev-parse", "HEAD")

    def event(self, head, base=None):
        return {"pull_request": {"base": {"sha": base or self.base}, "head": {"sha": head}}}

    def classify(self, head, base=None):
        return scope.classify(self.repo, "pull_request", self.event(head, base), head)

    def test_ordinary_markdown_add_modify_delete_and_docs_rename_are_inapplicable(self):
        self.write("README.md", "Updated documentation\n")
        self.write("CHANGELOG.md")
        self.write("CONTRIBUTING.md")
        self.write("docs/testing/sonarcloud.md")
        first = self.commit()
        result = self.classify(first)
        self.assertFalse(result.full_analysis)
        self.assertEqual(result.changed_files, 4)
        self.git("mv", "docs/testing/sonarcloud.md", "docs/testing/sonar-scheduling.md")
        (self.repo / "CHANGELOG.md").unlink()
        second = self.commit()
        self.assertFalse(self.classify(second, first).full_analysis)

    def test_main_documentation_push_is_inapplicable_but_unknown_ref_is_full(self):
        self.write("docs/README.md")
        head = self.commit()
        event = {"ref": "refs/heads/main", "before": self.base, "after": head}
        self.assertFalse(scope.classify(self.repo, "push", event, head).full_analysis)
        event["ref"] = "refs/heads/unregistered"
        self.assertTrue(scope.classify(self.repo, "push", event, head).full_analysis)

    def test_code_tests_ci_locks_config_schema_and_verification_markdown_are_full(self):
        paths = ["lib/runtime.php", "tests/Fixture.md", ".github/workflows/sonarcloud.yml",
                 "tests/security/sonar_change_scope.py", "composer.lock", "package-lock.json",
                 "mise.toml", "sonar-project.properties", ".dockerignore", "config/services.yaml",
                 "docs/audit_schema.sql", "docs/html/help.html", "docs/fixture.json",
                 "docs/cdef-reference-integrity.md", "docs/testing/behavioral-surface.md",
                 "docs/fixtures/input.md", "docs/evidence/receipt.md", "AGENTS.md", "unknown.md"]
        for path in paths:
            with self.subTest(path=path):
                self.write(path)
                head = self.commit()
                self.assertTrue(self.classify(head).full_analysis)
                (self.repo / path).unlink()
                self.commit()

    def test_complete_pr_with_code_then_documentation_stays_full(self):
        self.write("lib/runtime.php")
        self.commit()
        self.write("README.md", "Last commit only changes prose\n")
        head = self.commit()
        self.assertEqual(self.git("diff", "--name-only", "HEAD~1", "HEAD"), "README.md")
        self.assertTrue(self.classify(head).full_analysis)

    def test_tested_merge_tree_covers_main_advances_and_pr_source(self):
        self.git("checkout", "-qb", "owned-pr")
        self.write("docs/README.md")
        head = self.commit()
        self.git("checkout", "-qb", "owned-main", self.base)
        self.write("lib/base-runtime.php")
        base = self.commit()
        self.git("merge", "--no-ff", "-qm", "Owned tested merge", head)
        tested = self.git("rev-parse", "HEAD")
        result = scope.classify(self.repo, "pull_request", self.event(head, base), tested)
        self.assertFalse(result.full_analysis)
        # A source change in the tested tree must not be hidden by a docs-only head.
        self.write("lib/merge-resolution.php")
        changed = self.commit()
        self.assertTrue(scope.classify(self.repo, "pull_request", self.event(head, base), changed).full_analysis)

    def test_code_to_docs_and_docs_to_code_renames_are_full(self):
        self.write("lib/input.php")
        base = self.commit()
        self.write("docs/input.md")
        (self.repo / "lib/input.php").unlink()
        head = self.commit()
        self.assertTrue(self.classify(head, base).full_analysis)
        self.write("lib/output.php")
        (self.repo / "docs/input.md").unlink()
        next_head = self.commit()
        self.assertTrue(self.classify(next_head, head).full_analysis)

    def test_executable_and_symlink_documentation_additions_changes_deletions_are_full(self):
        path = self.write("docs/executable.md")
        self.git("add", "docs/executable.md")
        self.git("update-index", "--chmod=+x", "docs/executable.md")
        self.git("commit", "-qm", "Owned executable fixture")
        executable = self.git("rev-parse", "HEAD")
        self.assertTrue(self.classify(executable).full_analysis)
        path.unlink()
        deleted = self.commit()
        self.assertTrue(self.classify(deleted, executable).full_analysis)
        target = self.repo / "docs/linked.md"
        target.symlink_to("../README.md")
        linked = self.commit()
        self.assertTrue(self.classify(linked).full_analysis)
        target.unlink()
        self.write("docs/linked.md")
        regular = self.commit()
        self.assertTrue(self.classify(regular, linked).full_analysis)
        self.git("update-index", "--chmod=+x", "README.md")
        self.git("commit", "-qm", "Owned mode transition")
        self.assertTrue(self.classify(self.git("rev-parse", "HEAD"), regular).full_analysis)

    def test_unavailable_history_empty_changes_and_unverified_checkout_are_full(self):
        for event in [self.event(self.base), self.event(self.base, "a" * 40),
                      self.event(self.base, "0" * 40), {}, {"pull_request": []}]:
            with self.subTest(event_shape=list(event)):
                self.assertTrue(scope.classify(self.repo, "pull_request", event, self.base).full_analysis)
        self.assertTrue(scope.classify(self.repo, "pull_request", self.event(self.base), "a" * 40).full_analysis)
        self.assertTrue(scope.classify(self.repo, "workflow_dispatch", {}, self.base).full_analysis)

    def test_special_paths_and_malformed_raw_records_are_full(self):
        for path in ["docs/line\nbreak.md", "docs/escape\\name.md", "docs/.hidden.md"]:
            self.write(path)
            head = self.commit()
            self.assertTrue(self.classify(head).full_analysis)
            (self.repo / path).unlink()
            self.commit()
        for raw in [b"", b"invalid\0docs/README.md\0", b"truncated", b"\0",
                    b":100644 100644 invalid invalid M\0docs/README.md\0"]:
            self.assertTrue(scope.classify_diff(raw).full_analysis)

    def test_binary_invalid_utf8_and_oversized_markdown_are_full(self):
        path = self.repo / "docs/input.md"
        path.parent.mkdir()
        for content in [b"binary\0input", b"\xff", b"x" * (4 * 1024 * 1024 + 1)]:
            with self.subTest(content_length=len(content)):
                path.write_bytes(content)
                head = self.commit()
                self.assertTrue(self.classify(head).full_analysis)
                path.unlink()
                self.commit()

    def test_large_document_batch_is_full_without_reading_unbounded_contents(self):
        for index in range(201):
            self.write(f"docs/guide-{index}.md")
        head = self.commit()
        result = self.classify(head)
        self.assertTrue(result.full_analysis)
        self.assertEqual(result.reason, "oversized-change")

    def test_actual_workflow_command_emits_current_scope_without_private_paths(self):
        content = (ROOT / ".github/workflows/sonarcloud.yml").read_text()
        shell = textwrap.dedent(content.split("        id: scope\n        run: |\n", 1)[1]
                                .split("\n  analyze:", 1)[0])
        tools = self.directory / "bin"
        tools.mkdir()
        runner = tools / "mise"
        runner.write_text("#!/bin/sh\nshift 4\nexec " + sys.executable + " \"$@\"\n")
        runner.chmod(0o700)
        event_path = self.directory / "event.json"
        output = self.directory / "output"
        for name, full in [("docs/README.md", False), ("lib/required.php", True)]:
            self.write(name)
            head = self.commit()
            event_path.write_text(json.dumps(self.event(head)))
            output.write_text("")
            env = {**os.environ, "PATH": str(tools) + os.pathsep + os.environ["PATH"],
                   "GITHUB_WORKSPACE": str(self.repo), "GITHUB_EVENT_PATH": str(event_path),
                   "GITHUB_EVENT_NAME": "pull_request", "GITHUB_SHA": head,
                   "GITHUB_OUTPUT": str(output)}
            result = subprocess.run(["/bin/bash", "-e", "-c", shell], cwd=ROOT,
                                    env=env, capture_output=True, text=True, check=False)
            self.assertEqual(result.returncode, 0)
            self.assertIn(f"full_analysis={'true' if full else 'false'}\n", output.read_text())
            self.assertNotIn(str(self.repo), result.stdout + result.stderr + output.read_text())
        event_path.write_text("not-json")
        result = subprocess.run(["/bin/bash", "-e", "-c", shell], cwd=ROOT,
                                env=env, capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0)
        self.assertIn("reason=unreadable-event", output.read_text())


if __name__ == "__main__":
    unittest.main()
