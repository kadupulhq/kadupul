#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Skip expensive Sonar coverage only for verified ordinary documentation changes."""

import argparse
from dataclasses import dataclass
import json
from pathlib import Path
import re
import shutil
import subprocess


# Keep an explicit list: new/unknown Markdown may carry executable verification
# provenance, so it requires full analysis until its consumers are reviewed.
ORDINARY_DOCUMENTS = frozenset((
    "README.md", "CHANGELOG.md", "CONTRIBUTING.md", "docs/README.md",
    "docs/testing/sonarcloud.md", "docs/digitalocean-runners.md", "docs/fork-import.md",
    "docs/upgrading-rrd-storage.md", "docs/symfony-external-links.md", "docs/security-headers.md",
))
SHA = re.compile(r"[0-9a-f]{40}\Z")


@dataclass(frozen=True)
class Scope:
    full_analysis: bool
    reason: str
    changed_files: int = 0


def git(checkout: Path, *arguments: str, input_bytes: bytes | None = None) -> bytes:
    executable = shutil.which("git")
    if executable is None:
        raise OSError("Git is unavailable")
    return subprocess.run(
        [executable, "-C", str(checkout), *arguments], check=True,
        input=input_bytes, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=30,
    ).stdout


def ordinary_document(path: bytes) -> bool:
    try:
        name = path.decode("utf-8")
    except UnicodeError:
        return False
    return name in ORDINARY_DOCUMENTS


def classify_diff(raw: bytes) -> Scope:
    if not raw:
        return Scope(True, "empty-change")
    if len(raw) > 4 * 1024 * 1024:
        return Scope(True, "oversized-change")
    fields = raw.split(b"\0")
    if fields.pop() != b"" or len(fields) % 2:
        return Scope(True, "unreadable-change")
    count = len(fields) // 2
    if count > 200:
        return Scope(True, "oversized-change", count)
    for index in range(0, len(fields), 2):
        header, path = fields[index:index + 2]
        metadata = header.split()
        if len(metadata) != 5 or not metadata[0].startswith(b":"):
            return Scope(True, "unreadable-change", count)
        old_mode, new_mode = metadata[0][1:], metadata[1]
        status = metadata[4]
        if any(re.fullmatch(rb"[0-9a-f]{40}", blob) is None for blob in metadata[2:4]):
            return Scope(True, "unreadable-change", count)
        if ((status == b"A" and old_mode != b"000000")
                or (status == b"D" and new_mode != b"000000")
                or (status == b"M" and b"000000" in (old_mode, new_mode))):
            return Scope(True, "unreadable-change", count)
        if (status not in (b"A", b"M", b"D")
                or old_mode not in (b"000000", b"100644")
                or new_mode not in (b"000000", b"100644")
                or (old_mode == new_mode == b"000000")
                or not ordinary_document(path)):
            return Scope(True, "analysis-input-change", count)
    return Scope(False, "documentation-only", count)


def ordinary_text_blobs(checkout: Path, raw: bytes) -> bool:
    # Batch immutable object reads rather than launching one process per file.
    headers = raw.split(b"\0")[:-1:2]
    identifiers = sorted({blob for header in headers for blob in header.split()[2:4]
                          if blob != b"0" * 40})
    request = b"\n".join(identifiers) + b"\n"
    records = git(checkout, "cat-file", "--batch-check", input_bytes=request).splitlines()
    if len(records) != len(identifiers):
        return False
    sizes = []
    for identifier, record in zip(identifiers, records):
        fields = record.split()
        if len(fields) != 3 or fields[:2] != [identifier, b"blob"] or not fields[2].isdigit():
            return False
        sizes.append(int(fields[2]))
    if sum(sizes) > 4 * 1024 * 1024:
        return False
    data = git(checkout, "cat-file", "--batch", input_bytes=request)
    offset = 0
    for identifier, size in zip(identifiers, sizes):
        header = identifier + b" blob " + str(size).encode("ascii") + b"\n"
        if data[offset:offset + len(header)] != header:
            return False
        offset += len(header)
        content = data[offset:offset + size]
        if len(content) != size or b"\0" in content or data[offset + size:offset + size + 1] != b"\n":
            return False
        try:
            content.decode("utf-8")
        except UnicodeError:
            return False
        offset += size + 1
    return offset == len(data)


def classify(checkout: Path, event_name: str, event: dict, expected: str) -> Scope:
    try:
        if not isinstance(event, dict) or not SHA.fullmatch(expected):
            return Scope(True, "unverified-checkout")
        actual = git(checkout, "rev-parse", "HEAD").decode("ascii").strip()
        if actual != expected:
            return Scope(True, "unverified-checkout")
        if event_name == "pull_request":
            base = event["pull_request"]["base"]["sha"]
            head = event["pull_request"]["head"]["sha"]
        elif event_name == "push" and event.get("ref") == "refs/heads/main":
            base, head = event["before"], event["after"]
            if head != expected:
                return Scope(True, "unverified-checkout")
        else:
            return Scope(True, "unsupported-event")
        if (not isinstance(base, str) or not isinstance(head, str)
                or not SHA.fullmatch(base) or not SHA.fullmatch(head)
                or base == "0" * 40 or head == "0" * 40):
            return Scope(True, "unavailable-history")
        # The actual checkout must contain both event inputs, including the PR's
        # current base. Comparing that tested merge tree covers the full PR,
        # merge-resolution changes and main advances, not merely its last commit.
        for revision in (base, head):
            git(checkout, "merge-base", "--is-ancestor", revision, actual)
        raw = git(
            checkout, "diff", "--raw", "--no-abbrev", "--no-renames", "-z",
            "--no-ext-diff", "--no-textconv", base, actual, "--",
        )
        decision = classify_diff(raw)
        if not decision.full_analysis and not ordinary_text_blobs(checkout, raw):
            return Scope(True, "nonordinary-documentation", decision.changed_files)
        return decision
    except (OSError, UnicodeError, KeyError, TypeError, ValueError, subprocess.SubprocessError):
        return Scope(True, "unavailable-history")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--checkout", type=Path, required=True)
    parser.add_argument("--event-path", type=Path, required=True)
    parser.add_argument("--event-name", required=True)
    parser.add_argument("--expected-checkout", required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    try:
        if args.event_path.stat().st_size > 2 * 1024 * 1024:
            raise ValueError("Oversized event")
        event = json.loads(args.event_path.read_text(encoding="utf-8"))
        scope = classify(args.checkout, args.event_name, event, args.expected_checkout)
    except (OSError, UnicodeError, ValueError, RecursionError):
        scope = Scope(True, "unreadable-event")
    with args.output.open("a", encoding="utf-8") as output:
        output.write(f"full_analysis={'true' if scope.full_analysis else 'false'}\n")
        output.write(f"reason={scope.reason}\nchanged_files={scope.changed_files}\n")
    if scope.full_analysis:
        print(f"Full Sonar coverage and analysis required: {scope.reason}")
    else:
        print(f"Sonar not applicable: {scope.changed_files} ordinary documentation changes; "
              "no new analysis or quality-gate result is claimed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
