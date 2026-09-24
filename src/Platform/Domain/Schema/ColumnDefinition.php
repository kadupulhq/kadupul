<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** One column as the catalog lists it: what fix_mediumint.php read from SHOW COLUMNS. */
final readonly class ColumnDefinition
{
    /** @param ?string $default the default value, or null when the column has none or defaults to NULL */
    public function __construct(public string $name, public string $type, public bool $nullable, public ?string $default, public string $extra) {}

    /**
     * Only integers narrower than int unsigned. fix_mediumint.php also rewrote
     * bigint, which narrowed it, and non-integer types; neither is touched.
     * MySQL 8 prints "int unsigned" without a width, and that is wide enough.
     */
    public function needsWidening(): bool
    {
        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)\b/i', $this->type, $match) !== 1) {
            return false;
        }

        return match (strtolower($match[1])) {
            'bigint' => false,
            'int' => !str_contains(strtolower($this->type), 'unsigned'),
            default => true,
        };
    }

    /**
     * MODIFY cannot give a generated column a plain type, and would silently
     * drop INVISIBLE; the original rewrote both. MySQL 8 also marks an
     * expression default DEFAULT_GENERATED, which this skips with them.
     */
    public function changeable(): bool
    {
        $extra = strtoupper($this->extra);

        return !str_contains($extra, 'GENERATED') && !str_contains($extra, 'INVISIBLE');
    }

    /** Every attribute read from the catalog, so a column changed since the read no longer matches. */
    public function sameAs(self $other): bool
    {
        return $this->name === $other->name && $this->type === $other->type && $this->nullable === $other->nullable
            && $this->default === $other->default && $this->extra === $other->extra;
    }

    /** Nullability is kept; the original forced NOT NULL on a column with a default. */
    public function change(): ColumnChange
    {
        return new ColumnChange(
            $this->name,
            // EXTRA lists every attribute, as in "auto_increment, INVISIBLE".
            str_contains(strtolower($this->extra), 'auto_increment'),
            $this->nullable,
            $this->default === null || $this->default === '' ? null : $this->default,
        );
    }
}
