<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

/*
 * Icon-only controls need a name that does not come from the glyph. Under
 * Font Awesome 7 the ::before content is announced as "", and the theme CSS
 * hides the Console tab's text, so without an aria-label these read as
 * unnamed links.
 */
final class IconAccessibleNameTest extends TestCase
{
    private const STUBS = <<<'PHP'
        $config = array('poller_id' => 1, 'connection' => 'online', 'url_path' => '/');
        $tabs_left = array();
        $help_file = 'graphs.html';
        $realms = array(8 => true, 28 => true);
        function __($text) { $args = func_get_args(); array_shift($args); return $args ? vsprintf($text, $args) : $text; }
        function __esc() { return htmlspecialchars(call_user_func_array('__', func_get_args()), ENT_QUOTES); }
        function get_current_page() { return 'graph_templates.php'; }
        function isempty_request_var($name) { return true; }
        function isset_request_var($name) { return false; }
        function get_nfilter_request_var($name) { return ''; }
        function clean_up_name($name) { return $name; }
        function html_help_page($page) { return $GLOBALS['help_file']; }
        function is_realm_allowed($realm) { return $GLOBALS['realms'][$realm] ?? false; }
        function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }
        function get_selected_theme() { return 'midwinter'; }
        function is_console_page($page) { return true; }
        function api_plugin_hook($name) {}
        function db_fetch_assoc($sql) { return array(); }
        PHP;

    public function testAddAndHelpLinksAreNamedAndHideTheirGlyph(): void
    {
        $html = $this->render(
            array('html_escape', 'html_start_box'),
            'html_start_box("Graph Templates", "100%", "", "3", "center", "graph_templates.php?action=template_edit");'
        );

        self::assertSame(array('Get Page Help', 'Add'), $this->linkNames($html));
        self::assertSame(0, $this->visibleGlyphCount($html));
    }

    public function testAddLinksUseTheCallerLabelOrTitle(): void
    {
        $html = $this->render(
            array('html_escape', 'html_start_box'),
            '$GLOBALS["help_file"] = false;'
            . 'html_start_box("Graph Templates", "100%", "", "3", "center", "graph_templates.php?action=template_edit", "New");'
            . 'html_start_box("Devices", "100%", "", "3", "center", array('
            . 'array("href" => "host.php?action=edit", "title" => "Create Device"),'
            . 'array("id" => "import", "class" => "fa fa-upload")));'
        );

        self::assertSame(array('New', 'Create Device', 'Add'), $this->linkNames($html));
        self::assertSame(0, $this->visibleGlyphCount($html));
    }

    public function testAddLabelsKeepQuotesInsideTheAttribute(): void
    {
        $html = $this->render(
            array('html_escape', 'html_start_box'),
            '$GLOBALS["help_file"] = false;'
            . 'html_start_box("Trees", "100%", "", "3", "center", "tree.php?action=edit", "Ajouter l\'arbre");'
            . 'html_start_box("Trees", "100%", "", "3", "center", array('
            . 'array("href" => "tree.php?action=edit", "title" => htmlspecialchars("Add \"Tree\"", ENT_QUOTES))));'
        );

        self::assertSame(array('Ajouter l\'arbre', 'Add "Tree"'), $this->linkNames($html));
        self::assertStringNotContainsString('&amp;', $html, 'labels already escaped with __esc stay single-encoded');
    }

    public function testUntitledBoxRendersNoIconLinks(): void
    {
        $html = $this->render(
            array('html_escape', 'html_start_box'),
            'html_start_box("", "100%", "", "3", "center", "graph_templates.php?action=template_edit");'
        );

        self::assertSame(array(), $this->linkNames($html));
    }

    public function testConsoleTabAndSubmenusAreNamed(): void
    {
        $html = $this->render(array('html_escape', 'html_show_tabs_left'), 'html_show_tabs_left();');

        self::assertSame(array('Console', 'Console', 'Show All'), $this->linkNames($html));
        self::assertSame(0, $this->visibleGlyphCount($html));

        $xpath = new DOMXPath($this->document($html));
        $tab = $xpath->query("//a[@id='tab-console']")->item(0);
        self::assertInstanceOf(DOMElement::class, $tab);
        self::assertSame('tab', $tab->getAttribute('role'));
        self::assertSame('Console', $tab->getAttribute('aria-label'), 'themes hide .text_tab-console');
    }

    /** @param list<string> $functions */
    private function render(array $functions, string $call): string
    {
        $root = dirname(__DIR__, 2);
        $sources = array(
            'html_escape' => 'lib/html.php',
            'html_start_box' => 'lib/html.php',
            'html_show_tabs_left' => 'lib/html.php',
        );

        $script = self::STUBS;
        foreach ($functions as $function) {
            $source = file_get_contents($root . '/' . $sources[$function]);
            self::assertIsString($source);
            $script .= "\n" . test_php_function_source($source, $function);
        }

        $pipes = array();
        $process = proc_open(
            array(PHP_BINARY, '-r', $script . "\n" . $call),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $error . $output);
        self::assertSame('', $error);
        self::assertIsString($output);

        return $output;
    }

    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NOERROR));

        return $document;
    }

    /**
     * Name of every link whose visible content is only an icon, as a screen
     * reader computes it: aria-label wins, otherwise the text that is not
     * aria-hidden.
     *
     * @return list<string>
     */
    private function linkNames(string $html): array
    {
        $xpath = new DOMXPath($this->document($html));
        $names = array();

        foreach ($xpath->query('//a[.//i or .//span[contains(@class, "glyph_")]]') as $link) {
            self::assertInstanceOf(DOMElement::class, $link);
            if ($link->hasAttribute('aria-label')) {
                $names[] = $link->getAttribute('aria-label');

                continue;
            }

            $text = '';
            foreach ($xpath->query('.//text()[not(ancestor::*[@aria-hidden="true"])]', $link) as $node) {
                $text .= $node->textContent;
            }
            $names[] = trim($text);
        }

        return $names;
    }

    private function visibleGlyphCount(string $html): int
    {
        $xpath = new DOMXPath($this->document($html));

        return $xpath->query('//a//i[not(@aria-hidden="true")] | //a//span[contains(@class, "glyph_")][not(@aria-hidden="true")]')->length;
    }
}
