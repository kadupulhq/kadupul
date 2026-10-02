<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

abstract class GraphItemOrderingContract extends TestCase
{
    /** @dataProvider scenarios */
    public function testOrderingUsesRealSqlAndKeepsOtherScopesUnchanged(string $scenario): void
    {
        $state = $this->runNative($scenario);
        $local = str_starts_with($scenario, 'graphs-') || str_contains($scenario, 'local');
        $filters = $local ? array(3) : array(2, 0);
        if (str_starts_with($scenario, 'sequence-')) {
            $expected = in_array($scenario, array('sequence-template', 'sequence-local'), true) ? 3 : 1;
            // MySQL coerces a bound numeric prefix to 3; SQLite does not.
            // Neither may evaluate the OR clause or include the other scope's sequence 50.
            if ($scenario === 'sequence-injection' && $this->useMysql()) {
                $expected = 3;
            }
            self::assertSame($expected, $state['result']);
            $filters = match ($scenario) {
                'sequence-local' => array(3),
                'sequence-injection' => array('3 OR 1=1'),
                'sequence-empty' => array(99),
                default => array(2, 0),
            };
            self::assertTrue($this->hasBoundQuery($state['queries'], 'SELECT max(sequence)+1', $filters));
            self::assertSame($state['before'], $state['after']);
            return;
        }
        if (str_contains($scenario, 'save')) {
            self::assertTrue($this->hasBoundQuery($state['queries'], 'SELECT max(sequence)+1', $filters));
            self::assertCount(1, $state['saved']);
            self::assertSame(3, $state['saved'][0]['sequence']);
            self::assertSame($local ? 3 : 0, (int) $state['saved'][0]['local_graph_id']);
            self::assertSame(3, $state['after'][20]);
            unset($state['after'][20]);
            self::assertSame($state['before'], $state['after']);
            return;
        }
        $expected = $state['before'];
        if (!str_contains($scenario, 'boundary')) {
            $base = $local ? 3 : 1;
            if (str_starts_with($scenario, 'group-')) {
                $expected[$base] = 3;
                $expected[$base + 1] = 4;
                $expected[11] = 1;
                $expected[12] = 2;
                self::assertTrue($this->hasBoundQuery($state['queries'], 'SELECT id, sequence', $filters));
            } else {
                $expected[$base] = 2;
                $expected[$base + 1] = 1;
                $sequence = str_ends_with($scenario, 'up') ? 2 : 1;
                self::assertTrue($this->hasBoundQuery($state['queries'], 'SELECT id FROM graph_templates_item WHERE sequence', array_merge(array($sequence), $filters)));
            }
        }
        self::assertSame($expected, $state['after'], 'Every unaffected template and local graph must retain its sequence');
    }

    public static function scenarios(): array
    {
        $result = array();
        foreach (array('templates-down', 'templates-up', 'templates-boundary-down', 'templates-boundary-up', 'graphs-down', 'graphs-up', 'graphs-boundary-down', 'graphs-boundary-up', 'templates-save', 'graphs-save', 'sequence-template', 'sequence-local', 'sequence-injection', 'sequence-empty', 'group-template-next', 'group-template-previous', 'group-local-next', 'group-local-previous') as $scenario) {
            $result[$scenario] = array($scenario);
        }
        return $result;
    }

    private function hasBoundQuery(array $queries, string $fragment, array $parameters): bool
    {
        foreach ($queries as [$sql, $values]) {
            $scopeParameters = str_contains($fragment, 'WHERE sequence') ? array_slice($parameters, 1) : $parameters;
            $scope = count($scopeParameters) === 2 ? '`graph_template_id` = ? AND `local_graph_id` = ?' : '`local_graph_id` = ?';
            if (str_contains($sql, $fragment) && $values === $parameters && str_contains($sql, $scope)) {
                return true;
            }
        }
        return false;
    }

    protected function useMysql(): bool
    {
        return false;
    }

    protected function runNative(string $scenario): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/native-graph-ordering-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        mkdir($directory . '/lib', 0700);
        foreach (array('include/auth.php', 'lib/api_data_source.php', 'lib/template.php', 'lib/utility.php', 'lib/poller.php') as $stub) {
            file_put_contents($directory . '/' . $stub, '<?php');
        }
        foreach (array('graph_templates_items.php', 'graphs_items.php', 'lib/graph_item_editor.php') as $source) {
            copy($root . '/' . $source, $directory . '/' . $source);
        }
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        try {
            $environment = getenv();
            $environment['NATIVE_ORDERING_MYSQL'] = $this->useMysql() ? '1' : '0';
            $environment['NATIVE_ORDERING_COVERAGE'] = $coverage !== null ? '1' : '0';
            $process = proc_open(array(PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'pcov.directory=/', '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', $root . '/tests/Fixtures/graph-ordering-native.php', $root, $directory, $scenario), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $directory, $environment);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr . $stdout);
            self::assertSame('', $stderr);
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                foreach ($reports as $report) {
                    $coverage->merge(unserialize(file_get_contents($report)));
                }
            }
            return json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        } finally {
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }
}
