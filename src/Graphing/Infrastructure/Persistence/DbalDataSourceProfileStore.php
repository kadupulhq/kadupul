<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\Graphing\Application\Port\DataSourceProfileAccess;
use Kadupul\Graphing\Application\Port\DataSourceProfileStore;
use Kadupul\Graphing\Domain\DataSourceProfile;
use Kadupul\Graphing\Domain\DataSourceProfileFilters;
use Kadupul\Graphing\Domain\DataSourceProfilePage;
use Kadupul\Graphing\Domain\DataSourceProfileRevision;
use Kadupul\Graphing\Domain\DataSourceProfileRra;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(DataSourceProfileStore::class)]
final readonly class DbalDataSourceProfileStore implements DataSourceProfileStore
{
    private const string STALE = 'The Data Source Profile changed since you opened this form. Reload before saving.';
    private const string ARCHIVE_STALE = 'The RRA changed since you opened this form. Reload before saving.';
    private const string NOT_FOUND = 'Data Source Profile not found.';
    // Distinct template and data source uses, as the legacy list grouped them.
    private const string USAGE = 'SELECT data_source_profile_id,
        SUM(CASE WHEN local_data_id > 0 THEN 1 ELSE 0 END) AS data_sources,
        SUM(CASE WHEN local_data_id = 0 THEN 1 ELSE 0 END) AS templates
        FROM (SELECT DISTINCT data_source_profile_id, data_template_id, local_data_id FROM data_template_data%s) d
        GROUP BY data_source_profile_id';
    private const string COLUMNS = 'p.id, p.hash, p.name, p.step, p.heartbeat, p.x_files_factor, p.`default`';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        private DataSourceProfileAccess $access,
        private AuditTrail $audit,
        private LegacyConfiguration $configuration,
    ) {}

    public function defaultRows(): int
    {
        $rows = filter_var($this->database->fetchOne("SELECT value FROM settings WHERE name = 'num_rows_table'"), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        return $rows === false ? 25 : $rows;
    }

    public function defaultHasData(): bool
    {
        return $this->database->fetchOne("SELECT value FROM settings WHERE name = 'default_has'") === 'on';
    }

    public function defaults(): array
    {
        $interval = filter_var($this->database->fetchOne("SELECT value FROM settings WHERE name = 'poller_interval'"), FILTER_VALIDATE_INT);
        $step = in_array($interval, DataSourceProfile::SAMPLING_INTERVALS, true) ? $interval : 300;
        $heartbeat = in_array($step * 2, DataSourceProfile::HEARTBEATS, true) ? $step * 2 : 600;
        return ['step' => $step, 'heartbeat' => $heartbeat];
    }

    public function list(DataSourceProfileFilters $filters): DataSourceProfilePage
    {
        $from = ' FROM data_source_profiles p LEFT JOIN (' . sprintf(self::USAGE, '') . ') u ON u.data_source_profile_id = p.id';
        $where = [];
        $params = [];
        if ($filters->filter !== '') {
            $where[] = "p.name LIKE ? ESCAPE '!'";
            $params[] = '%' . strtr($filters->filter, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        if ($filters->hasData) {
            $where[] = 'COALESCE(u.data_sources, 0) > 0';
        }
        $from .= $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $sort = ['name' => 'p.name', 'step' => 'p.step', 'heartbeat' => 'p.heartbeat', 'data_sources' => 'data_sources', 'templates' => 'templates'][$filters->sortColumn];
        $total = (int) $this->database->fetchOne('SELECT COUNT(*)' . $from, $params);
        $rows = $this->database->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ', COALESCE(u.data_sources, 0) AS data_sources, COALESCE(u.templates, 0) AS templates'
                . $from . ' ORDER BY ' . $sort . ' ' . $filters->sortDirection . ', p.id ASC LIMIT ? OFFSET ?',
            [...$params, $filters->rows, ($filters->page - 1) * $filters->rows],
            [...array_fill(0, count($params), ParameterType::STRING), ParameterType::INTEGER, ParameterType::INTEGER],
        );
        return new DataSourceProfilePage(array_map(fn(array $row): DataSourceProfile => $this->hydrate($row, (int) $row['data_sources'], (int) $row['templates']), $rows), $total, $filters);
    }

    public function find(int $id): ?DataSourceProfile
    {
        return $this->findMany([$id])[$id] ?? null;
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $usage = [];
        foreach ($this->database->fetchAllAssociative(sprintf(self::USAGE, " WHERE data_source_profile_id IN ($marks)"), $ids) as $row) {
            $usage[(int) $row['data_source_profile_id']] = [(int) $row['data_sources'], (int) $row['templates']];
        }
        $profiles = [];
        foreach ($this->database->fetchAllAssociative('SELECT ' . self::COLUMNS . " FROM data_source_profiles p WHERE p.id IN ($marks) ORDER BY p.id", $ids) as $parent) {
            $id = (int) $parent['id'];
            $profiles[$id] = $this->hydrate($parent, $usage[$id][0] ?? 0, $usage[$id][1] ?? 0, $this->functions($id, ''), $this->archives($id, ''));
        }
        return $profiles;
    }

    public function save(int $actorId, ?int $id, string $name, ?int $step, ?int $heartbeat, ?string $xFilesFactor, array $functions, bool $makeDefault, ?string $revision): array
    {
        DataSourceProfile::validateName($name);
        $factor = $xFilesFactor === null ? null : DataSourceProfile::xFilesFactor($xFilesFactor);
        $functions = self::functionSelection($functions);
        if ($id === null && ($step === null || $heartbeat === null || $factor === null)) {
            throw new \InvalidArgumentException('Choose a polling interval, heartbeat and X-Files Factor.');
        }
        return $this->write($actorId, $id === null ? 'create' : 'edit', $id === null ? 'new' : (string) $id, function () use ($id, $name, $step, $heartbeat, $factor, $functions, $makeDefault, $revision): array {
            if ($id === null) {
                if (!in_array($step, DataSourceProfile::SAMPLING_INTERVALS, true) || !in_array($heartbeat, DataSourceProfile::HEARTBEATS, true)) {
                    throw new \InvalidArgumentException('Choose a listed polling interval and heartbeat.');
                }
                if ($makeDefault) {
                    $this->database->executeStatement("UPDATE data_source_profiles SET `default` = ''");
                }
                // A 32-digit hex hash, the shape get_hash_data_source_profile() gives templates and exports.
                $this->database->insert('data_source_profiles', ['hash' => bin2hex(random_bytes(16)), 'name' => $name, 'step' => $step, 'heartbeat' => $heartbeat, 'x_files_factor' => $factor, '`default`' => $makeDefault ? 'on' : '']);
                $created = (int) $this->database->lastInsertId();
                if ($created < 1) {
                    throw new \RuntimeException('Data Source Profile creation was not confirmed.');
                }
                foreach ($functions as $function) {
                    $this->database->insert('data_source_profiles_cf', ['data_source_profile_id' => $created, 'consolidation_function_id' => $function]);
                }
                return ['id' => $created, 'retune' => false];
            }
            [$parent, $stored] = $this->lockProfile($id, $revision);
            // Stored values that are not on the legacy lists stay valid; only new choices are limited to them.
            if ($step !== null && $step !== (int) $parent['step'] && !in_array($step, DataSourceProfile::SAMPLING_INTERVALS, true)) {
                throw new \InvalidArgumentException('Choose a listed polling interval and heartbeat.');
            }
            if ($heartbeat !== null && $heartbeat !== (int) $parent['heartbeat'] && !in_array($heartbeat, DataSourceProfile::HEARTBEATS, true)) {
                throw new \InvalidArgumentException('Choose a listed polling interval and heartbeat.');
            }
            $localSources = $this->localSources($id);
            $structural = ($step !== null && $step !== (int) $parent['step'])
                || ($factor !== null && $factor !== (float) $parent['x_files_factor'])
                || ($functions !== [] && $functions !== $stored);
            if ($localSources > 0 && $structural) {
                throw new \InvalidArgumentException(DataSourceProfileStore::READ_ONLY);
            }
            if ($makeDefault) {
                $this->database->executeStatement("UPDATE data_source_profiles SET `default` = '' WHERE id <> ?", [$id]);
            }
            $hash = (string) $parent['hash'];
            $changes = [
                // Keep a valid hash so templates and exports still match; replace a damaged one, as get_hash_data_source_profile() does.
                'hash' => strlen($hash) === 32 && ctype_xdigit($hash) ? $hash : bin2hex(random_bytes(16)),
                'name' => $name,
                'step' => $step ?? (int) $parent['step'],
                'heartbeat' => $heartbeat ?? (int) $parent['heartbeat'],
                'x_files_factor' => $factor ?? (float) $parent['x_files_factor'],
            ];
            if ($makeDefault) {
                $changes['`default`'] = 'on';
            }
            $this->database->update('data_source_profiles', $changes, ['id' => $id]);
            if ($functions !== [] && $functions !== $stored) {
                $this->database->executeStatement('DELETE FROM data_source_profiles_cf WHERE data_source_profile_id = ?', [$id]);
                foreach ($functions as $function) {
                    $this->database->insert('data_source_profiles_cf', ['data_source_profile_id' => $id, 'consolidation_function_id' => $function]);
                }
            }
            $retune = false;
            if ($heartbeat !== null && $heartbeat !== (int) $parent['heartbeat']) {
                // Templates and data sources take the profile heartbeat. A local
                // row matches its own data source; a template row only its own
                // template, so another template's sources are not touched.
                $join = 'INNER JOIN data_template_data AS dtd ON dtd.local_data_id = dtr.local_data_id
                    AND (dtr.local_data_id > 0 OR dtd.data_template_id = dtr.data_template_id)';
                $this->database->executeStatement($this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform
                    ? "UPDATE data_template_rrd AS dtr $join SET dtr.rrd_heartbeat = ? WHERE dtd.data_source_profile_id = ?"
                    : "UPDATE data_template_rrd SET rrd_heartbeat = ? WHERE id IN (SELECT dtr.id FROM data_template_rrd AS dtr $join WHERE dtd.data_source_profile_id = ?)", [$heartbeat, $id]);
                $retune = $localSources > 0;
            }
            return ['id' => $id, 'retune' => $retune];
        });
    }

    public function saveArchive(int $actorId, int $profileId, ?int $archiveId, string $name, ?int $levelSeconds, ?int $rows, int $timespan, ?string $revision): int
    {
        DataSourceProfileRra::validateName($name);
        if ($rows !== null) {
            DataSourceProfileRra::validateRows($rows);
        }
        if ($timespan < 0 || $timespan > DataSourceProfileRra::MAX_ROWS) {
            throw new \InvalidArgumentException('Choose a default timespan.');
        }
        if ($archiveId === null && ($levelSeconds === null || $rows === null)) {
            throw new \InvalidArgumentException('Choose an aggregation level and a number of rows.');
        }
        return $this->write($actorId, $archiveId === null ? 'rra.create' : 'rra.edit', (string) $profileId, function () use ($profileId, $archiveId, $name, $levelSeconds, $rows, $timespan, $revision): int {
            $parent = $this->lockParent($profileId);
            $readOnly = $this->localSources($profileId) > 0;
            $steps = $levelSeconds === null ? null : DataSourceProfileRra::stepsFor($levelSeconds, (int) $parent['step']);
            if ($archiveId === null) {
                if ($readOnly) {
                    throw new \InvalidArgumentException(DataSourceProfileStore::READ_ONLY);
                }
                $this->database->insert('data_source_profiles_rra', ['data_source_profile_id' => $profileId, 'name' => $name, 'steps' => $steps, '`rows`' => $rows, 'timespan' => $timespan]);
                $created = (int) $this->database->lastInsertId();
                if ($created < 1) {
                    throw new \RuntimeException('RRA creation was not confirmed.');
                }
                return $created;
            }
            $archive = $this->lockArchive($profileId, $archiveId, $revision);
            if ($readOnly && (($steps !== null && $steps !== $archive->steps) || ($rows !== null && $rows !== $archive->rows))) {
                throw new \InvalidArgumentException(DataSourceProfileStore::READ_ONLY);
            }
            $this->database->update('data_source_profiles_rra', ['name' => $name, 'steps' => $steps ?? $archive->steps, '`rows`' => $rows ?? $archive->rows, 'timespan' => $timespan], ['id' => $archiveId, 'data_source_profile_id' => $profileId]);
            return $archiveId;
        });
    }

    public function deleteArchive(int $actorId, int $profileId, int $archiveId, string $revision): void
    {
        $this->write($actorId, 'rra.delete', (string) $profileId, function () use ($profileId, $archiveId, $revision): void {
            $this->lockParent($profileId);
            $this->lockArchive($profileId, $archiveId, $revision);
            // The legacy page only hid the delete marker of a read-only profile.
            if ($this->localSources($profileId) > 0) {
                throw new \InvalidArgumentException(DataSourceProfileStore::READ_ONLY);
            }
            if ($this->database->executeStatement('DELETE FROM data_source_profiles_rra WHERE id = ? AND data_source_profile_id = ?', [$archiveId, $profileId]) !== 1) {
                throw new \RuntimeException('RRA removal was not confirmed.');
            }
        });
    }

    public function duplicate(int $actorId, array $ids, array $revisions, string $titleFormat): void
    {
        self::validateSelection($ids, $revisions);
        if (mb_strlen($titleFormat, 'UTF-8') > 255 || preg_match('//u', $titleFormat) !== 1 || preg_match('/[\x00\r\n]/', $titleFormat) === 1) {
            throw new \InvalidArgumentException('Enter a valid title format.');
        }
        $this->write($actorId, 'duplicate', self::target($ids), function () use ($ids, $revisions, $titleFormat): void {
            foreach ($ids as $id) {
                [$parent, $functions, $archives] = $this->lockProfile($id, $revisions[$id]);
                // The copy is never the default; its hash is new, as get_hash_data_source_profile(0) made it.
                $this->database->insert('data_source_profiles', [
                    'hash' => bin2hex(random_bytes(16)),
                    'name' => DataSourceProfile::duplicateName($titleFormat, (string) $parent['name']),
                    'step' => (int) $parent['step'],
                    'heartbeat' => (int) $parent['heartbeat'],
                    'x_files_factor' => (float) $parent['x_files_factor'],
                    '`default`' => '',
                ]);
                $copy = (int) $this->database->lastInsertId();
                if ($copy < 1) {
                    throw new \RuntimeException('Data Source Profile duplication was not confirmed.');
                }
                foreach ($functions as $function) {
                    $this->database->insert('data_source_profiles_cf', ['data_source_profile_id' => $copy, 'consolidation_function_id' => $function]);
                }
                foreach ($archives as $archive) {
                    $this->database->insert('data_source_profiles_rra', ['data_source_profile_id' => $copy, 'name' => $archive->name, 'steps' => $archive->steps, '`rows`' => $archive->rows, 'timespan' => $archive->timespan]);
                }
            }
        });
    }

    public function delete(int $actorId, array $ids, array $revisions): void
    {
        self::validateSelection($ids, $revisions);
        $this->write($actorId, 'delete', self::target($ids), function () use ($ids, $revisions): void {
            foreach ($ids as $id) {
                [$parent] = $this->lockProfile($id, $revisions[$id]);
                if ($parent['default'] === 'on') {
                    throw new \InvalidArgumentException('The default Data Source Profile cannot be deleted.');
                }
            }
            $marks = implode(',', array_fill(0, count($ids), '?'));
            // Under the parent locks, a locking read also covers the index gap,
            // so no new template or data source can take a selected profile.
            if ($this->database->fetchOne("SELECT data_source_profile_id FROM data_template_data WHERE data_source_profile_id IN ($marks) LIMIT 1" . $this->forUpdate(), $ids) !== false) {
                throw new \InvalidArgumentException('Data Source Profiles in use by Data Templates or Data Sources can not be deleted.');
            }
            $this->database->executeStatement("DELETE FROM data_source_profiles_cf WHERE data_source_profile_id IN ($marks)", $ids);
            $this->database->executeStatement("DELETE FROM data_source_profiles_rra WHERE data_source_profile_id IN ($marks)", $ids);
            if ($this->database->executeStatement("DELETE FROM data_source_profiles WHERE id IN ($marks)", $ids) !== count($ids)) {
                throw new \RuntimeException('Data Source Profile deletion was not confirmed.');
            }
        });
    }

    /**
     * Lock the profile, its functions and archives, then refuse a stale form.
     *
     * @return array{array<string, mixed>, list<int>, list<DataSourceProfileRra>}
     */
    private function lockProfile(int $id, ?string $revision): array
    {
        $parent = $this->lockParent($id);
        $functions = $this->functions($id, $this->forUpdate());
        $archives = $this->archives($id, $this->forUpdate());
        if ($revision === null || !hash_equals(DataSourceProfileRevision::of($parent, $functions, $archives), $revision)) {
            throw new \InvalidArgumentException(self::STALE);
        }
        return [$parent, $functions, $archives];
    }

    /** @return array<string, mixed> */
    private function lockParent(int $id): array
    {
        $parent = $this->database->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM data_source_profiles p WHERE p.id = ?' . $this->forUpdate(), [$id]);
        if ($parent === false) {
            throw new \InvalidArgumentException(self::NOT_FOUND);
        }
        return $parent;
    }

    private function lockArchive(int $profileId, int $archiveId, ?string $revision): DataSourceProfileRra
    {
        $row = $this->database->fetchAssociative('SELECT id, data_source_profile_id, name, steps, `rows`, timespan FROM data_source_profiles_rra WHERE id = ? AND data_source_profile_id = ?' . $this->forUpdate(), [$archiveId, $profileId]);
        if ($row === false) {
            throw new \InvalidArgumentException('RRA not found.');
        }
        $archive = self::archive($row);
        if ($revision === null || !hash_equals($archive->revision, $revision)) {
            throw new \InvalidArgumentException(self::ARCHIVE_STALE);
        }
        return $archive;
    }

    /**
     * Data sources whose RRDfiles were built from the profile. The caller holds
     * the parent lock, which every reference writer's guard trigger waits for,
     * so the count cannot grow before commit.
     */
    private function localSources(int $id): int
    {
        return (int) $this->database->fetchOne('SELECT COUNT(*) FROM data_template_data WHERE data_source_profile_id = ? AND local_data_id > 0', [$id]);
    }

    /** @return list<int> */
    private function functions(int $id, string $lock): array
    {
        return array_map('intval', $this->database->fetchFirstColumn('SELECT consolidation_function_id FROM data_source_profiles_cf WHERE data_source_profile_id = ? ORDER BY consolidation_function_id' . $lock, [$id]));
    }

    /** @return list<DataSourceProfileRra> in the legacy steps order */
    private function archives(int $id, string $lock): array
    {
        return array_map(self::archive(...), $this->database->fetchAllAssociative('SELECT id, data_source_profile_id, name, steps, `rows`, timespan FROM data_source_profiles_rra WHERE data_source_profile_id = ? ORDER BY steps, id' . $lock, [$id]));
    }

    /** @param array<string, mixed> $row */
    private static function archive(array $row): DataSourceProfileRra
    {
        return new DataSourceProfileRra((int) $row['id'], (int) $row['data_source_profile_id'], (string) $row['name'], (int) $row['steps'], (int) $row['rows'], (int) $row['timespan']);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<int> $functions
     * @param list<DataSourceProfileRra>|null $archives null for list rows, which carry no revision
     */
    private function hydrate(array $row, int $dataSources, int $templates, array $functions = [], ?array $archives = null): DataSourceProfile
    {
        return new DataSourceProfile(
            (int) $row['id'],
            (string) $row['name'],
            (int) $row['step'],
            (int) $row['heartbeat'],
            (float) $row['x_files_factor'],
            $row['default'] === 'on',
            $dataSources,
            $templates,
            $archives === null ? '' : DataSourceProfileRevision::of($row, $functions, $archives),
            $functions,
            $archives ?? [],
        );
    }

    /**
     * @param list<int> $functions
     * @return list<int>
     */
    private static function functionSelection(array $functions): array
    {
        if (count($functions) > count(DataSourceProfile::CONSOLIDATION_FUNCTIONS)
            || array_any($functions, static fn(mixed $function): bool => !is_int($function) || !isset(DataSourceProfile::CONSOLIDATION_FUNCTIONS[$function]))) {
            throw new \InvalidArgumentException('Choose valid consolidation functions.');
        }
        $functions = array_values(array_unique($functions));
        sort($functions, SORT_NUMERIC);
        return $functions;
    }

    /**
     * @param list<int> $ids
     * @param array<int, string> $revisions
     */
    private static function validateSelection(array $ids, array $revisions): void
    {
        $sorted = $ids;
        sort($sorted, SORT_NUMERIC);
        if ($ids === [] || count($ids) > self::MAX_SELECTION || !array_is_list($ids) || $sorted !== $ids
            || count(array_unique($ids)) !== count($ids)
            || array_any($ids, static fn(mixed $id): bool => !is_int($id) || $id < 1 || $id > 16777215)) {
            throw new \InvalidArgumentException('Invalid profile selection.');
        }
        $keys = array_keys($revisions);
        sort($keys, SORT_NUMERIC);
        if ($keys !== $ids || array_any($revisions, static fn(mixed $revision): bool => !is_string($revision))) {
            throw new \InvalidArgumentException(self::STALE);
        }
    }

    /** @param list<int> $ids */
    private static function target(array $ids): string
    {
        return count($ids) === 1 ? (string) $ids[0] : 'selection-' . substr(hash('sha256', implode(',', $ids)), 0, 32);
    }

    private function write(int $actorId, string $action, string $target, callable $operation): mixed
    {
        $decision = AuditEvent::DENIED;
        $outcome = AuditEvent::DENIED;
        try {
            $this->prepareWrite();
            $this->database->beginTransaction();
            try {
                $this->access->assertCurrent($actorId);
                $decision = AuditEvent::ALLOWED;
                $outcome = AuditEvent::FAILED;
                $result = $operation();
                $this->database->commit();
            } catch (\Throwable $error) {
                if ($this->database->isTransactionActive()) {
                    $this->database->rollBack();
                }
                throw $error;
            }
            $outcome = AuditEvent::SUCCEEDED;
            return $result;
        } finally {
            try {
                $this->audit->record(new AuditEvent(bin2hex(random_bytes(16)), $actorId, 'graphing.data_source_profile.' . $action, 'data_source_profile', $target, $decision, $outcome));
            } catch (\Throwable) { /* Audit failure cannot change a confirmed persistence result. */
            }
        }
    }

    private function prepareWrite(): void
    {
        if ($this->database->isTransactionActive()) {
            throw new \RuntimeException('Data Source Profile writes require their own transaction.');
        }
        if (!$this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }
        if (($this->configuration->values()['collector_id'] ?? null) !== 1) {
            throw new \RuntimeException('Data Source Profiles must be changed on the primary collector.');
        }
        foreach (['data_source_profiles', 'data_source_profiles_rra', 'data_source_profiles_cf', 'data_template_data', 'data_template_rrd',
            'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'] as $table) {
            // SHOW CREATE TABLE sees a temporary table that shadows the real one.
            $definition = $this->database->fetchNumeric('SHOW CREATE TABLE ' . $this->database->quoteIdentifier($table));
            if ($definition === false || !is_string($definition[1] ?? null) || preg_match('/\n\) ENGINE=InnoDB\b/i', $definition[1]) !== 1) {
                throw new \RuntimeException('Data Source Profile writes require transactional tables.');
            }
        }
        $this->assertReferenceGuards();
        // Keep the reference range locks when the session default is READ COMMITTED.
        $this->database->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }

    /**
     * Deletion and the read-only rule rely on the reference triggers from
     * lib/data_source_profile_integrity.php; a missing or altered one fails
     * closed, as the legacy page's data_source_profile_reference_guards_available() did.
     */
    private function assertReferenceGuards(): void
    {
        require_once dirname(__DIR__, 4) . '/lib/data_source_profile_integrity.php';
        $definitions = \data_source_profile_reference_triggers();
        $names = array_keys($definitions);
        $rows = $this->database->fetchAllAssociative(
            'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME IN (' . implode(',', array_fill(0, count($names), '?')) . ')',
            $names,
        );
        $normalize = static fn(string $body): string => (string) preg_replace('/\s+/', ' ', trim($body));
        $found = [];
        foreach ($rows as $row) {
            $definition = $definitions[$row['TRIGGER_NAME'] ?? ''] ?? null;
            if ($definition === null || ($row['EVENT_OBJECT_TABLE'] ?? '') !== $definition['table'] || ($row['ACTION_TIMING'] ?? '') !== $definition['timing']
                || ($row['EVENT_MANIPULATION'] ?? '') !== $definition['event'] || $normalize((string) ($row['ACTION_STATEMENT'] ?? '')) !== $normalize($definition['body'])) {
                throw new \RuntimeException('Data Source Profile reference guards are modified. Run the database upgrade before changing profiles.');
            }
            $found[$row['TRIGGER_NAME']] = true;
        }
        if (count($found) !== count($definitions)) {
            throw new \RuntimeException('Data Source Profile reference guards are missing. Run the database upgrade before changing profiles.');
        }
    }

    private function forUpdate(): string
    {
        return $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' FOR UPDATE' : '';
    }
}
