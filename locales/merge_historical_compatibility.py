#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Complete normal GNU extraction with explicitly reviewed historical catalogs."""

import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile


def merge(root: Path) -> None:
    specification = importlib.util.spec_from_file_location("catalog_integrity", root / "tests/security/verify_gettext_catalogs.py")
    verifier = importlib.util.module_from_spec(specification)
    specification.loader.exec_module(verifier)
    historical = verifier.compatibility(root / "locales/historical-compatibility.json")
    locales = json.loads((root / "locales/catalogs.json").read_text())["translated_locales"]
    with tempfile.TemporaryDirectory(prefix="kadupul-catalog-compatibility-") as temporary:
        directory = Path(temporary)
        for locale in [None, *locales]:
            blocks = []
            for (context, singular, plural), item in historical.items():
                selected = item if locale is None else item["translations"][locale]
                lines = ["#: " + item["references"]] if item["references"] else []
                if selected["flags"]:
                    lines.append("#, " + ", ".join(selected["flags"]))
                if context:
                    lines.append("msgctxt " + json.dumps(context, ensure_ascii=False))
                lines.append("msgid " + json.dumps(singular, ensure_ascii=False))
                if plural:
                    lines.append("msgid_plural " + json.dumps(plural, ensure_ascii=False))
                fields = selected["fields"] if locale is not None else ({"msgstr[0]": "", "msgstr[1]": ""} if plural else {"msgstr": ""})
                lines.extend(field + " " + json.dumps(value, ensure_ascii=False) for field, value in fields.items())
                blocks.append("\n".join(lines))
            extension = directory / "compatibility.po"
            extension.write_text('msgid ""\nmsgstr ""\n"Content-Type: text/plain; charset=UTF-8\\n"\n\n' + "\n\n".join(blocks) + "\n")
            target = root / "locales/po" / ("cacti.pot" if locale is None else locale + ".po")
            merged = directory / "merged.po"
            subprocess.run(["msgcat", "--use-first", "--no-wrap", str(target), str(extension), "-o", str(merged)], check=True, timeout=30)
            if locale is not None:
                subprocess.run(["msgmerge", "--backup=off", "--no-wrap", "--no-fuzzy-matching", "--update", "-F", str(merged), str(root / "locales/po/cacti.pot")], check=True, timeout=30)
            subprocess.run(["msgattrib", "--no-obsolete", "--no-wrap", str(merged), "-o", str(target)], check=True, timeout=30)
            if locale is not None:
                subprocess.run(["msgfmt", "--check-format", str(target), "-o", str(root / "locales/LC_MESSAGES" / (locale + ".mo"))], check=True, timeout=30)


if __name__ == "__main__":
    merge(Path(__file__).resolve().parents[1])
