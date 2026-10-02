<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AggregateGraphItemsBoundaryTest extends TestCase
{
    private array $post;
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/functions.php';
        require_once dirname(__DIR__, 2) . '/lib/html_validate.php';
        require_once dirname(__DIR__, 2) . '/lib/api_aggregate.php';
        $this->post = $_POST;
    }
    protected function tearDown(): void
    {
        $_POST = $this->post;
    }
    public static function malformed(): iterable
    {
        foreach ([[],['1'],new \stdClass(),1.5,true,null,'-1','1e2','1.5','4294967296'] as $value) yield ['agg_color_1',$value];
        foreach (['agg_skip_1','agg_total_1'] as $name) {
            foreach ([[],['on'],new \stdClass(),true,1,null,'invalid'] as $value) yield [$name,$value];
        }
    }
    #[DataProvider('malformed')]
    public function testMalformedSelectionRefusesBeforeChangingAnyItem(string $field, mixed $value): void
    {
        $items = [1 => ['color_template' => '0','item_skip' => '','item_total' => '']];
        $before = $items;
        $_POST = ['agg_color_1' => '7',$field => $value];
        try {
            \aggregate_validate_graph_items($_POST, $items);
            self::fail('Malformed aggregate selection was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $items);
        }
    }
    public function testMalformedUnmatchedFieldsRemainIgnored(): void
    {
        $items = [1 => ['color_template' => '0', 'item_skip' => '', 'item_total' => '']];
        $_POST = [99 => ['ignored'], 'agg_color_1' => '7', 'agg_color_999' => [], 'agg_skip_999' => ['on'], 'agg_total_999' => new \stdClass()];
        $present = array_key_exists('config', $GLOBALS);
        $previous = $GLOBALS['config'] ?? null;
        $GLOBALS['config'] = ['is_web' => false, 'base_path' => dirname(__DIR__, 2),
            'config_options_array' => ['log_destination' => '0', 'path_cactilog' => '', 'selective_debug' => '']];
        try {
            \aggregate_validate_graph_items($_POST, $items);
        } finally {
            if ($present) $GLOBALS['config'] = $previous;
            else unset($GLOBALS['config']);
        }
        self::assertSame('7', $items[1]['color_template']);
        self::assertSame('', $items[1]['item_skip']);
        self::assertSame('', $items[1]['item_total']);
        self::assertCount(1, $items);
    }
    public function testZeroDecimalAndOmittedCheckboxesPreserveLegacyFormValues(): void
    {
        foreach ([0,'0','01','4294967295'] as $value) {
            $items = [1 => ['color_template' => '7','item_skip' => '','item_total' => '']];
            $_POST = ['agg_color_1' => $value];
            \aggregate_validate_graph_items($_POST, $items);
            self::assertSame($value, $items[1]['color_template']);
            self::assertSame('', $items[1]['item_skip']);
            self::assertSame('', $items[1]['item_total']);
        }
        $items = [1 => ['item_skip' => '','item_total' => '']];
        $_POST = ['agg_skip_1' => 'on','agg_total_1' => 'on'];
        \aggregate_validate_graph_items($_POST, $items);
        self::assertSame('on', $items[1]['item_skip']);
        self::assertSame('on', $items[1]['item_total']);
    }
}
