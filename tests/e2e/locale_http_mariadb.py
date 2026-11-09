#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Compare the locale of a legacy page and a Symfony route over real routing.

Both paths use one shared resolver. Each scenario stores its settings, saved
language and plugins in the Compose MariaDB, signs in as admin with a fresh
cookie jar and one Accept-Language header, and then requests the legacy
utilities.php page (include/global.php and gettext) and the Symfony
/app.php/inventory/sites route with that same session. Both must render text
of the same language: the German catalog's "Zielsysteme" for "Devices" on both,
or the French "Équipements" (legacy gettext) and "Appareils" (Symfony YAML).

Start a dedicated tests/e2e stack first, then run this from the repository
root, for example:

  E2E_BASE_URL=http://localhost:8080 python3 tests/e2e/locale_http_mariadb.py
"""

from __future__ import annotations

import html
import http.cookiejar
import os
import subprocess
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser


BASE_URL = os.environ.get("E2E_BASE_URL", "http://localhost:8080").rstrip("/")
COMPOSE_PROJECT = os.environ.get("E2E_COMPOSE_PROJECT", "kadupul-locale-e2e")
COMPOSE_FILE = os.environ.get("E2E_COMPOSE_FILE", "tests/e2e/docker-compose.yml")
COMPOSE_OVERRIDE = os.environ.get("E2E_COMPOSE_OVERRIDE", "")

SETTINGS = ("i18n_language_support", "i18n_auto_detection", "i18n_default_language")
# A disabled plugin row: strict mode counts every plugin_config row, while the
# plugin loader runs only enabled plugins, so no plugin code is needed.
PROBE_PLUGIN = "localeprobe"

LEGACY_PAGE = "/utilities.php"
SYMFONY_ROUTE = "/app.php/inventory/sites"

TEXT = {
    "en": {"legacy": "Devices", "symfony": "Devices"},
    "de": {"legacy": "Zielsysteme", "symfony": "Zielsysteme"},
    "fr": {"legacy": "Équipements", "symfony": "Appareils"},
}
LANG = {"en": "en", "de": "de-DE", "fr": "fr"}


def sql(statement: str) -> None:
    command = ["docker", "compose", "-p", COMPOSE_PROJECT, "-f", COMPOSE_FILE]
    if COMPOSE_OVERRIDE:
        command.extend(["-f", COMPOSE_OVERRIDE])
    command.extend(["exec", "-T", "mariadb", "mariadb", "-ucactiuser", "-pcactipass", "cacti", "-e", statement])
    subprocess.run(command, check=True, stdout=subprocess.DEVNULL)


def quote(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "''") + "'"


def configure(settings: dict[str, str], saved: str | None, plugins: bool) -> None:
    names = ", ".join(quote(name) for name in SETTINGS)
    statements = [f"DELETE FROM settings WHERE name IN ({names});"]
    for name, value in settings.items():
        statements.append(f"INSERT INTO settings (name, value) VALUES ({quote(name)}, {quote(value)});")
    statements.append("DELETE FROM settings_user WHERE user_id = 1 AND name IN ('user_language', 'language');")
    if saved is not None:
        statements.append(f"INSERT INTO settings_user (user_id, name, value) VALUES (1, 'user_language', {quote(saved)});")
    statements.append(f"DELETE FROM plugin_config WHERE directory = {quote(PROBE_PLUGIN)};")
    if plugins:
        statements.append(
            "INSERT INTO plugin_config (directory, name, status, author, webpage, version) "
            f"VALUES ({quote(PROBE_PLUGIN)}, 'Locale probe', 0, '', '', '1.0');"
        )
    sql(" ".join(statements))


class LoginForm(HTMLParser):
    """The action and inputs of the form holding login_username."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.forms: list[tuple[str, dict[str, str]]] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attributes = dict(attrs)
        if tag == "form":
            self.forms.append((attributes.get("action") or "", {}))
        elif tag == "input" and self.forms and attributes.get("name"):
            self.forms[-1][1][attributes["name"] or ""] = attributes.get("value") or ""

    def login(self, page: str) -> tuple[str, dict[str, str]]:
        self.feed(page)
        for action, fields in self.forms:
            if "login_username" in fields:
                return action, fields
        raise AssertionError("login form was not rendered")


class Client:
    def __init__(self, accept: str) -> None:
        self.accept = accept
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(self, path: str, data: dict[str, str] | None = None) -> tuple[int, str]:
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        headers = {"Accept-Language": self.accept}
        if data is not None:
            headers["Origin"] = BASE_URL
        request = urllib.request.Request(BASE_URL + path, data=body, headers=headers, method="POST" if data is not None else "GET")
        try:
            with self.opener.open(request) as response:
                return response.status, response.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode("utf-8", "replace")

    def login(self) -> None:
        status, page = self.request("/")
        check(status == 200, f"login page answered HTTP {status}")
        action, fields = LoginForm().login(page)
        fields.update({"login_username": "admin", "login_password": "admin"})
        target = urllib.parse.urlparse(urllib.parse.urljoin(BASE_URL + "/", action))
        status, page = self.request(target.path + ("?" + target.query if target.query else ""), fields)
        check(status == 200 and "login_username" not in page, "admin login did not complete")


def check(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def language_of(page: str, path: str) -> str:
    text = html.unescape(page)
    found = [language for language, texts in TEXT.items() if language != "en" and texts[path] in text]
    if not found:
        return "en" if TEXT["en"][path] in text else "unknown"
    return found[0] if len(found) == 1 else "ambiguous"


def scenario(name: str, expected: str, accept: str, settings: dict[str, str] | None = None,
             saved: str | None = None, plugins: bool = False, legacy_query: str = "", symfony_query: str = "") -> None:
    configure(settings or {}, saved, plugins)
    client = Client(accept)
    client.login()
    status, legacy = client.request(LEGACY_PAGE + legacy_query)
    check(status == 200, f"{name}: legacy page answered HTTP {status}")
    status, symfony = client.request(SYMFONY_ROUTE + symfony_query)
    check(status == 200, f"{name}: Symfony route answered HTTP {status}")
    results = (language_of(legacy, "legacy"), language_of(symfony, "symfony"))
    check(results == (expected, expected), f"{name}: expected {expected} on both paths, got legacy={results[0]} symfony={results[1]}")
    check(f'<html lang="{LANG[expected]}' in symfony, f"{name}: Symfony html lang is not {LANG[expected]}")
    print(f"ok - {name}: {expected}")


def main() -> None:
    try:
        scenario("saved language beats the browser", "de", "fr-FR", saved="de-DE")
        scenario("browser language", "de", "de-DE,en;q=0.5")
        # The intentional change: the first entry is unknown, so legacy pages
        # previously showed the default language (French) here.
        scenario("weighted browser language", "de", "xx-YY,de-DE;q=0.8", {"i18n_default_language": "fr-FR"})
        scenario("detection off uses the default", "fr", "de-DE", {"i18n_auto_detection": "0", "i18n_default_language": "fr-FR"})
        scenario("disabled support is English", "en", "de-DE", {"i18n_language_support": "0"}, saved="de-DE")
        scenario("saved zh-HK has no catalog", "en", "de-DE", saved="zh-HK")
        scenario("strict mode with a plugin lacking the language", "en", "de-DE", {"i18n_language_support": "2"}, plugins=True)
        scenario("strict mode keeps the language without plugins", "de", "de-DE", {"i18n_language_support": "2"})
        # The legacy parameter lasts through the session; Symfony ignores its own.
        scenario("legacy ?language= carries to Symfony", "de", "en-US", legacy_query="?language=de-DE", symfony_query="?language=fr-FR")
    finally:
        configure({}, None, False)


if __name__ == "__main__":
    main()
