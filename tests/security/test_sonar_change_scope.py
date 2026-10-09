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
        return {"repository": {"full_name": "owned/repository", "default_branch": "main"},
                "pull_request": {"base": {"sha": base or self.base},
                                 "head": {"sha": head, "ref": "sonar/owned",
                                          "repo": {"full_name": "owned/repository"}}}}

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
        self.git("mv", "docs/testing/sonarcloud.md", "docs/fork-import.md")
        (self.repo / "CHANGELOG.md").unlink()
        second = self.commit()
        self.assertFalse(self.classify(second, first).full_analysis)

    def test_every_explicit_prose_document_is_admitted_with_real_git_blobs(self):
        for name in sorted(scope.ORDINARY_DOCUMENTS):
            with self.subTest(path=name):
                base = self.git("rev-parse", "HEAD")
                self.write(name, "Updated ordinary prose\n")
                head = self.commit()
                self.assertFalse(self.classify(head, base).full_analysis)

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
                 "docs/reviews/migration-review-carry.md", "docs/reviews/twig-page-batch-feedback.md",
                 "docs/testing/review-dispositions.md", "docs/testing/coverage-matrix.md",
                 "docs/testing/php84-modernization-audit.md", "docs/new-unreviewed.md",
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
        self.write("docs/README.md")
        (self.repo / "lib/input.php").unlink()
        head = self.commit()
        self.assertTrue(self.classify(head, base).full_analysis)
        self.write("lib/output.php")
        (self.repo / "docs/README.md").unlink()
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
        path = self.repo / "docs/README.md"
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
        body = content.split("      - name: Classify the complete tested change\n", 1)[1].split("        run: |\n", 1)[1]
        # A run scalar ends at its indentation boundary, regardless of the
        # next job's name or the number of independent coverage producers.
        lines = []
        for line in body.splitlines():
            if line.strip() and not line.startswith("          "):
                break
            lines.append(line)
        shell = textwrap.dedent("\n".join(lines))
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
            event = self.event(head)
            if not full:
                event["pull_request"]["head"]["ref"] = "docs/owned"
            event_path.write_text(json.dumps(event))
            output.write_text("")
            env = {**os.environ, "PATH": str(tools) + os.pathsep + os.environ["PATH"],
                   "GITHUB_WORKSPACE": str(self.repo), "GITHUB_EVENT_PATH": str(event_path),
                   "GITHUB_EVENT_NAME": "pull_request", "GITHUB_SHA": head,
                   "GITHUB_OUTPUT": str(output), "ENABLE_SONAR": "true", "SONAR_ALL_PRS": "",
                   "RUN_SONAR": "", "REPOSITORY": "owned/repository", "ACTOR": "owned-maintainer",
                   "GITHUB_REF": "refs/pull/1/merge"}
            result = subprocess.run(["/bin/bash", "-e", "-c", shell], cwd=ROOT,
                                    env=env, capture_output=True, text=True, check=False)
            self.assertEqual(result.returncode, 0)
            self.assertIn(f"full_analysis={'true' if full else 'false'}\n", output.read_text())
            self.assertNotIn(str(self.repo), result.stdout + result.stderr + output.read_text())
        event_path.write_text("not-json")
        result = subprocess.run(["/bin/bash", "-e", "-c", shell], cwd=ROOT,
                                env=env, capture_output=True, text=True, check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("::error::", result.stdout)

    def policy(self, event_name="pull_request", branch="sonar/owned", enable="true",
               all_prs="", run_sonar="", source="owned/repository", actor="maintainer",
               default="main", ref="refs/heads/main"):
        event = {"repository": {"full_name": "owned/repository", "default_branch": default},
                 "ref": ref, "pull_request": {"head": {"ref": branch, "repo": {"full_name": source}}}}
        return scope.event_policy(event_name, event, enable, all_prs, run_sonar,
                                  "owned/repository", actor, ref)

    def test_source_branch_matrix_and_future_all_prs_switch(self):
        for branch in ["feature/change", "fix/change", "refactor/change", "chore/change",
                       "docs/change", "experiment/change", "data/change", "model/change",
                       "chore/consolidate-main-identity"]:
            with self.subTest(branch=branch):
                self.assertFalse(self.policy(branch=branch).full_analysis)
                self.assertTrue(self.policy(branch=branch, all_prs="true").full_analysis)
        for branch in ["sonar/change", "release/v1", "sonar/nested/change", "SONAR/change", "Release/v1"]:
            self.assertTrue(self.policy(branch=branch).full_analysis)
        for branch in ["sonar", "release", "sonar-other", "feature/sonar/change"]:
            self.assertFalse(self.policy(branch=branch).full_analysis)

    def test_default_branch_and_manual_dispatch(self):
        self.assertTrue(self.policy(event_name="push").full_analysis)
        self.assertTrue(self.policy(event_name="push", default="primary", ref="refs/heads/primary").full_analysis)
        self.assertFalse(self.policy(event_name="push", ref="refs/heads/feature/change").full_analysis)
        self.assertTrue(self.policy(event_name="workflow_dispatch", run_sonar="true", ref="refs/heads/fix/change").full_analysis)
        self.assertFalse(self.policy(event_name="workflow_dispatch", run_sonar="false").full_analysis)
        self.assertFalse(self.policy(event_name="workflow_dispatch").full_analysis)

    def test_disabled_and_fork_events_never_admit_analysis(self):
        for enable in ["", "false"]:
            for name in ["pull_request", "push", "workflow_dispatch"]:
                self.assertFalse(self.policy(event_name=name, enable=enable, run_sonar="true", all_prs="true").full_analysis)
        for all_prs in ["", "true"]:
            for branch in ["sonar/change", "release/v1", "feature/change"]:
                self.assertFalse(self.policy(branch=branch, source="outside/fork", all_prs=all_prs).full_analysis)
        self.assertFalse(self.policy(actor="dependabot[bot]", all_prs="true").full_analysis)
        self.assertFalse(self.policy(event_name="pull_request_target").full_analysis)

    def test_bad_configuration_or_event_cannot_masquerade_as_clean_skip(self):
        for flag in ["enabled", "1", "true\nfalse"]:
            with self.assertRaises(ValueError):
                self.policy(enable=flag)
        for event in [{}, [], {"repository": {}},
                      {"repository": {"full_name": "owned/repository"}, "pull_request": []}]:
            with self.assertRaises(ValueError):
                scope.event_policy("pull_request", event, "true", "", "", "owned/repository", "maintainer", "refs/pull/1/merge")
        with self.assertRaises(ValueError):
            scope.event_policy("push", {"repository": {"full_name": "owned/repository", "default_branch": "main"},
                                       "ref": "refs/heads/other"}, "true", "", "", "owned/repository", "maintainer", "refs/heads/main")

    def test_requested_credential_step_fails_without_exposing_values(self):
        content = (ROOT / ".github/workflows/sonarcloud.yml").read_text()
        shell = textwrap.dedent(content.split("      - name: Verify requested analysis credentials\n", 1)[1]
                                .split("        run: |\n", 1)[1].split("      - name: Checkout", 1)[0])
        for token, expected in [("", 1), ("owned-not-a-real-token", 0)]:
            result = subprocess.run(["/bin/bash", "-e", "-c", shell],
                                    env={**os.environ, "SONAR_TOKEN": token}, capture_output=True, text=True)
            self.assertEqual(result.returncode, expected)
            if token:
                self.assertNotIn(token, result.stdout + result.stderr)
            else:
                self.assertIn("SONAR_TOKEN is not configured", result.stdout)


    def test_future_required_gate_rejects_failed_cancelled_and_skipped_analysis(self):
        content = (ROOT / ".github/workflows/sonarcloud.yml").read_text()
        shell = textwrap.dedent(content.split("      - name: Require completed analysis\n", 1)[1]
                                .split("        run: |\n", 1)[1])
        for scope_result in ["success", "failure", "cancelled", "skipped"]:
            for analysis_result in ["success", "failure", "cancelled", "skipped"]:
                result = subprocess.run(["/bin/bash", "-e", "-c", shell],
                                        env={**os.environ, "SCOPE_RESULT": scope_result,
                                             "ANALYSIS_RESULT": analysis_result}, capture_output=True, text=True)
                self.assertEqual(result.returncode, 0 if scope_result == analysis_result == "success" else 1)



if __name__ == "__main__":
    unittest.main()
