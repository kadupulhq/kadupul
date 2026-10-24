<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/**
 * The Composer-style range a pack declares for Kadupul (^1.0, >=1.2 <2.0,
 * 1.2.*, 1.0 - 1.4, alternatives joined by ||). The composer/semver package
 * is not reachable from the Domain, so the subset the pack schema allows is
 * evaluated here with Composer's semantics, including its stability rule:
 * a < or >= bound without an explicit stability is taken at the dev build.
 */
final readonly class VersionConstraint
{
    private const OPERATORS = '>=|<=|==|!=|<>|>|<|=|\^|~';
    private const STABILITY = ['dev' => 0, 'alpha' => 1, 'a' => 1, 'beta' => 2, 'b' => 2, 'rc' => 3];
    private const STABLE = 4;

    /** @param list<list<array{string, list<int>}>> $alternatives */
    private function __construct(private array $alternatives) {}

    public static function parse(string $text): self
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            throw new InvalidVersionConstraint('A version constraint must not be empty.');
        }
        $alternatives = [];
        foreach (preg_split('/\s*\|\|?\s*/', $trimmed) ?: [] as $group) {
            $alternatives[] = self::group($group, $text);
        }

        return new self($alternatives);
    }

    public static function isVersion(string $version): bool
    {
        return self::operand($version, self::STABLE) !== null;
    }

    public function isSatisfiedBy(string $version): bool
    {
        $candidate = self::operand($version, self::STABLE)
            ?? throw new \InvalidArgumentException(sprintf('"%s" is not a version number.', $version));
        foreach ($this->alternatives as $conditions) {
            foreach ($conditions as [$operator, $bound]) {
                if (!self::holds($candidate[0] <=> $bound, $operator)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /** @return list<array{string, list<int>}> */
    private static function group(string $group, string $text): array
    {
        if ($group === '') {
            throw new InvalidVersionConstraint(sprintf('"%s" has an empty alternative.', $text));
        }
        if (preg_match('/^(\S+)\s+-\s+(\S+)$/D', $group, $m) === 1) {
            $low = self::operand($m[1], 0) ?? self::fail($m[1], $text);
            $high = self::operand($m[2], self::STABLE) ?? self::fail($m[2], $text);

            return [['>=', $low[0]], $high[1] < 3 ? ['<', self::bump($high[0], $high[1] - 1)] : ['<=', $high[0]]];
        }
        $conditions = [];
        $joined = preg_replace('/(' . self::OPERATORS . ')\s+/', '$1', $group) ?? $group;
        foreach (preg_split('/[\s,]+/', trim($joined)) ?: [] as $term) {
            array_push($conditions, ...self::term($term, $text));
        }

        return $conditions;
    }

    /** @return list<array{string, list<int>}> */
    private static function term(string $term, string $text): array
    {
        if (in_array($term, ['*', 'x', 'X'], true)) {
            return [];
        }
        preg_match('/^(' . self::OPERATORS . ')?(.*)$/D', $term, $m);
        $operator = $m[1] ?? '';
        $operand = $m[2] ?? '';
        if (preg_match('/^v?(\d+)(?:\.(\d+))?\.[*xX]$/D', $operand, $w) === 1 && in_array($operator, ['', '=', '=='], true)) {
            $given = isset($w[2]) && $w[2] !== '' ? 2 : 1;
            $low = [(int) $w[1], $given === 2 ? (int) $w[2] : 0, 0, 0, 0, 0];

            return [['>=', $low], ['<', self::bump($low, $given - 1)]];
        }
        switch ($operator) {
            case '^':
                [$low, $given] = self::operand($operand, 0) ?? self::fail($term, $text);
                $position = $low[0] !== 0 || $given === 1 ? 0 : ($low[1] !== 0 || $given === 2 ? 1 : 2);

                return [['>=', $low], ['<', self::bump($low, $position)]];
            case '~':
                [$low, $given] = self::operand($operand, 0) ?? self::fail($term, $text);

                return [['>=', $low], ['<', self::bump($low, $given >= 3 ? 1 : 0)]];
            case '<':
            case '>=':
                return [[$operator, (self::operand($operand, 0) ?? self::fail($term, $text))[0]]];
            case '>':
            case '<=':
                return [[$operator, (self::operand($operand, self::STABLE) ?? self::fail($term, $text))[0]]];
            case '!=':
            case '<>':
                return [['!=', (self::operand($operand, self::STABLE) ?? self::fail($term, $text))[0]]];
            default:
                return [['=', (self::operand($operand, self::STABLE) ?? self::fail($term, $text))[0]]];
        }
    }

    /**
     * @return array{list<int>, int}|null the comparable version and how many numeric parts were written
     */
    private static function operand(string $text, int $defaultStability): ?array
    {
        if (preg_match('/^v?(\d{1,9})(?:\.(\d{1,9}))?(?:\.(\d{1,9}))?(?:\.(\d{1,9}))?(?:-?(dev|alpha|a|beta|b|rc)[.-]?(\d{0,9}))?$/iD', $text, $m) !== 1) {
            return null;
        }
        $given = 1;
        foreach ([2, 3, 4] as $i) {
            if (isset($m[$i]) && $m[$i] !== '') {
                $given = $i;
            }
        }
        $stability = isset($m[5]) && $m[5] !== '' ? self::STABILITY[strtolower($m[5])] : $defaultStability;

        return [[(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0), (int) ($m[4] ?? 0), $stability, (int) ($m[6] ?? 0)], $given];
    }

    /**
     * @param list<int> $version
     * @return list<int> the dev build of the next version at $position
     */
    private static function bump(array $version, int $position): array
    {
        $next = [0, 0, 0, 0, 0, 0];
        for ($i = 0; $i < $position; $i++) {
            $next[$i] = $version[$i];
        }
        $next[$position] = $version[$position] + 1;

        return $next;
    }

    private static function holds(int $comparison, string $operator): bool
    {
        return match ($operator) {
            '>=' => $comparison >= 0,
            '>' => $comparison > 0,
            '<' => $comparison < 0,
            '<=' => $comparison <= 0,
            '!=' => $comparison !== 0,
            default => $comparison === 0,
        };
    }

    private static function fail(string $term, string $text): never
    {
        throw new InvalidVersionConstraint(sprintf('"%s" in "%s" is not a version, a wildcard such as 1.2.*, or an operator followed by a version.', $term, $text));
    }
}
