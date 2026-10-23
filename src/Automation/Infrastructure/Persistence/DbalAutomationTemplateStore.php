<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\AutomationTemplateStore;
use Kadupul\Automation\Domain\AutomationTemplate;
use Kadupul\Automation\Domain\AutomationTemplateFilters;
use Kadupul\Automation\Domain\AutomationTemplatePage;
use Kadupul\Automation\Domain\AutomationTemplateRevision;
use Kadupul\Automation\Domain\AvailabilityMethods;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(AutomationTemplateStore::class)]
final readonly class DbalAutomationTemplateStore implements AutomationTemplateStore
{
    private const string STALE = 'The automation template changed since you opened this form. Reload before saving.';
    private const string ORDER_STALE = 'The automation template order changed since you opened this page. Reload before moving.';
    private const string SELECT = 'SELECT at.id, at.host_template, at.availability_method, at.sysDescr, at.sysName, at.sysOid, at.sequence, ht.name
        FROM automation_templates at LEFT JOIN host_template ht ON ht.id = at.host_template';
    // MariaDB reports a pattern it cannot compile as 1139; MySQL 8 uses 3684 to 3700.
    private const array REGEXP_ERRORS = [1139, 3684, 3685, 3686, 3687, 3688, 3689, 3690, 3691, 3692, 3693, 3694, 3695, 3696, 3697, 3698, 3699, 3700];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        private AutomationTemplateAccess $access,
        private AuditTrail $audit,
        private LegacyConfiguration $configuration,
    ) {}

    public function defaultRows(): int
    {
        $rows = filter_var($this->database->fetchOne("SELECT value FROM settings WHERE name = 'num_rows_table'"), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        return $rows === false ? 25 : $rows;
    }

    public function defaultAvailability(): int
    {
        $method = filter_var($this->database->fetchOne("SELECT value FROM settings WHERE name = 'availability_method'"), FILTER_VALIDATE_INT);
        return is_int($method) && isset(AvailabilityMethods::LABELS[$method]) ? $method : AvailabilityMethods::SNMP;
    }

    public function list(AutomationTemplateFilters $filters): AutomationTemplatePage
    {
        $where = '';
        $params = [];
        if ($filters->filter !== '') {
            // The legacy search covered the device template name and all three stored matches.
            $where = " WHERE (ht.name LIKE ? ESCAPE '!' OR at.sysName LIKE ? ESCAPE '!' OR at.sysDescr LIKE ? ESCAPE '!' OR at.sysOid LIKE ? ESCAPE '!')";
            $params = array_fill(0, 4, '%' . strtr($filters->filter, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%');
        }
        $total = (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_templates at LEFT JOIN host_template ht ON ht.id = at.host_template' . $where, $params);
        $rows = $this->database->fetchAllAssociative(
            self::SELECT . $where . ' ORDER BY at.sequence, at.id LIMIT ? OFFSET ?',
            [...$params, $filters->rows, ($filters->page - 1) * $filters->rows],
            [...array_fill(0, count($params), ParameterType::STRING), ParameterType::INTEGER, ParameterType::INTEGER],
        );
        $order = $this->order('');
        return new AutomationTemplatePage(
            array_map(self::template(...), $rows),
            $total,
            $filters,
            AutomationTemplateRevision::order($order),
            $order === [] ? null : (int) $order[0]['id'],
            $order === [] ? null : (int) $order[count($order) - 1]['id'],
        );
    }

    public function find(int $id): ?AutomationTemplate
    {
        $row = $this->database->fetchAssociative(self::SELECT . ' WHERE at.id = ?', [$id]);
        return $row === false ? null : self::template($row);
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $templates = [];
        foreach ($this->database->fetchAllAssociative(self::SELECT . " WHERE at.id IN ($marks) ORDER BY at.id", $ids) as $row) {
            $templates[(int) $row['id']] = self::template($row);
        }
        return $templates;
    }

    public function deviceTemplates(): array
    {
        $choices = [];
        foreach ($this->database->fetchAllAssociative('SELECT id, name FROM host_template ORDER BY name, id') as $row) {
            $choices[(int) $row['id']] = (string) $row['name'];
        }
        return $choices;
    }

    public function save(int $actorId, ?int $id, int $hostTemplate, int $availabilityMethod, string $sysDescr, string $sysName, string $sysOid, ?string $revision): int
    {
        AutomationTemplate::validateAvailability($availabilityMethod);
        $values = [
            'host_template' => $hostTemplate,
            'availability_method' => $availabilityMethod,
            'sysDescr' => AutomationTemplate::storedDescription($sysDescr),
            'sysName' => $sysName,
            'sysOid' => $sysOid,
        ];
        AutomationTemplate::validateMatches($sysName, $sysOid);
        return $this->write($actorId, $id === null ? 'create' : 'edit', function () use ($id, $values, $revision): int {
            // Discovery compiles each match with REGEXP; one it cannot compile
            // fails the lookup for every template, not only this one.
            $this->assertCompiles($values['sysDescr'], 'System Description Match is not a regular expression the database accepts.');
            $this->assertCompiles($values['sysName'], 'System Name Match is not a regular expression the database accepts.');
            $this->assertCompiles($values['sysOid'], 'System OID Match is not a regular expression the database accepts.');
            // The shared lock keeps the device template until this row is committed.
            if ($values['host_template'] < 1 || $this->database->fetchOne('SELECT id FROM host_template WHERE id = ?' . $this->shareLock(), [$values['host_template']]) === false) {
                throw new \InvalidArgumentException('Choose a Device Template that exists.');
            }
            if ($id === null) {
                // Legacy saved new templates with the column default, so they match first.
                $this->database->insert('automation_templates', $values + ['sequence' => 0]);
                $created = (int) $this->database->lastInsertId();
                if ($created < 1) {
                    throw new \RuntimeException('Automation template creation was not confirmed.');
                }
                return $created;
            }
            $this->lockRevision($id, $revision);
            $this->database->update('automation_templates', $values, ['id' => $id]);
            return $id;
        });
    }

    public function move(int $actorId, int $id, int $offset, string $order): void
    {
        if (!in_array($offset, [-1, 1], true)) {
            throw new \InvalidArgumentException('Invalid automation template order.');
        }
        $this->write($actorId, 'move', function () use ($id, $offset, $order): void {
            $rows = $this->order($this->forUpdate());
            if (!hash_equals(AutomationTemplateRevision::order($rows), $order)) {
                throw new \InvalidArgumentException(self::ORDER_STALE);
            }
            $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
            $position = array_search($id, $ids, true);
            if (!is_int($position) || !isset($ids[$position + $offset])) {
                throw new \InvalidArgumentException('Invalid automation template order.');
            }
            [$ids[$position], $ids[$position + $offset]] = [$ids[$position + $offset], $ids[$position]];
            // Renumber densely in match order, as the legacy drag and drop did;
            // ties that legacy left unordered are settled by ID, as listed.
            $current = array_column($rows, 'sequence', 'id');
            foreach ($ids as $index => $templateId) {
                if ((int) $current[$templateId] !== $index + 1) {
                    $this->database->update('automation_templates', ['sequence' => $index + 1], ['id' => $templateId]);
                }
            }
        });
    }

    public function delete(int $actorId, array $ids, array $revisions): void
    {
        self::validateSelection($ids, $revisions);
        $this->write($actorId, 'delete', function () use ($ids, $revisions): void {
            foreach ($ids as $id) {
                $this->lockRevision($id, $revisions[$id]);
            }
            $marks = implode(',', array_fill(0, count($ids), '?'));
            if ($this->database->executeStatement("DELETE FROM automation_templates WHERE id IN ($marks)", $ids) !== count($ids)) {
                throw new \RuntimeException('Automation template deletion was not confirmed.');
            }
        });
    }

    /** @return list<array<string, mixed>> id and sequence of every template in match order */
    private function order(string $lock): array
    {
        return $this->database->fetchAllAssociative('SELECT id, sequence FROM automation_templates ORDER BY sequence, id' . $lock);
    }

    /** @param array<string, mixed> $row */
    private static function template(array $row): AutomationTemplate
    {
        return new AutomationTemplate(
            (int) $row['id'],
            (int) $row['host_template'],
            $row['name'] === null ? null : (string) $row['name'],
            (int) $row['availability_method'],
            (string) $row['sysDescr'],
            (string) $row['sysName'],
            (string) $row['sysOid'],
            (int) $row['sequence'],
            AutomationTemplateRevision::of($row),
        );
    }

    private function lockRevision(int $id, ?string $revision): void
    {
        $row = $this->database->fetchAssociative('SELECT id, host_template, availability_method, sysDescr, sysName, sysOid FROM automation_templates WHERE id = ?' . $this->forUpdate(), [$id]);
        if ($row === false) {
            throw new \InvalidArgumentException('Automation template not found.');
        }
        if ($revision === null || !hash_equals(AutomationTemplateRevision::of($row), $revision)) {
            throw new \InvalidArgumentException(self::STALE);
        }
    }

    /** Ask the database that runs discovery, so the check matches its regular expression dialect. */
    private function assertCompiles(string $pattern, string $message): void
    {
        if ($pattern === '' || !$this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }
        try {
            $this->database->fetchOne("SELECT '' REGEXP CONCAT('/', ?, '/')", [$pattern]);
        } catch (DriverException $error) {
            if (in_array($error->getCode(), self::REGEXP_ERRORS, true)) {
                throw new \InvalidArgumentException($message);
            }
            throw $error;
        }
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
            || array_any($ids, static fn(mixed $id): bool => !is_int($id) || $id < 1 || $id > 4294967295)) {
            throw new \InvalidArgumentException('Invalid automation template selection.');
        }
        $keys = array_keys($revisions);
        sort($keys, SORT_NUMERIC);
        if ($keys !== $ids || array_any($revisions, static fn(mixed $revision): bool => !is_string($revision))) {
            throw new \InvalidArgumentException(self::STALE);
        }
    }

    private function write(int $actorId, string $action, callable $operation): mixed
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
            $this->record($actorId, $action, $decision, $outcome);
        }
    }

    private function prepareWrite(): void
    {
        if ($this->database->isTransactionActive()) {
            throw new \RuntimeException('Automation template writes require their own transaction.');
        }
        if (!$this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }
        // Remote collectors receive the table by replication from the primary.
        if (($this->configuration->values()['collector_id'] ?? null) !== 1) {
            throw new \RuntimeException('Automation templates must be changed on the primary collector.');
        }
        foreach (['automation_templates', 'host_template', 'user_auth', 'user_auth_realm', 'user_auth_group',
            'user_auth_group_realm', 'user_auth_group_members', 'settings'] as $table) {
            // SHOW CREATE TABLE sees a temporary table that shadows the real one.
            $definition = $this->database->fetchNumeric('SHOW CREATE TABLE ' . $this->database->quoteIdentifier($table));
            if ($definition === false || !is_string($definition[1] ?? null) || preg_match('/\n\) ENGINE=InnoDB\b/i', $definition[1]) !== 1) {
                throw new \RuntimeException('Automation template writes require transactional tables.');
            }
        }
        // Keep the order and device template locks when the session default is READ COMMITTED.
        $this->database->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }

    private function record(int $actorId, string $action, string $decision, string $outcome): void
    {
        try {
            $this->audit->record(new AuditEvent(bin2hex(random_bytes(16)), $actorId, 'automation.template.' . $action, 'automation_template', 'automation_template', $decision, $outcome));
        } catch (\Throwable) { /* Audit failure cannot change a confirmed persistence result. */
        }
    }

    private function forUpdate(): string
    {
        return $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' FOR UPDATE' : '';
    }

    private function shareLock(): string
    {
        return $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' LOCK IN SHARE MODE' : '';
    }
}
