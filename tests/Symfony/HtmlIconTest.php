<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

/*
 * html_icon() is the only way lib/html.php draws a registry icon, so it must
 * refuse an icon a screen reader would announce as nothing.
 */
final class HtmlIconTest extends TestCase
{
    public function testLabelledIconsAreImagesWithAName(): void
    {
        self::assertSame(
            "<i class='fa fa-plus' role='img' aria-label='Add Device'></i>",
            $this->call("html_icon('add', 'Add Device')")
        );
    }

    public function testDecorativeIconsAreHidden(): void
    {
        self::assertSame(
            "<i class='fa fa-sort-up' aria-hidden='true'></i>",
            $this->call("html_icon('sort-asc', '', array('aria-hidden' => 'true'))")
        );
    }

    public function testTheSelectedThemeRedrawsAnIcon(): void
    {
        self::assertSame(
            "<i class='fas fa-sliders' aria-hidden='true'></i>",
            $this->call("html_icon('filter', '', array('aria-hidden' => 'true'))", 'midwinter')
        );
        self::assertSame('fa fa-filter', $this->call("html_icon_class('filter')", 'modern'));
        self::assertSame('fa fa-chevron-down', $this->call("html_icon_class('export')", 'paw'));
    }

    public function testExtraClassesAndAttributesAreEscaped(): void
    {
        self::assertSame(
            "<i class='fa fa-angle-double-left previous' aria-hidden='true' data-page='a&apos;b&lt;'></i>",
            $this->call("html_icon('page-previous', '', array('class' => 'previous', 'aria-hidden' => 'true', 'data-page' => \"a'b<\"))")
        );
        self::assertSame(
            "<i class='fa fa-plus' role='img' aria-label='Add &quot;Tree&quot; &amp; more'></i>",
            $this->call("html_icon('add', htmlspecialchars('Add \"Tree\"', ENT_QUOTES) . ' & more')"),
            'a label already escaped with __esc stays single-encoded'
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'no label and not hidden' => ["html_icon('add', '')", "Icon add needs a label, or aria-hidden='true' if it is decorative"];
        yield 'hidden only by a falsy flag' => ["html_icon('add', '', array('aria-hidden' => 'false'))", "Icon add needs a label, or aria-hidden='true' if it is decorative"];
        yield 'labelled and hidden' => ["html_icon('add', 'Add', array('aria-hidden' => 'true'))", 'Icon add cannot be both labelled and hidden'];
        yield 'role passed as an attribute' => ["html_icon('add', 'Add', array('role' => 'button'))", 'Icon add takes its role and aria-label from the label argument'];
        yield 'aria-label passed as an attribute' => ["html_icon('add', '', array('aria-hidden' => 'true', 'aria-label' => 'Add'))", 'Icon add takes its role and aria-label from the label argument'];
        yield 'attribute name that breaks out' => ["html_icon('add', 'Add', array('x onclick' => 'y'))", 'Icon add has an invalid attribute name'];
        yield 'numeric attribute key' => ["html_icon('add', 'Add', array('title'))", 'Icon add has an invalid attribute name'];
        yield 'blank label' => ["html_icon('add', '   ')", "Icon add needs a label, or aria-hidden='true' if it is decorative"];
        yield 'event attribute' => ["html_icon('add', 'Add', array('onclick' => 'alert(1)'))", 'Icon add has an invalid attribute name'];
        yield 'mixed-case event attribute' => ["html_icon('add', 'Add', array('onClick' => 'alert(1)'))", 'Icon add has an invalid attribute name'];
        yield 'unknown name' => ["html_icon('fa-plus', 'Add')", 'Unknown icon: fa-plus'];
    }

    #[DataProvider('refusals')]
    public function testRefusesIconsWithoutAnAccessibleContract(string $call, string $message): void
    {
        self::assertSame(
            'InvalidArgumentException: ' . $message,
            $this->call("(function () { try { return $call; } catch (InvalidArgumentException \$e) { return get_class(\$e) . ': ' . \$e->getMessage(); } })()")
        );
    }

    public function testTheHeaderHandsLayoutJsTheResolvedMap(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root . '/lib/html.php');
        self::assertIsString($source);

        // html_common_header() prints the map straight into an inline script.
        self::assertMatchesRegularExpression(
            '/var kadupulIcons=<\?php print json_encode\(html_icon_registry\(\)->forTheme\(\$selectedTheme\), JSON_HEX_TAG \| JSON_HEX_AMP \| JSON_HEX_APOS \| JSON_HEX_QUOT \| JSON_THROW_ON_ERROR\);\?>;/',
            test_php_function_source($source, 'html_common_header')
        );

        $map = json_decode($this->call("json_encode(html_icon_registry()->forTheme('sunrise'))"), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('fa fa-chevron-up', $map['import']);
        self::assertSame('fa fa-plus', $map['add']);
    }

    public function testMenuGlyphsTakeRegistryNamesAndPluginClasses(): void
    {
        $menu = <<<'PHP'
            (function () {
                global $menu_glyphs, $user_auth_realm_filenames;
                $user_auth_realm_filenames = array('graphs.php' => 8);
                $menu_glyphs = array('Create' => 'menu-create', 'Plugin' => 'fa fa-cube', 'Odd' => "x' onmouseover='y");
                ob_start();
                draw_menu(array(
                    'Create' => array('graphs.php' => 'New Graphs'),
                    'Plugin' => array('graphs.php' => 'Plugin Page'),
                    'Other' => array('graphs.php' => 'Other Page'),
                    'Odd' => array('graphs.php' => 'Odd Page'),
                ));
                preg_match_all('/<i class="menu_glyph ([^"]*)"><\/i>/', ob_get_clean(), $glyphs);
                return implode('|', $glyphs[1]);
            })()
            PHP;
        $stubs = 'function is_realm_allowed($realm) { return true; }'
            . 'function api_user_realm_auth($file) { return true; }'
            . 'function clean_up_name($name) { return $name; }'
            . 'function get_current_page() { return "index.php"; }'
            . 'function is_menu_pick_active($url) { return false; }';

        self::assertSame(
            'fa fa-chart-area|fa fa-cube|fa fa-folder|x&apos; onmouseover=&apos;y',
            $this->call($menu, 'modern', $stubs, array('draw_menu'))
        );
        self::assertSame(
            'fa fa-plus|fa fa-cube|far fa-folder|x&apos; onmouseover=&apos;y',
            $this->call($menu, 'midwinter', $stubs, array('draw_menu')),
            'midwinter redraws core menu glyphs but leaves plugin classes alone'
        );
    }

    /** @param list<string> $functions further lib/html.php functions the expression needs */
    private function call(string $expression, string $theme = 'modern', string $stubs = '', array $functions = array()): string
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root . '/lib/html.php');
        self::assertIsString($source);

        $script = 'require ' . var_export($root . '/include/vendor/autoload.php', true) . ';'
            . '$config = array("url_path" => "/kadupul/", "base_path" => ' . var_export($root, true) . ');'
            . 'function get_selected_theme() { return ' . var_export($theme, true) . '; }'
            . $stubs;
        foreach (array_merge(array('html_escape_charset', 'html_escape', 'html_icon_registry', 'html_icon_class', 'html_icon'), $functions) as $function) {
            $script .= "\n" . test_php_function_source($source, $function);
        }

        $pipes = array();
        $process = proc_open(
            array(PHP_BINARY, '-r', $script . "\nprint $expression;"),
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
}
