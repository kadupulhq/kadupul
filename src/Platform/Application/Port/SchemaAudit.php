<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Port;

use Kadupul\Platform\Domain\Schema\LiveTable;
use Kadupul\Platform\Domain\Schema\TableAlter;

interface SchemaAudit
{
    /** include/cacti_version, trimmed: what CACTI_VERSION held. */
    public function codeVersion(): string;

    /** SELECT cacti FROM version, untrimmed, as audit_database.php:87 read it; '' when the table has no row. */
    public function databaseVersion(DatabaseTarget $target): string;

    /** Every base table in SHOW TABLES order, with SHOW COLUMNS and SHOW INDEXES as they print, and the plugin_db_changes rows. */
    public function catalog(DatabaseTarget $target): AuditCatalog;

    /** The statement alter() sends. $alter must be buildable and its table in catalog(). */
    public function statement(DatabaseTarget $target, TableAlter $alter): string;

    /**
     * @param LiveTable $read the table as catalog() read it when the alter was decided
     * @return bool false when the table no longer reads as $read, which sends nothing, or when the server refused the statement
     */
    public function alter(DatabaseTarget $target, TableAlter $alter, LiveTable $read): bool;
}
