<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace RemoveGraphsSelectorContractTest;

$root = dirname(__DIR__, 4);

/**
 * Run the real selection block of cli/remove_graphs.php for one invocation and
 * report what it decided, without a database. The block is taken from the file
 * so the help below is checked against the behaviour, not against a copy.
 *
 * @param array $selectors host_ids, host_template_ids, graph_template_ids, regex.
 * @param bool  $all       The --all flag.
 * @param bool  $list      The --list flag.
 *
 * @return array{status: int, out: string, where: string|null}
 */
function selection_outcome(array $selectors, $all = false, $list = false)
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/cli/remove_graphs.php');
    expect($source)->not->toBeFalse();

    $start = strpos($source, "\$sql_where  = 'WHERE gl.id > 0';");
    $end   = strpos($source, '$graphs = db_fetch_assoc(', $start);
    expect($start)->not->toBeFalse();
    expect($end)->not->toBeFalse();

    $fragment = substr($source, $start, $end - $start);
    // The guard is the decision under test; refuse a fragment that lost it.
    expect($fragment)->toContain('must use the --all option');

    $defaults = array('host_ids' => array(), 'host_template_ids' => array(),
        'graph_template_ids' => array(), 'regex' => array());
    $vars     = array_merge($defaults, $selectors);

    $code = 'function cacti_sizeof($a) { return is_array($a) ? count($a) : 0; }'
        . 'function db_qstr_rlike($r) { return "RLIKE " . chr(39) . $r . chr(39); }'
        . '$host_ids = ' . var_export($vars['host_ids'], true) . ';'
        . '$host_template_ids = ' . var_export($vars['host_template_ids'], true) . ';'
        . '$graph_template_ids = ' . var_export($vars['graph_template_ids'], true) . ';'
        . '$regex = ' . var_export($vars['regex'], true) . ';'
        . '$all = ' . var_export((bool) $all, true) . ';'
        . '$list = ' . var_export((bool) $list, true) . ';'
        . $fragment
        . 'echo "WHERE:" . $sql_where;';

    $pipes   = array();
    $process = proc_open(
        array(PHP_BINARY, '-r', $code),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    expect($process)->not->toBeFalse();

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    expect($err)->toBe('');

    $where = null;
    if (strpos($out, 'WHERE:') !== false) {
        $where = substr($out, strpos($out, 'WHERE:') + 6);
    }

    return array('status' => $status, 'out' => $out, 'where' => $where);
}

/*
 * The help called --graph-template-id mandatory. It never was: each of the four
 * selectors is accepted on its own, which is what these cases pin down.
 */
test('each selector is accepted on its own', function () {
    $accepted = array();

    foreach (array(
        'graph_template_ids' => array(3),
        'host_template_ids'  => array(4),
        'host_ids'           => array(5),
        'regex'              => array('^edge'),
    ) as $field => $value) {
        $result = selection_outcome(array($field => $value));

        $accepted[$field] = $result['status'] === 0 && $result['where'] !== 'WHERE gl.id > 0';
    }

    expect($accepted)->toBe(array(
        'graph_template_ids' => true,
        'host_template_ids'  => true,
        'host_ids'           => true,
        'regex'              => true,
    ));
});

test('selectors narrow together rather than replacing one another', function () {
    $result = selection_outcome(array('host_ids' => array(5), 'graph_template_ids' => array(3)));

    expect($result['status'])->toBe(0)
        ->and($result['where'])->toContain('gl.host_id IN (5)')
        ->and($result['where'])->toContain('gl.graph_template_id IN (3)');
});

test('no selector is refused unless --all says so', function () {
    $refused = selection_outcome(array());

    expect($refused['status'])->toBe(1)
        ->and($refused['out'])->toContain('must use the --all option');

    $explicit = selection_outcome(array(), true);

    expect($explicit['status'])->toBe(0)
        ->and($explicit['where'])->toBe('WHERE gl.id > 0');
});

/* --list removes nothing, so it is the one mode allowed to select everything. */
test('a bare --list selects every graph instead of being refused', function () {
    $result = selection_outcome(array(), false, true);

    expect($result['status'])->toBe(0)
        ->and($result['where'])->toBe('WHERE gl.id > 0');
});

test('--all ignores the other selectors', function () {
    $result = selection_outcome(array('host_ids' => array(5)), true);

    expect($result['where'])->toBe('WHERE gl.id > 0');
});

/**
 * Render the real --help output by running display_help() from the file, with
 * the two globals it needs stubbed. Asserting on the printed text means a
 * requirement satisfied only by a comment or an unused string cannot pass.
 *
 * @return string Everything --help prints.
 */
function rendered_help()
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/cli/remove_graphs.php');
    expect($source)->not->toBeFalse();

    $functions = '';

    foreach (array('function display_version()', 'function display_help()') as $signature) {
        $start = strpos($source, $signature);
        expect($start)->not->toBeFalse();

        $open  = strpos($source, '{', $start);
        $depth = 0;
        $end   = $open;

        for ($i = $open; $i < strlen($source); $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    $end = $i + 1;

                    break;
                }
            }
        }

        $functions .= substr($source, $start, $end - $start) . "\n";
    }

    $code = 'define("COPYRIGHT_YEARS", "2004-2026");'
        . 'function get_cacti_cli_version() { return "1.2.32"; }'
        . $functions . 'display_help();';

    $pipes   = array();
    $process = proc_open(
        array(PHP_BINARY, '-r', $code),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    expect($process)->not->toBeFalse();

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect($err)->toBe('');
    expect($out)->not->toBe('');

    return $out;
}

/*
 * Tie the printed help to the behaviour above, so the two cannot drift apart.
 */
test('the printed help describes the contract the code implements', function () {
    $help  = rendered_help();
    $wrong = array();

    // No selector is mandatory, and the old wording said one was.
    if (strpos($help, 'Mandatory') !== false) {
        $wrong[] = 'still calls a selector mandatory';
    }

    if (strpos($help, 'you must provide from one to many graph-template-id') !== false) {
        $wrong[] = 'still requires graph-template-id';
    }

    // Both facts a reader cannot get from the option list alone.
    if (strpos($help, 'refused') === false) {
        $wrong[] = 'does not say an empty selection is refused';
    }

    if (strpos($help, 'lists every Graph') === false) {
        $wrong[] = 'does not say a selectorless --list lists every Graph';
    }

    // A stray escape would reach the terminal verbatim.
    if (strpos($help, '\\') !== false) {
        $wrong[] = 'prints a backslash';
    }

    foreach (array('--graph-template-id', '--host-template-id', '--host-id',
        '--graph-regex', '--all', '--list', '--force', '--preserve') as $option) {
        if (strpos($help, $option) === false) {
            $wrong[] = 'omits ' . $option;
        }
    }

    expect($wrong)->toBe(array());
});

/*
 * The usage block is its own regression: it named only two of the options and
 * left its first bracket unclosed, which the option list above would not catch.
 */
test('the printed usage block lists every option and balances its brackets', function () {
    $help  = rendered_help();
    $start = strpos($help, 'usage: remove_graphs.php');
    expect($start)->not->toBeFalse();

    $end = strpos($help, 'Kadupul utility');
    if ($end === false) {
        $end = strpos($help, 'Cacti utility');
    }
    expect($end)->not->toBeFalse();

    $usage = substr($help, $start, $end - $start);

    expect(substr_count($usage, '['))->toBe(substr_count($usage, ']'));

    $missing = array();
    foreach (array('--graph-template-id', '--host-template-id', '--host-id',
        '--graph-regex', '--all', '--list', '--force', '--preserve') as $option) {
        if (strpos($usage, $option) === false) {
            $missing[] = $option;
        }
    }

    expect($missing)->toBe(array());
});

/*
 * --all bypasses the empty-selection guard too, so the help must not claim
 * --list is the only mode that works without a selector.
 */
test('the help does not call --list the only selectorless mode', function () {
    expect(strpos(rendered_help(), 'the one mode that accepts no'))->toBeFalse();
});

/**
 * Run the command's real getopt() spec and its real option-to-selector loop
 * against an argv, then feed the result straight into the selection block, so
 * the chain from command line to WHERE clause is covered rather than assumed.
 *
 * @param array $argv The tokens to pass as if they were the command line.
 *
 * @return array{status: int, out: string, where: string|null}
 */
function parsed_selection(array $argv)
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/cli/remove_graphs.php');
    expect($source)->not->toBeFalse();

    // The spec and the mapping loop, taken from the file rather than retyped.
    $spec_start = strpos($source, '$shortopts =');
    $loop_start = strpos($source, 'foreach ($options as $arg => $value) {', $spec_start);
    expect($spec_start)->not->toBeFalse();
    expect($loop_start)->not->toBeFalse();

    $open  = strpos($source, '{', $loop_start);
    $depth = 0;
    $loop_end = $open;

    for ($i = $open; $i < strlen($source); $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;

            if ($depth === 0) {
                $loop_end = $i + 1;

                break;
            }
        }
    }

    $parser = substr($source, $spec_start, $loop_end - $spec_start);
    expect($parser)->toContain('getopt(');

    $sel_start = strpos($source, "\$sql_where  = 'WHERE gl.id > 0';");
    $sel_end   = strpos($source, '$graphs = db_fetch_assoc(', $sel_start);
    $selection = substr($source, $sel_start, $sel_end - $sel_start);

    $code = 'function cacti_sizeof($a) { return is_array($a) ? count($a) : 0; }'
        . 'function db_qstr_rlike($r) { return "RLIKE " . chr(39) . $r . chr(39); }'
        . 'function display_help() {} function display_version() {}'
        . '$host_ids = array(); $host_template_ids = array(); $graph_template_ids = array(); $regex = array();'
        . '$all = false; $list = false; $force = false; $preserve = false; $quietMode = false;'
        . '$listHosts = false; $listHostTemplates = false; $listGraphTemplates = false; $graphType = "";'
        . $parser . $selection
        . 'echo "WHERE:" . $sql_where;';

    $command = array_merge(array(PHP_BINARY, '-r', $code, '--'), $argv);
    $pipes   = array();
    $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    expect($process)->not->toBeFalse();

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    $where = strpos($out, 'WHERE:') !== false ? substr($out, strpos($out, 'WHERE:') + 6) : null;

    return array('status' => $status, 'out' => $out, 'err' => $err, 'where' => $where);
}

/*
 * The cases above inject the selector arrays directly. This one starts from an
 * argv so a parser change that stopped populating them would be caught.
 */
test('selectors given on the command line reach the selection', function () {
    $result = parsed_selection(array('--host-id=5', '--graph-template-id=3'));

    expect($result['status'])->toBe(0)
        ->and($result['where'])->toContain('gl.host_id IN (5)')
        ->and($result['where'])->toContain('gl.graph_template_id IN (3)');
});

/* Each selector is declared with "::", so a repeat arrives as an array. */
test('a repeated selector keeps every value', function () {
    $result = parsed_selection(array('--host-id=5', '--host-id=7', '--host-id=9'));

    expect($result['status'])->toBe(0)
        ->and($result['where'])->toContain('gl.host_id IN (5,7,9)');
});

test('an argv with no selector is refused the same way', function () {
    $result = parsed_selection(array());

    expect($result['status'])->toBe(1)
        ->and($result['out'])->toContain('must use the --all option');
});

test('--all on the command line selects everything', function () {
    $result = parsed_selection(array('--all'));

    expect($result['status'])->toBe(0)
        ->and($result['where'])->toBe('WHERE gl.id > 0');
});
