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
import os
from pathlib import Path
import re
import subprocess
import tempfile

COMPATIBILITY_CONTENT_SHA256 = "241909acf069b916e2fb4c9ee0ab342fe9f3b8f4da492eb6f10eb839c7a11947"
# Legacy gettext is frozen on main: new UI strings belong in Symfony ICU YAML
# catalogs under config/translations/*.yaml rather than expanding cacti.pot.
MAX_STATIC_GETTEXT_MESSAGES = 4180
# Catalogs record file-only references so moving lines never rewrites them.
LINE_REFERENCE = re.compile(r":\d+$")
# "Label (--option ...)" labels name an RRDtool option that must stay verbatim.
OPTION_LABEL = re.compile(r"^.*\S \((--[^()&]+?)[ =]?(?:&lt;.*)?\)$")
# Literal RRDtool option syntax snippets that must remain verbatim in translations.
RRD_SYNTAX_TOKENS = (
    "(dash-offset=offset)",
    "(dashes[=on_s[,off_s[,on_s,off_s]...]])",
)
# An entity split by inserted whitespace, such as "&gt ;" or "& #160;", is shown literally.
SPLIT_ENTITY = re.compile(r"&(?:lt|gt|amp|quot|apos|nbsp) ;|& #\d+;")
# Unclosed HTML tag fragments ("<b" at end of string or "<b<i").
UNCLOSED_HTML_TAG = re.compile(r"<[A-Za-z][^<>]*(?=<|$)")
BALANCED_HTML_TAGS = ("b", "i", "strong", "em", "u", "a", "code", "span", "p", "div", "li", "ul", "ol")
# Printf conversion specifiers (prose "%" and escaped "%%" are ignored).
PRINTF_SPEC = re.compile(r"%(?:(\d+)\$)?[-+0# ]?(?:\d+|\*)?(?:\.(?:\d+|\*))?[hlL]?([diouxXeEfFgGaAcspn])")
# Forbidden invisible/formatting characters (ZWNJ U+200C, ZWJ U+200D, RLM U+200F are allowed).
FORBIDDEN_INVISIBLE = re.compile(r"[\u200B\uFEFF\u0640\u00AD\u200E]")
DOUBLE_SENTENCE_PUNCT = re.compile(r"([?!\uFF1F\uFF01][.\u3002])\s*$")
SENTENCE_END_PUNCT = re.compile(r"[.!?。？！۔።։।]\s*$")
BRAND_CACTI = re.compile(r"(?<![A-Za-z0-9_])cacti(?![A-Za-z0-9_])", re.IGNORECASE)
LOCALE_CODE = re.compile(r"[a-z]{2}-[A-Z]{2}")
SCRIPT_NAME = re.compile(r"[A-Z][A-Za-z_]+")
# Thai prose does not use trailing periods.
END_STOP_EXEMPT_LOCALES = frozenset(("th-TH",))
RETIRED_MESSAGES = frozenset(("Access Denied!  LDAP Error: %s",
                             "Access Denied!  LDAP Search Error: %s",
                             "LDAP Search Error: %s"))


def validate_manifest(manifest: object) -> list[str]:
    """Validate locales/catalogs.json structure and return declared translated locales."""
    if not isinstance(manifest, dict):
        raise ValueError("Invalid shipped gettext locale manifest")
    declared = manifest.get("translated_locales")
    if (manifest.get("source_fallback") != "en-US" or not isinstance(declared, list)
            or not declared or any(not isinstance(locale, str) or not LOCALE_CODE.fullmatch(locale) for locale in declared)
            or len(declared) != len(set(declared)) or "en-US" in declared):
        raise ValueError("Invalid shipped gettext locale manifest")
    declared_set = set(declared)
    sparse = manifest.get("sparse_yaml_overrides")
    if sparse is not None:
        if (not isinstance(sparse, list)
                or any(not isinstance(locale, str) or not LOCALE_CODE.fullmatch(locale) for locale in sparse)
                or len(sparse) != len(set(sparse))
                or not set(sparse).issubset(declared_set)):
            raise ValueError("Invalid sparse_yaml_overrides in locale manifest")
    scripts = manifest.get("scripts")
    if scripts is not None:
        if not isinstance(scripts, dict):
            raise ValueError("Invalid scripts mapping in locale manifest")
        for locale, script_list in scripts.items():
            if (locale not in declared_set or not isinstance(script_list, list) or not script_list
                    or any(not isinstance(s, str) or not SCRIPT_NAME.fullmatch(s) for s in script_list)
                    or len(script_list) != len(set(script_list))):
                raise ValueError(f"Invalid scripts entry for {locale!r} in locale manifest")
    return declared


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
                if any(LINE_REFERENCE.search(item) for item in line[3:].split()):
                    raise ValueError("Gettext reference records a source line: " + line)
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


def _printf_signature(text: str) -> list[tuple[int | None, str]]:
    cleaned = text.replace("%%", "")
    return [(int(m.group(1)) if m.group(1) else None, m.group(2)) for m in PRINTF_SPEC.finditer(cleaned)]


def _printf_defect(source: str, value: str) -> str | None:
    expected = _printf_signature(source)
    actual = _printf_signature(value)
    if len(expected) != len(actual):
        return f"printf specifier count mismatch (expected {len(expected)}, got {len(actual)})"
    if not expected:
        return None
    expected_types = [conv for _, conv in expected]
    if any(pos is not None for pos, _ in actual):
        if any(pos is None for pos, _ in actual):
            return "mixes positional and sequential printf specifiers"
        seen_positions = set()
        for pos, conv in actual:
            assert pos is not None
            if pos < 1 or pos > len(expected_types):
                return f"positional printf specifier %{pos}${conv} out of range"
            if expected_types[pos - 1] != conv:
                return f"printf specifier %{pos}${conv} type mismatch (expected {expected_types[pos - 1]})"
            seen_positions.add(pos)
        if len(seen_positions) != len(expected_types):
            return "duplicate or omitted positional printf specifier"
    else:
        actual_types = [conv for _, conv in actual]
        if actual_types != expected_types:
            return f"printf specifier type/order mismatch (expected {expected_types}, got {actual_types})"
    return None


def _html_tag_defect(source: str, value: str) -> str | None:
    if UNCLOSED_HTML_TAG.search(value) and not UNCLOSED_HTML_TAG.search(source):
        return "has an unclosed HTML tag"
    for tag in BALANCED_HTML_TAGS:
        src_open = len(re.findall(rf"<{tag}(?:\s[^<>]*?)?>", source, re.IGNORECASE))
        src_close = len(re.findall(rf"</{tag}\s*>", source, re.IGNORECASE))
        val_open = len(re.findall(rf"<{tag}(?:\s[^<>]*?)?>", value, re.IGNORECASE))
        val_close = len(re.findall(rf"</{tag}\s*>", value, re.IGNORECASE))
        if src_open == src_close and val_open != val_close:
            return f"has unbalanced <{tag}> HTML tags ({val_open} open vs {val_close} close)"
    return None


def translation_defects(message: dict[str, str], *, locale: str | None = None, is_historical: bool = False) -> list[str]:
    """Defects a translation shows against its English source without reading the language."""
    defects = []
    msgid = message["msgid"]
    msgid_plural = message.get("msgid_plural", "")
    flags = {flag.strip() for flag in message.get("flags", "").split(",") if flag.strip()}
    check_printf = "php-format" in flags or "c-format" in flags
    option = OPTION_LABEL.match(msgid)

    if msgid_plural:
        plural_fields = sorted(field for field in message if field.startswith("msgstr["))
        if plural_fields and any(not message[field].strip() for field in plural_fields):
            defects.append("has an empty plural translation form")

    for field, value in message.items():
        if not field.startswith("msgstr") or not value:
            continue
        source = msgid_plural if (msgid_plural and field not in ("msgstr", "msgstr[0]")) else msgid

        double_match = DOUBLE_SENTENCE_PUNCT.search(value)
        if double_match and not source.rstrip().endswith(double_match.group(1)):
            defects.append(f"{field} ends with {double_match.group(1)!r}, the source does not")

        if option and option.group(1) not in value:
            defects.append(f"{field} drops the RRDtool option {option.group(1)}")

        for token in RRD_SYNTAX_TOKENS:
            if token in source and token not in value:
                defects.append(f"{field} alters the RRDtool syntax {token}")

        if "cacti.sql" in source and "cacti.sql" not in value:
            defects.append(f"{field} alters the schema filename cacti.sql")

        if SPLIT_ENTITY.search(value) and not SPLIT_ENTITY.search(source):
            defects.append(f"{field} splits an HTML entity with whitespace")

        tag_issue = _html_tag_defect(source, value)
        if tag_issue:
            defects.append(f"{field} {tag_issue}")

        if BRAND_CACTI.search(value) and not BRAND_CACTI.search(source):
            defects.append(f"{field} introduces unexpected 'Cacti' brand absent from source")

        if check_printf:
            printf_issue = _printf_defect(source, value)
            if printf_issue:
                defects.append(f"{field} {printf_issue}")

        if not is_historical:
            invis = FORBIDDEN_INVISIBLE.search(value)
            if invis and not FORBIDDEN_INVISIBLE.search(source):
                defects.append(f"{field} contains forbidden invisible/kashida character U+{ord(invis.group(0)):04X}")

            if (locale not in END_STOP_EXEMPT_LOCALES
                    and source.rstrip().endswith(".")
                    and not source.rstrip().endswith("...")
                    and len(source.split()) >= 4
                    and not SENTENCE_END_PUNCT.search(value)):
                defects.append(f"{field} drops sentence-ending punctuation from source")

    return defects


def check_legacy_gettext_ceiling(static_count: int, ceiling: int = MAX_STATIC_GETTEXT_MESSAGES) -> None:
    """Prevent new UI strings from growing the legacy gettext surface on main."""
    if static_count > ceiling:
        raise ValueError(
            f"Legacy gettext catalog grew to {static_count} static messages (ceiling {ceiling}); "
            "add new UI strings to Symfony ICU YAML catalogs under config/translations/*.yaml instead"
        )


def verify(source: Path, catalogs: Path) -> None:
    historical = compatibility(source / "locales/historical-compatibility.json")
    manifest = json.loads((source / "locales/catalogs.json").read_text())
    declared = validate_manifest(manifest)
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
        subprocess.run(["xgettext", "--from-code=UTF-8", "--no-wrap", "--add-location=file", "-F",
                        *["-k" + key for key in keywords], "-o", str(pot), *files],
                       cwd=source, check=True, timeout=120)
        expected = entries(pot.read_text())
        if (source / "templates/platform/console_home_body.html.twig").is_file():
            extractor = Path(__file__).resolve().parents[2] / "tools/translations/extract-console.php"
            console = subprocess.run([os.environ.get("PHP", "php"), str(extractor), str(source)],
                                     capture_output=True, text=True, check=True, timeout=30)
            extracted = entries(console.stdout)
            if not extracted:
                raise ValueError("No console Twig messages extracted")
            for key, message in extracted.items():
                if key in expected:
                    message["references"] = " ".join(sorted(set(message["references"].split()) | set(expected[key]["references"].split())))
                    message["flags"] = ",".join(sorted(set(message["flags"].split(",")) | set(expected[key]["flags"].split(","))))
                expected[key] = message
        check_legacy_gettext_ceiling(len(expected))
        actual = entries((catalogs / "po/cacti.pot").read_text())
        required = set(expected) | set(historical)
        if any(key[1] in RETIRED_MESSAGES for key in actual) or any(key[1] in RETIRED_MESSAGES for key in expected):
            raise ValueError("Retired LDAP identity has returned")
        if not expected or required != set(actual):
            raise ValueError(f"POT differs from current PHP/Twig and reviewed compatibility: missing={len(required - set(actual))}, stale={len(set(actual) - required)}")
        if any(expected[key]["references"] != actual[key]["references"] for key in expected):
            raise ValueError("POT source locations differ from current PHP/Twig extraction")
        translations = sorted((catalogs / "po").glob("*.po"))
        if not translations:
            raise ValueError("No translation catalogs discovered")
        for po in translations:
            translated = entries(po.read_text())
            if set(translated) != required:
                raise ValueError("PO differs from current PHP/Twig extraction: " + po.name)
            if any(expected[key]["references"] != translated[key]["references"] for key in expected):
                raise ValueError("PO source locations differ from current PHP/Twig extraction: " + po.name)
            for key, item in historical.items():
                fields = item["translations"][po.stem]["fields"]
                actual_fields = {field: value for field, value in translated[key].items() if field.startswith("msgstr")}
                if actual_fields != fields:
                    raise ValueError("Reviewed historical translation differs: " + po.name)
                if translated[key]["flags"] != ",".join(item["translations"][po.stem]["flags"]):
                    raise ValueError("Reviewed historical translation flags differ: " + po.name)
            for key, message in translated.items():
                defects = translation_defects(message, locale=po.stem, is_historical=(key in historical))
                if defects:
                    raise ValueError(f"Translation defect in {po.name} {key[1]!r}: " + "; ".join(defects))
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
