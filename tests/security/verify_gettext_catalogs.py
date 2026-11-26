#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Validate LTS 1.2 gettext POT/PO/MO catalogs against live PHP sources.

Requires GNU gettext (`xgettext`, `msgfmt`); missing tools and empty discovery
are hard failures. Zero missing/stale/untranslated/fuzzy/obsolete entries and
zero deterministic translation regressions are allowed.
"""

from __future__ import annotations

import argparse
import ast
from collections import Counter
from pathlib import Path
import re
import subprocess
import tempfile

GETTEXT_KEYWORDS = (
    "__gettext",
    "__",
    "__n:1,2",
    "__x:1c,2",
    "__xn:1c,2,3",
    "__esc",
    "__esc_n:1,2",
    "__esc_x:1c,2",
    "__esc_xn:1c,2,3",
    "__date",
)

LOCALE_NAME_RE = re.compile(r"^[a-z]{2}-[A-Z]{2}$")
FIELD_RE = re.compile(r"^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?) (\".*\")$")
NPLURALS_RE = re.compile(r"nplurals=(\d+)")

# HTML tags that can appear in Cacti UI messages; excludes placeholder tokens like <not set>.
HTML_TAGS = frozenset((
    "a", "abbr", "b", "blockquote", "br", "code", "dd", "del", "div", "dl", "dt",
    "em", "font", "h1", "h2", "h3", "h4", "h5", "h6", "hr", "i", "img", "input",
    "ins", "kbd", "label", "li", "ol", "p", "pre", "s", "samp", "small", "span",
    "strong", "sub", "sup", "table", "tbody", "td", "tfoot", "th", "thead", "tr",
    "tt", "u", "ul", "var",
))
VOID_HTML_TAGS = frozenset(("br", "hr", "img", "input"))
HTML_TAG_RE = re.compile(r"<(/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(/?)>")
SPLIT_ENTITY_RE = re.compile(r"&(?:lt|gt|amp|quot|nbsp|apos) ;|& #\d+;")

# "Label (--option ...)" labels name an RRDtool option that must stay verbatim.
OPTION_LABEL_RE = re.compile(r"^.*\S \((--[^()&]+?)[ =]?(?:&lt;.*)?\)$")
CLI_FLAG_RE = re.compile(r"(?<![A-Za-z0-9-])(--[a-z][a-z0-9-]*)")

# PHP sprintf specifiers (excludes prose '%' followed by space).
PRINTF_SPEC_RE = re.compile(
    r"%(?:(\d+)\$)?([+\-0#']*)(\d+|\*(?:\d+\$)?)?(?:\.(\d+|\*(?:\d+\$)?))?([bcdeEfFgGhHosuxX])"
)
SPACED_PRINTF_RE = re.compile(r"% (?:[sdfoxXubceEgG]|(?:\d+\$)?[sdf])(?!\w)")

# Forbidden invisible / formatting junk characters (script-required ZWNJ/ZWJ/RLM are allowed).
FORBIDDEN_INVISIBLE = {
    "\u200b": "U+200B (ZERO WIDTH SPACE)",
    "\ufeff": "U+FEFF (BOM)",
    "\u0640": "U+0640 (ARABIC TATWEEL/KASHIDA)",
    "\u00ad": "U+00AD (SOFT HYPHEN)",
    "\u200e": "U+200E (LEFT-TO-RIGHT MARK)",
}

BRAND_LEAK_RE = re.compile(r"\bkadupul\b", re.IGNORECASE)


def parse_catalog(text: str) -> tuple[str | None, dict[tuple[str, str, str], dict], int]:
    """Parse a PO or POT file into (header, entries, obsolete_count)."""
    entries: dict[tuple[str, str, str], dict] = {}
    header: str | None = None
    obsolete = 0

    for raw_block in re.split(r"\n\s*\n", text.strip()):
        lines = raw_block.splitlines()
        if not lines:
            continue
        if any(line.startswith("#~") for line in lines):
            obsolete += 1
            continue

        fields: dict[str, object] = {}
        flags: set[str] = set()
        current_field: str | None = None

        for line in lines:
            if line.startswith("#, "):
                flags.update(flag.strip() for flag in line[3:].split(",") if flag.strip())
            if line.startswith("#"):
                continue
            match = FIELD_RE.match(line)
            if match:
                current_field = match[1]
                fields[current_field] = ast.literal_eval(match[2])
            elif line.startswith('"') and current_field is not None:
                fields[current_field] = str(fields[current_field]) + ast.literal_eval(line)

        if "msgid" not in fields:
            continue
        if fields["msgid"] == "" and "msgctxt" not in fields:
            header = str(fields.get("msgstr", ""))
            continue

        key = (
            str(fields.get("msgctxt", "")),
            str(fields["msgid"]),
            str(fields.get("msgid_plural", "")),
        )
        if key in entries:
            raise ValueError(f"Duplicate gettext message identity: {key[1]!r}")
        fields["_flags"] = flags
        entries[key] = fields

    return header, entries, obsolete


def unbalanced_html_tags(text: str) -> dict[str, tuple[int, int]]:
    """Return non-void HTML tags whose opening and closing counts differ."""
    opens: Counter[str] = Counter()
    closes: Counter[str] = Counter()
    for match in HTML_TAG_RE.finditer(text):
        tag = match.group(2).lower()
        if tag not in HTML_TAGS or tag in VOID_HTML_TAGS or match.group(3) == "/":
            continue
        if match.group(1) == "/":
            closes[tag] += 1
        else:
            opens[tag] += 1
    return {
        tag: (opens[tag], closes[tag])
        for tag in sorted(set(opens) | set(closes))
        if opens[tag] != closes[tag]
    }


def extract_printf_specifiers(text: str) -> list[tuple[str, str]]:
    """Extract (position, conversion_type) tuples while ignoring escaped %%."""
    cleaned = text.replace("%%", "")
    return [(match.group(1) or "", match.group(5)) for match in PRINTF_SPEC_RE.finditer(cleaned)]


def parse_nplurals(header: str | None) -> int:
    if not header:
        raise ValueError("Missing PO catalog header")
    match = NPLURALS_RE.search(header)
    if not match:
        raise ValueError("Missing Plural-Forms nplurals declaration in PO header")
    count = int(match.group(1))
    if count < 1:
        raise ValueError(f"Invalid nplurals value: {count}")
    return count


def entry_defects(message: dict, nplurals: int) -> list[str]:
    """Return deterministic defects for a single PO message entry."""
    defects: list[str] = []
    flags: set[str] = message["_flags"]
    msgid = str(message["msgid"])
    plural = str(message.get("msgid_plural", ""))

    if "fuzzy" in flags:
        defects.append("entry is marked fuzzy")

    if plural:
        forms = []
        actual_indices = sorted(
            int(field[7:-1])
            for field in message
            if field.startswith("msgstr[") and field.endswith("]")
        )
        expected_indices = list(range(nplurals))
        if actual_indices != expected_indices:
            defects.append(
                f"plural form indices {actual_indices} do not match header nplurals={nplurals}"
            )
        for index in expected_indices:
            field = f"msgstr[{index}]"
            value = str(message.get(field, ""))
            if not value.strip():
                defects.append(f"empty plural form {field}")
            else:
                forms.append((field, value))
    else:
        value = str(message.get("msgstr", ""))
        if not value.strip():
            defects.append("untranslated entry")
            forms = []
        else:
            forms = [("msgstr", value)]

    option_label = OPTION_LABEL_RE.match(msgid)

    for field, value in forms:
        source = plural if (plural and field != "msgstr[0]") else msgid

        # 1. HTML entity splitting & newly introduced HTML tag imbalance.
        if SPLIT_ENTITY_RE.search(value) and not SPLIT_ENTITY_RE.search(source):
            defects.append(f"{field} splits an HTML entity with whitespace")
        src_unbal = unbalanced_html_tags(source)
        val_unbal = unbalanced_html_tags(value)
        new_unbal = {tag: counts for tag, counts in val_unbal.items() if counts != src_unbal.get(tag)}
        if new_unbal:
            defects.append(f"{field} introduces unbalanced HTML tags {new_unbal}")

        # 2. CLI flag & RRDtool option label preservation.
        if option_label:
            required_option = option_label.group(1)
            if required_option not in value:
                defects.append(f"{field} drops RRDtool option {required_option}")
        else:
            for flag in sorted(set(CLI_FLAG_RE.findall(source))):
                if flag not in value:
                    defects.append(f"{field} drops CLI flag {flag}")

        # 3. Printf placeholder count & type parity on php-format messages.
        if "php-format" in flags:
            src_specs = extract_printf_specifiers(source)
            val_specs = extract_printf_specifiers(value)
            if Counter(t for _, t in src_specs) != Counter(t for _, t in val_specs):
                defects.append(
                    f"{field} printf specifiers differ (source={src_specs}, translation={val_specs})"
                )
            if SPACED_PRINTF_RE.search(value.replace("%%", "")) and not SPACED_PRINTF_RE.search(source.replace("%%", "")):
                defects.append(f"{field} contains a spaced printf specifier")

        # 4. Forbidden invisible characters & kashida.
        for char, label in FORBIDDEN_INVISIBLE.items():
            if char in value and char not in source:
                defects.append(f"{field} contains forbidden invisible character {label}")

        # 5. Brand-leak guard for LTS 1.2 (Cacti 1.2 series).
        if BRAND_LEAK_RE.search(value) and not BRAND_LEAK_RE.search(source):
            defects.append(f"{field} leaks non-LTS brand name")

        # 6. Double sentence-ending punctuation guard.
        ending = value.rstrip()[-2:]
        if ending in ("?.", "!.") and not source.rstrip().endswith(ending):
            defects.append(f"{field} ends with {ending!r}, source does not")

    return defects


def discover_php_sources(source: Path) -> list[str]:
    """Discover PHP sources matching locales/build_gettext.sh (`find . -maxdepth 2 -name '*.php'`)."""
    files = sorted(path.relative_to(source).as_posix() for path in source.glob("*.php"))
    files += sorted(path.relative_to(source).as_posix() for path in source.glob("*/*.php"))
    if not files:
        raise ValueError(f"No PHP source files discovered under {source}")
    return files


def extract_pot(source: Path, destination: Path) -> dict[tuple[str, str, str], dict]:
    files = discover_php_sources(source)
    subprocess.run(
        [
            "xgettext",
            "--from-code=UTF-8",
            "--no-wrap",
            "-F",
            *[f"-k{kw}" for kw in GETTEXT_KEYWORDS],
            "-o",
            str(destination),
            *files,
        ],
        cwd=source,
        check=True,
        capture_output=True,
        text=True,
        timeout=120,
    )
    _, extracted, _ = parse_catalog(destination.read_text(encoding="utf-8"))
    if not extracted:
        raise ValueError("Fresh xgettext extraction produced zero messages")
    return extracted


def verify(source: Path, catalogs: Path, expected_locale_count: int | None = 49) -> dict[str, int]:
    """Run all POT, PO, MO, and deterministic quality checks; raise ValueError on failure."""
    po_dir = catalogs / "po"
    mo_dir = catalogs / "LC_MESSAGES"
    pot_path = po_dir / "cacti.pot"
    if not pot_path.is_file():
        raise ValueError(f"Missing POT template: {pot_path}")

    po_files = sorted(po_dir.glob("*.po"))
    mo_files = sorted(mo_dir.glob("*.mo"))
    if not po_files:
        raise ValueError(f"No PO catalogs discovered under {po_dir}")

    po_locales = {path.stem for path in po_files}
    mo_locales = {path.stem for path in mo_files}
    invalid_locales = sorted(loc for loc in po_locales | mo_locales if not LOCALE_NAME_RE.match(loc))
    if invalid_locales:
        raise ValueError(f"Invalid locale filenames: {invalid_locales}")
    if po_locales != mo_locales:
        raise ValueError(
            f"PO/MO locale mismatch: po_only={sorted(po_locales - mo_locales)}, "
            f"mo_only={sorted(mo_locales - po_locales)}"
        )
    if expected_locale_count is not None and len(po_files) != expected_locale_count:
        raise ValueError(
            f"Expected {expected_locale_count} shipped locales, found {len(po_files)}"
        )

    errors: list[str] = []

    with tempfile.TemporaryDirectory(prefix="kadupul-lts12-i18n-") as temporary:
        temp_dir = Path(temporary)
        fresh_pot = temp_dir / "cacti.pot"
        expected_entries = extract_pot(source, fresh_pot)

        _, actual_pot, pot_obsolete = parse_catalog(pot_path.read_text(encoding="utf-8"))
        missing_pot = sorted(set(expected_entries) - set(actual_pot))
        stale_pot = sorted(set(actual_pot) - set(expected_entries))
        if missing_pot or stale_pot or pot_obsolete:
            errors.append(
                f"locales/po/cacti.pot drift: missing={len(missing_pot)}, "
                f"stale={len(stale_pot)}, obsolete={pot_obsolete}"
            )
            for key in missing_pot[:5]:
                errors.append(f"  missing POT entry: {key[1]!r}")
            for key in stale_pot[:5]:
                errors.append(f"  stale POT entry: {key[1]!r}")

        expected_keys = set(actual_pot)

        for po_path in po_files:
            header, po_entries, po_obsolete = parse_catalog(po_path.read_text(encoding="utf-8"))
            missing = set(expected_keys) - set(po_entries)
            extra = set(po_entries) - set(expected_keys)
            if missing or extra or po_obsolete:
                errors.append(
                    f"{po_path.name}: key drift missing={len(missing)}, "
                    f"extra={len(extra)}, obsolete={po_obsolete}"
                )

            try:
                nplurals = parse_nplurals(header)
            except ValueError as exc:
                errors.append(f"{po_path.name}: {exc}")
                nplurals = 2

            for key, message in po_entries.items():
                for defect in entry_defects(message, nplurals):
                    errors.append(f"{po_path.name} [{key[1]!r}]: {defect}")

            compiled_mo = temp_dir / f"{po_path.stem}.mo"
            proc = subprocess.run(
                [
                    "msgfmt",
                    "--check-format",
                    "--check-header",
                    "--check-domain",
                    str(po_path),
                    "-o",
                    str(compiled_mo),
                ],
                capture_output=True,
                text=True,
                timeout=30,
            )
            if proc.returncode != 0:
                errors.append(f"{po_path.name}: msgfmt failed: {proc.stderr.strip()}")
            else:
                shipped_mo = mo_dir / compiled_mo.name
                if compiled_mo.read_bytes() != shipped_mo.read_bytes():
                    errors.append(f"{shipped_mo.name}: MO binary differs from fresh msgfmt compilation")

    if errors:
        preview = "\n".join(errors[:50])
        suffix = f"\n... and {len(errors) - 50} more errors" if len(errors) > 50 else ""
        raise ValueError(f"Gettext catalog validation failed ({len(errors)} issues):\n{preview}{suffix}")

    return {
        "php_sources": len(discover_php_sources(source)),
        "messages": len(expected_entries),
        "locales": len(po_files),
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--source-root",
        type=Path,
        default=Path(__file__).resolve().parents[2],
        help="Repository root containing PHP sources.",
    )
    parser.add_argument(
        "--catalog-root",
        type=Path,
        help="Directory containing po/ and LC_MESSAGES/ (defaults to <source-root>/locales).",
    )
    parser.add_argument(
        "--expected-locales",
        type=int,
        default=49,
        help="Expected number of shipped PO/MO locale pairs (0 disables fixed count check).",
    )
    args = parser.parse_args()
    source_root = args.source_root.resolve()
    catalog_root = (args.catalog_root or source_root / "locales").resolve()
    expected = args.expected_locales if args.expected_locales > 0 else None
    summary = verify(source_root, catalog_root, expected_locale_count=expected)
    print(
        f"PASS: {summary['php_sources']} PHP sources, {summary['messages']} POT messages, "
        f"{summary['locales']} PO/MO locale pairs validated with 0 errors"
    )


if __name__ == "__main__":
    main()
