<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Symfony;

use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Domain\SiteSelection;
use PHPUnit\Framework\TestCase;

final class SelectionIdsTest extends TestCase
{
    public function testBothSelectionContractsPreserveShapeGrammarRangeAndOrdering(): void
    {
        foreach ([[DeviceSelection::class, 'devices', 'device', 16777215], [SiteSelection::class, 'sites', 'site', 4294967295]] as [$class, $plural, $singular, $maximum]) {
            self::assertSame([1, 3, $maximum], $class::validateIds([(string) $maximum, '3', 1]));
            self::assertSame(range(1, 100), $class::validateIds(range(1, 100)));
            foreach ([[], range(1, 101), array_fill_keys(range(2, 102), 1)] as $ids) {
                $this->assertRejected($class, $ids, 'Select between 1 and 100 ' . $plural . '.');
            }
            foreach ([[1 => 2], ['key' => 2], [0 => 1, 2 => 2], [0], ['01'], ['1 '], ['1.0'], ['1e1'], [-1], [null], [false], [1.0], [[]], ['9999999999999999999999999'], [(string) ($maximum + 1)], [1, '1']] as $ids) {
                $this->assertRejected($class, $ids, 'Invalid ' . $singular . ' selection.');
            }
            $selection = new $class([3 => str_repeat('b', 64), 1 => str_repeat('a', 64)]);
            self::assertSame([1, 3], array_keys($selection->revisions));
            foreach (['', str_repeat('A', 64), str_repeat('a', 63), 1, null] as $revision) {
                try {
                    new $class([1 => $revision]);
                    self::fail('Malformed revision accepted');
                } catch (\InvalidArgumentException $error) {
                    self::assertSame('Invalid ' . $singular . ' selection.', $error->getMessage());
                }
            }
        }
    }

    private function assertRejected(string $class, array $ids, string $message): void
    {
        try {
            $class::validateIds($ids);
            self::fail('Malformed selection accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }
}
