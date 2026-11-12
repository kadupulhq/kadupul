<!--
SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
SPDX-License-Identifier: GPL-3.0-or-later
-->

# Translations on Symfony pages

Legacy PHP pages translate with gettext: `__()` and its variants in
`include/global_languages.php` read the compiled catalogs in
`locales/LC_MESSAGES/<ll-CC>.mo`. Pages migrated to Symfony and Twig use
Symfony Translation instead, with one message domain per feature. They reuse
the legacy translations by English text, so a migrated page is not limited to
the English and French that its own files provide. This page describes how
that works, when a domain needs more than two files, how the request locale is
chosen and what CI checks.

## How a Symfony page's text is translated

Each feature has a domain in `config/translations/`: `<domain>.en.yaml` and
`<domain>.fr.yaml`. Message keys are the English text, with named placeholders
such as `%count%`:

```yaml
"Default (%count%)": "Default (%count%)"
```

Templates and PHP pass the domain explicitly:

```twig
{{ 'Add GPRINT Preset'|trans({}, 'gprint') }}
```

```php
$translator->trans('Access denied.', [], 'data_input');
```

For a request locale, Symfony looks a key up in this order:

1. The domain's own file for that locale, if one exists, for example
   `gprint.fr.yaml` or `graph_template_input.de_DE.yaml`.
2. For any other shipped locale, the legacy `locales/po/<ll-CC>.po` catalog
   of the same language, matched by English text. This is the gettext bridge:
   `ConsoleTranslations::register()` adds one `kadupul_gettext` resource per
   shipped locale and `*.en.yaml` domain, and `GettextCatalogLoader` fills only
   the keys that the locale's own YAML file does not translate.
3. English, from `<domain>.en.yaml` (`fallbacks: [en]` in
   `config/packages/translation.yaml`).

French is the exception to step 2 in practice. `TranslationCatalogTest`
requires `<domain>.fr.yaml` to define every key of `<domain>.en.yaml`, so
French text always comes from the reviewed YAML file and never from
`fr-FR.po`.

`GettextCatalog::translate()` decides whether a `.po` entry matches a key. It
is deliberately conservative, and anything it cannot match exactly shows in
English:

- The key's `%name%` placeholders are tried as `%s` in the msgid, then
  all as `%d`. The msgstr's `%s`, `%d` and positional `%1$s` conversions are mapped
  back to the key's named placeholders. A key with any other `%` does not match.
- A msgid that also occurs with a `msgctxt` anywhere in the catalog is
  skipped.
- Empty and fuzzy msgstrs are skipped (Symfony's `PoFileLoader` drops fuzzy
  entries).
- Migrated pages choose between separate singular and plural keys with the
  English rule, `n == 1`. A `.po` plural entry is used only when the catalog's
  `Plural-Forms` rule is `n != 1` or a single form. In languages with other
  rules, such as Russian or Polish, both keys show English.

New domains need no registration code. The bridge globs
`config/translations/*.en.yaml` when the Symfony container is compiled, and
the container tracks `locales/catalogs.json` and each `.po` file as resources.
Symfony caches compiled catalogues, so in a production install clear the cache
(`php bin/console cache:clear`) after changing YAML or catalog files.

Two other domains exist. The `console` domain loads the compiled `.mo` files
directly for the console home page, whose text is extracted into the shared
gettext catalog. The `cacti` domain (`TranslationResources`,
`GettextMoLoader`) loads each `.mo` under its exact gettext identities,
including contexts and every plural form. Nothing translates through it yet:
the kernel translator does not register it, and
`GettextMoLoaderParityTest::testTheKernelTranslatorDoesNotLoadTheCactiDomainYet`
pins that. It exists so that legacy `__()` calls can later move onto Symfony
Translation, and its tests compare it with php-gettext output for every
shipped catalog.

## When a domain needs other-locale files

The bridge only works while the `.po` files still contain the English text.
`locales/build_gettext.sh` extracts msgids from PHP files (top-level pages,
one directory below them, and `src/`) and from the console home template. It
does not read YAML files or other Twig templates. It then runs
`msgattrib --no-obsolete`, which removes every msgid that no longer occurs in
that source. `tests/security/verify_gettext_catalogs.py` fails if a `.po` file
differs from the current extraction plus the 195 reviewed historical
identities in `locales/historical-compatibility.json`, so an entry cannot be
kept by editing the `.po` file by hand.

When a migration removes or shims the legacy page that used a string, and no
other PHP source uses it, the next catalog rebuild drops that string from
every `.po` file. The migrated page then shows it in English in every
language except French. The graph item inputs migration hit this: strings
such as "Associated Graph Items" were used only by `graph_templates_inputs.php`.

The fix is to copy the legacy translations into the domain before the rebuild
removes them, as `config/translations/<domain>.<ll_CC>.yaml` for each shipped
locale except French. Name the file with the Symfony locale (`de_DE`,
`pt_BR`, `zh_CN`), the same name the bridge uses for that language. Symfony
loads the file from the translation directory, and the bridge leaves its keys
alone.

Generate the values with the bridge's own matcher, from the `.po` files as
they were before the rebuild (for example the files on `origin/main`), so the
copied text is exactly what the page showed before. Run this from the
repository root for one domain:

```php
<?php
// php copy-legacy-translations.php <domain>
require 'include/vendor/autoload.php';

use Kadupul\Platform\Infrastructure\Symfony\GettextCatalog;
use Kadupul\Platform\Infrastructure\Symfony\TranslationResources;
use Symfony\Component\Yaml\Yaml;

$domain = $argv[1];
$keys = array_keys(Yaml::parseFile("config/translations/$domain.en.yaml"));
foreach (TranslationResources::shippedLocales('.') as $locale) {
    if ($locale === 'fr-FR') {
        continue;
    }
    $catalog = GettextCatalog::fromFile("locales/po/$locale.po");
    $messages = [];
    foreach ($keys as $key) {
        $message = $catalog->translate((string) $key);
        if ($message !== null) {
            $messages[(string) $key] = $message;
        }
    }
    if ($messages !== []) {
        $file = "config/translations/$domain." . str_replace('-', '_', $locale) . '.yaml';
        file_put_contents($file, Yaml::dump($messages));
    }
}
```

Add the SPDX header used by the other files in `config/translations/`, then
read the output before committing it. A legacy msgstr can be wrong in ways the
matcher cannot see; the graph item inputs work left out one Hebrew entry
whose text was double-encoded UTF-8. Expect fewer messages for languages
whose catalogs are incomplete, and none at all for some.

`TranslationCatalogTest` currently checks only the `en` and `fr` files of each
domain. Check other-locale files by hand for keys that are not in
`<domain>.en.yaml`, blank values and placeholders the English key does not
have.

## Locale names

The same language has several spellings, depending on the layer:

| Layer | Example | Source |
| --- | --- | --- |
| Known locale, used by settings and user preferences | `de-DE` | `src/Platform/Application/Locale/KnownLocales.php` |
| Legacy catalog file name, also accepted | `german_germany.mo` | `filename` in `KnownLocales` |
| Shipped catalog | `locales/po/de-DE.po`, `locales/LC_MESSAGES/de-DE.mo` | `translated_locales` in `locales/catalogs.json` |
| Symfony locale and YAML file name | `de_DE`, `<domain>.de_DE.yaml` | `ConsoleTranslations::normalize()` |
| English and French in Symfony | `en`, `fr` | `ConsoleTranslations::normalize()` |

`KnownLocales` lists 50 locales; `locales/catalogs.json` lists the 23 that
ship a catalog. A known locale without a catalog, such as `zh-HK`, is shown in
English. Its order matters: a bare language picks the first listed region,
so `pt` means `pt-PT`. In Symfony, every English or French locale becomes `en`
or `fr`. Another region of a language with one shipped catalog uses that
catalog. For Portuguese and Chinese, which ship two each, a bare `pt` or `zh`
picks `pt_PT` or `zh_CN`, and an unshipped region such as `zh-HK` is shown in
English.

## How the locale is selected

Legacy pages and Symfony routes both call
`Kadupul\Platform\Application\Locale\LocaleResolver`, so the rules exist once.
Its precedence is:

1. **Language Support** (`i18n_language_support`, Settings > General) set to
   Disabled shows English.
2. The installation's forced language (`$i18n_force_language` in
   `include/config.php`), else the legacy `language` request parameter.
3. The session language, when it is a known locale.
4. The user's saved **User Language**.
5. The browser's `Accept-Language` list, unless **Auto Language Detection**
   (`i18n_auto_detection`) is off. The first range by weight that names a
   locale with a catalog wins; English needs no catalog.
6. The default **Language** (`i18n_default_language`), then the settings
   table default, then `en-US`.

With **Enabled (strict mode)**, the request is shown in English unless every
plugin in `plugin_config` ships a catalog for the selected locale.

`Kadupul\Platform\Infrastructure\Symfony\LocaleSubscriber` applies the
resolver to Symfony routes, after routing and before Symfony's own
`LocaleListener`. It differs from the legacy bootstrap in four ways:

- It ignores the `language` request parameter.
- It writes no session value, user setting or process locale.
- A request without cookies gets `en` without a database query.
- The preference comes from `LegacyLocalePreference`: the session language,
  or the saved User Language when the session has none, and nothing while the
  account must change its password.

The subscriber leaves these routes untouched, so they use the default locale,
`en`:

| Route name | Path below `app.php` | Why |
| --- | --- | --- |
| `health` | `/healthz` | Probe |
| `platform_installer` | `/installer` | May run before a database exists; the installer sets its own locale |
| `platform_console_login_post` | `POST /console` | Login handoff |
| `session` | `/session` | Session handoff |
| `inventory_devices_json` | `/inventory/devices.json` | Values are data |
| `inventory_devices_csv` | `/inventory/devices.csv` | Values and headers are data |
| `inventory_sites_json` | `/inventory/sites.json` | Values are data |

The list is `LOCALE_NEUTRAL_ROUTES` in `LocaleSubscriber`. Add a route to it
only when its response must not depend on the viewer's language.

## What CI enforces

`tests/Symfony/TranslationCatalogTest.php`, in the Symfony PHPUnit suite:

- Every `*.en.yaml` domain has a `*.fr.yaml` file with the same keys.
- No message is blank, and no French message adds a placeholder the key does
  not have (a plural form may drop `%count%`).
- No PHP file under `src/` calls a gettext function (`__`, `__esc`, `_n`,
  `__x`, `_x`, `gettext`, `_`), including fully qualified calls such as
  `\__()`. Three legacy-page helpers are allowed a fixed number of calls; a
  new call anywhere fails the test.
- No Twig template emits visible text without a message key: text nodes, the
  `title`, `alt`, `placeholder`, `aria-label`, `aria-description` and
  `label` attributes, button values and multi-word strings in inline scripts.
  The product name is exempt.

The **Gettext source and compiled catalogs** job in `.github/workflows/ci.yml`:

- `tests/security/verify_gettext_catalogs.py` checks that `cacti.pot` and
  every `.po` file match the current PHP and console Twig extraction plus the
  reviewed historical identities, that source references name files only
  (`#: lib/foo.php`, no line numbers), and that each `.mo` file is the
  compilation of its `.po` file.
- `tests/security/test_gettext_catalogs.py` checks that the verifier rejects
  bad catalogs.
- The plugin fixture catalogs under `tests/Symfony/Fixtures/translation/`
  must compile from their sources.

`tests/Symfony/Translation/` holds the rendered corpus tests. They render every
catalog identity through the real `include/global_languages.php` helpers and
php-gettext, for every shipped locale and two fixture plugins, and compare the
`cacti` domain with that output. `GettextCatalogBridgeTest`,
`LocaleResolverTest`, `LegacyLocaleResolutionTest` and `TranslationTest`
cover the bridge, the resolver and the subscriber.

## Adding a string

1. Add the English text as a key to `<domain>.en.yaml` and its translation to
   `<domain>.fr.yaml`. Use `%name%` placeholders, not `%s`.
2. Use it with `|trans({}, '<domain>')` in Twig or
   `TranslatorInterface::trans()` in PHP. Do not call `__()` from `src/`.
3. If a legacy page already uses the same English text, the other languages
   get its translation through the bridge. If the text is new, they show
   English until someone translates it.
4. If the change removes the last legacy use of a string that a migrated page
   shows, copy its translations into other-locale files first, as described
   above. Then rebuild the catalogs with `locales/build_gettext.sh`, not
   `update-pot.sh`, and run `verify_gettext_catalogs.py`.
   [CONTRIBUTING.md](../CONTRIBUTING.md) lists the rebuild alongside the
   other generated files.
5. Run `TranslationCatalogTest`.
