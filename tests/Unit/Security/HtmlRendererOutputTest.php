<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\Security\HtmlRendererOutput;

use DOMDocument;
use DOMXPath;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/Helpers/NativeChildCoverageEvidence.php';

function rendererCoverageSources(bool $automation = false): array
{
    $sources = array(
        'lib/html.php', 'tests/Fixtures/rrd-process-coverage.php',
        'tests/Helpers/NativeChildCoverageEvidence.php', 'composer.lock', 'tests/composer.lock',
        'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php',
        'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php',
        'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php',
    );
    return $automation ? array_merge($sources, array('tests/Fixtures/automation-header-native.php', 'lib/api_automation.php', 'include/global_constants.php')) : $sources;
}

/** Mutate only the owned authentic report and a temporary source, restoring all artifacts. */
function verifyRendererEvidenceFailures(string $report, string $root, string $scenario, array $sources, array $markers, array $hits): void
{
    $originalReport = file_get_contents($report);
    $originalEvidence = file_get_contents($report . '.json');
    $load = fn() => \NativeChildCoverageEvidence::load($report, $root, 'tests/Unit/Security/HtmlRendererOutputTest.php', $scenario, $sources, $markers, $hits);
    $temporarySource = dirname($report) . '/source.php';
    try {
        $changed = json_decode($originalEvidence, true, 512, JSON_THROW_ON_ERROR);
        $changed['sources']['lib/html.php'] = str_repeat('0', 64);
        file_put_contents($report . '.json', json_encode($changed, JSON_THROW_ON_ERROR));
        expect($load)->toThrow(RuntimeException::class, 'source is missing or stale');
        file_put_contents($report . '.json', $originalEvidence);

        unlink($report . '.json');
        expect($load)->toThrow(RuntimeException::class, 'report or evidence is missing');
        file_put_contents($report . '.json', $originalEvidence);

        file_put_contents($report, '');
        clearstatcache(true, $report);
        expect($load)->toThrow(RuntimeException::class, 'report or evidence is missing');
        file_put_contents($report, $originalReport . 'changed');
        clearstatcache(true, $report);
        expect($load)->toThrow(RuntimeException::class, 'identity or report digest is stale');
        file_put_contents($report, $originalReport);

        // A real source change after snapshot must fail at publication, before any merge.
        file_put_contents($temporarySource, '<?php // Original owned source.');
        $snapshot = \NativeChildCoverageEvidence::snapshot(dirname($report), 'source.php', $scenario, array());
        file_put_contents($temporarySource, '<?php // Changed owned source.');
        expect(fn() => \NativeChildCoverageEvidence::write($report, dirname($report), $snapshot, $markers))
            ->toThrow(RuntimeException::class, 'source is missing or stale');
    } finally {
        file_put_contents($report, $originalReport);
        file_put_contents($report . '.json', $originalEvidence);
        if (is_file($temporarySource)) {
            unlink($temporarySource);
        }
    }
}

const PAYLOADS = array(
    'quote-breakout' => '\'" onmouseover="alert(1)" x=\'',
    'element-breakout' => '\'><img src=x onerror=alert(1)><script>alert(2)</script>',
    'grave-accent' => "a`b",
    'entities' => '&#39;&quot;&amp;',
);

/*
 * Render with the real lib/html.php in a child process, so the stubs below
 * never collide with functions other test files declare in the parent.
 */
function render(string $call, array $arguments, ?object $coverage): string
{
    $root = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir() . '/html-renderer-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $program = <<<'PHP'
        $a = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
        $GLOBALS['config'] = array('url_path' => $a['url_path'] ?? '/', 'poller_id' => 1);
        $GLOBALS['settings'] = array('spikes' => array(
            'spikekill_deviations' => array('array' => array()),
            'spikekill_number' => array('array' => array()),
        ));
        function __($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
        function __esc($text, ...$args) { return html_escape(__($text, ...$args)); }
        function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }
        function cacti_count($value) { return cacti_sizeof($value); }
        function read_user_setting($name, $default = false, $force = false) { return $GLOBALS['a']['user'][$name] ?? $default; }
        function read_config_option($name) { return $GLOBALS['a']['option'][$name] ?? ''; }
        function is_realm_allowed($realm) { return true; }
        function get_current_graph_start() { return -86400; }
        function get_current_graph_end() { return 0; }
        function get_current_page($basename = true) { return $GLOBALS['a']['page'] ?? 'graphs.php'; }
        function api_plugin_hook_function($name, $value = null) { return $value; }
        function aggregate_build_children_url($id) { return ''; }
        function db_fetch_cell_prepared($sql, $args) { return $GLOBALS['a']['cell'] ?? 0; }
        function api_plugin_hook($name, $args = array()) {}
        function isset_request_var($name) { return array_key_exists($name, $GLOBALS['a']['request'] ?? array()); }
        function isempty_request_var($name) { return true; }
        function get_nfilter_request_var($name) { return $GLOBALS['a']['request'][$name] ?? ''; }
        function get_request_var($name) { return $GLOBALS['a']['request'][$name] ?? ''; }
        function clean_up_name($name) { return $name; }
        class CactiSecureHeaders { public static function getNonceAttribute() { return 'nonce="fixture"'; } }
        $_SERVER['SCRIPT_NAME'] = '/graphs.php';
        $_SESSION = $a['session'] ?? array();
        require $argv[1] . '/lib/html.php';
        PHP;
    $program .= "\n" . $call;
    $scenario = json_encode(array($call, $arguments), JSON_THROW_ON_ERROR);
    $automation = $arguments['automation'] ?? false;
    $sources = rendererCoverageSources($automation);
    $markers = array('renderer-call-completed', 'renderer-html-produced');
    $hits = array('lib/html.php');
    if ($automation) {
        $markers[] = 'automation-stored-label-rendered';
        $hits[] = 'lib/api_automation.php';
    }
    if ($coverage !== null) {
        // The child registers its producer and sources before any measured execution.
        $program = 'define("HTML_RENDERER_TEST_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($directory, true) . ');'
            . 'require ' . var_export($root . '/tests/Helpers/NativeChildCoverageEvidence.php', true) . ';'
            . '$GLOBALS["nativeChildCoverageSnapshot"] = NativeChildCoverageEvidence::snapshot($argv[1],'
            . '"tests/Unit/Security/HtmlRendererOutputTest.php", $argv[3], ' . var_export($sources, true) . ');'
            . ($automation ? 'define("HTML_AUTOMATION_LABEL_TEST_COVERAGE",true);' : '')
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';'
            . 'ob_start();' . $program
            . '$GLOBALS["nativeChildCoverageMarkers"][] = "renderer-call-completed";'
            . 'if (strlen(ob_get_contents()) > 0) { $GLOBALS["nativeChildCoverageMarkers"][] = "renderer-html-produced"; }'
            . 'ob_end_flush();';
    }

    try {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('Unable to start native HTML renderer: proc_open is unavailable.');
        }
        $pipes = array();
        $process = proc_open(
            array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $program, $root, json_encode($arguments, JSON_THROW_ON_ERROR), $scenario),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $directory
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start native HTML renderer.');
        }
        $html = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $errors !== '') {
            throw new RuntimeException($errors . $html);
        }
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            if ($reports === false || count($reports) !== 1) {
                throw new RuntimeException('Native renderer coverage report is missing or ambiguous.');
            }
            $report = $reports[0];
            $child = \NativeChildCoverageEvidence::load($report, $root, 'tests/Unit/Security/HtmlRendererOutputTest.php', $scenario, $sources, $markers, $hits);
            static $evidenceChecked = array();
            $evidenceKind = $automation ? 'automation' : 'renderer';
            if (!isset($evidenceChecked[$evidenceKind])) {
                expect(\NativeChildCoverageEvidence::verifyRejections($report, $root, 'tests/Unit/Security/HtmlRendererOutputTest.php', $scenario, $sources, $markers, $hits, 'tests/Helpers/NativeChildCoverageEvidence.php'))->toBe(count($sources) + count($markers) + 10);
                verifyRendererEvidenceFailures($report, $root, $scenario, $sources, $markers, $hits);
                $evidenceChecked[$evidenceKind] = true;
            }
            $coverage->merge($child);
        }
    } finally {
        foreach (glob($directory . '/*') ?: array() as $report) {
            unlink($report);
        }
        rmdir($directory);
    }

    return $html;
}

function document(string $html): DOMXPath
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        $document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>');
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    return new DOMXPath($document);
}

/* html_escape() leaves existing entities alone, so a pre-escaped value is not encoded twice. */
function decoded(string $value): string
{
    return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/* The payload must stay inside the value it was written to: no new element, no event handler. */
function expectNoInjection(DOMXPath $xpath, int $handlers = 0): void
{
    expect($xpath->query('//script[contains(., "alert")]|//img[@src="x"]')->length)->toBe(0);
    expect($xpath->query('//@*[starts-with(name(), "on")]')->length)->toBe($handlers);
    expect($xpath->query('//@x')->length)->toBe(0);
}

test('graph drill-down icons keep identifiers numeric and the realtime popup inside its JavaScript strings', function ($payload) {
    $html = render(
        'graph_drilldown_icons($a["id"], "graph_buttons", 3, 4);',
        array('id' => '7' . $payload, 'url_path' => '/k' . $payload . '/', 'cell' => '5' . $payload,
            'option' => array('realtime_enabled' => 'on'), 'user' => array('realtime_mode' => '2')),
        $this->getTestResultObject()->getCodeCoverage()
    );
    $xpath = document($html);

    expectNoInjection($xpath, 1);
    expect($xpath->query('//a[@class="iconLink utils"]')->item(0)->getAttribute('id'))->toBe('graph_7_util');
    expect($xpath->query('//span[@class="iconLink spikekill"]')->item(0)->getAttribute('data-graph'))->toBe('7');
    expect($xpath->query('//img[@id="de5_0"]')->length)->toBe(1);
    foreach ($xpath->query('//img[@class="drillDown"]') as $image) {
        expect($image->getAttribute('src'))->toStartWith(decoded('/k' . $payload . '/images/'));
    }

    $handler = $xpath->query('//@onclick')->item(0)->value;
    expect(preg_match('/^window\.open\(("(?:\\\\.|[^"\\\\])*"), ("(?:\\\\.|[^"\\\\])*"), \'[a-z=,0-9]+\'\);return false$/', $handler, $arguments))->toBe(1);
    expect(json_decode($arguments[1], true, 512, JSON_THROW_ON_ERROR))->toBe('/k' . $payload . '/graph_realtime.php?top=0&left=0&local_graph_id=7');
    expect(json_decode($arguments[2], true, 512, JSON_THROW_ON_ERROR))->toBe('popup_7');
    expect($arguments[1] . $arguments[2])->not->toContain('<', '>', '&', "'");
})->with(PAYLOADS);

test('renderer coverage rejects a child that exits before completing its rendering call', function () {
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    if ($coverage === null) {
        $this->markTestSkipped('This regression requires actual child-process coverage.');
    }
    expect(fn() => render('exit(0);', array(), $coverage))
        ->toThrow(RuntimeException::class, 'completion marker is missing');
});

test('renderer coverage rejects a completed call that produces no HTML', function () {
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    if ($coverage === null) {
        $this->markTestSkipped('This regression requires actual child-process coverage.');
    }
    expect(fn() => render('html_escape("plain text");', array(), $coverage))
        ->toThrow(RuntimeException::class, 'completion marker is missing');
});

test('renderer coverage rejects an absent child report instead of merging zero reports', function () {
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    if ($coverage === null) {
        $this->markTestSkipped('This regression requires actual child-process coverage.');
    }
    $call = 'html_section_header("Rendered before report loss");'
        . 'register_shutdown_function(function () { register_shutdown_function(function () {'
        . 'foreach (glob(RRD_TEST_COVERAGE_DIRECTORY . "/*.coverage*") as $report) { unlink($report); }'
        . '}); });';
    expect(fn() => render($call, array(), $coverage))
        ->toThrow(RuntimeException::class, 'coverage report is missing or ambiguous');
});

test('graph areas keep graph values inside their attributes and text', function ($renderer, $payload) {
    $graph = array('local_graph_id' => '9' . $payload, 'host_id' => 1, 'disabled' => '', 'width' => '500' . $payload,
        'height' => '120' . $payload, 'title_cache' => 'Title' . $payload, 'data_query_name' => 'Query' . $payload);
    $html = render(
        '$graphs = array($a["graph"]); ' . $renderer . '($graphs, "", "", "", $a["columns"]);',
        array('graph' => $graph, 'columns' => 1, 'user' => array(
            'show_graph_title' => 'on', 'custom_fonts' => 'on', 'title_size' => '10' . $payload,
            'default_width' => '300' . $payload, 'default_height' => '90' . $payload, 'page_refresh' => 300,
        )),
        $this->getTestResultObject()->getCodeCoverage()
    );
    $xpath = document($html);

    expectNoInjection($xpath);
    $wrapper = $xpath->query('//div[contains(@class, "graphWrapper")]')->item(0);
    expect($wrapper->getAttribute('id'))->toBe(decoded('wrapper_9' . $payload));
    expect($xpath->query('//td[contains(@class, "graphDrillDown")]')->item(0)->getAttribute('id'))->toBe(decoded('dd9' . $payload));
    expect($xpath->query('//span[@class="center"]')->item(0)->textContent)->toBe(decoded('Title' . $payload));
    if ($renderer === 'html_graph_area') {
        expect($wrapper->getAttribute('graph_width'))->toBe(decoded('500' . $payload));
        expect($wrapper->getAttribute('graph_height'))->toBe(decoded('120' . $payload));
        expect($wrapper->getAttribute('title_font_size'))->toBe(decoded('10' . $payload));
    } else {
        expect($wrapper->getAttribute('graph_width'))->toBe(decoded('300' . $payload));
        expect($wrapper->getAttribute('graph_height'))->toBe(decoded('90' . $payload));
        expect($xpath->query('//td[contains(@class, "graphSubHeaderColumn")]')->item(0)->textContent)->toBe(decoded('Data Query: Query' . $payload));
    }
})->with(array('html_graph_area', 'html_graph_thumbnail_area'))->with(PAYLOADS);

test('start boxes keep caller values inside their attributes', function ($payload) {
    $html = render(
        'html_start_box("Title", "100%" . $a["p"], false, "3" . $a["p"], "center" . $a["p"], array('
        . 'array("id" => "add" . $a["p"], "class" => "fa fa-plus" . $a["p"], "href" => "x.php?a=1" . $a["p"], "title" => "Add" . $a["p"])));'
        . 'html_end_box(false);',
        array('p' => $payload, 'page' => 'page' . $payload . '.php'),
        $this->getTestResultObject()->getCodeCoverage()
    );
    $xpath = document($html);

    expectNoInjection($xpath);
    $value = fn(string $query) => $xpath->query($query)->item(0)?->nodeValue;
    expect($value('//div[contains(@class, "cactiTable")]/@id'))->toBe(decoded(basename('page' . $payload . '.php', '.php') . '1'));
    expect($value('//div[contains(@class, "cactiTable")]/@style'))->toBe(decoded('width:100%' . $payload . ';text-align:center' . $payload . ';'));
    expect($value('//table[contains(@class, "cactiTable")]/@style'))->toBe(decoded('padding:3' . $payload . 'px;'));
    expect($value('//span[@class="cactiFilterAdd"]/a/@id'))->toBe(decoded('add' . $payload));
    expect($value('//span[@class="cactiFilterAdd"]/a/@href'))->toBe(decoded('x.php?a=1' . $payload));
    expect($value('//span[@class="cactiFilterAdd"]//i/@class'))->toBe(decoded('fa fa-plus' . $payload));
})->with(PAYLOADS);

test('table headers keep caller values inside their attributes', function ($payload) {
    $html = render(
        'print "<table>";'
        . 'html_header_sort(array("nosort" => array("display" => "Other", "align" => "right" . $a["p"]),'
        . ' "name" . $a["p"] => array("display" => "Name", "align" => "left" . $a["p"], "sort" => "ASC" . $a["p"])), "", "", "2" . $a["p"], "sort.php?x=1" . $a["p"], "main" . $a["p"]);'
        . 'html_header_sort_checkbox(array("host" . $a["p"] => array("display" => "Host", "align" => "center" . $a["p"])), "", "", true, "form.php" . $a["p"], "", "chk" . $a["p"]);'
        . 'html_header(array(array("display" => "Head", "align" => "left" . $a["p"]), "Tail"), "3" . $a["p"]);'
        . 'html_section_header(array("display" => "Section", "align" => "left" . $a["p"]), "4" . $a["p"]);'
        . 'html_header_checkbox(array(array("display" => "Box", "align" => "right" . $a["p"])), true, "check.php" . $a["p"], true, "pre" . $a["p"]);'
        . 'print "</table>";',
        array('p' => $payload),
        $this->getTestResultObject()->getCodeCoverage()
    );
    $xpath = document($html);

    expectNoInjection($xpath);
    $value = fn(string $query) => $xpath->query($query)->item(0)?->nodeValue;
    $sort = $xpath->query('//div[@class="sortinfo"]')->item(0);
    expect($sort->getAttribute('sort-return'))->toBe(decoded('main' . $payload));
    expect($sort->getAttribute('sort-page'))->toBe(decoded('sort.php?x=1' . $payload));
    expect($sort->getAttribute('sort-column'))->toBe(decoded('name' . $payload));
    expect($sort->getAttribute('sort-direction'))->toBe(decoded('ASC' . $payload));
    expect($value('//th[contains(@class, "sortable")]/@class'))->toContain(decoded('left' . $payload));
    expect($value('//th[text()="Other"]/@colspan'))->toBe(decoded('2' . $payload));

    $checkboxSort = $xpath->query('//div[@class="sortinfo"]')->item(1);
    expect($checkboxSort->getAttribute('sort-page'))->toBe(decoded('form.php' . $payload));
    expect($checkboxSort->getAttribute('sort-column'))->toBe(decoded('host' . $payload));
    expect($value('(//th[.//div[@sort-column]])[2]/@class'))->toContain(decoded('center' . $payload));

    expect($value('//th[text()="Head"]/@class'))->toBe(decoded(' left' . $payload));
    expect($value('//th[text()="Tail"]/@colspan'))->toBe(decoded('3' . $payload));
    expect($value('//th[text()="Section"]/@style'))->toBe(decoded('text-align:left' . $payload . ';'));
    expect($value('//th[text()="Section"]/@colspan'))->toBe(decoded('4' . $payload));
    expect($value('//th[text()="Box"]/@class'))->toBe(decoded('right' . $payload . ' '));

    $forms = $xpath->query('//form');
    expect($forms->length)->toBe(2);
    expect($forms->item(0)->getAttribute('id'))->toBe(decoded('chk' . $payload));
    expect($forms->item(0)->getAttribute('action'))->toBe(decoded('form.php' . $payload));
    expect($forms->item(1)->getAttribute('name'))->toBe(decoded('pre' . $payload));
    expect($forms->item(1)->getAttribute('action'))->toBe(decoded('check.php' . $payload));
    $prefixes = array();
    foreach ($xpath->query('//input[@id="selectall"]') as $input) {
        $prefixes[] = $input->getAttribute('data-prefix');
    }
    expect($prefixes)->toBe(array(decoded('chk' . $payload), decoded('pre' . $payload)));
})->with(PAYLOADS);

test('spike removal menu items keep caller values inside their attributes', function ($payload) {
    $html = render(
        'print html_spikekill_menu_item("Remove", "fa fa-check" . $a["p"], "rstddev" . $a["p"], "method" . $a["p"], "12" . $a["p"]);',
        array('p' => $payload),
        $this->getTestResultObject()->getCodeCoverage()
    );
    $xpath = document($html);

    expectNoInjection($xpath);
    $item = $xpath->query('//li')->item(0);
    expect($item->getAttribute('id'))->toBe(decoded('method' . $payload));
    expect($item->getAttribute('data-graph'))->toBe(decoded('12' . $payload));
    expect($item->getAttribute('class'))->toBe(decoded(' rstddev' . $payload));
    expect($xpath->query('//li//i')->item(0)->getAttribute('class'))->toBe(decoded('fa fa-check' . $payload));
    expect($xpath->query('//span[@class="spikeKillMenuItem"]')->item(0)->textContent)->toBe('Remove');
})->with(PAYLOADS);

test('section headers render array and scalar labels as text', function ($payload) {
    $html = render(
        'print "<table>";'
        . 'html_section_header(array("display" => "Array " . $a["p"], "align" => "left"), 2);'
        . 'html_section_header("Scalar " . $a["p"], 3);'
        . 'print "</table>";',
        array('p' => $payload),
        $this->getTestResultObject()->getCodeCoverage()
    );
    $xpath = document($html);
    expectNoInjection($xpath);
    $headers = $xpath->query('//th');
    expect($headers->length)->toBe(2);
    expect($headers->item(0)->textContent)->toBe(decoded('Array ' . $payload));
    expect($headers->item(1)->textContent)->toBe(decoded('Scalar ' . $payload));
    expect($headers->item(0)->getAttribute('colspan'))->toBe('2');
    expect($headers->item(1)->getAttribute('colspan'))->toBe('3');
})->with(PAYLOADS);

test('spike menu labels stay text while nested menu markup remains functional', function ($payload) {
    $html = render(
        '$child = html_spikekill_menu_item("Child &amp; label", "fa fa-check", "child", "child");'
        . 'print html_spikekill_menu_item("Parent " . $a["p"], "fa fa-cog", "parent", "parent", "", $child);',
        array('p' => $payload),
        $this->getTestResultObject()->getCodeCoverage()
    );
    $xpath = document($html);
    expectNoInjection($xpath);
    expect($xpath->query('//li[@id="parent"]/span')->item(0)->textContent)->toBe(decoded('Parent ' . $payload));
    expect($xpath->query('//li[@id="parent"]/ul/li[@id="child"]')->length)->toBe(1);
    expect($xpath->query('//li[@id="child"]/span')->item(0)->textContent)->toBe('Child & label');
    expect($xpath->query('//li[@id="child"]/span/i')->item(0)->getAttribute('class'))->toBe('fa fa-check');
})->with(PAYLOADS);


test('HTML escaping preserves null scalar and existing entity contracts', function () {
    $values = array(null, false, true, 0, 1.5, '', 'plain', '&amp;&#39;&quot;', "a`b", "<tag>\"'");
    $output = render(
        'print json_encode(array_map("html_escape", $a["values"]), JSON_THROW_ON_ERROR);',
        array('values' => $values),
        $this->getTestResultObject()->getCodeCoverage()
    );
    expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))
        ->toBe(array(null, '', '1', '0', '1.5', '', 'plain', '&amp;&#39;&quot;', 'a&#96;b', '&lt;tag&gt;&quot;&apos;'));
});

test('both sortable headers restore cached order and retain independent page counters', function () {
    $call = <<<'PHP'
        $headers = array('name' => array('display' => '<strong>Name</strong>', 'sort' => 'ASC'),
            'second' => array('Second', 'DESC'), 'nosort' => array('display' => 'Fixed'));
        ob_start();
        print '<table>';
        html_header_sort($headers, 'name', 'ASC');
        html_header_sort_checkbox($headers, 'name', 'ASC');
        html_header_sort($headers, '', 'DESC');
        html_header_sort_checkbox($headers, '', 'DESC');
        print '</table>';
        $html = ob_get_clean();
        print json_encode(array('html' => $html, 'session' => $_SESSION), JSON_THROW_ON_ERROR);
        PHP;
    $result = json_decode(render(
        $call,
        array('request' => array('action' => 'edit', 'tab' => 'general'),
            'session' => array('sort_data' => array('0_graphs_edit_general' => array('second' => 'DESC', 'name' => 'ASC')))),
        $this->getTestResultObject()->getCodeCoverage()
    ), true, 512, JSON_THROW_ON_ERROR);
    expect($result['session']['valid_sort_columns'])->toBe(array(
        '0_graphs_edit_general' => array('name', 'second'), '1_graphs_edit_general' => array('name', 'second')));
    $xpath = document($result['html']);
    expectNoInjection($xpath);
    expect($xpath->query('//tr'))->toHaveCount(4)
        ->and($xpath->query('//strong'))->toHaveCount(0)
        ->and($xpath->query('//div[@class="textSubHeaderDark"][starts-with(text(),"<strong>Name</strong>")]'))->toHaveCount(4)
        ->and($xpath->query('//div[@class="sortinfo"]'))->toHaveCount(8);
    foreach (array(1, 2) as $row) {
        expect($xpath->evaluate('string(//tr[' . $row . ']/th[1]/@class)'))->toContain('secondarySort')
            ->and($xpath->evaluate('string(//tr[' . $row . ']/th[2]/@class)'))->toContain('primarySort')
            ->and($xpath->evaluate('string(//tr[' . $row . ']/th[1]/div/@sort-direction)'))->toBe('DESC')
            ->and($xpath->evaluate('string(//tr[' . $row . ']/th[2]/div/@sort-direction)'))->toBe('ASC')
            ->and($xpath->evaluate('string(//tr[' . $row . ']/th[1]/div/@sort-page)'))->toBe('graphs.php');
    }
});

test('sortable header metadata preserves primary secondary and default column choices', function (array $ordering, string $selected, string $direction, string $nameDirection, string $secondDirection) {
    $call = <<<'CHILD'
        $headers = array('name' => array('display' => 'Name', 'align' => 'center', 'tip' => 'Name hint', 'nohide' => true),
            'second' => array('Second', 'DESC'));
        print '<table>';
        html_header_sort($headers, $a['selected'], $a['direction']);
        html_header_sort_checkbox($headers, $a['selected'], $a['direction']);
        print '</table>';
        CHILD;
    $arguments = array('selected' => $selected, 'direction' => $direction);
    if ($ordering !== array()) {
        $arguments['session'] = array('sort_data' => array('0_graphs' => $ordering));
    }
    $xpath = document(render($call, $arguments, $this->getTestResultObject()->getCodeCoverage()));
    expectNoInjection($xpath);
    foreach (array(1, 2) as $row) {
        expect($xpath->evaluate('string(//tr[' . $row . ']/th[1]/@title)'))->toBe('Name hint')
            ->and($xpath->evaluate('string(//tr[' . $row . ']/th[1]/@class)'))->toContain('nohide')
            ->and($xpath->evaluate('string(//tr[' . $row . ']/th[1]/div/@sort-direction)'))->toBe($nameDirection)
            ->and($xpath->evaluate('string(//tr[' . $row . ']/th[2]/div/@sort-direction)'))->toBe($secondDirection);
    }
})->with(array(
    'named primary selected' => array(array('name' => 'ASC', 'second' => 'DESC'), 'name', 'ASC', 'DESC', 'ASC'),
    'cached named primary and legacy secondary' => array(array('name' => 'ASC', 'second' => 'DESC'), '', 'DESC', 'DESC', 'ASC'),
    'legacy secondary selected' => array(array('name' => 'ASC', 'second' => 'DESC'), 'second', 'DESC', 'DESC', 'ASC'),
    'legacy primary selected and named default' => array(array(), 'second', 'ASC', 'ASC', 'DESC'),
));


test('cached empty sort order renders both header layouts without warnings', function () {
    $call = <<<'CHILD'
        set_error_handler(static function ($severity, $message, $file, $line) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        $headers = array('name' => array('display' => 'Name'), 'second' => array('Second', 'DESC'));
        print '<table>';
        html_header_sort($headers, '', 'ASC');
        html_header_sort_checkbox($headers, '', 'ASC');
        print '</table>';
        restore_error_handler();
        CHILD;
    $xpath = document(render(
        $call,
        array('session' => array('sort_data' => array('0_graphs' => array()))),
        $this->getTestResultObject()->getCodeCoverage()
    ));
    expectNoInjection($xpath);
    expect($xpath->query('//div[@class="sortinfo"]'))->toHaveCount(4)
        ->and($xpath->query('//th[contains(@class,"primarySort") or contains(@class,"secondarySort")]'))->toHaveCount(0);
    foreach (array(1, 2) as $row) {
        expect($xpath->evaluate('string(//tr[' . $row . ']/th[1]/div/@sort-direction)'))->toBe('ASC')
            ->and($xpath->evaluate('string(//tr[' . $row . ']/th[2]/div/@sort-direction)'))->toBe('DESC');
    }
});


test('sortable and fixed header labels are text in both layouts', function ($payload) {
    $call = <<<'CHILD'
        $headers = array('name' => array('display' => $a['label'], 'align' => 'center', 'tip' => 'Header hint'),
            'second' => array($a['label'], 'DESC'), 'nosort' => array('display' => $a['label']),
            'nosort2' => array($a['label'], 'ASC'));
        print '<table>';
        html_header_sort($headers, 'name', 'ASC', 2, 'graphs.php?action=edit&id=1', 'main');
        html_header_sort_checkbox($headers, 'name', 'ASC', true, 'graphs.php?action=edit&id=1', 'main');
        print '</table>';
        CHILD;
    $xpath = document(render($call, array('label' => $payload), $this->getTestResultObject()->getCodeCoverage()));
    expectNoInjection($xpath);
    expect($xpath->query('//tr'))->toHaveCount(2)->and($xpath->query('//div[@class="sortinfo"]'))->toHaveCount(4);
    foreach (array(1, 2) as $row) {
        foreach (array(1, 2, 3, 4) as $column) {
            expect($xpath->evaluate('string((//tr[' . $row . ']/th[' . $column . ']//text())[1])'))->toBe(decoded($payload));
        }
        $sort = $xpath->query('//tr[' . $row . ']/th[1]/div')->item(0);
        expect($sort->getAttribute('sort-page'))->toBe('graphs.php?action=edit&id=1')
            ->and($sort->getAttribute('sort-column'))->toBe('name')->and($sort->getAttribute('sort-direction'))->toBe('DESC');
    }
    expect($xpath->query('//i[contains(@class,"fa-sort")]'))->toHaveCount(4);
})->with(PAYLOADS);

test('matching-tree headers encode the actual persisted automation field', function ($label) {
    $html = render(
        'require $argv[1] . "/tests/Fixtures/automation-header-native.php";',
        array('label' => $label, 'automation' => true),
        $this->getTestResultObject()->getCodeCoverage()
    );
    $xpath = document($html);
    expectNoInjection($xpath);
    expect($xpath->query('//div[@sort-column="source"]'))->toHaveCount(1)
        ->and($xpath->evaluate('string((//div[@sort-column="source"]//text())[1])'))->toBe(decoded($label))
        ->and($xpath->evaluate('string(//div[@sort-column="source"]/@sort-page)'))->toContain('automation_tree_rules.php?action=item_edit&id=1')
        ->and($xpath->query('//select[@id="host_template_id"]'))->toHaveCount(1)
        ->and($xpath->query('//select[@id="host_status"]'))->toHaveCount(1);
})->with(array('stored markup' => array('<img src=x onerror=alert(1)>'), 'valid producer' => array('h.hostname'), 'existing entities' => array('Host &amp; label')));


test('explicit header encoding preserves cached charset entities and scalar labels', function (string $initial, string $later, string $byte) {
    $call = <<<'CHILD'
        ini_set('default_charset', $a['initial']);
        $value = hex2bin($a['byte']) . ' &amp;&apos;&#96; "\'';
        $expected = html_escape($value);
        ini_set('default_charset', $a['later']);
        $headers = array('name' => array('display' => $value, 'align' => 'left', 'tip' => $value),
            'nosort' => array('display' => $value, 'tip' => $value));
        ob_start();
        html_header_sort($headers, 'name', 'ASC', 1, $value, $value);
        html_header_sort_checkbox($headers, 'name', 'ASC', true, $value, $value, $value);
        $html = ob_get_clean();
        print json_encode(array('html' => bin2hex($html), 'expected' => bin2hex($expected),
            'charset' => html_escape_charset(), 'null' => html_escape(null)), JSON_THROW_ON_ERROR);
        CHILD;
    $result = json_decode(render(
        $call,
        array('initial' => $initial, 'later' => $later, 'byte' => $byte),
        $this->getTestResultObject()->getCodeCoverage()
    ), true, 512, JSON_THROW_ON_ERROR);
    $html = hex2bin($result['html']);
    $expected = hex2bin($result['expected']);
    expect($result['charset'])->toBe($initial === '' ? 'UTF-8' : $initial)
        ->and($result['null'])->toBeNull()
        ->and(substr_count($html, "title='" . $expected . "'"))->toBe(4)
        ->and(substr_count($html, "sort-page='" . $expected . "'"))->toBe(2)
        ->and(substr_count($html, "sort-return='" . $expected . "'"))->toBe(2)
        ->and(substr_count($html, ">" . $expected . "<"))->toBe(4)
        ->and($html)->toContain(
            "class='fa fa-sort-up'",
            "data-prefix='" . $expected . "'",
            "action='" . $expected . "'"
        );
})->with(array(
    'UTF-8 cached across configuration change' => array('UTF-8', 'ISO-8859-1', 'c3a9'),
    'Latin-1 cached across configuration change' => array('ISO-8859-1', 'UTF-8', 'e9'),
    'empty charset uses UTF-8 fallback' => array('', 'ISO-8859-1', 'c3a9'),
));
