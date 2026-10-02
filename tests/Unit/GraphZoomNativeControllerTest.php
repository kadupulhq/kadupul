<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/GraphZoomNativeHarness.php';

final class GraphZoomNativeControllerTest extends TestCase
{
    /** @dataProvider missingData */
    public function testMissingStoredDataRedirectsBeforeAnyOutput(array $scenario): void
    {
        $result = GraphZoomNativeHarness::run($scenario, $this->getTestResultObject()->getCodeCoverage());
        self::assertStringContainsString(' 302 ', $result['headers'][0]);
        self::assertContains('Location: graph_view.php', $result['headers']);
        self::assertSame('', $result['html']);
        self::assertSame(array('message' => 'This Graph has no stored data to zoom into.', 'level' => 3), $result['session']['sess_messages']['graph_no_data']);
        self::assertDoesNotMatchRegularExpression('/PHP (Warning|Fatal error|Notice):/', $result['stderr']);
    }

    public function missingData(): array
    {
        return array('no profile' => array(array('no_profile' => true)), 'unassociated selected RRA' => array(array('request' => array('rra_id' => '12'))), 'removed default row' => array(array('deleted_rra' => true)));
    }

    /** @dataProvider selections */
    public function testNativeZoomUsesTheOrderedStoredRraAndItsCausalTimeWindow(string $requested, int $expected, int $timespan): void
    {
        $result = GraphZoomNativeHarness::run(array('request' => array('rra_id' => $requested)), $this->getTestResultObject()->getCodeCoverage());
        self::assertStringContainsString(' 200 ', $result['headers'][0]);
        self::assertStringContainsString('NATIVE_PAGE_HEADER', $result['html']);
        self::assertStringContainsString('NATIVE_PAGE_FOOTER', $result['html']);
        self::assertArrayNotHasKey('sess_messages', $result['session']);
        self::assertDoesNotMatchRegularExpression('/PHP (Warning|Fatal error|Notice):/', $result['stderr']);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML($result['html'], LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $wrapper = $xpath->query('//div[@id="wrapper_4"]')->item(0);
        self::assertSame((string) $expected, $wrapper->getAttribute('rra_id'));
        self::assertSame('4', $wrapper->getAttribute('graph_id'));
        $start = (int) $xpath->query('//input[@id="graph_start"]')->item(0)->getAttribute('value');
        self::assertGreaterThanOrEqual($result['before'], $start + $timespan);
        self::assertLessThanOrEqual($result['after'], $start + $timespan);
        $fetches = array_values(array_filter($result['queries'], fn($query) => str_starts_with($query[0], 'SELECT dspr.id')));
        self::assertCount(1, $fetches);
        self::assertSame(array($expected), $fetches[0][1]);
        $associations = array_values(array_filter($result['queries'], fn($query) => str_starts_with($query[0], 'SELECT DISTINCT')));
        self::assertCount(1, $associations);
        self::assertStringContainsString('ORDER BY dspr.steps', $associations[0][0]);
    }

    public function selections(): array
    {
        return array(array('all', 5, 3000), array('0', 5, 3000), array('', 5, 3000), array('5', 5, 3000), array('7', 7, 360000));
    }
}
