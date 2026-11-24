<!--
SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
SPDX-License-Identifier: GPL-3.0-or-later
-->

# Translations on Symfony pages

Legacy PHP pages translate with gettext: `__()` and its variants in
`include/global_languages.php` read the compiled catalogs in
`locales/LC_MESSAGES/<ll-CC>.mo`, by default through Symfony Translation (see
[Legacy pages](#legacy-pages-the-language-handler)). Pages migrated to Symfony
and Twig use Symfony Translation with one message domain per feature, whose
text lives in YAML files. This page describes how that works, how the other
locales are covered, how the request locale is chosen and what CI checks.

## How a Symfony page's text is translated

Each feature has a domain in `config/translations/`: `<domain>.en.yaml`, a
complete file for each of 40 locales, such as `<domain>.fr.yaml` or
`<domain>.de_DE.yaml`, and a few partial files (below).
Message keys are the English text, with named placeholders
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

1. The domain's own file for that locale, for example `gprint.fr.yaml` or
   `graph_template_input.de_DE.yaml`.
2. The locale's parent language, when it has one: `fr_CA` and `fr_MA` use `fr`,
   `en_GB` uses `en`.
3. English, from `<domain>.en.yaml` (`fallbacks: [en]` in
   `config/packages/translation.yaml`).

The `.po` catalogs play no part here. Text that only the legacy catalogs
contain does not reach a Symfony page.

Files are named with the Symfony locale (`de_DE`, `pt_BR`, `zh_CN`; see
[Locale names](#locale-names)). Two kinds exist:

- **Complete files.** `TranslationCatalogTest` requires every locale in its
  `TRANSLATED_LOCALES` list to define exactly the keys of `<domain>.en.yaml`.
- **Partial files** for `ar_EG`, `ar_MA`, `en_GB`, `es_MX`, `fr_CA`, `fr_MA`,
  `mg_MG`, `rw_RW` and `so_SO`. They hold only the messages that differ from
  the fallback above and may be missing for a domain. A key they omit shows
  the fallback's text. `TranslationCatalogTest` checks that they add no keys
  and no placeholders and leave no blank text.

New domains need no registration code. Symfony reads every file in
`config/translations`. Symfony caches compiled catalogues, so in a production
install clear the cache (`php bin/console cache:clear`) after changing YAML
files.

Two other domains exist. The `console` domain loads the compiled `.mo` files
directly for the console home page, whose text is extracted into the shared
gettext catalog. The `cacti` domain (`TranslationResources`,
`GettextMoLoader`) loads each `.mo` under its exact gettext identities,
including contexts and every plural form. Symfony pages do not translate
through it yet: the kernel translator does not register it, and
`GettextMoLoaderParityTest::testTheKernelTranslatorDoesNotLoadTheCactiDomainYet`
pins that. Legacy `__()` calls read the same identities through the same
loader, with a translator of their own, as the next section describes.

## Legacy pages: the language handler

`include/global_languages.php` resolves `__()`, `__n()`, `__x()`, `__xn()` and
their `__esc` variants through a language handler. The default is the Symfony
handler (`CACTI_LANGUAGE_HANDLER_SYMFONY`, value 4).
`Kadupul\Platform\Infrastructure\Legacy\LegacyTranslations` builds a
Symfony `Translator` for the request locale, without booting the kernel, and
registers `GettextMoLoader` for the core catalog (`cacti`) and for the catalog
of every installed plugin that ships one. Lookups use the gettext identities
and each catalog's own `Plural-Forms` rule, so the text is what php-gettext
returned. Call sites, catalogs and the `sprintf` argument handling of `__()`
are unchanged. English needs no catalog and loads no handler.

The handler is chosen the way it was before:

1. **Preferred Language Processor** (`i18n_language_handler`, Settings >
   General), when it is not "Use the First Handler Found".
2. Otherwise `$i18n_handler` in `include/config.php`, as the number of a
   `CACTI_LANGUAGE_HANDLER_*` constant.
3. Otherwise the first handler found: Symfony, then php-gettext.

To go back to php-gettext, select "Use the PHP GetText Handler" or set
`$i18n_handler = 1;`. No cache needs clearing, and the next request uses it.
A saved choice is now honoured: the setting is stored as a string, which
matched none of the integer constants, so every saved choice used to get the
first handler found. MoTranslator can be selected only where its library is
installed under `include/vendor/motranslator`, and the Oscarotero handler only
on PHP 8.0 or older, which `main` does not support; without their library both
choices get the first handler found.

Compiled catalogues are cached in `var/cache/legacy-translations`, one file
per locale and set of catalogs (`catalogue.<locale>.<hash>.php`). The hash
covers the text domains and their file paths, so installing or removing a
plugin catalog uses another file. Each file records its `.mo` files and is
rebuilt when one of them has a later modification time, so a replaced catalog
needs no cache clear. Deleting the directory is always safe. `cache:clear`
does not touch it.

The directory is created on first use with the permissions of the process
that got there first, like the rest of `var/cache`. It holds PHP files that
are included, so it must be writable only by the application. When it cannot
be created or written, for example on a read-only deployment or when another
user created it, the catalogs are read from the `.mo` files on every request
instead: the text is the same and the page costs about what php-gettext cost.

A catalog that is missing, truncated or not a `.mo` file no longer ends the
request. Its text domain is shown in English, the other domains still load,
and every such request writes a `WARNING` with the file name to the Kadupul
log.

## When the legacy catalogs lose a string

Translations of a migrated page live in its YAML files, so rebuilding the
`.po` catalogs does not affect them. `locales/build_gettext.sh` extracts
msgids from PHP files and the console home template, not from YAML or other
Twig templates, and `msgattrib --no-obsolete` drops every msgid no longer
found there. `tests/security/verify_gettext_catalogs.py` fails if a `.po` file
differs from the current extraction plus the reviewed historical identities in
`locales/historical-compatibility.json`, so an entry cannot be kept by editing
the `.po` file by hand. Legacy `__()` calls are the only readers of those
catalogs.

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
- `tests/Symfony/TranslationExtractionTest.php` runs Symfony's message extractor
  over `src/` and `templates/` in the kernel and requires every key it finds to
  exist in that domain's `<domain>.en.yaml`. A call with no domain, or a shared
  template whose caller's domain lacks the key, fails it. Label tables and shared
  templates pass keys through variables, which the extractor cannot see, so the
  reverse (an unused English key) is not checked yet.
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
`cacti` domain with that output. `LegacySymfonyHandlerTest` renders the same
corpus with no handler chosen, so the legacy bootstrap selects the Symfony
handler itself, and requires identical values, errors and warnings; it also
covers handler selection, a locale without a catalog, an unreadable catalog
and an unwritable cache directory. `LegacyTranslationsTest` covers the
catalogue cache. `ConsoleTranslationsTest`,
`LocaleResolverTest`, `LegacyLocaleResolutionTest` and `TranslationTest`
cover the locale names, the resolver, the subscriber and the YAML lookup.

## Adding a string

1. Add the English text as a key to `<domain>.en.yaml` and its translation to
   every complete locale file, `<domain>.fr.yaml` first. Use `%name%`
   placeholders, not `%s`. `TranslationCatalogTest` requires the full key set in
   each complete file, so a locale you cannot translate still needs an entry;
   use the English text.
2. Use it with `|trans({}, '<domain>')` in Twig or
   `TranslatorInterface::trans()` in PHP, always naming the domain. Do not call
   `__()` from `src/`. Run `bin/console translation:extract en --dump-messages`
   to see the keys the code uses; it also lists existing English keys, so rely on
   `TranslationExtractionTest` for the verdict.
3. Add the translation to a partial file only when that locale's text should
   differ from its fallback.
4. Run `TranslationCatalogTest` and `TranslationExtractionTest`.
