<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/../Helpers/PestCodeCoverageCompatibility.php';
require_once __DIR__ . '/../Helpers/NativeChildCoverageEvidence.php';
require_once __DIR__ . '/../Helpers/PresentationMutationEvidence.php';

use PHPUnit\Framework\TestCase;

final class PresentationMutationNativeCoverageTest extends TestCase
{
    use \PestCodeCoverageCompatibility;

    #[\PHPUnit\Framework\Attributes\DataProvider('mutations')]
    public function testActualQueryAndColorMutationsPreserveAdjacentPersistedRecords(string $case): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/presentation-mutation-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $coverage = method_exists($this, 'getTestResultObject') ? $this->getTestResultObject()->getCodeCoverage() : null;
        try {
            $environment = getenv();
            $environment['PRESENTATION_MUTATION_COVERAGE'] = $coverage === null ? '0' : '1';
            $process = proc_open(
                array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr',
                    '-d', 'date.timezone=UTC', '-d', 'session.gc_maxlifetime=1440', '-d', 'upload_max_filesize=2M',
                    '-d', 'post_max_size=8M', '-d', 'memory_limit=512M', '-d', 'max_execution_time=0', '-d', 'default_charset=UTF-8',
                    '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
                    $root . '/tests/Fixtures/presentation-mutations-native.php', $root, $directory, $case),
                array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $directory . '/stdout', 'w'), 2 => array('file', $directory . '/stderr', 'w')),
                $pipes,
                $root,
                $environment
            );
            self::assertIsResource($process);
            $status = proc_close($process);
            $errors = file_get_contents($directory . '/stderr');
            self::assertSame(0, $status, $errors);
            self::assertSame('', $errors);
            $result = json_decode(file_get_contents($directory . '/outcome.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($case, $result['case']);
            self::assertSame(PresentationMutationEvidence::tables(), array_keys($result['after']));
            self::assertNotEmpty($result['queries']);
            $adjacentCache = static fn(array $rows): array => array_values(array_filter(
                $rows,
                static fn(array $row): bool => $row['snmp_query_id'] === 20
            ));
            self::assertSame($adjacentCache($result['before']['host_snmp_cache']), $adjacentCache($result['after']['host_snmp_cache']));
            if ($coverage !== null) {
                $sources = PresentationMutationEvidence::sources();
                $markers = PresentationMutationEvidence::markers($case);
                $page = str_starts_with($case, 'color-') ? 'color_templates_items.php' : 'data_queries.php';
                $hits = array($page, 'lib/database.php');
                $report = $directory . '/mutation.coverage';
                $child = NativeChildCoverageEvidence::load($report, $root, 'tests/Fixtures/presentation-mutations-native.php', $case, $sources, $markers, $hits);
                if ($case === 'query-remove') {
                    self::assertSame(count($sources) + count($markers) + 10, NativeChildCoverageEvidence::verifyRejections(
                        $report,
                        $root,
                        'tests/Fixtures/presentation-mutations-native.php',
                        $case,
                        $sources,
                        $markers,
                        $hits,
                        'cli/refresh_csrf.php'
                    ));
                }
                $coverage->merge($child);
            }
        } finally {
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }

    public static function mutations(): array
    {
        $cases = array();
        foreach (array('query-remove', 'query-remove-missing', 'query-item-remove', 'query-item-remove-missing',
            'color-up', 'color-down', 'color-up-first', 'color-down-last', 'color-missing', 'color-zero', 'color-gapped-down') as $case) {
            $cases[$case] = array($case);
        }
        if ($cases === array()) {
            throw new RuntimeException('Native persisted presentation scenario discovery is empty');
        }
        return $cases;
    }

    public function testOmittingAnAdmittedMutationCannotProduceACompletedOutcome(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root . '/tests/Fixtures/presentation-mutations-native.php');
        self::assertNotFalse($source);
        foreach (array('query-remove' => 'data_query_remove($id);', 'query-item-remove' => 'data_query_item_remove();',
            'color-up' => '$up ? aggregate_color_item_moveup() : aggregate_color_item_movedown();') as $case => $call) {
            $directory = sys_get_temp_dir() . '/presentation-mutation-control-' . bin2hex(random_bytes(8));
            self::assertTrue(mkdir($directory, 0700));
            try {
                $altered = str_replace($call, '/* Actual callee omitted for outcome rejection control. */', $source, $changed);
                self::assertSame(1, $changed);
                self::assertSame(strlen($altered), file_put_contents($directory . '/producer.php', $altered));
                $environment = getenv();
                $environment['PRESENTATION_MUTATION_COVERAGE'] = '0';
                $process = proc_open(
                    array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr',
                        '-d', 'date.timezone=UTC', $directory . '/producer.php', $root, $directory, $case),
                    array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $directory . '/stdout', 'w'), 2 => array('file', $directory . '/stderr', 'w')),
                    $pipes,
                    $root,
                    $environment
                );
                self::assertIsResource($process);
                self::assertSame(255, proc_close($process));
                $message = $case === 'query-remove' ? 'Actual query removal did not publish its replication CRC'
                    : 'Persisted presentation outcome/query budget mismatch: ' . $case;
                self::assertStringContainsString($message, file_get_contents($directory . '/stderr'));
                self::assertFileDoesNotExist($directory . '/outcome.json');
                self::assertSame(array(), glob($directory . '/*.coverage'));
            } finally {
                $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($entries as $entry) {
                    $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
                }
                rmdir($directory);
            }
        }
    }
}
