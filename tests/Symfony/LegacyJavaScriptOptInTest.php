<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/PhpSource.php';

/**
 * html_require_legacy_js() and the head slots of html_common_header(). The
 * production functions are extracted from lib/html.php and lib/functions.php
 * and executed against a fixture tree. Each test runs alone because the
 * registry is a function-level static.
 */
final class LegacyJavaScriptOptInTest extends TestCase
{
    private const array LEGACY = ['chart', 'dygraphs', 'sparkline', 'colorpicker'];

    private string $base;
    private string $logged = '';

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->base = sys_get_temp_dir() . '/kadupul-legacy-js-' . bin2hex(random_bytes(6));

        foreach ($this->expected('modern', self::LEGACY) as [, $path]) {
            $file = $this->base . '/' . $path;
            is_dir(dirname($file)) || mkdir(dirname($file), 0o777, true);
            file_put_contents($file, '/* ' . $path . " */\n");
        }

        $GLOBALS['config'] = ['base_path' => $this->base, 'url_path' => '/kadupul/', 'is_web' => false];
        $GLOBALS['path2calendar'] = 'include/js/calendar-lang.js';
        $GLOBALS['path2timepicker'] = 'include/js/timepicker-lang.js';
        $GLOBALS['path2colorpicker'] = 'include/js/colorpicker-lang.js';
        $GLOBALS['path2ms'] = 'include/js/ms-lang.js';
        $GLOBALS['path2msfilter'] = 'include/js/msfilter-lang.js';
        $GLOBALS['legacyJsLog'] = [];
        $GLOBALS['legacyJsRequest'] = [];
        chdir($this->base);

        eval(<<<'PHP'
            class CactiSecureHeaders
            {
                public static function getNonceAttribute()
                {
                    return 'nonce="fixture"';
                }
            }

            function db_table_exists($table)
            {
                return false;
            }

            function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = 1)
            {
                $GLOBALS['legacyJsLog'][] = [$message, $environ, $level];
            }

            function isset_request_var($name)
            {
                return isset($GLOBALS['legacyJsRequest'][$name]);
            }

            function get_nfilter_request_var($name)
            {
                return $GLOBALS['legacyJsRequest'][$name] ?? '';
            }

            function get_selected_theme()
            {
                return 'modern';
            }

            define('POLLER_VERBOSITY_DEBUG', 5);
            PHP);

        $functions = (string) file_get_contents($root . '/lib/functions.php');
        foreach (['get_md5_hash', 'get_include_relpath', 'get_compiled_asset_path', 'get_md5_include_js', 'get_md5_include_css'] as $name) {
            eval(test_php_function_source($functions, $name));
        }

        $html = (string) file_get_contents($root . '/lib/html.php');
        foreach (['html_page_chrome_requested', 'html_legacy_js_registry', 'html_legacy_js_global', 'html_legacy_js_state', 'html_legacy_js_tags', 'html_legacy_js_head', 'html_require_legacy_js'] as $name) {
            eval(test_php_function_source($html, $name));
        }

        // The include block of the production head, up to the plugin hook.
        $head = test_php_function_source($html, 'html_common_header');
        $start = strpos($head, "print get_md5_include_css('include/themes/' . \$selectedTheme . '/jquery.zoom.css');");
        $end = strpos($head, "api_plugin_hook('page_head');");
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        eval('function legacy_js_head_block($selectedTheme) { global $path2calendar, $path2timepicker, $path2ms, $path2msfilter;'
            . substr($head, $start, $end - $start) . '}');
    }

    protected function tearDown(): void
    {
        chdir(dirname(__DIR__, 2));
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->base);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHeadBlockKeepsItsOrderAsyncFlagsAndStylesheets(): void
    {
        self::assertSame($this->render('modern', self::LEGACY), $this->head());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRequestsDoNotAddOrRepeatTagsWhileLoadedGlobally(): void
    {
        $before = '';
        foreach (self::LEGACY as $name) {
            ob_start();
            html_require_legacy_js($name);
            $before .= ob_get_clean();
        }

        $head = $this->head();

        ob_start();
        foreach (self::LEGACY as $name) {
            html_require_legacy_js($name);
            html_require_legacy_js($name);
        }
        $after = ob_get_clean();

        self::assertSame('', $before . $after);
        self::assertSame($this->render('modern', self::LEGACY), $head);
        self::assertSame([], $GLOBALS['legacyJsLog']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnknownNamesEmitNothingAndAreLogged(): void
    {
        ob_start();
        foreach (['Chart', 'chart.js', '', "sparkline\n<script>", 'jquery'] as $name) {
            html_require_legacy_js($name);
        }
        $output = ob_get_clean();

        self::assertSame('', $output);
        self::assertSame($this->render('modern', self::LEGACY), $this->head());
        self::assertCount(5, $GLOBALS['legacyJsLog']);
        self::assertStringStartsWith('WARNING: Unknown legacy JavaScript library requested: ', $GLOBALS['legacyJsLog'][0][0]);
        self::assertSame(5, $GLOBALS['legacyJsLog'][0][2]);
        self::assertStringNotContainsString("\n", $GLOBALS['legacyJsLog'][3][0]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOnDemandHeadOmitsLibrariesNobodyRequested(): void
    {
        define('KADUPUL_LEGACY_JS_ON_DEMAND', true);

        self::assertSame($this->render('modern', []), $this->head());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOnDemandHeadEmitsRequestedLibrariesAtTheirUsualSlots(): void
    {
        define('KADUPUL_LEGACY_JS_ON_DEMAND', true);

        html_require_legacy_js('colorpicker');
        html_require_legacy_js('sparkline');
        html_require_legacy_js('colorpicker');
        html_require_legacy_js('unknown');

        self::assertSame($this->render('modern', ['sparkline', 'colorpicker']), $this->head());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOnDemandRequestAfterTheHeadPrintsOnceWithItsStylesheetAndLanguageFile(): void
    {
        define('KADUPUL_LEGACY_JS_ON_DEMAND', true);

        $this->head();

        ob_start();
        html_require_legacy_js('colorpicker');
        html_require_legacy_js('colorpicker');
        $late = ob_get_clean();

        $expected = '';
        foreach ($this->expected('modern', ['colorpicker']) as [$name, , $tag]) {
            $expected .= $name === 'colorpicker' ? $tag : '';
        }

        self::assertSame($expected, $late);
        self::assertSame(3, substr_count($late, PHP_EOL));
        self::assertStringContainsString('jquery.colorpicker.css', $late);
        self::assertStringContainsString('colorpicker-lang.js', $late);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOnDemandRequestForALibraryTheHeadAlreadyPrintedAddsNothing(): void
    {
        define('KADUPUL_LEGACY_JS_ON_DEMAND', true);

        html_require_legacy_js('chart');
        $this->head();

        ob_start();
        html_require_legacy_js('chart');
        self::assertSame('', ob_get_clean());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOnDemandPartialResponseEmitsAtTheCallSiteOnceWithTheNonce(): void
    {
        define('KADUPUL_LEGACY_JS_ON_DEMAND', true);
        $GLOBALS['legacyJsRequest'] = ['header' => 'false'];

        ob_start();
        html_require_legacy_js('sparkline');
        html_require_legacy_js('sparkline');
        $partial = ob_get_clean();

        $expected = '';
        foreach ($this->expected('modern', ['sparkline']) as [$name, , $tag]) {
            $expected .= $name === 'sparkline' ? $tag : '';
        }

        self::assertSame($expected, $partial);
        self::assertStringContainsString('nonce="fixture"', $partial);
        self::assertStringContainsString(' async></script>', $partial);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPartialResponseStaysEmptyWhileLoadedGlobally(): void
    {
        $GLOBALS['legacyJsRequest'] = ['header' => 'false'];

        ob_start();
        html_require_legacy_js('chart');

        self::assertSame('', ob_get_clean());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExplicitHeaderTrueWaitsForTheHeadSlot(): void
    {
        define('KADUPUL_LEGACY_JS_ON_DEMAND', true);
        $GLOBALS['legacyJsRequest'] = ['header' => 'true'];

        ob_start();
        html_require_legacy_js('chart');
        $before = ob_get_clean();

        self::assertSame('', $before);
        self::assertSame($this->render('modern', ['chart']), $this->head());
    }

    private function head(): string
    {
        ob_start();
        legacy_js_head_block('modern');

        return (string) ob_get_clean();
    }

    /** Expected head output with only $libraries present among the four legacy ones. */
    private function render(string $theme, array $libraries): string
    {
        $out = '';
        foreach ($this->expected($theme, $libraries) as [, , $tag]) {
            $out .= $tag;
        }

        return $out;
    }

    /**
     * The head include block as it stood before the registry, in order, as
     * [library or null, path, tag]. Tags are written out here rather than built
     * with the production helpers so a reordered or re-flagged slot fails.
     *
     * @return list<array{0: ?string, 1: string, 2: string}>
     */
    private function expected(string $theme, array $libraries): array
    {
        $css = static fn(string $path): string => "<link href='/kadupul/" . $path . '?' . md5('/* ' . $path . " */\n") . "' type='text/css' rel='stylesheet'>" . PHP_EOL;
        $js = static fn(string $path, bool $async): string => "<script type='text/javascript' nonce=\"fixture\" src='/kadupul/" . $path . '?' . md5('/* ' . $path . " */\n") . "'" . ($async ? ' async' : '') . '></script>' . PHP_EOL;
        $t = 'include/themes/' . $theme . '/';

        $entries = [
            [null, $t . 'jquery.zoom.css', 'css'],
            [null, $t . 'jquery-ui.css', 'css'],
            [null, $t . 'default/style.css', 'css'],
            [null, $t . 'jquery.multiselect.css', 'css'],
            [null, $t . 'jquery.multiselect.filter.css', 'css'],
            [null, $t . 'jquery.timepicker.css', 'css'],
            ['colorpicker', $t . 'jquery.colorpicker.css', 'css'],
            [null, $t . 'billboard.css', 'css'],
            [null, $t . 'pace.css', 'css'],
            [null, $t . 'Diff.css', 'css'],
            [null, 'include/fa/css/all.css', 'css'],
            [null, 'include/vendor/flag-icons/css/flag-icons.css', 'css'],
            [null, $t . 'main.css', 'css'],
            [null, 'include/js/screenfull.js', 'sync'],
            [null, 'include/js/jquery.js', 'sync'],
            [null, 'include/js/jquery-migrate.js', 'sync'],
            [null, 'include/js/jquery-ui.js', 'sync'],
            [null, 'include/js/jquery.ui.touch.punch.js', 'async'],
            [null, 'include/js/jquery.cookie.js', 'sync'],
            [null, 'include/js/js.storage.js', 'sync'],
            [null, 'include/js/jstree.js', 'sync'],
            [null, 'include/js/jquery.hotkeys.js', 'async'],
            [null, 'include/js/jquery.tablednd.js', 'async'],
            [null, 'include/js/jquery.zoom.js', 'async'],
            [null, 'include/js/jquery.multiselect.js', 'sync'],
            [null, 'include/js/jquery.multiselect.filter.js', 'sync'],
            [null, 'include/js/jquery.timepicker.js', 'sync'],
            ['colorpicker', 'include/js/jquery.colorpicker.js', 'async'],
            [null, 'include/js/jquery.tablesorter.js', 'sync'],
            [null, 'include/js/jquery.tablesorter.widgets.js', 'async'],
            [null, 'include/js/jquery.tablesorter.pager.js', 'async'],
            ['sparkline', 'include/js/jquery.sparkline.js', 'async'],
            ['chart', 'include/js/Chart.js', 'async'],
            ['dygraphs', 'include/js/dygraph-combined.js', 'async'],
            [null, 'include/js/d3.js', 'sync'],
            [null, 'include/js/billboard.js', 'sync'],
            [null, 'include/layout.js', 'sync'],
            [null, 'include/js/pace.js', 'sync'],
            [null, 'include/js/purify.js', 'sync'],
            [null, 'include/realtime.js', 'sync'],
            [null, $t . 'main.js', 'sync'],
            [null, 'include/js/calendar-lang.js', 'sync'],
            [null, 'include/js/timepicker-lang.js', 'sync'],
            ['colorpicker', 'include/js/colorpicker-lang.js', 'sync'],
            [null, 'include/js/ms-lang.js', 'sync'],
            [null, 'include/js/msfilter-lang.js', 'sync'],
        ];

        $result = [];
        foreach ($entries as [$name, $path, $kind]) {
            if ($name !== null && !in_array($name, $libraries, true)) {
                continue;
            }
            $result[] = [$name, $path, $kind === 'css' ? $css($path) : $js($path, $kind === 'async')];
        }

        return $result;
    }
}
