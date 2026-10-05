<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PestCodeCoverageCompatibility.php';
require_once dirname(__DIR__, 3) . '/Helpers/GraphZoomNativeHarness.php';

use PHPUnit\Framework\TestCase;

final class RealtimeButtonGateTest extends TestCase
{
    use \PestCodeCoverageCompatibility;

    public function testRenderedRealtimeButtonsRespectBothRealmsAndSetting(): void
    {
        foreach (array('', 'on') as $enabled) {
            foreach (array(false, true) as $realtimeRealm) {
                foreach (array(false, true) as $utilityRealm) {
                    $realms = array();
                    if ($realtimeRealm) {
                        $realms[] = 25;
                    }
                    if ($utilityRealm) {
                        $realms[] = 27;
                    }
                    $result = GraphZoomNativeHarness::run(array(
                        'request' => array('action' => 'view'),
                        'realtime_enabled' => $enabled,
                        'realms' => $realms,
                    ), $this->getTestResultObject()->getCodeCoverage());
                    self::assertStringContainsString(' 200 ', $result['headers'][0]);
                    self::assertStringContainsString("rra_id='5'", $result['html']);
                    self::assertStringContainsString("rra_id='7'", $result['html']);
                    self::assertDoesNotMatchRegularExpression('/PHP (Warning|Fatal error|Notice):/', $result['stderr']);
                    $document = new DOMDocument();
                    $previous = libxml_use_internal_errors(true);
                    try {
                        self::assertTrue($document->loadHTML($result['html']));
                    } finally {
                        libxml_clear_errors();
                        libxml_use_internal_errors($previous);
                    }
                    $xpath = new DOMXPath($document);
                    $buttons = $xpath->query('//a[img[@title="Click to view just this Graph in Real-time"]]');
                    self::assertCount($enabled === 'on' && $realtimeRealm && $utilityRealm ? 2 : 0, $buttons);
                    foreach ($buttons as $button) {
                        self::assertStringContainsString("window.open('/cacti/graph_realtime.php?top=0&left=0&local_graph_id=4'", $button->getAttribute('onclick'));
                    }
                }
            }
        }
    }
}
