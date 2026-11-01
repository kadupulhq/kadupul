<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Domain\DataQuerySelection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataQuerySelectionTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, ?int, ?int}> */
    public static function accepted(): iterable
    {
        yield 'both all' => ['all', 'all', null, null];
        yield 'any case' => ['ALL', 'All', null, null];
        yield 'ids' => ['7', '11', 7, 11];
        yield 'leading zeros' => ['007', '011', 7, 11];
        yield 'one all' => ['3', 'all', 3, null];
        yield 'an overflowing id saturates as the cast did' => ['99999999999999999999', '1', PHP_INT_MAX, 1];
    }

    #[DataProvider('accepted')]
    public function testItAcceptsWhatTheScriptAccepted(string $host, ?string $query, ?int $hostId, ?int $queryId): void
    {
        $selection = DataQuerySelection::parse($host, $query);

        self::assertSame([$hostId, $queryId], [$selection->hostId, $selection->queryId]);
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function refused(): iterable
    {
        yield 'zero host' => ['0', 'all', DataQuerySelection::BAD_HOST];
        yield 'negative host' => ['-1', 'all', DataQuerySelection::BAD_HOST];
        yield 'host list' => ['1,2', 'all', DataQuerySelection::BAD_HOST];
        yield 'empty host' => ['', 'all', DataQuerySelection::BAD_HOST];
        yield 'decimal host' => ['1.5', 'all', DataQuerySelection::BAD_HOST];
        yield 'missing query' => ['all', null, DataQuerySelection::BAD_QUERY];
        yield 'empty query' => ['all', '', DataQuerySelection::BAD_QUERY];
        yield 'zero query' => ['all', '0', DataQuerySelection::BAD_QUERY];
        yield 'text query' => ['all', 'invalid', DataQuerySelection::BAD_QUERY];
        yield 'the host is judged first' => ['0', null, DataQuerySelection::BAD_HOST];
    }

    #[DataProvider('refused')]
    public function testItRefusesWithTheScriptsMessage(string $host, ?string $query, string $message): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException($message));
        DataQuerySelection::parse($host, $query);
    }
}
