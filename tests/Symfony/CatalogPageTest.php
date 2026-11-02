<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use Kadupul\Navigation\Contract\Management\CatalogPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogPageTest extends TestCase
{
    public function testStoredZeroEmptyAndNullValuesRemainDistinct(): void
    {
        $rows = [['id' => 4294967296, 'zero' => 0, 'empty' => '', 'null' => null, 'fraction' => 1.25, 'literal' => '<b>']];
        $page = CatalogPage::fromRows($rows, null);
        self::assertSame($rows, $page->records);
        self::assertSame('', $page->parentName);
        self::assertSame('0', CatalogPage::fromRows([], '0')->parentName);
    }

    public static function invalidValues(): iterable
    {
        yield 'nested array' => [[['private' => 'credential-canary']], 'parent'];
        yield 'object' => [[new \stdClass()], 'parent'];
        yield 'boolean driver value' => [[false], 'parent'];
        yield 'invalid parent' => [[], ['credential-canary']];
    }

    #[DataProvider('invalidValues')]
    public function testUnexpectedDriverShapesFailBeforeRendering(array $values, mixed $parent): void
    {
        $this->expectException(\UnexpectedValueException::class);
        CatalogPage::fromRows($values === [] ? [] : [['value' => $values[0]]], $parent);
    }
}
