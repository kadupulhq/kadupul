<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use DOMDocument;
use DOMXPath;

require_once dirname(__DIR__, 1) . '/Helpers/PestCodeCoverageCompatibility.php';

use PHPUnit\Framework\TestCase;

final class FormRendererOutputContextTest extends TestCase
{
    use \PestCodeCoverageCompatibility;

    #[\PHPUnit\Framework\Attributes\DataProvider('forms')]
    public function testNativeFormEncodingRoundTrips(array $scenario, string $expectedId): void
    {
        $html = $this->render($scenario)['html'];
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML($html, LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        self::assertCount(1, $xpath->query('//form'));
        $form = $xpath->query('//form')->item(0);
        self::assertSame($scenario['action'], $form->getAttribute('action'));
        self::assertSame($expectedId, $form->getAttribute('id'));
        self::assertSame($expectedId, $form->getAttribute('name'));
        self::assertFalse($form->hasAttribute('onfocus'));
        self::assertSame(!empty($scenario['multipart']) ? 'multipart/form-data' : '', $form->getAttribute('enctype'));
        $scripts = $xpath->query('//script');
        self::assertCount(1, $scripts);
        $script = $scripts->item(0)->textContent;
        foreach (array("/getElementById\\('([^']*)'\\)/" => $expectedId, "/formArray\\['([^']*)'\\]/" => $expectedId, "/strURL\\s*=\\s*'([^']*)'/" => $scenario['action']) as $pattern => $expected) {
            self::assertSame(1, preg_match($pattern, $script, $match));
            self::assertSame($expected, json_decode('"' . $match[1] . '"', true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public static function forms(): array
    {
        return array(
            array(array('action' => "save.php' autofocus onfocus='alert(1)</script><script>", 'id' => "form' onfocus='alert(1)</script><script>"), "form' onfocus='alert(1)</script><script>"),
            array(array('action' => 'user_admin.php?tab=permsg&id=5', 'id' => ''), 'form1'),
            array(array('action' => 'save.php?value=%26%23%20&array[]=1', 'id' => '  a.b  ', 'multipart' => true), 'a.b')
        );
    }

    public function testOrphanAndNonAjaxFormsAreWarningFree(): void
    {
        $orphan = $this->render(array('orphan' => true))['html'];
        self::assertStringContainsString("getElementById('')", $orphan);
        self::assertStringContainsString("strURL  = ''", $orphan);
        $plain = $this->render(array('action' => 'save.php', 'id' => 'plain', 'ajax' => false))['html'];
        self::assertStringNotContainsString('<script', $plain);
        self::assertStringContainsString('</form>', $plain);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inputs')]
    public function testSharedNativeInputStatePreservesDefaultsAndErrors(array $scenario, string $value, bool $error): void
    {
        $result = $this->render($scenario);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML($result['html'], LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        self::assertSame($value, $document->getElementsByTagName('input')->item(0)->getAttribute('value'));
        self::assertSame($error, str_contains($result['html'], 'txtErrorTextBox'));
        self::assertArrayNotHasKey('fixture', $result['session']['sess_error_fields'] ?? array());
    }

    public static function inputs(): array
    {
        $cases = array();
        foreach (array('directory', 'font') as $input) {
            $cases[] = array(array('input' => $input), 'default', false);
            $cases[] = array(array('input' => $input, 'value' => 'saved', 'current_id' => 1, 'session' => array('sess_error_fields' => array('fixture' => true), 'sess_field_values' => array('fixture' => "submitted'&"))), "submitted'&", true);
            $cases[] = array(array('input' => $input, 'value' => '', 'current_id' => 1, 'session' => array('sess_error_fields' => array())), '', false);
        }
        return $cases;
    }

    public function testNativeEditFormHandsOffCheckboxFileFontAndColourControls(): void
    {
        $result = $this->render(array('controls' => true));
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML($result['html'], LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        foreach (array('filepath', 'font') as $id) {
            $input = $xpath->query('//input[@id="' . $id . '"]')->item(0);
            self::assertSame($id, $input->getAttribute('name'));
            self::assertSame('saved', $input->getAttribute('value'));
            self::assertSame('64', $input->getAttribute('maxlength'));
        }
        self::assertTrue($xpath->query('//input[@id="enabled"]')->item(0)->hasAttribute('checked'));
        $file = $xpath->query('//input[@id="file"]')->item(0);
        self::assertSame('file', $file->getAttribute('type'));
        self::assertSame('.xml', $file->getAttribute('accept'));
        $colour = $xpath->query('//select[@id="drop_color"]')->item(0);
        self::assertSame('background-color: #FFFFFF;', $colour->getAttribute('style'));
        self::assertTrue($xpath->query('//select[@id="drop_color"]/option[@value="5"]')->item(0)->hasAttribute('selected'));
    }

    private function render(array $scenario): array
    {
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $directory = sys_get_temp_dir() . '/form-renderer-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $command = array(PHP_BINARY, '-d', 'error_reporting=24575', '-d', 'auto_prepend_file=', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', __DIR__ . '/../Fixtures/form-renderer-native.php', json_encode($scenario, JSON_THROW_ON_ERROR));
            if ($coverage !== null) {
                $command[] = $directory;
            }
            $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            self::assertSame('', $error);
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $coverage->merge(unserialize(file_get_contents($reports[0])));
            }
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
