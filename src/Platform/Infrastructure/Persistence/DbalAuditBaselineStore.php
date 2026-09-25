<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Kadupul\Platform\Application\Port\AuditBaselineStore;
use Kadupul\Platform\Application\Port\AuditCatalog;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Domain\Schema\AuditBaseline;
use Kadupul\Platform\Domain\Schema\AuditSchemaDump;
use Kadupul\Platform\Domain\Schema\BaselineColumn;
use Kadupul\Platform\Domain\Schema\BaselineIndex;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The audit schema's file and tables. The script piped the file into the
 * mysql client with the password on its command line; this reads it with
 * AuditSchemaDump and inserts the rows with bound values. Every DDL
 * statement here is a constant: the table definitions are copied from
 * create_tables() and from the file, and a test keeps the second pair equal
 * to the file.
 */
final readonly class DbalAuditBaselineStore implements AuditBaselineStore
{
    public const string FILE = '/docs/audit_schema.sql';
    public const array TABLES = ['table_columns', 'table_indexes'];

    // cli/audit_database.php:948-958 and 967-982 before the move.
    private const string CREATE_COLUMNS = "CREATE TABLE IF NOT EXISTS table_columns (
        table_name varchar(50) NOT NULL,
        table_sequence int(10) unsigned NOT NULL,
        table_field varchar(50) NOT NULL,
        table_type varchar(50) default NULL,
        table_null varchar(10) default NULL,
        table_key varchar(4) default NULL,
        table_default varchar(50) default NULL,
        table_extra varchar(128) default NULL,
        PRIMARY KEY (table_name, table_sequence, table_field))
        ENGINE=InnoDB
        COMMENT='Holds Default Kadupul Table Definitions'";
    private const string CREATE_INDEXES = "CREATE TABLE IF NOT EXISTS table_indexes (
        idx_table_name varchar(50) NOT NULL,
        idx_non_unique int(10) unsigned default NULL,
        idx_key_name varchar(128) NOT NULL,
        idx_seq_in_index int(10) unsigned NOT NULL,
        idx_column_name varchar(50) NOT NULL,
        idx_collation varchar(10) default NULL,
        idx_cardinality int(10) unsigned default NULL,
        idx_sub_part varchar(50) default NULL,
        idx_packed varchar(128) default NULL,
        idx_null varchar(10) default NULL,
        idx_index_type varchar(20) default NULL,
        idx_comment varchar(128) default NULL,
        PRIMARY KEY (idx_table_name, idx_key_name, idx_seq_in_index, idx_column_name))
        ENGINE=InnoDB
        COMMENT='Holds Default Kadupul Index Definitions'";
    private const array RESET = ['TRUNCATE table_columns', 'TRUNCATE table_indexes'];
    // docs/audit_schema.sql: what a load left in place.
    public const string DUMP_COLUMNS = "CREATE TABLE `table_columns` (
  `table_name` varchar(50) NOT NULL,
  `table_sequence` int(10) unsigned NOT NULL,
  `table_field` varchar(50) NOT NULL,
  `table_type` varchar(50) DEFAULT NULL,
  `table_null` varchar(10) DEFAULT NULL,
  `table_key` varchar(4) DEFAULT NULL,
  `table_default` varchar(50) DEFAULT NULL,
  `table_extra` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`table_name`,`table_sequence`,`table_field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Holds Default Cacti Table Definitions'";
    public const string DUMP_INDEXES = "CREATE TABLE `table_indexes` (
  `idx_table_name` varchar(50) NOT NULL,
  `idx_non_unique` int(10) unsigned DEFAULT NULL,
  `idx_key_name` varchar(128) NOT NULL,
  `idx_seq_in_index` int(10) unsigned NOT NULL,
  `idx_column_name` varchar(50) NOT NULL,
  `idx_collation` varchar(10) DEFAULT NULL,
  `idx_cardinality` int(10) unsigned DEFAULT NULL,
  `idx_sub_part` varchar(50) DEFAULT NULL,
  `idx_packed` varchar(128) DEFAULT NULL,
  `idx_null` varchar(10) DEFAULT NULL,
  `idx_index_type` varchar(20) DEFAULT NULL,
  `idx_comment` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`idx_table_name`,`idx_key_name`,`idx_seq_in_index`,`idx_column_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Holds Default Cacti Index Definitions'";
    private const array REPLACE = ['DROP TABLE IF EXISTS `table_columns`', self::DUMP_COLUMNS, 'DROP TABLE IF EXISTS `table_indexes`', self::DUMP_INDEXES];
    private const string INSERT_COLUMN = 'INSERT INTO table_columns (table_name, table_sequence, table_field, table_type, table_null, table_key, table_default, table_extra)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
    private const string INSERT_INDEX = 'INSERT INTO table_indexes (idx_table_name, idx_non_unique, idx_key_name, idx_seq_in_index, idx_column_name,
        idx_collation, idx_cardinality, idx_sub_part, idx_packed, idx_null, idx_index_type, idx_comment)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    /** db_dump_data() preferred mariadb-dump when either of these existed (lib/database.php:2345-2348). */
    private const array MARIADB_DUMP = ['/usr/bin/mariadb-dump', '/usr/local/bin/mariadb-dump'];

    /** @param float $dumpTimeout seconds the dump may run; a test shortens it */
    public function __construct(
        private string $projectDir,
        private Filesystem $filesystem,
        private MaintenanceConnections $connections,
        private InstallationConfiguration $configuration,
        private float $dumpTimeout = 300.0,
    ) {}

    #[\Override]
    public function read(): ?AuditBaseline
    {
        try {
            $dump = $this->filesystem->readFile($this->projectDir . self::FILE);
        } catch (IOException) {
            return null;
        }

        return AuditSchemaDump::parse($dump);
    }

    #[\Override]
    public function reset(DatabaseTarget $target): ?string
    {
        // create_tables() checked each table exists after creating it, and
        // stopped at the first that did not. It never checked the TRUNCATEs.
        foreach ([self::CREATE_COLUMNS, self::CREATE_INDEXES] as $index => $create) {
            $this->connections->execute($target, $create);
            if (!$this->connections->tableCatalog($target)->has(self::TABLES[$index])) {
                return self::TABLES[$index];
            }
        }
        foreach (self::RESET as $truncate) {
            $this->connections->execute($target, $truncate);
        }

        return null;
    }

    #[\Override]
    public function replace(DatabaseTarget $target, AuditBaseline $baseline): bool
    {
        // DDL commits on its own, so the definitions go first, one statement
        // at a time, and only the rows share a transaction.
        foreach (self::REPLACE as $statement) {
            if (!$this->connections->execute($target, $statement)) {
                return false;
            }
        }
        $rows = [
            ...array_map(static fn(BaselineColumn $column): array => [self::INSERT_COLUMN, array_values($column->row())], $baseline->columnRows),
            ...array_map(static fn(BaselineIndex $index): array => [self::INSERT_INDEX, array_values($index->row())], $baseline->indexRows),
        ];

        return $rows === [] || $this->connections->write($target, $rows) !== null;
    }

    #[\Override]
    public function import(DatabaseTarget $target, AuditCatalog $catalog): bool
    {
        $rows = [];
        foreach ($catalog->tables() as $table) {
            foreach (array_values($table->columns) as $sequence => $column) {
                $rows[] = [self::INSERT_COLUMN, [$table->name, $sequence + 1, $column['Field'], $column['Type'], $column['Null'], $column['Key'], $column['Default'], $column['Extra']]];
            }
            foreach ($table->indexes as $index) {
                $rows[] = [self::INSERT_INDEX, array_values($index)];
            }
        }

        return $rows === [] || $this->connections->write($target, $rows) !== null;
    }

    #[\Override]
    public function dumpPath(): ?string
    {
        return is_dir($this->projectDir . '/docs') ? $this->projectDir . self::FILE : null;
    }

    #[\Override]
    public function export(DatabaseTarget $target): bool
    {
        $path = $this->dumpPath();
        $credentials = $this->configuration->databaseTargets()[$target->value] ?? null;
        if ($path === null || $credentials === null) {
            return false;
        }
        $database = (string) $credentials['database'];
        $binary = array_any(self::MARIADB_DUMP, static fn(string $file): bool => is_file($file)) ? 'mariadb-dump' : 'mysqldump';
        // db_dump_data()'s options, aimed at the configured server rather than
        // the client's default. The password travels in MYSQL_PWD, as
        // db_dump_data() passed it: both clients read it, and a process's
        // environment is readable only by its owner and root, where its
        // arguments are not. --user= and "--" keep a name that starts with
        // "-" from being read as another option.
        $process = new Process(
            [$binary, '--extended-insert=FALSE', ...self::connectionOptions($credentials), '--user=' . (string) $credentials['username'], '--', $database, ...self::TABLES],
            $this->projectDir,
            self::dumpEnvironment((string) $credentials['password']),
            null,
            $this->dumpTimeout,
        );
        // A timeout or a failed write is a failed export, as a non-zero exit
        // is: the use case records it and the run reports the error. Neither
        // exception's text is logged, since the timeout's names the command.
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->connections->log($target, 'DBCALL', 'ERROR: mysqldump timed out after ' . $this->dumpTimeout . " seconds for database '" . $database . "'");

            return false;
        }
        if (!$process->isSuccessful()) {
            $this->connections->log($target, 'DBCALL', 'ERROR: mysqldump failed with exit code ' . $process->getExitCode() . " for database '" . $database . "'");

            return false;
        }
        // Written only once the dump succeeded, and atomically, so a failure
        // leaves the previous file; the script truncated it first.
        try {
            $this->filesystem->dumpFile($path, $process->getOutput());
        } catch (IOException) {
            $this->connections->log($target, 'DBCALL', "ERROR: could not write the audit schema dump for database '" . $database . "'");

            return false;
        }

        return true;
    }

    /**
     * Host, port and, when the connection uses TLS, the files it names. No
     * --ssl-mode: the client's own default applies, as it does for the
     * connection settings themselves.
     *
     * @param array<string, mixed> $credentials one of databaseTargets()' sets
     * @return list<string>
     */
    private static function connectionOptions(#[\SensitiveParameter] array $credentials): array
    {
        $options = ['--host=' . (string) $credentials['host'], '--port=' . (string) $credentials['port']];
        // The same test DatabaseTls::options() applies to the connection.
        if ($credentials['ssl']) {
            foreach (['ssl_ca' => '--ssl-ca=', 'ssl_cert' => '--ssl-cert=', 'ssl_key' => '--ssl-key='] as $key => $option) {
                $file = (string) ($credentials[$key] ?? '');
                if ($file !== '') {
                    $options[] = $option . $file;
                }
            }
        }

        return $options;
    }

    /**
     * Only PATH, HOME and the password. Process passes on every variable it
     * is not told to drop, and the clients read MYSQL_HOST, MYSQL_TCP_PORT,
     * MYSQL_UNIX_PORT and others from it, which could send the dump to
     * another server; false drops a variable.
     *
     * @return array<string, string|false>
     */
    private static function dumpEnvironment(#[\SensitiveParameter] string $password): array
    {
        $environment = array_fill_keys(array_map('strval', array_keys(getenv() + $_ENV)), false);
        foreach (['PATH', 'HOME'] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $environment[$name] = $value;
            }
        }
        $environment['MYSQL_PWD'] = $password;

        return $environment;
    }
}
