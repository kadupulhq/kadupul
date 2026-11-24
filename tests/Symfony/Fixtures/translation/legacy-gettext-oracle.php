<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Renders a list of translation calls through the legacy runtime for one
// locale: include/global_languages.php over the shipped .mo catalogs, with
// the language handler the i18n_language_handler setting selects. Only the
// request environment is stubbed (settings, request variables, the
// plugin_config rows); the helpers, the domain heuristic and the catalog
// reader are the production ones.
//
// Arguments: <project root> <base path> <locale> <calls file> <output file>
//            <handler setting> <expected locale> <expected handler>
//            [<Symfony cache directory>]
// The base path holds locales/ and plugins/ the way an installation does.
// The handler setting is the stored string: '' for none, '1' for php-gettext.
// With a cache directory, the text domains php-gettext loaded are then
// answered from Symfony catalogues instead (CatalogueGettextReader), so the
// same helpers render over the loader alone.
// Like the legacy pages it stands in for, this file is not strictly typed.

if (PHP_SAPI !== 'cli' || ($argc !== 9 && $argc !== 10)) {
    fwrite(STDERR, "usage: legacy-gettext-oracle.php <root> <base> <locale> <calls> <output> <handler> <expected locale> <expected handler> [<cache>]\n");
    exit(2);
}

[, $root, $base, $locale, $callsFile, $outputFile, $handlerSetting, $expectedLocale] = $argv;
$expectedHandler = (int) $argv[8];
$symfonyCache = $argv[9] ?? null;

date_default_timezone_set('UTC');

/** @var array<string, string> $request_vars */
$request_vars = [];
$_SESSION = [];
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'xx';

$config = [
    'base_path' => $base,
    'include_path' => $root . '/include',
    // The forced language is applied through the same request-variable path
    // as ?language=, which is how an operator pins a locale today.
    'i18n_force_language' => $locale,
];

// Settings exactly as an installation stores them: strings. An unset handler
// is chosen by get_src_language_files(null).
function read_config_option($name)
{
    $settings = [
        'i18n_language_support' => '1',
        'i18n_auto_detection' => '0',
        'i18n_default_language' => 'en-US',
        'i18n_language_handler' => $GLOBALS['handlerSetting'],
        // Read for the saved user language when the forced one is not a locale.
        'auth_method' => '1',
    ];
    if (!array_key_exists($name, $settings)) {
        throw new LogicException('Unexpected setting read by the language runtime: ' . $name);
    }

    return $settings[$name];
}

function read_default_config_option($name)
{
    throw new LogicException('Unexpected default setting read by the language runtime: ' . $name);
}

/** @var list<string> $logged */
$logged = [];

function cacti_log($string, $output = false, $environ = 'CMDPHP')
{
    $GLOBALS['logged'][] = $environ . ': ' . $string;
}

function set_request_var($name, $value)
{
    $GLOBALS['request_vars'][$name] = $value;
}

function get_request_var($name)
{
    return $GLOBALS['request_vars'][$name] ?? '';
}

function isempty_request_var($name)
{
    return empty($GLOBALS['request_vars'][$name]);
}

function set_user_setting($name, $value)
{
    // Persisting ?language= is outside the corpus.
}

function db_table_exists($table)
{
    // No saved user language.
    return false;
}

function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}

function db_fetch_assoc($sql)
{
    if (!str_contains($sql, 'FROM `plugin_config`')) {
        throw new LogicException('Unexpected query by the language runtime: ' . $sql);
    }

    return [['directory' => 'i18nprobe'], ['directory' => 'folder']];
}

require $root . '/include/global_constants.php';
require $root . '/include/global_languages.php';

if (CACTI_LOCALE !== $expectedLocale || CACTI_LANGUAGE_HANDLER !== $expectedHandler) {
    fwrite(STDERR, sprintf("legacy runtime selected locale %s with handler %s, expected %s with handler %s\n", CACTI_LOCALE, var_export(CACTI_LANGUAGE_HANDLER, true), $expectedLocale, $expectedHandler));
    exit(3);
}
$readerClass = [
    CACTI_LANGUAGE_HANDLER_PHPGETTEXT => gettext_reader::class,
    CACTI_LANGUAGE_HANDLER_SYMFONY => Kadupul\Platform\Infrastructure\Legacy\LegacyTextDomain::class,
    CACTI_LANGUAGE_HANDLER_MOTRANSLATOR => PhpMyAdmin\MoTranslator\Translator::class,
][CACTI_LANGUAGE_HANDLER] ?? null;
foreach ($i18n ?? [] as $domain => $reader) {
    if ($readerClass === null || !$reader instanceof $readerClass) {
        fwrite(STDERR, 'text domain ' . $domain . ' is not read by handler ' . CACTI_LANGUAGE_HANDLER . "\n");
        exit(3);
    }
}
// The Symfony handler leaves an unreadable catalog out; the caller checks the
// readers it expects.
if (CACTI_LANGUAGE_HANDLER === CACTI_LANGUAGE_HANDLER_PHPGETTEXT && !isset($i18n['cacti'])) {
    fwrite(STDERR, "the core catalog was not loaded\n");
    exit(3);
}

if ($symfonyCache !== null) {
    // After the legacy runtime has chosen its domains, as a handler would.
    require $root . '/include/vendor/autoload.php';
    $i18n = Kadupul\Tests\Translation\CatalogueGettextReader::replace($i18n ?? [], $cacti_textdomains, $root, $symfonyCache, $locale);
}

$calls = unserialize((string) file_get_contents($callsFile), ['allowed_classes' => false]);
if (!is_array($calls) || $calls === []) {
    fwrite(STDERR, "no calls to render\n");
    exit(2);
}

$allowed = ['__', '__gettext', '__n', '__x', '__xn', '__date'];
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
}, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// One list of values for compactness; errors and warnings by call index.
$values = [];
$errors = [];
$callWarnings = [];
foreach ($calls as $index => [$function, $arguments]) {
    if (!in_array($function, $allowed, true) || !is_array($arguments)) {
        fwrite(STDERR, 'call ' . $index . " is not a translation helper\n");
        exit(2);
    }
    $warnings = [];
    try {
        $values[] = $function(...$arguments);
    } catch (Throwable $exception) {
        $values[] = null;
        $errors[$index] = $exception::class;
    }
    if ($warnings !== []) {
        $callWarnings[$index] = $warnings;
    }
}
restore_error_handler();

// The Symfony handler's reader has no select_string(); its plural choice is
// compared through __n() and __xn().
$pluralIndex = [];
foreach ($i18n ?? [] as $domain => $reader) {
    if (!method_exists($reader, 'select_string')) {
        continue;
    }
    for ($n = 0; $n <= 1000; $n++) {
        $pluralIndex[$domain][$n] = $reader->select_string($n);
    }
}

$output = serialize([
    'locale' => CACTI_LOCALE,
    'handler' => CACTI_LANGUAGE_HANDLER,
    'domains' => array_map(static fn(array $paths): string => $paths['path2catalogue'], $cacti_textdomains),
    'readers' => array_map(static fn(object $reader): string => $reader::class, $i18n ?? []),
    'plural_index' => $pluralIndex,
    'values' => $values,
    'errors' => $errors,
    'warnings' => $callWarnings,
    'log' => $logged,
]);
if (file_put_contents($outputFile, $output) !== strlen($output)) {
    fwrite(STDERR, "cannot write the rendered corpus\n");
    exit(1);
}
