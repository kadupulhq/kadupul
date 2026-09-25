<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/**
 * A column type the audit may write into DDL, parsed from the audit schema's
 * table_type text into a keyword from a closed list and bounded numbers. The
 * text itself never reaches the server; sql() rebuilds it from the parts.
 */
final readonly class ColumnType
{
    private const string PATTERN = '/^(?<base>[a-z]+)(?:\((?<length>\d{1,5})(?:,(?<scale>\d{1,2}))?\))?(?<unsigned> unsigned)?(?<zerofill> zerofill)?$/D';

    private function __construct(public ColumnBase $base, public ?int $length, public ?int $scale, public bool $unsigned, public bool $zerofill) {}

    /** Null for anything outside the grammar, so the statement that needed it is not sent. */
    public static function parse(string $text): ?self
    {
        if (preg_match(self::PATTERN, $text, $match) !== 1) {
            return null;
        }
        $base = ColumnBase::tryFrom($match['base']);
        $length = ($match['length'] ?? '') === '' ? null : (int) $match['length'];
        $scale = ($match['scale'] ?? '') === '' ? null : (int) $match['scale'];
        $unsigned = ($match['unsigned'] ?? '') !== '';
        $zerofill = ($match['zerofill'] ?? '') !== '';
        if ($base === null || !$base->accepts($length, $scale, $unsigned || $zerofill)) {
            return null;
        }

        return new self($base, $length, $scale, $unsigned, $zerofill);
    }

    /**
     * Whether changing a column of type $live to this type could lose values:
     * a smaller integer, a shorter char or varchar, or a decimal with fewer
     * digits on either side of the point. Other pairs are never compared, so
     * they never count as narrower.
     */
    public function narrows(self $live): bool
    {
        $integers = [ColumnBase::Tinyint, ColumnBase::Smallint, ColumnBase::Mediumint, ColumnBase::Int, ColumnBase::Bigint];
        $strings = [ColumnBase::Char, ColumnBase::Varchar];

        return match (true) {
            in_array($this->base, $integers, true) && in_array($live->base, $integers, true) => array_search($this->base, $integers, true) < array_search($live->base, $integers, true),
            in_array($this->base, $strings, true) && in_array($live->base, $strings, true) => $this->length < $live->length,
            $this->base === ColumnBase::Decimal && $live->base === ColumnBase::Decimal => $this->whole() < $live->whole() || ($this->scale ?? 0) < ($live->scale ?? 0),
            default => false,
        };
    }

    /** Digits before a decimal's point; MariaDB's default precision is 10. */
    private function whole(): int
    {
        return ($this->length ?? 10) - ($this->scale ?? 0);
    }

    public function sql(): string
    {
        $size = $this->length === null ? '' : '(' . $this->length . ($this->scale === null ? '' : ',' . $this->scale) . ')';

        return $this->base->value . $size . ($this->unsigned ? ' unsigned' : '') . ($this->zerofill ? ' zerofill' : '');
    }
}
