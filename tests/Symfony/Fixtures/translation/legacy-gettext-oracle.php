<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Renders a list of translation calls through the legacy runtime for one
// locale: include/global_languages.php with the php-gettext handler over the
// shipped .mo catalogs. Only the request environment is stubbed (settings,
// request variables, the plugin_config rows); the helpers, the domain
// heuristic and the catalog reader are the production ones.
//
// Arguments: <project root> <base path> <locale> <calls file> <output file>
// The base path holds locales/ and plugins/ the way an installation does.
// Like the legacy pages it stands in for, this file is not strictly typed.

if (PHP_SAPI !== 'cli' || $argc !== 6) {
    fwrite(STDERR, "usage: legacy-gettext-oracle.php <root> <base> <locale> <calls> <output>\n");
    exit(2);
}

[, $root, $base, $locale, $callsFile, $outputFile] = $argv;

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

// Settings exactly as an installation stores them: strings, and an unset
// handler, so the handler is chosen by get_src_language_files(null).
function read_config_option($name)
{
    $settings = [
        'i18n_language_support' => '1',
        'i18n_auto_detection' => '0',
        'i18n_default_language' => 'en-US',
        'i18n_language_handler' => '',
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

$translated = $locale !== 'en-US';
$expectedHandler = $translated ? CACTI_LANGUAGE_HANDLER_PHPGETTEXT : CACTI_LANGUAGE_HANDLER_DEFAULT;
if (CACTI_LOCALE !== $locale || CACTI_LANGUAGE_HANDLER !== $expectedHandler) {
    fwrite(STDERR, sprintf("legacy runtime selected locale %s with handler %s, expected %s with handler %s\n", CACTI_LOCALE, var_export(CACTI_LANGUAGE_HANDLER, true), $locale, $expectedHandler));
    exit(3);
}
foreach ($i18n ?? [] as $domain => $reader) {
    if (!$reader instanceof gettext_reader) {
        fwrite(STDERR, 'text domain ' . $domain . " is not read by php-gettext\n");
        exit(3);
    }
}
if ($translated && !isset($i18n['cacti'])) {
    fwrite(STDERR, "the core catalog was not loaded\n");
    exit(3);
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

$pluralIndex = [];
foreach ($i18n ?? [] as $domain => $reader) {
    for ($n = 0; $n <= 1000; $n++) {
        $pluralIndex[$domain][$n] = $reader->select_string($n);
    }
}

$output = serialize([
    'locale' => CACTI_LOCALE,
    'handler' => CACTI_LANGUAGE_HANDLER,
    'domains' => array_map(static fn(array $paths): string => $paths['path2catalogue'], $cacti_textdomains),
    'plural_index' => $pluralIndex,
    'values' => $values,
    'errors' => $errors,
    'warnings' => $callWarnings,
]);
if (file_put_contents($outputFile, $output) !== strlen($output)) {
    fwrite(STDERR, "cannot write the rendered corpus\n");
    exit(1);
}
