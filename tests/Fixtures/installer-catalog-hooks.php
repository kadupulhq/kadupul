<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Isolated native catalog hook for the production service boundary. */
function __(string $text, mixed ...$arguments): string
{
    return $arguments === [] ? $text : vsprintf($text, $arguments);
}

/** Isolated native catalog hook for the production service boundary. */
function config_value_exists(string $name): bool
{
    return array_key_exists($name, \Kadupul\Tests\InstallerCatalogServicesTest::$options);
}

/** Isolated native catalog hook for the production service boundary. */
function read_config_option(string $name, bool $force = false): string
{
    return \Kadupul\Tests\InstallerCatalogServicesTest::$options[$name] ?? '';
}

/** Isolated native catalog hook for the production service boundary. */
function cacti_log(mixed ...$arguments): void {}

/** Isolated native catalog hook for the production service boundary. */
function clean_up_lines(string $value): string
{
    return $value;
}

/** Isolated native catalog hook for the production service boundary. */
function get_installed_rrdtool_version(): string
{
    return 'fixture-version';
}

/** Isolated native catalog hook for the production service boundary. */
function get_cacti_base_tables(): mixed
{
    return \Kadupul\Tests\InstallerCatalogServicesTest::$tables;
}

/** Isolated native catalog hook for the production service boundary. */
function db_fetch_row_prepared(string $sql, array $parameters): array|false
{
    if (\Kadupul\Tests\InstallerCatalogServicesTest::$database === null) {
        return \Kadupul\Tests\InstallerCatalogServicesTest::$tableRow;
    }
    $query = \Kadupul\Tests\InstallerCatalogServicesTest::$database->prepare($sql);
    $query->execute($parameters);
    // Native db_fetch_row_return() uses an empty array for a successful zero-row read.
    $row = $query->fetch(\PDO::FETCH_ASSOC);
    return $row === false ? [] : $row;
}

/** Native count semantics for the frozen procedural oracle. */
function cacti_sizeof(mixed $value): int
{
    return is_countable($value) ? count($value) : 0;
}
