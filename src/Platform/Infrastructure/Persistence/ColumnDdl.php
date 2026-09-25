<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Platform\Domain\Schema\ColumnExtra;
use Kadupul\Platform\Domain\Schema\ColumnSpec;

/**
 * Column definitions as DDL, for the widening and the audit alike, so both
 * quote names and literals the same way. Only the name and the default come
 * from data, and both are quoted; the type and EXTRA come from closed lists.
 */
final class ColumnDdl
{
    /**
     * @param bool $explicitNull write DEFAULT NULL for a nullable column with
     *     no default, as fix_mediumint.php did; the audit's original wrote nothing
     */
    public static function modify(Connection $db, ColumnSpec $spec, bool $explicitNull = false): string
    {
        return 'MODIFY COLUMN ' . $db->quoteSingleIdentifier($spec->name) . ' ' . self::definition($db, $spec, $explicitNull);
    }

    /** @param ?string $after the column it follows, or null for FIRST */
    public static function add(Connection $db, ColumnSpec $spec, ?string $after): string
    {
        return 'ADD COLUMN ' . $db->quoteSingleIdentifier($spec->name) . ' ' . self::definition($db, $spec, false)
            . ($after === null ? ' FIRST' : ' AFTER ' . $db->quoteSingleIdentifier($after));
    }

    /**
     * The value SHOW COLUMNS would print for an information_schema
     * COLUMN_DEFAULT. MariaDB 10.2.7 and later quote a string literal and
     * write DEFAULT NULL as the bare word NULL; MySQL gives the value itself
     * and SQL NULL. A string default that reads "NULL" is indistinguishable
     * here, so a caller must only use this where that cannot matter.
     */
    public static function defaultValue(?string $raw): ?string
    {
        return match (true) {
            $raw === null, $raw === 'NULL' => null,
            strlen($raw) >= 2 && str_starts_with($raw, "'") && str_ends_with($raw, "'") => str_replace("''", "'", substr($raw, 1, -1)),
            default => $raw,
        };
    }

    private static function definition(Connection $db, ColumnSpec $spec, bool $explicitNull): string
    {
        // The MySQL platform doubles backslashes as well as quotes, so a
        // default ending in "\" cannot escape its closing quote.
        $default = match (true) {
            $spec->defaultNow => ' DEFAULT CURRENT_TIMESTAMP',
            $spec->default !== null => ' DEFAULT ' . $db->getDatabasePlatform()->quoteStringLiteral($spec->default),
            $explicitNull && !$spec->notNull => ' DEFAULT NULL',
            default => '',
        };

        return $spec->type->sql() . ($spec->notNull ? ' NOT NULL' : '') . $default . ($spec->extra === ColumnExtra::None ? '' : ' ' . $spec->extra->value);
    }
}
