<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

final class PollerCacheOutputTypeTest extends TestCase
{
    public function testOutputTypeIsBoundAndMalformedValuesFailClosed(): void
    {
        $utilitySource = file_get_contents(__DIR__ . '/../../../lib/utility.php');
        self::assertIsString($utilitySource);

        $functionStart = strpos($utilitySource, 'function update_poller_cache(');
        self::assertNotFalse($functionStart);
        $functionEnd = strpos($utilitySource, "\nfunction ", $functionStart + 1);
        self::assertNotFalse($functionEnd);
        $functionBody = substr($utilitySource, $functionStart, $functionEnd - $functionStart);

        self::assertStringContainsString("' AND sqgr.snmp_query_graph_id = ?'", $functionBody);
        self::assertStringContainsString('$params[] = $output_type;', $functionBody);
        self::assertStringContainsString('!ctype_digit($output_type)', $functionBody);
        self::assertStringContainsString('$outputs = array();', $functionBody);
        self::assertStringNotContainsString(
            "' AND sqgr.snmp_query_graph_id = ' . \$field['output_type']",
            $functionBody
        );
    }
}
