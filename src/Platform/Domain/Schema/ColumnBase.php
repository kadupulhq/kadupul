<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** The type keywords docs/audit_schema.sql uses, and the others MariaDB prints the same way. */
enum ColumnBase: string
{
    case Tinyint = 'tinyint';
    case Smallint = 'smallint';
    case Mediumint = 'mediumint';
    case Int = 'int';
    case Bigint = 'bigint';
    case Decimal = 'decimal';
    case Float = 'float';
    case Double = 'double';
    case Char = 'char';
    case Varchar = 'varchar';
    case Binary = 'binary';
    case Varbinary = 'varbinary';
    case Tinytext = 'tinytext';
    case Text = 'text';
    case Mediumtext = 'mediumtext';
    case Longtext = 'longtext';
    case Tinyblob = 'tinyblob';
    case Blob = 'blob';
    case Mediumblob = 'mediumblob';
    case Longblob = 'longblob';
    case Date = 'date';
    case Datetime = 'datetime';
    case Time = 'time';
    case Timestamp = 'timestamp';
    case Year = 'year';

    /** Whether this keyword takes the size, and the unsigned or zerofill attribute, SHOW COLUMNS printed with it. */
    public function accepts(?int $length, ?int $scale, bool $sign): bool
    {
        return match ($this) {
            self::Tinyint, self::Smallint, self::Mediumint, self::Int, self::Bigint => $scale === null,
            self::Decimal, self::Float, self::Double => true,
            self::Char, self::Varchar, self::Binary, self::Varbinary => $length !== null && $scale === null && !$sign,
            self::Datetime, self::Time, self::Timestamp => $scale === null && !$sign && ($length === null || $length <= 6),
            self::Year => $scale === null && !$sign && ($length === null || $length === 4),
            default => $length === null && $scale === null && !$sign,
        };
    }
}
