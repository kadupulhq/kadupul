<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Inventory\Contract\CacheDiagnostics;
use Kadupul\Platform\Application\Port\ProductVersion;
use Kadupul\Platform\Application\Port\SystemDiagnostics;
use Kadupul\Platform\Application\ReadModel\DiagnosticPage;
use Kadupul\Platform\Domain\Diagnostics\DiagnosticFilters;
use Kadupul\Platform\Domain\Diagnostics\DiagnosticScreen;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(SystemDiagnostics::class)]
final readonly class DbalSystemDiagnostics implements SystemDiagnostics
{
    // Explicit names prevent a newly installed database plugin from exposing a
    // credential through SHOW GLOBAL VARIABLES. Unknown variables stay private.
    private const array DATABASE_SETTINGS = [
        'version', 'version_comment', 'character_set_server', 'collation_server',
        'max_connections', 'max_allowed_packet', 'wait_timeout', 'interactive_timeout',
        'connect_timeout', 'net_read_timeout', 'net_write_timeout', 'sql_mode',
        'transaction_isolation', 'tx_isolation', 'autocommit', 'time_zone', 'system_time_zone',
        'innodb_buffer_pool_size', 'innodb_buffer_pool_instances', 'innodb_log_buffer_size',
        'innodb_log_file_size', 'innodb_redo_log_capacity', 'innodb_flush_log_at_trx_commit',
        'innodb_flush_method', 'innodb_file_per_table', 'innodb_doublewrite',
        'innodb_read_io_threads', 'innodb_write_io_threads', 'innodb_io_capacity',
        'innodb_io_capacity_max', 'innodb_lock_wait_timeout', 'innodb_deadlock_detect',
        'innodb_strict_mode', 'innodb_stats_on_metadata', 'innodb_stats_persistent',
        'innodb_adaptive_hash_index', 'innodb_autoinc_lock_mode',
        'key_buffer_size', 'query_cache_size', 'query_cache_type', 'tmp_table_size',
        'max_heap_table_size', 'sort_buffer_size', 'read_buffer_size', 'read_rnd_buffer_size',
        'join_buffer_size', 'thread_stack', 'thread_cache_size', 'table_open_cache',
        'table_definition_cache', 'binlog_cache_size', 'log_bin', 'binlog_format',
        'sync_binlog', 'performance_schema', 'lower_case_table_names', 'max_execution_time',
    ];
    private const array SUMMARY_SETTINGS = [
        'poller_interval', 'poller_type', 'php_servers', 'rrdtool_version', 'rsa_fingerprint',
        'storage_location', 'snmpagent_enable',
    ];
    private const array BOOST_SETTINGS = [
        'boost_last_run_time', 'boost_next_run_time', 'boost_last_end_time', 'boost_poller_status',
        'boost_rrd_update_enable', 'boost_png_cache_enable', 'boost_rrd_update_max_records',
        'boost_rrd_update_max_runtime', 'boost_rrd_update_interval', 'boost_peak_memory',
        'stats_detail_boost', 'stats_boost', 'boost_poller_mem_limit', 'boost_parallel',
        'boost_max_output_length', 'boost_png_cache_directory',
    ];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        private CacheDiagnostics $caches,
        private ProductVersion $version,
        private string $projectDir,
    ) {}

    public function read(DiagnosticScreen $screen, DiagnosticFilters $filters, int $actorId): DiagnosticPage
    {
        return match ($screen) {
            DiagnosticScreen::Menu => new DiagnosticPage([], []),
            DiagnosticScreen::Summary => $this->summary($filters),
            DiagnosticScreen::Database => $this->tables($filters),
            DiagnosticScreen::DatabaseSettings => $this->variables($filters, false),
            DiagnosticScreen::DatabaseStatus => $this->variables($filters, true),
            DiagnosticScreen::Php => $this->php($filters),
            DiagnosticScreen::Changelog => $this->changelog($filters),
            DiagnosticScreen::Boost => $this->boost($filters),
            DiagnosticScreen::PollerCache, DiagnosticScreen::DataQueryCache => $this->cache($screen, $filters, $actorId),
        };
    }

    private function summary(DiagnosticFilters $filters): DiagnosticPage
    {
        $counts = $this->database->fetchNumeric("SELECT
            (SELECT COUNT(*) FROM host WHERE deleted = ''),
            (SELECT COUNT(*) FROM graph_local),
            (SELECT COUNT(*) FROM data_template_data WHERE local_data_id <> 0),
            (SELECT COUNT(*) FROM poller_item), VERSION()");
        if ($counts === false) {
            throw new \RuntimeException('Missing installation summary.');
        }
        $rows = [
            ['Kadupul Version', $this->version->release()->version], ['Operating System', PHP_OS_FAMILY],
            ['PHP Version', PHP_VERSION], ['PHP SAPI', PHP_SAPI], ['Devices', $counts[0]],
            ['Graphs', $counts[1]], ['Data Sources', $counts[2]], ['Poller Items', $counts[3]],
            ['Database Version', $counts[4]],
        ];
        array_push($rows, ...$this->settings(self::SUMMARY_SETTINGS));
        return $this->page(['Name', 'Value'], $rows, $filters);
    }

    private function tables(DiagnosticFilters $filters): DiagnosticPage
    {
        $rows = $this->database->fetchAllNumeric('SELECT TABLE_NAME, ENGINE, TABLE_ROWS,
            AVG_ROW_LENGTH, DATA_LENGTH, INDEX_LENGTH, TABLE_COLLATION, ROW_FORMAT, TABLE_COMMENT
            FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?
            ORDER BY TABLE_NAME LIMIT ' . ($filters->rows + 1) . ' OFFSET ' . $filters->offset(), ['%' . $filters->query['filter'] . '%']);
        return new DiagnosticPage(['Table', 'Engine', 'Estimated Rows', 'Average Row Length', 'Data Length', 'Index Length', 'Collation', 'Row Format', 'Comment'], array_slice($rows, 0, $filters->rows), count($rows) > $filters->rows);
    }

    private function variables(DiagnosticFilters $filters, bool $status): DiagnosticPage
    {
        // SHOW has no portable LIMIT; server variable sets are finite. Read only
        // the approved names, or numeric status counters, into the view model.
        $values = $this->database->fetchAllNumeric($status ? 'SHOW GLOBAL STATUS' : 'SHOW GLOBAL VARIABLES');
        $rows = [];
        foreach ($values as [$name, $value]) {
            if (($status && preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D', (string) $value))
                || (!$status && in_array(strtolower($name), self::DATABASE_SETTINGS, true))) {
                $rows[] = [$name, $value];
            }
        }
        usort($rows, static fn(array $a, array $b): int => strcasecmp($a[0], $b[0]));
        return $this->page(['Name', 'Value'], $rows, $filters);
    }

    private function php(DiagnosticFilters $filters): DiagnosticPage
    {
        $rows = [['PHP Version', PHP_VERSION], ['PHP SAPI', PHP_SAPI], ['Operating System', PHP_OS_FAMILY]];
        foreach (['memory_limit', 'max_execution_time', 'max_input_vars', 'post_max_size', 'upload_max_filesize', 'date.timezone', 'default_charset', 'display_errors', 'log_errors', 'opcache.enable', 'opcache.memory_consumption', 'session.save_handler'] as $name) {
            $rows[] = [$name, (string) ini_get($name)];
        }
        $extensions = get_loaded_extensions();
        sort($extensions, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($extensions as $extension) {
            $rows[] = [$extension, phpversion($extension) ?: 'Installed'];
        }
        return $this->page(['Name', 'Value'], $rows, $filters);
    }

    private function changelog(DiagnosticFilters $filters): DiagnosticPage
    {
        $handle = @fopen($this->projectDir . '/CHANGELOG.md', 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open ChangeLog.');
        }
        try {
            $text = stream_get_contents($handle, 2 * 1024 * 1024 + 1);
            if ($text === false || strlen($text) > 2 * 1024 * 1024 || !feof($handle)) {
                throw new \RuntimeException('Unable to read complete ChangeLog.');
            }
        } finally {
            fclose($handle);
        }
        $rows = array_map(static fn(string $line): array => [$line], preg_split('/\r?\n/', $text));
        return $this->page(['ChangeLog'], $rows, $filters);
    }

    private function boost(DiagnosticFilters $filters): DiagnosticPage
    {
        $rows = $this->settings(self::BOOST_SETTINGS);
        $storage = $this->database->fetchNumeric("SELECT
            COALESCE(SUM(CASE WHEN TABLE_NAME = 'poller_output_boost' THEN TABLE_ROWS ELSE 0 END), 0),
            COALESCE(SUM(CASE WHEN TABLE_NAME <> 'poller_output_boost' THEN TABLE_ROWS ELSE 0 END), 0),
            COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0)
            FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
            AND (TABLE_NAME = 'poller_output_boost' OR TABLE_NAME LIKE 'poller!_output!_boost!_arch!_%' ESCAPE '!')");
        if ($storage === false) {
            throw new \RuntimeException('Missing Boost storage statistics.');
        }
        [$pending, $archive, $bytes] = $storage;
        $rows[] = ['Estimated Pending Records', $pending];
        $rows[] = ['Estimated Archived Records', $archive];
        $rows[] = ['Allocated Storage Bytes', $bytes];
        $rows[] = ['Running Boost Processes', $this->database->fetchOne("SELECT COUNT(*) FROM processes WHERE tasktype = 'boost' AND taskname = 'child'")];
        $rows[] = ['Pending Data Sources', $this->database->fetchOne('SELECT COUNT(*) FROM poller_output_boost_local_data_ids')];
        return $this->page(['Name', 'Value'], $rows, $filters);
    }

    private function cache(DiagnosticScreen $screen, DiagnosticFilters $filters, int $actorId): DiagnosticPage
    {
        $poller = $screen === DiagnosticScreen::PollerCache;
        $rows = $this->caches->read($poller, $actorId, $filters->query, $filters->rows, $filters->offset());
        $columns = $poller
            ? ['Data Source', 'Device', 'Device ID', 'Local Data ID', 'Action', 'RRD Name', 'RRD Path', 'RRD Step', 'Next Step']
            : ['Device', 'Data Query', 'Index', 'Field Name', 'Field Value', 'OID'];
        return new DiagnosticPage($columns, array_slice($rows, 0, $filters->rows), count($rows) > $filters->rows);
    }

    /** @param list<string> $names @return list<list<string>> */
    private function settings(array $names): array
    {
        return $this->database->fetchAllNumeric('SELECT name, value FROM settings WHERE name IN (' . implode(',', array_fill(0, count($names), '?')) . ') ORDER BY name', $names);
    }

    private function page(array $columns, array $rows, DiagnosticFilters $filters): DiagnosticPage
    {
        if ($filters->query['filter'] !== '') {
            $search = $filters->query['filter'];
            $rows = array_values(array_filter($rows, static fn(array $row): bool => array_any($row, static fn(mixed $value): bool => mb_stripos((string) $value, $search) !== false)));
        }
        return new DiagnosticPage($columns, array_slice($rows, $filters->offset(), $filters->rows), count($rows) > $filters->offset() + $filters->rows);
    }
}
