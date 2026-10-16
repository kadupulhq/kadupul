<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;

use function Kadupul\Tools\FunctionsInventory\analyze_body;
use function Kadupul\Tools\FunctionsInventory\diff_against_disk;
use function Kadupul\Tools\FunctionsInventory\encode;
use function Kadupul\Tools\FunctionsInventory\find_declarations;
use function Kadupul\Tools\FunctionsInventory\generate;
use function Kadupul\Tools\FunctionsInventory\tokenize;
use function Kadupul\Tools\FunctionsInventory\tracked_php_files;
use function Kadupul\Tools\FunctionsInventory\validate_human;

require_once dirname(__DIR__, 2) . '/tools/functions-inventory.php';

final class FunctionsInventoryTest extends TestCase
{
    private string $scratch = '';

    protected function tearDown(): void
    {
        if ($this->scratch !== '') {
            exec('rm -rf ' . escapeshellarg($this->scratch));
        }
    }

    public function testCommittedInventoryMatchesLibFunctions(): void
    {
        $root = dirname(__DIR__, 2);
        $result = generate($root);

        self::assertSame([], $result['problems']);
        self::assertSame([], diff_against_disk($root, generate($root, true)['files']));
        self::assertGreaterThan(200, count($result['records']));
    }

    public function testCheckCommandExitsZeroWhenCurrent(): void
    {
        $root = dirname(__DIR__, 2);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/functions-inventory.php') . ' --check 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertStringContainsString('is current', implode("\n", $output));
    }

    public function testDeclarationsCoverReferencesConditionalsAndMethods(): void
    {
        $source = <<<'PHP'
<?php
/** Documented. */
function &by_reference(array &$rows, ?string $name = null, int|string ...$rest): ?array { return $rows; }
function plain($a, $b = ['x' => 1, 'y' => [2, 3]], $c = PHP_EOL) {
    $f = function ($z) { return $z; };
    $g = fn($z) => $z;
    function inner_named() {}
}
if (true) {
    function maybe($v) { return "{$v}"; }
}
class Holder { public function method() {} }
PHP;
        $found = [];
        foreach (find_declarations($source) as $declaration) {
            $found[$declaration['name']] = $declaration;
        }

        self::assertSame(['by_reference', 'plain', 'inner_named', 'maybe', 'method'], array_keys($found));
        self::assertSame('top', $found['by_reference']['context']);
        self::assertTrue($found['by_reference']['returns_reference']);
        self::assertTrue($found['by_reference']['docblock']);
        self::assertSame('?array', $found['by_reference']['return_type']);
        self::assertFalse($found['plain']['docblock']);
        self::assertSame('nested', $found['inner_named']['context']);
        self::assertSame('conditional', $found['maybe']['context']);
        self::assertSame('method', $found['method']['context']);
        self::assertSame(10, $found['maybe']['end_line']);

        [$rows, $name, $rest] = $found['by_reference']['parameters'];
        self::assertTrue($rows['by_ref']);
        self::assertSame('array', $rows['type']);
        self::assertTrue($name['nullable']);
        self::assertSame('null', $name['default']);
        self::assertTrue($rest['variadic']);
        self::assertSame('int|string', $rest['type']);

        self::assertSame("['x' => 1, 'y' => [2, 3]]", $found['plain']['parameters'][1]['default']);
        self::assertSame('PHP_EOL', $found['plain']['parameters'][2]['default']);
    }

    public function testBodyFactsIncludeUnresolvedHooksAndSessionKeys(): void
    {
        $source = <<<'PHP'
<?php
function sample($x) {
    global $config, $database_default;
    static $cache, $other;
    $_SESSION['sess_user_id'] = 1;
    $k = $_SESSION[$x];
    $r = $_REQUEST['a'] . $_SERVER['REQUEST_URI'];
    db_fetch_row_prepared('SELECT 1');
    db_execute('SELECT 2');
    db_execute('SELECT 3');
    header('X-Test: 1');
    echo 'out';
    $s = new static();
    api_plugin_hook('fixed_name');
    api_plugin_hook_function('dyn_' . $x, $y);
    fopen('f', 'r');
    exec('ls');
    helper();
    $o->helper();
    sample(1);
    exit;
}
function helper() {}
PHP;
        [$tokens, $lines] = tokenize($source);
        $declarations = find_declarations($source);
        $facts = analyze_body($tokens, $lines, $declarations[0], ['sample' => true, 'helper' => true]);

        self::assertSame(['config', 'database_default'], $facts['uses_global']);
        self::assertSame(['cache', 'other'], $facts['static_variables']);
        self::assertSame(['$_REQUEST', '$_SERVER', '$_SESSION'], $facts['superglobals']);
        self::assertSame(['(dynamic)', 'sess_user_id'], $facts['session']['keys']);
        self::assertSame(['db_execute' => 2, 'db_fetch_row_prepared' => 1], $facts['db_calls']);
        self::assertSame(['header'], $facts['headers']);
        self::assertSame(['exit'], $facts['exits']);
        self::assertSame(['echo'], $facts['output']);
        self::assertSame(['fopen'], $facts['file_functions']);
        self::assertSame(['exec'], $facts['process_functions']);
        self::assertSame(['helper', 'sample'], $facts['calls']);

        $hooks = $facts['plugin_hooks'];
        self::assertSame('fixed_name', $hooks[0]['name']);
        self::assertTrue($hooks[0]['resolved']);
        self::assertNull($hooks[1]['name']);
        self::assertFalse($hooks[1]['resolved']);
        self::assertSame("'dyn_' . \$x", $hooks[1]['expression']);
    }

    public function testRegenerationKeepsHumanFieldsAndRefusesUnlistedFunctions(): void
    {
        $root = $this->temporaryRepository();
        $path = $root . '/docs/migration/functions-inventory/title_trim.json';
        $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $record['human']['status'] = 'planned';
        $record['human']['notes'] = 'kept by hand';
        $record['static']['start_line'] = 999;
        file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $result = generate($root);

        self::assertSame([], $result['problems']);
        $regenerated = json_decode($result['files']['docs/migration/functions-inventory/title_trim.json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('planned', $regenerated['human']['status']);
        self::assertSame('kept by hand', $regenerated['human']['notes']);
        self::assertNotSame(999, $regenerated['static']['start_line']);
        self::assertNotSame([], diff_against_disk($root, $result['files']), 'stale static facts must be reported');

        file_put_contents($root . '/lib/functions.php', "\nfunction brand_new_function() {}\n", FILE_APPEND);
        self::assertSame(['brand_new_function'], generate($root)['new_functions']);
    }

    public function testCheckReportsRemovedFunctionAsOrphan(): void
    {
        $root = $this->temporaryRepository();
        $source = (string) file_get_contents($root . '/lib/functions.php');
        $source = preg_replace('/^function padleft\(.*?^}\n/ms', '', $source, 1, $count);
        self::assertSame(1, $count);
        file_put_contents($root . '/lib/functions.php', $source);

        $messages = diff_against_disk($root, generate($root)['files']);

        self::assertContains('orphan: docs/migration/functions-inventory/padleft.json (function no longer declared in lib/functions.php)', $messages);
    }

    public function testStatusGateRequiresBehaviorRecord(): void
    {
        $root = $this->scratchDirectory();
        $golden = 'tests/Golden/functions/kadupul/php-8.4';
        mkdir($root . '/' . $golden, 0775, true);
        file_put_contents($root . '/' . $golden . '/title_trim.json', json_encode(['function' => 'title_trim', 'cases' => []]));
        file_put_contents($root . '/' . $golden . '/padleft.json', json_encode(['function' => 'padleft', 'cases' => []]));
        file_put_contents($root . '/' . $golden . '/nameless.json', json_encode(['cases' => []]));
        file_put_contents($root . '/' . $golden . '/broken.json', '{not json');
        file_put_contents($root . '/composer.json', '{}');
        $human = [
            'target_module' => 'Platform/Domain/Text',
            'wave' => 1,
            'status' => 'implemented',
            'lts_status' => 'not assessed',
            'behavior_record' => null,
            'notes' => '',
        ];

        self::assertStringContainsString('requires a behavior_record', implode("\n", validate_human($root, 'title_trim', $human)));

        $human['behavior_record'] = 'tests/Golden/functions/does_not_exist.json';
        self::assertStringContainsString('is not a file in the repository', implode("\n", validate_human($root, 'title_trim', $human)));

        $human['behavior_record'] = 'composer.json';
        self::assertStringContainsString('must be under tests/Golden/functions/', implode("\n", validate_human($root, 'title_trim', $human)));

        $human['behavior_record'] = $golden . '/padleft.json';
        self::assertStringContainsString('records function "padleft", not title_trim', implode("\n", validate_human($root, 'title_trim', $human)));

        $human['behavior_record'] = $golden . '/nameless.json';
        self::assertStringContainsString('records function "(none)"', implode("\n", validate_human($root, 'title_trim', $human)));

        $human['behavior_record'] = $golden . '/broken.json';
        self::assertStringContainsString('is not valid JSON', implode("\n", validate_human($root, 'title_trim', $human)));

        $human['behavior_record'] = $golden . '/title_trim.json';
        self::assertSame([], validate_human($root, 'title_trim', $human));

        $human['behavior_record'] = '../outside.json';
        self::assertNotSame([], validate_human($root, 'title_trim', $human));

        $human['status'] = 'done';
        self::assertStringContainsString('is not one of', implode("\n", validate_human($root, 'title_trim', $human)));

        $human['status'] = 'verified';
        $human['behavior_record'] = null;
        self::assertStringContainsString('requires a behavior_record', implode("\n", validate_human($root, 'title_trim', $human)));
    }

    public function testCheckIgnoresFanOutFromUnrelatedFiles(): void
    {
        $root = $this->temporaryRepository();
        mkdir($root . '/src');
        file_put_contents($root . '/src/x.php', "<?php\n\$mailer = 1;\nget_item();\n");
        exec('git -C ' . escapeshellarg($root) . ' add -A 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));

        self::assertSame([], diff_against_disk($root, generate($root, true)['files']));

        $refreshed = diff_against_disk($root, generate($root)['files']);
        self::assertStringContainsString('stale: docs/migration/functions-inventory/mailer.json', implode("\n", $refreshed), 'plain generation must still refresh fan_out');
    }

    public function testCheckStillReportsChangedSignature(): void
    {
        $root = $this->temporaryRepository();
        $source = (string) file_get_contents($root . '/lib/functions.php');
        $changed = preg_replace('/^function padleft\(/m', 'function padleft($extra, ', $source, 1, $count);
        self::assertSame(1, $count);
        file_put_contents($root . '/lib/functions.php', $changed);

        $messages = implode("\n", diff_against_disk($root, generate($root, true)['files']));

        self::assertStringContainsString('stale: docs/migration/functions-inventory/padleft.json', $messages);
    }

    public function testTrackedPhpFilesMatchesGitAndSkipsUntrackedFiles(): void
    {
        $root = $this->scratchDirectory();
        mkdir($root . '/dir with space');
        foreach (['a.php', 'dir with space/b.php', "it's.php", 'notes.txt'] as $path) {
            file_put_contents($root . '/' . $path, "<?php\n");
        }
        exec('git -C ' . escapeshellarg($root) . ' init -q 2>&1 && git -C ' . escapeshellarg($root) . ' add -A 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        file_put_contents($root . '/untracked.php', "<?php\n");

        $listed = (string) shell_exec('git -C ' . escapeshellarg($root) . " ls-files -z -- '*.php'");
        $expected = array_values(array_filter(explode("\0", $listed), static fn(string $f): bool => $f !== ''));
        sort($expected, SORT_STRING);

        self::assertSame(['a.php', 'dir with space/b.php', "it's.php"], tracked_php_files($root));
        self::assertSame($expected, tracked_php_files($root));
    }

    public function testTrackedPhpFilesThrowsOutsideAGitCheckout(): void
    {
        $root = $this->scratchDirectory();
        file_put_contents($root . '/a.php', "<?php\n");
        // Stop git from discovering a repository above the scratch directory.
        $ceiling = getenv('GIT_CEILING_DIRECTORIES');
        putenv('GIT_CEILING_DIRECTORIES=' . dirname($root));

        try {
            tracked_php_files($root);
            self::fail('tracked_php_files() must reject a directory that is not a git checkout');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('git ls-files failed (exit 128); run the inventory from a git checkout', $e->getMessage());
        } finally {
            putenv($ceiling === false ? 'GIT_CEILING_DIRECTORIES' : 'GIT_CEILING_DIRECTORIES=' . $ceiling);
        }
    }

    public function testEncodeKeepsContainersOnOneLineUpToOneHundredColumns(): void
    {
        foreach ([95, 96] as $length) {
            $line = '["' . str_repeat('x', $length) . '"]';

            self::assertSame($line . "\n", encode([str_repeat('x', $length)]));
        }
        self::assertSame(100, strlen(trim(encode([str_repeat('x', 96)]))));
        self::assertSame("[\n    \"" . str_repeat('x', 97) . "\"\n]\n", encode([str_repeat('x', 97)]));
    }

    public function testEncodeCountsIndentationKeyAndTrailingComma(): void
    {
        // The inner list sits at 4 spaces + '"key": ' and is followed by a comma, so 84 characters reach column 100.
        foreach ([83 => true, 84 => true, 85 => false] as $length => $inline) {
            $lines = explode("\n", encode(['key' => [str_repeat('x', $length)], 'z' => 1]));

            if ($inline) {
                self::assertSame('    "key": ["' . str_repeat('x', $length) . '"],', $lines[1]);
                self::assertLessThanOrEqual(100, strlen($lines[1]));
            } else {
                self::assertSame('    "key": [', $lines[1]);
                self::assertSame('        "' . str_repeat('x', $length) . '"', $lines[2]);
            }
        }
    }

    public function testEncodeEmptyContainersAndUnicodeWidth(): void
    {
        self::assertSame("[]\n", encode([]));
        self::assertSame(
            "{\n    \"e\": [],\n    \"o\": {},\n    \"p\": \"" . str_repeat('x', 100) . "\"\n}\n",
            encode(['e' => [], 'o' => new \stdClass(), 'p' => str_repeat('x', 100)])
        );
        // Columns are characters, not bytes: 96 two-byte characters still fit.
        self::assertSame('["' . str_repeat('é', 96) . "\"]\n", encode([str_repeat('é', 96)]));
        self::assertSame("[\n    \"" . str_repeat('é', 97) . "\"\n]\n", encode([str_repeat('é', 97)]));
    }

    public function testEncodeRoundTripsAwkwardValues(): void
    {
        $values = [
            'quote' => 'say "hi"', 'backslash' => 'a\\b', 'slash' => 'a/b', 'brace' => '{[}]', 'comma' => 'a, b: c',
            'control' => "tab\there\nnewline\x01", 'unicode' => "é \u{1F600} \u{2028}", 'empty' => '', 'zero' => '0',
            'ints' => [0, -1, PHP_INT_MAX], 'floats' => [0.0, 1.0, 0.5, -2.25e-10], 'bools' => [true, false, null],
            'nested' => ['a' => [], 'b' => [[], [[]]], 'c' => ['d' => ['e' => str_repeat('long ', 30)]]],
            'objects' => [['k' => 1], ['k' => [1, 2, 3], 'long' => str_repeat('y', 90)]],
            'key"with\\odd/keys' => 1,
        ];
        $encoded = encode($values);

        self::assertSame($values, json_decode($encoded, true, 512, JSON_THROW_ON_ERROR));
        self::assertStringEndsWith("}\n", $encoded);
        self::assertSame($encoded, encode(json_decode($encoded, true, 512, JSON_THROW_ON_ERROR)));
        self::assertStringContainsString('"floats": [0.0, 1.0, 0.5, -2.25e-10]', $encoded);
        self::assertStringContainsString('é', $encoded);
        self::assertStringContainsString('a/b', $encoded);
    }

    private function scratchDirectory(): string
    {
        $this->scratch = sys_get_temp_dir() . '/functions-inventory-' . bin2hex(random_bytes(6));
        mkdir($this->scratch, 0775, true);

        return $this->scratch;
    }

    /**
     * A git repository holding only the files the generator reads, so the
     * test never touches the working tree it runs from.
     */
    private function temporaryRepository(): string
    {
        $root = dirname(__DIR__, 2);
        $this->scratchDirectory();
        mkdir($this->scratch . '/lib', 0775, true);
        mkdir($this->scratch . '/docs/migration', 0775, true);
        copy($root . '/lib/functions.php', $this->scratch . '/lib/functions.php');
        exec('cp -R ' . escapeshellarg($root . '/docs/migration/functions-inventory') . ' ' . escapeshellarg($this->scratch . '/docs/migration/'));
        // Records name their golden as behavior_record, and the generator wants that file tracked.
        mkdir($this->scratch . '/tests/Golden', 0775, true);
        exec('cp -R ' . escapeshellarg($root . '/tests/Golden/functions') . ' ' . escapeshellarg($this->scratch . '/tests/Golden/'));
        exec('git -C ' . escapeshellarg($this->scratch) . ' init -q && git -C ' . escapeshellarg($this->scratch) . ' add -A 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));

        return $this->scratch;
    }
}
