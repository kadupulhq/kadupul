<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use PHPUnit\Framework\TestCase;

final class VdefRouteLinkTest extends TestCase
{
    public function testFixedRouteEncodesMaliciousFilterAndEscapesLabel(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $twig = $kernel->getContainer()->get('test.service_container')->get('twig');
            $payload = 'javascript:alert(1)" onclick="alert(2)<script>x</script>';
            $html = $twig->createTemplate('<a href="{{ path("graph_vdefs", {filter: value}) }}">{{ value }}</a>')->render(['value' => $payload]);
            $document = new \DOMDocument();
            self::assertTrue(@$document->loadHTML($html));
            $link = $document->getElementsByTagName('a')->item(0);
            self::assertNotNull($link);
            self::assertStringStartsWith('/graph-definitions/vdefs', $link->getAttribute('href'));
            self::assertFalse($link->hasAttribute('onclick'));
            self::assertSame(0, $document->getElementsByTagName('script')->length);
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            self::assertSame($payload, $query['filter']);
        } finally {
            $kernel->shutdown();
        }
    }
}
