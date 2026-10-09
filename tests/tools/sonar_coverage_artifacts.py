#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Bind complete coverage artifacts to their checkout before Sonar imports them."""

from __future__ import annotations

import argparse
import hashlib
import json
import posixpath
import re
import shutil
import subprocess
from pathlib import Path
from urllib.parse import unquote, urlsplit

import defusedxml.ElementTree as ET
from defusedxml.common import DefusedXmlException

REPORTS = {
    "legacy": ("tests/coverage.xml",),
    "application": (
        "var/symfony-coverage.xml", "var/offline-coverage.xml",
        "tests/vendor-sync.lcov", "var/browser-coverage.lcov",
    ),
}


def identity(root: Path, expected: str) -> tuple[str, str]:
    if not re.fullmatch(r"[0-9a-f]{40}", expected):
        raise ValueError("Invalid expected source revision")
    def git(ref: str) -> str:
        return subprocess.check_output(["git", "-C", str(root), "rev-parse", ref], text=True).strip()
    sha = git("HEAD")
    if sha != expected:
        raise ValueError("Checkout does not match the requested source revision")
    return sha, git("HEAD^{tree}")


def digest(path: Path) -> str:
    if path.is_symlink() or not path.is_file() or path.stat().st_size == 0:
        raise ValueError("Missing, empty or linked coverage report")
    return hashlib.sha256(path.read_bytes()).hexdigest()


def seal(root: Path, part: str, bundle: Path, expected: str) -> None:
    sha, tree = identity(root, expected)
    # Validate the entire input before creating an artifact.
    hashes = {name: digest(root / name) for name in REPORTS[part]}
    bundle.mkdir(parents=True, exist_ok=False)
    for name in hashes:
        target = bundle / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(root / name, target)
    (bundle / "receipt.json").write_text(json.dumps({
        "version": 1, "part": part, "source_sha": sha, "source_tree": tree,
        "source_root": str(root.resolve()), "reports": hashes,
    }, sort_keys=True) + "\n")


def mapped_path(value: str, producer: str, root: Path) -> Path:
    if value.startswith("file:"):
        url = urlsplit(value)
        if url.netloc not in ("", "localhost") or url.query or url.fragment:
            raise ValueError("Unsupported coverage source URL")
        value = unquote(url.path)
    if "\x00" in value or "\\" in value:
        raise ValueError("Invalid coverage source path")
    if value.startswith("/"):
        if value == producer:
            value = "."
        elif value.startswith(producer + "/"):
            value = value[len(producer) + 1:]
        else:
            raise ValueError("Coverage source is outside the producing checkout")
    value = posixpath.normpath(value)
    if value == ".." or value.startswith("../") or value.startswith("/") or ":" in value:
        raise ValueError("Coverage source escapes its checkout")
    path = root / value
    if not path.resolve().is_relative_to(root.resolve()):
        raise ValueError("Coverage source follows a link outside its checkout")
    return path


def normalize_xml(data: bytes, producer: str, root: Path, python: bool) -> bytes:
    document = ET.fromstring(data, forbid_entities=True, forbid_external=True)
    if python:
        if document.tag != "coverage":
            raise ValueError("Expected a Python Cobertura report")
        sources = document.findall("./sources/source")
        if not sources:
            raise ValueError("Python coverage has no source roots")
        paths = [mapped_path(source.text or ".", producer, root) for source in sources]
        for source, path in zip(sources, paths):
            source.text = str(path)
        classes = document.findall(".//class")
        if not classes or not any(int(line.get("hits", "0")) > 0 for line in document.findall(".//line")):
            raise ValueError("Python coverage contains no executed statements")
        for item in classes:
            filename = item.get("filename", "")
            if not filename:
                raise ValueError("Python coverage class has no source filename")
            if filename.startswith("/") or filename.startswith("file:"):
                item.set("filename", str(mapped_path(filename, producer, root)))
            else:
                candidates = [mapped_path(str(path / filename), str(root), root) for path in paths]
                if not any(path.is_file() for path in candidates):
                    raise ValueError("Python coverage source is absent from the checkout")
    else:
        metrics = document.find("./project/metrics")
        files = document.findall(".//file")
        if document.tag != "coverage" or metrics is None or not files or int(metrics.get("coveredstatements", "0")) <= 0:
            raise ValueError("Expected complete, nonempty PHP Clover coverage")
        for item in files:
            filename = item.get("name", "")
            if not filename:
                raise ValueError("PHP coverage file has no source filename")
            item.set("name", str(mapped_path(filename, producer, root)))
    return ET.tostring(document, encoding="utf-8", xml_declaration=True)


def normalize_lcov(data: bytes, producer: str, root: Path) -> bytes:
    lines = data.decode("utf-8").splitlines()
    sources = hits = 0
    for i, line in enumerate(lines):
        if line.startswith("SF:"):
            if not line[3:]:
                raise ValueError("LCOV source filename is empty")
            lines[i] = "SF:" + str(mapped_path(line[3:], producer, root))
            sources += 1
        elif line.startswith("DA:"):
            fields = line[3:].split(",")
            if len(fields) < 2 or int(fields[0]) <= 0 or int(fields[1]) < 0:
                raise ValueError("Invalid LCOV statement counter")
            hits += int(fields[1])
    if sources == 0 or hits == 0:
        raise ValueError("LCOV contains no executed source statements")
    return ("\n".join(lines) + "\n").encode()


def consume(root: Path, bundles: Path, expected: str) -> None:
    sha, tree = identity(root, expected)
    outputs: dict[str, bytes] = {}
    # Prepare all reports before replacing any destination: partial sets never
    # reach the scanner, and both producer jobs must use this exact checkout.
    for part, names in REPORTS.items():
        bundle = bundles / part
        receipt_path = bundle / "receipt.json"
        if receipt_path.is_symlink():
            raise ValueError("Linked coverage receipt")
        receipt = json.loads(receipt_path.read_text())
        if not isinstance(receipt, dict) or type(receipt.get("version")) is not int:
            raise ValueError("Invalid coverage receipt format")
        if (receipt.get("version"), receipt.get("part"), receipt.get("source_sha"), receipt.get("source_tree")) != (1, part, sha, tree):
            raise ValueError("Coverage receipt does not match this source and phase")
        hashes = receipt.get("reports")
        if not isinstance(hashes, dict) or set(hashes) != set(names):
            raise ValueError("Coverage receipt has an incomplete or unexpected report set")
        present = {str(path.relative_to(bundle)) for path in bundle.rglob("*") if path.is_file()}
        if present != {*names, "receipt.json"} or any(path.is_symlink() for path in bundle.rglob("*")):
            raise ValueError("Coverage artifact has unexpected files or links")
        producer = receipt.get("source_root")
        if not isinstance(producer, str) or not producer.startswith("/") or posixpath.normpath(producer) != producer:
            raise ValueError("Invalid producing checkout root")
        for name in names:
            path = bundle / name
            if digest(path) != hashes[name]:
                raise ValueError("Coverage report checksum does not match its receipt")
            data = path.read_bytes()
            outputs[name] = normalize_lcov(data, producer, root) if name.endswith(".lcov") else normalize_xml(data, producer, root, name == "var/offline-coverage.xml")
    if any(not (root / name).resolve().is_relative_to(root.resolve()) for name in outputs):
        raise ValueError("Coverage destination escapes its checkout")
    for name, data in outputs.items():
        target = root / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=("seal", "consume"))
    parser.add_argument("--root", type=Path, default=Path.cwd())
    parser.add_argument("--bundle", type=Path, required=True)
    parser.add_argument("--expected-source", required=True)
    parser.add_argument("--part", choices=REPORTS)
    args = parser.parse_args()
    try:
        if args.mode == "seal":
            if not args.part:
                parser.error("seal requires --part")
            seal(args.root.resolve(), args.part, args.bundle, args.expected_source)
        else:
            consume(args.root.resolve(), args.bundle, args.expected_source)
    except (ValueError, OSError, ET.ParseError, DefusedXmlException) as error:
        parser.exit(1, f"Coverage artifact verification failed: {error}\n")
    print("Complete coverage report set verified for the requested source revision.")


if __name__ == "__main__":
    main()
