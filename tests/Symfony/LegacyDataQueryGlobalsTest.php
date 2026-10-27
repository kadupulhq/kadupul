<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class LegacyDataQueryGlobalsTest extends TestCase
{
    public function testConfirmationKeepsItsRequestAndOutputContractWithoutInitializingVdefCatalogs(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__) . '/Fixtures/data-query-confirmation-native.php']);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $result['created']);
        self::assertSame([['validate', 'id'], ['validate', 'snmp_query_id'], ['query', 'SELECT * FROM snmp_query_graph WHERE id = ?', [7]]], $result['calls']);
        self::assertStringContainsString('Mapping &lt;img', $result['html']);
        self::assertStringNotContainsString('<img src=x', $result['html']);
        self::assertStringContainsString("id='snmp_query_graph_id' value='7'", $result['html']);
        self::assertStringContainsString("id='continue'", $result['html']);
        self::assertStringContainsString("id='cancel'", $result['html']);
    }
}
