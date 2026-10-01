<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class AutomationTreeReplacementNativeTest extends TestCase
{
    /** @dataProvider replacements */
    public function testNativeReplacementFailsClosedOrPreservesExactResults(array $scenario, array $expected, string $diagnostic): void
    {
        $state = $this->runNative($scenario + array('mode' => 'helper'));
        self::assertSame($expected, $state['result']);
        if ($diagnostic !== '') {
            self::assertStringContainsString('AUTOM8 WARNING:', $state['log']);
            self::assertStringContainsString($diagnostic, $state['log']);
            self::assertStringContainsString(json_encode($scenario['search'], JSON_INVALID_UTF8_SUBSTITUTE), $state['log']);
        } else {
            self::assertStringNotContainsString('WARNING:', $state['log']);
        }
        self::assertCount(2, $state['nodes']);
    }

    public static function replacements(): array
    {
        return array(
            'case insensitive' => array(array('search' => 'host-[0-9]+', 'replace' => 'Device', 'target' => 'HOST-17'), array('Device'), ''),
            'delimiter' => array(array('search' => 'sensor~[0-9]+', 'replace' => 'disk', 'target' => 'sensor~3'), array('disk'), ''),
            'rare delimiter' => array(array('search' => '^[~#%!@;`=/_]+$', 'replace' => 'matched', 'target' => '~#%!@;`=/_'), array('matched'), ''),
            'no delimiter' => array(array('search' => '^[~#%!@;`=/_' . chr(127) . ']+$', 'replace' => 'x', 'target' => 'y'), array(), 'no available delimiter'),
            'invalid' => array(array('search' => '(', 'replace' => 'x', 'target' => 'y'), array(), 'Internal error'),
            'limited' => array(array('search' => '^(a+)+$', 'replace' => 'matched', 'target' => str_repeat('a', 255) . '!'), array(), 'Backtrack limit exhausted'),
            'split empty segments' => array(array('search' => '^host$', 'replace' => 'A\\n\\nB\\n', 'target' => 'host'), array('A','B'), ''),
            'nonmatching' => array(array('search' => '^other$', 'replace' => 'x', 'target' => 'host'), array('host'), ''),
            'slash' => array(array('search' => 'eth(\\d+)/(\\d+)', 'replace' => 'Port$1-$2', 'target' => 'eth12/5'), array('Port12-5'), ''),
        );
    }

    /** @dataProvider handoffs */
    public function testNativeTreeHandoffCreatesOnlyCompleteNestedHeaders(array $scenario, array $titles): void
    {
        $state = $this->runNative($scenario + array('mode' => 'handoff', 'repeat' => true));
        self::assertSame('Parent', $state['nodes'][0]['title']);
        self::assertSame('Unrelated', $state['nodes'][1]['title']);
        self::assertSame(9, (int) $state['nodes'][1]['graph_tree_id']);
        self::assertSame($titles, array_column(array_slice($state['nodes'], 2), 'title'));
        $parent = 77;
        foreach (array_slice($state['nodes'], 2) as $node) {
            self::assertSame($parent, (int) $node['parent']);
            self::assertSame(8, (int) $node['graph_tree_id']);
            $parent = (int) $node['id'];
        }
        self::assertSame($parent, (int) $state['result']);
        if (!$titles) {
            self::assertStringContainsString('AUTOM8 WARNING:', $state['log']);
        }
    }

    public static function handoffs(): array
    {
        return array(
            'nested' => array(array('search' => '^host$', 'replace' => 'A\\nB', 'target' => 'host'), array('A','B')),
            'slash' => array(array('search' => 'eth(\\d+)/(\\d+)', 'replace' => 'Port$1-$2', 'target' => 'eth12/5'), array('Port12-5')),
            'invalid' => array(array('search' => '(', 'replace' => 'x', 'target' => 'y'), array()),
            'limited' => array(array('search' => '^(a+)+$', 'replace' => 'x', 'target' => str_repeat('a', 255) . '!'), array()),
        );
    }

    /** @dataProvider previews */
    public function testNativePreviewRendersCompleteReplacementOrEmptyCell(array $scenario, string $expected): void
    {
        $state = $this->runNative($scenario + array('mode' => 'preview'));
        $document = new DOMDocument();
        @$document->loadHTML($state['html']);
        $cells = (new DOMXPath($document))->query('//tr[@id="line7"]/td');
        self::assertCount(6, $cells);
        self::assertSame($expected, $cells->item(5)->textContent);
        if ($expected !== '') {
            self::assertStringContainsString('A<br>---&nbsp;B', $state['html']);
        }
        self::assertStringNotContainsString('Warning:', $state['html']);
        self::assertStringNotContainsString('Fatal error:', $state['html']);
        self::assertCount(2, $state['nodes']);
    }

    public static function previews(): array
    {
        return array(
            'nested' => array(array('search' => '^host$', 'replace' => 'A\\nB', 'target' => 'host'), "A---\xc2\xa0B"),
            'invalid' => array(array('search' => '(', 'replace' => 'x', 'target' => 'y'), ''),
            'limited' => array(array('search' => '^(a+)+$', 'replace' => 'x', 'target' => str_repeat('a', 255) . '!'), ''),
        );
    }

    protected function runNative(array $scenario): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/native-profile-deletion-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        try {
            $fixture = 'automation-tree-native.php';
            $command = array(PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'pcov.directory=/', '-d', 'error_reporting=24575', '-d', 'display_errors=stderr', $root . '/tests/Fixtures/' . $fixture, json_encode($scenario, JSON_THROW_ON_ERROR), $directory);
            if ($coverage !== null) {
                $command[] = $directory;
            }
            $environment = getenv();
            $process = proc_open($command, array(1 => array('pipe','w'),2 => array('pipe','w')), $pipes, $directory, $environment);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr . $stdout);
            self::assertSame('', $stderr);
            self::assertSame('', $stdout);
            $state = json_decode(file_get_contents($directory . '/result.json'), true, flags: JSON_THROW_ON_ERROR);
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $coverage->merge(unserialize(file_get_contents($reports[0])));
            }
            return $state;
        } finally {
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }
}
