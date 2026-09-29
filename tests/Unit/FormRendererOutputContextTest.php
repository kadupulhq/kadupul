<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Tests;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class FormRendererOutputContextTest extends TestCase
{
    public function testFormActionAndIdStayInTheirHtmlAndJavaScriptContexts(): void
    {
        $root = dirname(__DIR__, 2);
        $script = 'require ' . var_export($root . '/include/global_constants.php', true) . ';'
            . 'require ' . var_export($root . '/lib/functions.php', true) . ';'
            . 'require ' . var_export($root . '/lib/headers_secure.php', true) . ';'
            . 'function __($message) { return $message; }'
            . 'function __esc($message) { return cacti_html_context_escape($message, CACTI_ESC_ELEMENT); }'
            . 'require ' . var_export($root . '/lib/html_form.php', true) . ';'
            . '$action = "save.php\' autofocus onfocus=\'alert(1)</script><script>";'
            . '$id = "form\' onfocus=\'alert(1)</script><script>";'
            . 'ob_start(); form_start($action, $id); form_end();'
            . 'echo json_encode(ob_get_clean(), JSON_THROW_ON_ERROR);';

        $html = $this->runPhp($script);
        $document = new DOMDocument();
        $previousLibxmlErrorMode = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML($html, LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlErrorMode);
        }

        $xpath = new DOMXPath($document);
        $forms = $xpath->query('//form');
        self::assertNotFalse($forms);
        self::assertCount(1, $forms);
        self::assertInstanceOf(DOMElement::class, $forms->item(0));
        self::assertSame("save.php' autofocus onfocus='alert(1)</script><script>", $forms->item(0)->getAttribute('action'));
        self::assertSame("form' onfocus='alert(1)</script><script>", $forms->item(0)->getAttribute('id'));
        self::assertFalse($forms->item(0)->hasAttribute('onfocus'));

        $scripts = $xpath->query('//script');
        self::assertNotFalse($scripts);
        self::assertCount(1, $scripts);
        self::assertStringContainsString('\\u0027', $scripts->item(0)->textContent);
        self::assertStringNotContainsString("strURL  = 'save.php'", $scripts->item(0)->textContent);
    }

    private function runPhp(string $script): string
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $error);
        self::assertIsString($output);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }
}
