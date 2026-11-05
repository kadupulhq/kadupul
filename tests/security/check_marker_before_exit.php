<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Flags a run marker that is recorded before an exit path in the same scope.
 *
 * A marker is a set_config_option() call whose key names a run, a last time
 * or a stamp (poller_lastrun_<id>, poller_mibsrun_<id>, ...). Recording it
 * claims that some work happened. When an exit or die can still follow in
 * the same function or script, a run that leaves there records work it never
 * did. That is how poller.php postponed system-MIB collection for four hours
 * (#1348). Yuan et al., "Simple Testing Can Prevent Most Critical Failures"
 * (OSDI 2014), found most catastrophic failures in error-handling paths like
 * this one.
 *
 * An exit in another branch of an if that holds the marker cannot follow it.
 * An exit that is the last statement of a block holding the marker ends the
 * run the marker describes, so neither it nor anything after it counts; an
 * exit in a branch, a loop or an expression (or die) is an early exit and does.
 *
 * The check is a heuristic: it cannot tell whether the exit skips the work.
 * Existing matches are listed in the baseline after review. A pull request
 * fails on a new match, and on a baseline row that no longer matches, so the
 * baseline only shrinks or grows by a reviewed edit.
 *
 * Semgrep is not used because its PHP parser falls back to a partial parse on
 * files with a block-scoped declare(ticks=...), which every long-running CLI
 * script here has, and then misses every exit in them.
 *
 * Usage:
 *   php check_marker_before_exit.php [--baseline FILE] [--write-baseline] [--root DIR] [FILE ...]
 * With no FILE, checks every tracked PHP file outside vendored and test trees.
 * Exit status: 0 clean, 1 findings differ from the baseline, 2 tool error.
 */

declare(strict_types=1);

require __DIR__ . '/../../include/vendor/autoload.php';

use PhpParser\Error as ParseError;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

const MARKER_KEY = '/run|last|stamp/i';
const EXCLUDED = '#^(include/vendor|include/fa|include/js|node_modules|tests|plugins|cache|log|rra)/#';

final class MarkerScopeVisitor extends NodeVisitorAbstract
{
    /** @var list<array{name: string, events: list<array{type: string, line: int, key: string, blocks: list<int>, branches: list<array{0: int, 1: int}>, block: int, last: bool}>}> */
    private array $stack = [];

    /** @var list<array{scope: string, key: string, line: int, exit: int}> */
    public array $findings = [];

    private Standard $printer;

    /** @var array<string, int> */
    private array $closures = [];

    /** @var list<int> statement lists enclosing the current node, innermost last */
    private array $blocks = [];

    private int $nextBlock = 0;

    /** @var list<array{0: int, 1: int}> if branches enclosing the current node: [if id, branch] */
    private array $branches = [];

    private int $nextIf = 0;

    public function __construct()
    {
        $this->printer = new Standard();
    }

    public function beforeTraverse(array $nodes): ?array
    {
        $this->stack = [['name' => '(file)', 'events' => []]];
        $this->findings = [];
        $this->closures = [];
        $this->blocks = [];
        $this->nextBlock = 0;
        $this->branches = [];
        $this->nextIf = 0;
        $this->markBlock($nodes);

        return null;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node->hasAttribute('markerBlock')) {
            $this->blocks[] = $node->getAttribute('markerBlock');
        }
        if ($node->hasAttribute('markerBranch')) {
            $this->branches[] = $node->getAttribute('markerBranch');
        }
        if ($node instanceof Stmt\If_) {
            $this->markBranches($node);
        }
        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->$name;
            if (is_array($child) && $child !== [] && $child[array_key_first($child)] instanceof Stmt) {
                $this->markBlock($child);
            }
        }

        if ($node instanceof Node\FunctionLike) {
            $this->stack[] = ['name' => $this->scopeName($node), 'events' => []];

            return null;
        }

        $scope = count($this->stack) - 1;
        if ($node instanceof Expr\Exit_ && !$node->hasAttribute('markerStatement')) {
            $this->stack[$scope]['events'][] = ['type' => 'exit', 'line' => $node->getStartLine(), 'key' => '', 'blocks' => $this->blocks, 'branches' => $this->branches,
                'block' => -1, 'last' => false];
        } elseif ($node instanceof Stmt\Expression && $node->expr instanceof Expr\Exit_) {
            // Recorded with its block here; the Exit_ node inside it is skipped above.
            $node->expr->setAttribute('markerStatement', true);
            $this->stack[$scope]['events'][] = ['type' => 'exit', 'line' => $node->getStartLine(), 'key' => '', 'blocks' => $this->blocks, 'branches' => $this->branches,
                'block' => (int) $node->getAttribute('markerBlock'), 'last' => (bool) $node->getAttribute('markerLast')];
        } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name
            && strtolower($node->name->getLast()) === 'set_config_option' && isset($node->args[0])
            && $node->args[0] instanceof Node\Arg) {
            $key = $this->printer->prettyPrintExpr($node->args[0]->value);
            if (preg_match(MARKER_KEY, $key) === 1) {
                $this->stack[$scope]['events'][] = ['type' => 'marker', 'line' => $node->getStartLine(), 'key' => $key, 'blocks' => $this->blocks, 'branches' => $this->branches,
                    'block' => -1, 'last' => false];
            }
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node->hasAttribute('markerBlock')) {
            array_pop($this->blocks);
        }
        if ($node->hasAttribute('markerBranch')) {
            array_pop($this->branches);
        }
        if ($node instanceof Node\FunctionLike) {
            $this->close(array_pop($this->stack));
        }

        return null;
    }

    public function afterTraverse(array $nodes): ?array
    {
        $this->close(array_pop($this->stack));

        return null;
    }

    /** The branches of one if are exclusive: a statement in one never runs after a statement in another. */
    private function markBranches(Stmt\If_ $node): void
    {
        $id = $this->nextIf++;
        foreach ($node->stmts as $statement) {
            $statement->setAttribute('markerBranch', [$id, 0]);
        }
        foreach ($node->elseifs as $index => $elseif) {
            $elseif->setAttribute('markerBranch', [$id, $index + 1]);
        }
        if ($node->else !== null) {
            $node->else->setAttribute('markerBranch', [$id, count($node->elseifs) + 1]);
        }
    }

    /**
     * @param list<array{0: int, 1: int}> $first
     * @param list<array{0: int, 1: int}> $second
     */
    private static function exclusive(array $first, array $second): bool
    {
        foreach ($first as [$id, $branch]) {
            foreach ($second as [$otherId, $otherBranch]) {
                if ($id === $otherId && $branch !== $otherBranch) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<Stmt> $statements */
    private function markBlock(array $statements): void
    {
        $block = $this->nextBlock++;
        $last = null;
        foreach ($statements as $index => $statement) {
            $statement->setAttribute('markerBlock', $block);
            // Declarations are hoisted and comments are not executed, so they do not follow an exit.
            if (!($statement instanceof Stmt\Function_ || $statement instanceof Stmt\ClassLike || $statement instanceof Stmt\Nop)) {
                $last = $index;
            }
        }
        if ($last !== null) {
            $statements[$last]->setAttribute('markerLast', true);
        }
    }

    /** @param array{name: string, events: list<array{type: string, line: int, key: string, blocks: list<int>, branches: list<array{0: int, 1: int}>, block: int, last: bool}>} $scope */
    private function close(array $scope): void
    {
        $events = $scope['events'];
        foreach ($events as $index => $marker) {
            if ($marker['type'] !== 'marker') {
                continue;
            }
            foreach (array_slice($events, $index + 1) as $later) {
                if ($later['type'] !== 'exit') {
                    continue;
                }
                // Ending the block that holds the marker finishes the run it records, and nothing after it runs.
                if ($later['last'] && in_array($later['block'], $marker['blocks'], true)) {
                    break;
                }
                if (self::exclusive($marker['branches'], $later['branches'])) {
                    continue;
                }
                $this->findings[] = ['scope' => $scope['name'], 'key' => $marker['key'], 'line' => $marker['line'], 'exit' => $later['line']];
                break;
            }
        }
    }

    private function scopeName(Node\FunctionLike $node): string
    {
        $parent = $this->stack[count($this->stack) - 1]['name'];
        if ($node instanceof Stmt\Function_) {
            return $node->name->toString();
        }
        if ($node instanceof Stmt\ClassMethod) {
            return $node->name->toString() . '()';
        }
        // Closures have no name; number them within their parent so a shift in lines does not rename them.
        $this->closures[$parent] = ($this->closures[$parent] ?? 0) + 1;

        return $parent . '>closure#' . $this->closures[$parent];
    }
}

/** @return list<string> */
function tracked_php_files(string $root): array
{
    $output = [];
    $status = 0;
    exec('git -C ' . escapeshellarg($root) . ' ls-files -z -- ' . escapeshellarg('*.php'), $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('git ls-files failed in ' . $root);
    }
    $files = array_filter(explode("\0", implode("\n", $output)), static fn(string $file): bool => $file !== '' && preg_match(EXCLUDED, $file) !== 1);
    sort($files);

    return array_values($files);
}

/** @return list<string> sorted tab-separated rows: file, scope, key, ordinal */
function scan(string $root, array $files, array &$messages): array
{
    $parser = (new ParserFactory())->createForHostVersion();
    $traverser = new NodeTraverser();
    $visitor = new MarkerScopeVisitor();
    $traverser->addVisitor($visitor);
    $rows = [];

    foreach ($files as $file) {
        $code = file_get_contents($root . '/' . $file);
        if ($code === false) {
            throw new RuntimeException('Unable to read ' . $file);
        }
        try {
            $ast = $parser->parse($code);
        } catch (ParseError $error) {
            throw new RuntimeException('Unable to parse ' . $file . ': ' . $error->getMessage());
        }
        if ($ast === null) {
            throw new RuntimeException('Unable to parse ' . $file);
        }
        $traverser->traverse($ast);
        $seen = [];
        foreach ($visitor->findings as $finding) {
            $identity = $file . "\t" . $finding['scope'] . "\t" . str_replace(["\t", "\n"], ' ', $finding['key']);
            $seen[$identity] = ($seen[$identity] ?? 0) + 1;
            $row = $identity . "\t" . $seen[$identity];
            $rows[] = $row;
            $messages[$row] = sprintf('%s:%d: %s is recorded before the exit at line %d in %s', $file, $finding['line'], $finding['key'], $finding['exit'], $finding['scope']);
        }
    }
    sort($rows);

    return $rows;
}

function main(array $argv): int
{
    $root = dirname(__DIR__, 2);
    $baseline = __DIR__ . '/baselines/marker_before_exit.baseline.tsv';
    $write = false;
    $files = [];
    for ($i = 1; $i < count($argv); $i++) {
        if ($argv[$i] === '--root' && isset($argv[$i + 1])) {
            $root = $argv[++$i];
        } elseif ($argv[$i] === '--baseline' && isset($argv[$i + 1])) {
            $baseline = $argv[++$i];
        } elseif ($argv[$i] === '--write-baseline') {
            $write = true;
        } elseif (str_starts_with($argv[$i], '--')) {
            fwrite(STDERR, 'Unknown option ' . $argv[$i] . "\n");

            return 2;
        } else {
            $files[] = $argv[$i];
        }
    }

    try {
        if ($files === []) {
            $files = tracked_php_files($root);
        }
        if ($files === []) {
            throw new RuntimeException('No PHP files to check');
        }
        $messages = [];
        $rows = scan($root, $files, $messages);
    } catch (RuntimeException $error) {
        fwrite(STDERR, 'ERROR: ' . $error->getMessage() . "\n");

        return 2;
    }

    if ($write) {
        $header = "# file\tscope\tmarker key\tordinal; reviewed matches of check_marker_before_exit.php\n";
        if (file_put_contents($baseline, $header . implode("\n", $rows) . ($rows === [] ? '' : "\n")) === false) {
            fwrite(STDERR, 'ERROR: Unable to write ' . $baseline . "\n");

            return 2;
        }
        printf("Wrote %d rows to %s\n", count($rows), $baseline);

        return 0;
    }

    $expected = @file($baseline, FILE_IGNORE_NEW_LINES);
    if ($expected === false) {
        fwrite(STDERR, 'ERROR: Unable to read ' . $baseline . "\n");

        return 2;
    }
    $expected = array_values(array_filter($expected, static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')));
    $new = array_diff($rows, $expected);
    $stale = array_diff($expected, $rows);

    foreach ($new as $row) {
        printf("::error::%s\n", $messages[$row]);
    }
    foreach ($stale as $row) {
        printf("::error::baseline row no longer matches: %s\n", str_replace("\t", ' | ', $row));
    }
    printf("%d files, %d matches, %d new, %d stale\n", count($files), count($rows), count($new), count($stale));
    if ($new !== []) {
        echo "Record the marker after the work it claims, or add the row to the baseline after review.\n";
    }

    return ($new === [] && $stale === []) ? 0 : 1;
}

exit(main($argv));
