<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Loads the production package trust and verification functions from
 * lib/import.php and package_import.php into this namespace. Only logging,
 * template import, path resolution and the trust-store query are stubbed;
 * signature checks run through real OpenSSL.
 */

namespace Kadupul\Tests\PackageSigning;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

// PHPUnit loads this file during discovery. Defining only a subset of the
// production constants here would make any later in-process load of
// global_constants.php fail on the first redefinition.
if (!defined('POLLER_VERBOSITY_LOW')) {
    require_once dirname(__DIR__, 2) . '/include/global_constants.php';
}

function load(): void
{
    if (function_exists(__NAMESPACE__ . '\import_package')) {
        return;
    }
    $root = dirname(__DIR__, 2);
    $import = file_get_contents($root . '/lib/import.php');
    foreach (['import_package', 'import_read_package_data', 'import_package_get_public_key', 'import_package_get_details', 'is_cacti_public_key', 'get_public_key', 'get_public_key_sha1', 'get_public_key_sha256', 'xml_to_array'] as $name) {
        eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($import, $name)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
    // Renamed so a test can put its own key in the Kadupul slot.
    $kadupul = \test_php_function_source($import, 'get_public_key_kadupul');
    eval('namespace ' . __NAMESPACE__ . '; ' . str_replace('function get_public_key_kadupul(', 'function production_public_key_kadupul(', $kadupul)); // nosemgrep: php.lang.security.eval-use.eval-use

    $page = file_get_contents($root . '/package_import.php');
    foreach (['package_validate_signature', 'package_file_get_contents', 'package_import_file_name_matches', 'package_import_normalize_selected_file', 'import_validate_public_key', 'package_public_key_is_trusted'] as $name) {
        // A helper a branch does not define yet fails only the tests that call it.
        if ($name === 'package_public_key_is_trusted' && !str_contains($page, "function {$name}(")) {
            continue;
        }
        eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($page, $name)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
    $functions = file_get_contents($root . '/lib/functions.php');
    foreach (['array_rekey', 'cacti_count'] as $name) {
        eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($functions, $name)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
    $xml = file_get_contents($root . '/lib/xml.php');
    foreach (['xml2array', 'get_children'] as $name) {
        eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($xml, $name)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
}

/** The Kadupul trust anchor: a test key when one is set, else the production key. */
function get_public_key_kadupul(): string
{
    return $GLOBALS['package_signing']['anchor'] ?? production_public_key_kadupul();
}

function cacti_log(string $message): void
{
    $GLOBALS['package_signing']['logs'][] = $message;
}

function __(string $text): string
{
    return $text;
}

function cacti_sizeof(mixed $value): int
{
    return is_array($value) ? count($value) : 0;
}

/** Records each template a verified package hands to the importer. */
function import_xml_data(string $xml): array
{
    $GLOBALS['package_signing']['templates'][] = $xml;

    return [];
}

/** Script and resource writes are covered elsewhere; skip them here. */
function validate_relative_path_within(): bool
{
    return false;
}

/**
 * The operator trust table is absent unless a test supplies its rows. A query
 * against the absent table fails as the production wrapper does: it logs the
 * SQL error and returns false.
 */
function db_table_exists(string $table, bool $log = true, mixed $connection = false): bool
{
    return $table === 'package_public_keys' && isset($GLOBALS['package_signing']['trusted_keys']);
}

function db_fetch_assoc(string $sql, bool $log = true, mixed $connection = false): array|false
{
    $GLOBALS['package_signing']['queries'][] = $sql;
    if (!str_contains($sql, 'package_public_keys')) {
        throw new \RuntimeException('Unexpected query: ' . $sql);
    }
    if (!isset($GLOBALS['package_signing']['trusted_keys'])) {
        cacti_log("SQL Error: Table 'cacti.package_public_keys' doesn't exist");

        return false;
    }

    return array_map(static fn(string $key): array => ['public_key' => $key], $GLOBALS['package_signing']['trusted_keys']);
}

function package_import_write_session_file(): string
{
    $path = tempnam(sys_get_temp_dir(), 'kadupul-package-session-');
    file_put_contents($path, $_SESSION['sess_import_package']);

    return $path;
}
