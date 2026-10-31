<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

/**
 * Which graphs remove_graphs.php acts on. The selectors narrow together; --all
 * ignores them. With no selector and no --all, nothing may be counted or removed.
 */
final readonly class GraphRemovalFilter
{
    /** The longest regular expression validate_is_regex() accepts. */
    private const int MAX_PATTERN_BYTES = 50;

    /**
     * @param list<int> $hostIds
     * @param list<int> $hostTemplateIds
     * @param list<int> $graphTemplateIds
     * @param list<string> $patterns title expressions, already through pattern()
     */
    public function __construct(
        public array $hostIds,
        public array $hostTemplateIds,
        public array $graphTemplateIds,
        public array $patterns,
        public bool $all,
    ) {}

    /** True when no selector narrows the set, so the filter would match every graph. */
    public function matchesEverything(): bool
    {
        return $this->all || ($this->hostIds === [] && $this->hostTemplateIds === [] && $this->graphTemplateIds === [] && $this->patterns === []);
    }

    /**
     * An id as the original read it: is_numeric() and above zero, then cast to int,
     * so "1e2" is 100 and "1.5" is 1. Null when the original called it invalid.
     */
    public static function id(string $value): ?int
    {
        return is_numeric($value) && $value > 0 ? (int) $value : null;
    }

    /**
     * The expression db_qstr_rlike() would send (it drops NUL, "|", "{" and "}"), or
     * null when it must be refused.
     * validate_is_regex() meant to refuse more than 50 bytes and ";" but its caller
     * tested for false, which it never returns, so the script accepted both. An
     * empty expression, also what "||" becomes, matches every title, so --graph-regex
     * with nothing after it could remove every graph; that is refused too.
     */
    public static function pattern(string $value): ?string
    {
        if (strlen($value) > self::MAX_PATTERN_BYTES || str_contains($value, ';')) {
            return null;
        }
        $pattern = str_replace(["\0", '|', '{', '}'], '', $value);

        return $pattern === '' ? null : $pattern;
    }
}
