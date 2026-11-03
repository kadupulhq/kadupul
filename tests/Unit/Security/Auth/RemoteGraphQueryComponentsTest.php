<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace RemoteGraphQueryComponentsTest;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

require_once __DIR__ . '/../../../Helpers/ChildProcessCoverage.php';

/** @param array<string, mixed> $request
 * @return array{accepted: bool, path?: string, query?: array<string, string>}
 */
function run(string $script, array $request): array
{
    $root = dirname(__DIR__, 4);
    $fixture = sys_get_temp_dir() . '/remote-graph-query-' . bin2hex(random_bytes(8));
    mkdir($fixture . '/include', 0700, true);
    mkdir($fixture . '/lib', 0700);
    file_put_contents($fixture . '/include/auth.php', '<?php');
    file_put_contents($fixture . '/lib/rrd.php', '<?php');
    $program = <<<'PHP'
        require $argv[1] . '/include/global_constants.php';
        require $argv[1] . '/lib/html_utility.php';
        function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
        function read_config_option($name, ...$options) { return ''; }
        function api_plugin_hook_function($name, $data = '') { return $data; }
        function cacti_session_close() {}
        function cacti_validate_theme($theme) { return $theme; }
        function get_selected_theme() { return 'classic'; }
        function is_graph_allowed(...$arguments) { return true; }
        function die_html_input_error(...$arguments) {
            $GLOBALS['nativeChildCoverageMarkers'] = ['remote-graph-query-observed'];
            while (ob_get_level()) { ob_end_clean(); }
            print json_encode(['accepted' => false], JSON_THROW_ON_ERROR);
            exit;
        }
        function call_remote_data_collector($poller, $url) {
            $GLOBALS['nativeChildCoverageMarkers'] = ['remote-graph-query-observed'];
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            while (ob_get_level()) { ob_end_clean(); }
            print json_encode(['accepted' => true, 'path' => parse_url($url, PHP_URL_PATH), 'query' => $query], JSON_THROW_ON_ERROR);
            exit;
        }
        $config = ['poller_id' => 2, 'url_path' => '/nested/'];
        $_SESSION = ['sess_user_id' => 42];
        $_CACTI_REQUEST = [];
        $_REQUEST = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR) + ['local_graph_id' => '7', 'image_format' => 'png'];
        require $argv[1] . '/' . $argv[2];
        PHP;
    $coverage = null;
    $registration = \child_coverage_registration(
        __FILE__,
        'remote-graph-query-' . $script,
        [$script, $request],
        ['remote-graph-query-observed'],
        [$script, 'lib/html_utility.php'],
        [$script, 'include/global_constants.php']
    );
    $registration['collectorPrelude'] = 'define("PAGE_FLAG_TEST_COVERAGE_SOURCE", ' . var_export($root . '/' . $script, true) . ');';
    $command = \child_coverage_command([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $root, $script, json_encode($request, JSON_THROW_ON_ERROR)], $coverage, $registration);
    try {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $fixture);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start the graph request fixture.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        expect($status)->toBe(0)->and($stderr)->toBe('');
        \child_coverage_collect($coverage);
        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        if ($coverage !== null && is_dir($coverage)) {
            foreach (glob($coverage . '/*.coverage*') ?: [] as $report) {
                unlink($report);
            }
            rmdir($coverage);
            unset($GLOBALS['child_coverage_registrations'][$coverage]);
        }
        unlink($fixture . '/include/auth.php');
        unlink($fixture . '/lib/rrd.php');
        rmdir($fixture . '/include');
        rmdir($fixture . '/lib');
        rmdir($fixture);
    }
}

test('remote graph requests preserve the actual integer and archive sentinel contracts', function (string $script, array $request, string $graph, string $archive): void {
    $result = run($script, $request);
    expect($result['accepted'])->toBeTrue()
        ->and($result['path'])->toBe('/nested/remote_agent.php')
        ->and($result['query']['action'])->toBe('graph_json')
        ->and($result['query']['local_graph_id'])->toBe($graph)
        ->and($result['query']['rra_id'])->toBe($archive);
})->with(['graph_image.php', 'graph_json.php'])->with([
    'numeric archive' => [['rra_id' => '3'], '7', '3'],
    'all archives' => [['rra_id' => 'all'], '7', 'all'],
    'missing archive' => [[], '7', ''],
    'empty archive' => [['rra_id' => ''], '7', ''],
    'zero archive' => [['rra_id' => '0'], '7', '0'],
    'undefined archive' => [['rra_id' => 'undefined'], '7', ''],
    'negative integer' => [['rra_id' => '-1'], '7', '-1'],
    'normalized integer input' => [['local_graph_id' => ' 7 ', 'rra_id' => ' 3 '], '7', '3'],
]);

test('malformed graph request identifiers stop before collector handoff', function (string $script, array $request): void {
    expect(run($script, $request))->toBe(['accepted' => false]);
})->with(['graph_image.php', 'graph_json.php'])->with([
    'archive text' => [['rra_id' => 'invalid']],
    'archive decimal' => [['rra_id' => '1.5']],
    'archive exponent' => [['rra_id' => '1e2']],
    'archive array' => [['rra_id' => ['3']]],
    'graph array' => [['local_graph_id' => ['7']]],
]);

test('remote graph identifier appends explicitly encode their query components', function (string $script): void {
    $source = file_get_contents(dirname(__DIR__, 4) . '/' . $script);
    expect($source)->toBeString();
    $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
    $finder = new NodeFinder();
    foreach (['&local_graph_id=', '&rra_id='] as $key) {
        $matches = $finder->find($nodes, static fn(Node $node): bool => $node instanceof Node\Expr\AssignOp\Concat
            && $node->var instanceof Node\Expr\Variable && $node->var->name === 'url'
            && $node->expr instanceof Node\Expr\BinaryOp\Concat
            && $node->expr->left instanceof Node\Scalar\String_ && $node->expr->left->value === $key);
        expect($matches)->toHaveCount(1);
        $encoded = array_values($matches)[0]->expr->right;
        expect($encoded)->toBeInstanceOf(Node\Expr\FuncCall::class)
            ->and($encoded->name->toString())->toBe('rawurlencode')
            ->and($encoded->args[0]->value)->toBeInstanceOf(Node\Expr\Cast\String_::class);
    }
})->with(['graph_image.php', 'graph_json.php']);
