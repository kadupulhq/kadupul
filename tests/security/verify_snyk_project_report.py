# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Require complete pinned Snyk legacy results without printing report contents.

Producer: snyk/cli e7e006e (v1.1307.4), format-test-results.ts and
snyk-test/run-test.ts. --print-deps selects testing, whereas --print-graph
can return without a vulnerability test. Use a dedicated --json-file-output;
stdout may contain dependency trees and is not a JSON report.
"""
import argparse
import json
import re
from pathlib import Path, PurePosixPath

EXPECTED_PROJECTS = {
    "composer.lock": "composer",
    "package-lock.json": "npm",
    "tests/composer.lock": "composer",
    "tests/Symfony/requirements.txt": "pip",
    "tests/tools/requirements.txt": "pip",
    "tests/e2e/package-lock.json": "npm",
}


class ReportError(ValueError):
    """A fixed, safe diagnostic that never includes untrusted report values."""


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ReportError("Report contains duplicate JSON keys")
        result[key] = value
    return result


def canonical_manifest(value, checkout):
    if not isinstance(value, str) or not value or "\x00" in value:
        raise ReportError("Project manifest identity is missing or invalid")
    path = PurePosixPath(value.replace("\\", "/"))
    if ".." in path.parts:
        raise ReportError("Project manifest identity escapes the checkout")
    if path.is_absolute():
        try:
            path = path.relative_to(PurePosixPath(checkout.resolve().as_posix()))
        except ValueError:
            raise ReportError("Project manifest identity escapes the checkout") from None
    name = path.as_posix()
    if name not in EXPECTED_PROJECTS:
        raise ReportError("Report includes an unexpected project manifest")
    return name


def verify_report(report, checkout):
    if not isinstance(report, list) or len(report) != len(EXPECTED_PROJECTS):
        raise ReportError(f"Report must contain exactly {len(EXPECTED_PROJECTS)} project results")
    counts = {}
    for project in report:
        if not isinstance(project, dict) or "error" in project:
            raise ReportError("Report contains a failed or invalid project result")
        if project.get("ok") is not True:
            raise ReportError("A project scan did not report successful completion")
        if project.get("severityThreshold") != "high":
            raise ReportError("A project scan used an unexpected severity threshold")
        vulnerabilities = project.get("vulnerabilities")
        if not isinstance(vulnerabilities, list) or vulnerabilities:
            raise ReportError("A project scan has missing or remaining findings")
        identities = []
        for field in ("targetFile", "displayTargetFile"):
            value = project.get(field)
            if value is not None and value != "":
                identities.append(canonical_manifest(value, checkout))
        if not identities:
            raise ReportError("Project manifest identity is missing or invalid")
        if any(identity != identities[0] for identity in identities):
            raise ReportError("Project manifest identities conflict")
        name = identities[0]
        if name in counts:
            raise ReportError("Report contains a duplicate project result")
        if project.get("packageManager") != EXPECTED_PROJECTS[name]:
            raise ReportError("A project scan used an unexpected package manager")
        count = project.get("dependencyCount")
        if type(count) is not int or count <= 0:
            raise ReportError("A project scan has no valid dependency count")
        graph = project.get("depGraph")
        if (not isinstance(graph, dict)
                or not isinstance(graph.get("pkgManager"), dict)
                or graph["pkgManager"].get("name") != EXPECTED_PROJECTS[name]
                or not isinstance(graph.get("pkgs"), list)
                or len(graph["pkgs"]) < 2
                or any(not isinstance(pkg, dict)
                       or not isinstance(pkg.get("info"), dict)
                       or not isinstance(pkg["info"].get("name"), str)
                       or not pkg["info"]["name"] for pkg in graph["pkgs"])):
            raise ReportError("A project scan has no matching nonempty dependency graph")
        counts[name] = count
    if set(counts) != set(EXPECTED_PROJECTS):
        raise ReportError("Report is missing an expected project result")
    return {name: counts[name] for name in EXPECTED_PROJECTS}


def read_report(path, checkout):
    try:
        report = json.loads(path.read_text(encoding="utf-8"), object_pairs_hook=unique_object)
    except (OSError, UnicodeError, json.JSONDecodeError, RecursionError):
        raise ReportError("Report is missing, unreadable or malformed") from None
    return verify_report(report, checkout)


def classify_scan_log(path):
    try:
        text = path.read_text(encoding="utf-8")
    except (OSError, UnicodeError):
        raise ReportError("Scan output is missing or unreadable") from None
    if not text.strip():
        raise ReportError("Scan output is empty")
    patterns = {
        "remote_enrichment_failed": r"Enrichment(?: of Test Failed|TestFailed)",
        "snyk_0003": r"\bSNYK-?0003\b",
        "snyk_9999": r"\bSNYK-?9999\b",
        "http_500": r"(?:HTTP(?: status)?|status(?: code)?|Server response)[: =]+500\b",
        "http_504": r"(?:HTTP(?: status)?|status(?: code)?|Server response)[: =]+504\b",
        "project_unavailable": (
            r"failed to (?:resolve|get) dependencies|skipping unsupported ecosystem|"
            r"(?:projects?|manifests?) (?:could not|couldn't|cannot) be (?:scanned|tested)|"
            r"failed to test (?:a |the )?project|unable to test (?:a |the )?project"
        ),
    }
    return [name for name, pattern in patterns.items() if re.search(pattern, text, re.I)]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("report", type=Path)
    parser.add_argument("--checkout", type=Path, required=True)
    parser.add_argument("--cli-exit", type=int, required=True)
    parser.add_argument("--scan-log", type=Path, required=True)
    parser.add_argument("--receipt", type=Path, required=True)
    args = parser.parse_args()
    receipt = {"complete": False, "cli_exit": args.cli_exit}
    try:
        receipt["classification"] = classify_scan_log(args.scan_log)
        if "project_unavailable" in receipt["classification"]:
            raise ReportError("Scan output reports an unavailable or skipped project")
        receipt["projects"] = read_report(args.report, args.checkout)
        if args.cli_exit != 0:
            if not receipt["classification"]:
                receipt["classification"] = ["cli_error"]
            raise ReportError("Snyk CLI returned a failure status")
        receipt["complete"] = True
    except ReportError as error:
        receipt["error"] = str(error)
        if args.cli_exit != 0 and not receipt.get("classification"):
            receipt["classification"] = ["cli_error"]
    args.receipt.write_text(json.dumps(receipt, indent=2) + "\n", encoding="utf-8")
    if not receipt["complete"]:
        print(receipt["error"])
        return 1
    print(f"Snyk completed all {len(EXPECTED_PROJECTS)} expected project scans")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
