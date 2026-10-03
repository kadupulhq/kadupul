#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Check shipped gettext catalogs against current production PHP messages.

Requires GNU gettext; missing tools and empty discovery are failures. Catalog
roots may differ from the source root to verify an archived catalog regression.
"""

import argparse
import ast
import json
from pathlib import Path
import re
import subprocess
import tempfile


def entries(text: str) -> dict[tuple[str, str, str], dict[str, str]]:
    result = {}
    for block in re.split(r"\n\s*\n", text):
        fields = {}
        field = None
        references = set()
        for line in block.splitlines():
            if line.startswith("#: "):
                references.update(item.removeprefix("./") for item in line[3:].split())
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
            key = (fields.get("msgctxt", ""), fields["msgid"], fields.get("msgid_plural", ""))
            if key in result:
                raise ValueError("Duplicate gettext message")
            result[key] = fields
    return result


def verify(source: Path, catalogs: Path) -> None:
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
        if not expected or set(expected) != set(actual):
            raise ValueError(f"POT differs from current PHP extraction: missing={len(set(expected) - set(actual))}, stale={len(set(actual) - set(expected))}")
        if any(expected[key]["references"] != actual[key]["references"] for key in expected):
            raise ValueError("POT source locations differ from current PHP extraction")
        translations = sorted((catalogs / "po").glob("*.po"))
        if not translations:
            raise ValueError("No translation catalogs discovered")
        for po in translations:
            translated = entries(po.read_text())
            if set(translated) != set(expected):
                raise ValueError("PO differs from current PHP extraction: " + po.name)
            if any(expected[key]["references"] != translated[key]["references"] for key in expected):
                raise ValueError("PO source locations differ from current PHP extraction: " + po.name)
            compiled = Path(temporary) / (po.stem + ".mo")
            subprocess.run(["msgfmt", "--check-format", str(po), "-o", str(compiled)],
                           check=True, timeout=30)
            if compiled.read_bytes() != (catalogs / "LC_MESSAGES" / compiled.name).read_bytes():
                raise ValueError("MO differs from checked PO compilation: " + compiled.name)
    print(f"PASS: {len(files)} PHP sources, {len(expected)} messages, {len(translations)} PO/MO pairs")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-root", type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument("--catalog-root", type=Path)
    arguments = parser.parse_args()
    verify(arguments.source_root.resolve(), (arguments.catalog_root or arguments.source_root / "locales").resolve())
