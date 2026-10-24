<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Kadupul\Tests\Security\GlobalStatements\compare;
use function Kadupul\Tests\Security\GlobalStatements\compare_with_base;
use function Kadupul\Tests\Security\GlobalStatements\count_source;
use function Kadupul\Tests\Security\GlobalStatements\parse_baseline;

require_once dirname(__DIR__) . '/security/global_statement_inventory.php';

/**
 * The global statement ratchet in tests/security may only tighten. These cases
 * pin what the counter reads as a statement and run the check as CI runs it.
 */
final class GlobalStatementInventoryTest extends TestCase
{
    private string $scratch = '';

    protected function tearDown(): void
    {
        if ($this->scratch !== '') {
            exec('rm -rf ' . escapeshellarg($this->scratch));
        }
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function fixtures(): iterable
    {
        yield 'one, several and multi-line statements in functions' => ['function-scope.php', 4, 6];
        yield 'comments, strings, heredocs, $GLOBALS and members named global' => ['not-statements.php', 0, 0];
        yield 'file scope, nested function, static closure and anonymous class' => ['nested-scopes.php', 4, 5];
        yield 'variable variables, braced names with commas and a close tag' => ['variable-names.php', 2, 3];
    }

    #[DataProvider('fixtures')]
    public function testCountsStatementsAndVariables(string $fixture, int $statements, int $variables): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/Fixtures/global-statements/' . $fixture);

        self::assertSame(['statements' => $statements, 'variables' => $variables], count_source($source));
    }

    public function testUnparsableSourceFailsInsteadOfCountingZero(): void
    {
        $this->expectException(\ParseError::class);

        count_source("<?php\nfunction broken( {\n    global \$config;\n");
    }

    public function testRepositoryMatchesItsBaseline(): void
    {
        [$status, $output] = $this->check(dirname(__DIR__, 2));

        self::assertSame(0, $status, $output);
        self::assertStringStartsWith('OK: ', $output);
    }

    public function testCheckPassesAtTheBaselineAndFailsAboveIt(): void
    {
        $root = $this->repository(['lib/a.php' => "<?php\nfunction a() {\n    global \$config;\n}\n"]);
        $this->writeBaseline($root);

        [$status, $output] = $this->check($root);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('OK: 1 global statements naming 1 variables in 1 files', $output);

        file_put_contents($root . '/lib/a.php', "<?php\nfunction a() {\n    global \$config, \$item_rows;\n}\n");
        [$status, $output] = $this->check($root);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('lib/a.php: 1 -> 1 statements, 1 -> 2 variables', $output);

        file_put_contents($root . '/lib/b.php', "<?php\nglobal \$config;\n");
        [$status, $output] = $this->check($root);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('lib/b.php: 0 -> 1 statements, 0 -> 1 variables', $output);
    }

    public function testCheckNamesTheRefreshCommandWhenTheCountFalls(): void
    {
        $root = $this->repository(['lib/a.php' => "<?php\nfunction a() {\n    global \$config;\n    global \$item_rows;\n}\n"]);
        $this->writeBaseline($root);
        file_put_contents($root . '/lib/a.php', "<?php\nfunction a() {\n    global \$config;\n}\n");

        [$status, $output] = $this->check($root);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('lib/a.php: 2 -> 1 statements, 2 -> 1 variables', $output);
        self::assertStringContainsString('php tests/security/global_statement_inventory.php > tests/security/baselines/global_statements.baseline.tsv', $output);

        $this->writeBaseline($root);
        [$status, $output] = $this->check($root);
        self::assertSame(0, $status, $output);
    }

    public function testNewSourceFileUnderSrcIsRejectedEvenWithARaisedBaseline(): void
    {
        $root = $this->repository(['src/Old.php' => "<?php\nfunction old() {\n    global \$config;\n}\n"]);
        $this->writeBaseline($root);
        $this->git($root, 'add -A');
        $this->git($root, 'commit -q -m base');

        file_put_contents($root . '/src/New.php', "<?php\nfunction fresh() {\n    global \$config;\n}\n");
        [$status, $output] = $this->check($root);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('src/New.php: 0 -> 1 statements, 0 -> 1 variables (a new file under src/ may not use global)', $output);

        $this->writeBaseline($root);
        [$status, $output] = $this->check($root, 'HEAD');
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('baseline adds src/New.php', $output);
        self::assertStringContainsString('baseline statements rose from 1 to 2', $output);
    }

    public function testBaseComparisonAllowsARenameOutsideSrc(): void
    {
        $base = parse_baseline("file\tstatements\tvariables\nlib/old.php\t2\t3\nsrc/A.php\t1\t1\n");
        $moved = parse_baseline("file\tstatements\tvariables\nlib/new.php\t2\t3\nsrc/A.php\t1\t1\n");
        $lowered = parse_baseline("file\tstatements\tvariables\nlib/new.php\t1\t1\n");

        self::assertSame([], compare_with_base($base, $moved));
        self::assertSame([], compare_with_base($base, $lowered));
        self::assertSame(['rises' => [], 'falls' => []], compare($moved, $moved));
    }

    public function testExcludedTreesAreNotCounted(): void
    {
        $global = "<?php\nglobal \$config;\n";
        $root = $this->repository([
            'lib/a.php' => $global,
            'include/vendor/package/a.php' => $global,
            'vendor/package/a.php' => $global,
            'tests/Unit/a.php' => $global,
            'var/cache/a.php' => $global,
        ]);
        file_put_contents($root . '/.gitignore', "var/\n");

        [$status, $output] = $this->invoke($root, []);
        self::assertSame(0, $status, $output);
        self::assertSame("file\tstatements\tvariables\nlib/a.php\t1\t1\n", $output);
    }

    public function testMalformedBaselineIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);

        parse_baseline("file\tstatements\tvariables\nlib/a.php\t0\t0\n");
    }

    /**
     * @param array<string, string> $files
     */
    private function repository(array $files): string
    {
        $this->scratch = sys_get_temp_dir() . '/global-statements-' . bin2hex(random_bytes(6));
        mkdir($this->scratch . '/tests/security/baselines', 0775, true);
        copy(dirname(__DIR__) . '/security/global_statement_inventory.php', $this->scratch . '/tests/security/global_statement_inventory.php');
        foreach ($files as $path => $source) {
            if (!is_dir(dirname($this->scratch . '/' . $path))) {
                mkdir(dirname($this->scratch . '/' . $path), 0775, true);
            }
            file_put_contents($this->scratch . '/' . $path, $source);
        }
        $this->git($this->scratch, 'init -q');
        $this->git($this->scratch, 'config user.email test@example.invalid');
        $this->git($this->scratch, 'config user.name test');
        $this->git($this->scratch, 'config commit.gpgsign false');

        return $this->scratch;
    }

    private function writeBaseline(string $root): void
    {
        [$status, $output] = $this->invoke($root, []);
        self::assertSame(0, $status, $output);
        file_put_contents($root . '/tests/security/baselines/global_statements.baseline.tsv', $output);
    }

    /**
     * @return array{int, string}
     */
    private function check(string $root, ?string $base = null): array
    {
        return $this->invoke($root, $base === null ? ['--check'] : ['--check', '--base', $base]);
    }

    /**
     * @param list<string> $args
     * @return array{int, string}
     */
    private function invoke(string $root, array $args): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/security/global_statement_inventory.php');
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $lines, $status);

        return [$status, implode("\n", $lines) . "\n"];
    }

    private function git(string $root, string $args): void
    {
        exec('git -C ' . escapeshellarg($root) . ' ' . $args . ' 2>&1', $lines, $status);
        self::assertSame(0, $status, implode("\n", $lines));
    }
}
