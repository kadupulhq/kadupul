<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 1) . '/Helpers/PestCodeCoverageCompatibility.php';

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/GraphZoomNativeHarness.php';

final class GraphInvalidLocalGraphIdTest extends TestCase
{
    use \PestCodeCoverageCompatibility;

    public function testMissingJoinedGraphRowRedirectsBeforeRendering(): void
    {
        $result = GraphZoomNativeHarness::run(array('request' => array('action' => 'view'), 'missing_graph_row' => true), $this->getTestResultObject()->getCodeCoverage());
        self::assertStringContainsString(' 302 ', $result['headers'][0]);
        self::assertContains('Location: graph_view.php', $result['headers']);
        self::assertSame('', $result['html']);
        self::assertSame(array('message' => 'The Graph you requested does not exist.', 'level' => 3), $result['session']['sess_messages']['graph_not_found']);
        self::assertDoesNotMatchRegularExpression('/PHP (Warning|Fatal error|Notice):/', $result['stderr']);
    }

    public function testUnknownGraphKeepsTheExistingInlineErrorAndFooter(): void
    {
        $result = GraphZoomNativeHarness::run(array('request' => array('action' => 'view', 'local_graph_id' => 999)), $this->getTestResultObject()->getCodeCoverage());
        self::assertStringContainsString(' 200 ', $result['headers'][0]);
        self::assertStringContainsString('GRAPH DOES NOT EXIST', $result['html']);
        self::assertStringContainsString('NATIVE_PAGE_HEADER', $result['html']);
        self::assertStringContainsString('NATIVE_PAGE_FOOTER', $result['html']);
        self::assertStringNotContainsString('graphWrapper', $result['html']);
        self::assertDoesNotMatchRegularExpression('/PHP (Warning|Fatal error|Notice):/', $result['stderr']);
    }

    public function testValidGraphViewRendersBothStoredRras(): void
    {
        $result = GraphZoomNativeHarness::run(array('request' => array('action' => 'view')), $this->getTestResultObject()->getCodeCoverage());
        self::assertStringContainsString(' 200 ', $result['headers'][0]);
        self::assertStringContainsString("rra_id='5'", $result['html']);
        self::assertStringContainsString("rra_id='7'", $result['html']);
        self::assertArrayNotHasKey('sess_messages', $result['session']);
        self::assertDoesNotMatchRegularExpression('/PHP (Warning|Fatal error|Notice):/', $result['stderr']);
    }
}
