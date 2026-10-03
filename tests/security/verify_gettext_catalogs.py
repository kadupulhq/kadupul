#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Check shipped gettext catalogs against current production PHP messages.

Requires GNU gettext; missing tools and empty discovery are failures. Catalog
roots may differ from the source root to verify an archived catalog regression.
"""

import argparse
import ast
import hashlib
import json
from pathlib import Path
import re
import subprocess
import tempfile

COMPATIBILITY_CONTENT_SHA256 = "be9c11c6bb6387a19851598ec6e08a32b153c03036716b26f45dc3353810eaab"
RETIRED_MESSAGES = frozenset(("Access Denied!  LDAP Error: %s",
                             "Access Denied!  LDAP Search Error: %s",
                             "LDAP Search Error: %s"))


def compatibility(path: Path) -> dict[tuple[str, str, str], dict]:
    """Read only the explicitly reviewed historical compatibility inventory."""
    data = json.loads(path.read_text())
    provenance = {
        "version": 1,
        "kind": "reviewed-historical-compatibility",
        "source_commit": "b697dd0241c50a844c5da95782d67ed703cf245c",
        "source_pot_sha256": "911beef0fb6b297947fe08403ba7ab19dcae5e1cda90e25aaa66bf2aed0de52b",
        "review_document": "docs/cdef-reference-integrity.md",
        "review_document_sha256": "17ef8354f7a00eae59d2d30baa17f4bff6c08b7d4cdc4aa681989c779419a55e",
    }
    if any(data.get(key) != value for key, value in provenance.items()):
        raise ValueError("Historical compatibility provenance differs from review")
    result = {}
    identities = data.get("identities")
    if not isinstance(identities, list) or len(identities) != 195:
        raise ValueError("Historical compatibility inventory is incomplete")
    for item in identities:
        key = item.get("identity") if isinstance(item, dict) else None
        if (not isinstance(key, list) or len(key) != 3
                or any(not isinstance(value, str) or "\0" in value for value in key)
                or not key[1] or key[1] in RETIRED_MESSAGES or tuple(key) in result):
            raise ValueError("Invalid historical compatibility identity")
        result[tuple(key)] = item
    canonical = json.dumps(data, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode()
    if hashlib.sha256(canonical).hexdigest() != COMPATIBILITY_CONTENT_SHA256:
        raise ValueError("Historical compatibility identities or translations differ from review")
    return result


def entries(text: str) -> dict[tuple[str, str, str], dict[str, str]]:
    result = {}
    for block in re.split(r"\n\s*\n", text):
        fields = {}
        field = None
        references = set()
        flags = set()
        for line in block.splitlines():
            if line.startswith("#: "):
                references.update(item.removeprefix("./") for item in line[3:].split())
            if line.startswith("#, "):
                flags.update(item.strip() for item in line[3:].split(","))
            if line.startswith("#"):
                continue
            match = re.match(r"(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?) (\".*\")$", line)
            if match:
                field = match[1]
                fields[field] = ast.literal_eval(match[2])
            elif line.startswith('"') and field is not None:
                fields[field] += ast.literal_eval(line)
        if fields.get("msgid"):
            fields["references"] = " ".join(sorted(references))
            fields["flags"] = ",".join(sorted(flags))
            key = (fields.get("msgctxt", ""), fields["msgid"], fields.get("msgid_plural", ""))
            if key in result:
                raise ValueError("Duplicate gettext message")
            result[key] = fields
    return result


def verify(source: Path, catalogs: Path) -> None:
    historical = compatibility(source / "locales/historical-compatibility.json")
    manifest = json.loads((source / "locales/catalogs.json").read_text())
    declared = manifest["translated_locales"]
    if (manifest["source_fallback"] != "en-US" or not isinstance(declared, list)
            or not declared or any(not isinstance(locale, str) or not re.fullmatch(r"[a-z]{2}-[A-Z]{2}", locale) for locale in declared)
            or len(declared) != len(set(declared)) or "en-US" in declared):
        raise ValueError("Invalid shipped gettext locale manifest")
    expected_locales = set(declared)
    if ({path.stem for path in (catalogs / "po").glob("*.po")} != expected_locales
            or {path.stem for path in (catalogs / "LC_MESSAGES").glob("*.mo")} != expected_locales):
        raise ValueError("PO/MO locale pairs differ from declared shipped locales")
    files = sorted(path.relative_to(source).as_posix() for path in source.glob("*.php"))
    files += sorted(path.relative_to(source).as_posix() for path in source.glob("*/*.php"))
    files += sorted(path.relative_to(source).as_posix() for path in (source / "src").rglob("*.php"))
    if not files:
        raise ValueError("No production PHP sources discovered")
    keywords = ["__gettext", "__", "__n:1,2", "__x:1c,2", "__xn:1c,2,3",
                "__esc", "__esc_n:1,2", "__esc_x:1c,2", "__esc_xn:1c,2,3", "__date"]
    with tempfile.TemporaryDirectory(prefix="kadupul-gettext-check-") as temporary:
        pot = Path(temporary) / "source.pot"
        subprocess.run(["xgettext", "--from-code=UTF-8", "--no-wrap", "-F",
                        *["-k" + key for key in keywords], "-o", str(pot), *files],
                       cwd=source, check=True, timeout=120)
        expected = entries(pot.read_text())
        actual = entries((catalogs / "po/cacti.pot").read_text())
        required = set(expected) | set(historical)
        if any(key[1] in RETIRED_MESSAGES for key in actual) or any(key[1] in RETIRED_MESSAGES for key in expected):
            raise ValueError("Retired LDAP identity has returned")
        if not expected or required != set(actual):
            raise ValueError(f"POT differs from current PHP and reviewed compatibility: missing={len(required - set(actual))}, stale={len(set(actual) - required)}")
        if any(expected[key]["references"] != actual[key]["references"] for key in expected):
            raise ValueError("POT source locations differ from current PHP extraction")
        translations = sorted((catalogs / "po").glob("*.po"))
        if not translations:
            raise ValueError("No translation catalogs discovered")
        for po in translations:
            translated = entries(po.read_text())
            if set(translated) != required:
                raise ValueError("PO differs from current PHP extraction: " + po.name)
            if any(expected[key]["references"] != translated[key]["references"] for key in expected):
                raise ValueError("PO source locations differ from current PHP extraction: " + po.name)
            for key, item in historical.items():
                fields = item["translations"][po.stem]["fields"]
                actual_fields = {field: value for field, value in translated[key].items() if field.startswith("msgstr")}
                if actual_fields != fields:
                    raise ValueError("Reviewed historical translation differs: " + po.name)
                if translated[key]["flags"] != ",".join(item["translations"][po.stem]["flags"]):
                    raise ValueError("Reviewed historical translation flags differ: " + po.name)
            compiled = Path(temporary) / (po.stem + ".mo")
            subprocess.run(["msgfmt", "--check-format", str(po), "-o", str(compiled)],
                           check=True, timeout=30)
            if compiled.read_bytes() != (catalogs / "LC_MESSAGES" / compiled.name).read_bytes():
                raise ValueError("MO differs from checked PO compilation: " + compiled.name)
    print(f"PASS: {len(files)} PHP sources, {len(expected)} static messages + {len(historical)} reviewed compatibility identities, {len(translations)} PO/MO pairs")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-root", type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument("--catalog-root", type=Path)
    arguments = parser.parse_args()
    verify(arguments.source_root.resolve(), (arguments.catalog_root or arguments.source_root / "locales").resolve())
