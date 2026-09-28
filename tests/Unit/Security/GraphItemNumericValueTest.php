<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

final class GraphItemNumericValueTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/lib/rrd.php');
        self::assertIsString($source);
        eval(test_php_function_source($source, 'rrdtool_graph_item_numeric_value'));
    }

    public function testOnlyOneNumericArgumentIsAccepted(): void
    {
        foreach (['1', '-1', '+1.25', '.5', '1.', '1e3', '-1.5E-2', ''] as $value) {
            self::assertSame($value, rrdtool_graph_item_numeric_value($value));
        }

        foreach (["1\nupdate /tmp/rrd.rrd N:1", "1\rupdate /tmp/rrd.rrd N:1", '1 update /tmp/rrd.rrd N:1', '1:2', 'NaN'] as $value) {
            self::assertNull(rrdtool_graph_item_numeric_value($value));
        }
    }

    public function testGraphSinksQuoteValidatedValueAndFormsRejectNewlineSuffixes(): void
    {
        $root = dirname(__DIR__, 3);
        $rrdSource = file_get_contents($root . '/lib/rrd.php');
        $graphItemsSource = file_get_contents($root . '/graphs_items.php');
        $templateItemsSource = file_get_contents($root . '/graph_templates_items.php');

        self::assertIsString($rrdSource);
        self::assertIsString($graphItemsSource);
        self::assertIsString($templateItemsSource);
        self::assertStringContainsString('rrdtool_pipe_quote($safe_graph_item_value)', $rrdSource);
        self::assertStringContainsString('$save[\'value\']', $graphItemsSource);
        self::assertStringContainsString('$save[\'value\']', $templateItemsSource);
        self::assertStringContainsString('\\\\z', $graphItemsSource);
        self::assertStringContainsString('\\\\z', $templateItemsSource);
    }
}
