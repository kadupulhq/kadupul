<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\GraphRuleStore;
use Kadupul\Automation\Domain\GraphRule;
use Kadupul\Automation\Domain\GraphRuleChange;
use Kadupul\Automation\Domain\GraphRuleRejected;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Persistence\AuditedPrimaryWrite;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * automation_graph_rules. lib/api_automation.php reads enabled rules when it
 * creates graphs for a device, using graph_type_id as an snmp_query_graph ID
 * of the rule's Data Query.
 */
#[AsAlias(GraphRuleStore::class)]
final readonly class DbalGraphRuleStore implements GraphRuleStore
{
    // Every table a write changes or locks, with the permission tables the actor recheck reads.
    private const array WRITE_TABLES = ['automation_graph_rules', 'snmp_query', 'snmp_query_graph', 'user_auth', 'user_auth_realm', 'user_auth_group',
        'user_auth_group_realm', 'user_auth_group_members', 'settings'];
    private const string STALE = 'The graph rule changed since you opened this form. Reload before saving.';

    private AuditedPrimaryWrite $writes;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        AutomationTemplateAccess $access,
        AuditTrail $audit,
        LegacyConfiguration $configuration,
    ) {
        // Graph Rules share the automation realm, so the Device Rules recheck applies unchanged.
        $this->writes = new AuditedPrimaryWrite(
            $database,
            $audit,
            $configuration,
            $access->assertCurrent(...),
            self::WRITE_TABLES,
            'Graph Rule',
            'Graph Rules',
            'automation.graph_rule',
            'automation_graph_rule'
        );
    }

    public function choices(): array
    {
        $queries = [];
        foreach ($this->database->fetchAllAssociative('SELECT id, name FROM snmp_query ORDER BY name, id') as $row) {
            $queries[self::int($row['id'])] = self::text($row['name']);
        }
        $types = [];
        foreach ($this->database->fetchAllAssociative('SELECT id, snmp_query_id, name FROM snmp_query_graph ORDER BY name, id') as $row) {
            $types[self::int($row['id'])] = ['query' => self::int($row['snmp_query_id']), 'name' => self::text($row['name'])];
        }
        return ['queries' => $queries, 'graphTypes' => $types];
    }

    public function find(int $id): ?GraphRule
    {
        $row = $this->database->fetchAssociative('SELECT ' . implode(', ', GraphRule::COLUMNS) . ' FROM automation_graph_rules WHERE id = ?', [$id]);
        if ($row === false) {
            return null;
        }
        return new GraphRule(
            self::int($row['id']),
            self::text($row['name']),
            self::int($row['snmp_query_id']),
            self::int($row['graph_type_id']),
            self::text($row['enabled']) === 'on',
            GraphRule::revisionOf($row)
        );
    }

    public function save(int $actorId, ?int $id, GraphRuleChange $change, ?string $revision): int
    {
        return $this->writes->run($actorId, $id === null ? 'create' : 'edit', 'automation_graph_rule', function () use ($id, $change, $revision): int {
            $this->assertGraphType($change);
            if ($id === null) {
                $this->database->insert('automation_graph_rules', $change->columns());
                $created = (int) $this->database->lastInsertId();
                if ($created < 1) {
                    throw new \RuntimeException('Graph rule creation was not confirmed.');
                }
                return $created;
            }
            $lock = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' FOR UPDATE' : '';
            $row = $this->database->fetchAssociative('SELECT ' . implode(', ', GraphRule::COLUMNS) . ' FROM automation_graph_rules WHERE id = ?' . $lock, [$id]);
            if ($row === false) {
                throw new \InvalidArgumentException('Graph rule not found.');
            }
            if ($revision === null || !hash_equals(GraphRule::revisionOf($row), $revision)) {
                throw new \InvalidArgumentException(self::STALE);
            }
            $this->database->update('automation_graph_rules', $change->columns(), ['id' => $id]);
            return $id;
        });
    }

    /** Shared locks keep the Data Query and its Graph Type from being deleted or reassigned before commit. */
    private function assertGraphType(GraphRuleChange $change): void
    {
        $lock = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' LOCK IN SHARE MODE' : '';
        if ($this->database->fetchOne('SELECT id FROM snmp_query WHERE id = ?' . $lock, [$change->snmpQueryId]) === false) {
            throw new GraphRuleRejected('Choose an existing Data Query.', [], 'snmp_query_id');
        }
        $query = $this->database->fetchOne('SELECT snmp_query_id FROM snmp_query_graph WHERE id = ?' . $lock, [$change->graphTypeId]);
        if ($query === false || self::int($query) !== $change->snmpQueryId) {
            throw new GraphRuleRejected('Choose a Graph Type of the selected Data Query.', [], 'graph_type_id');
        }
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** NULL columns read as empty text, as the legacy form showed them. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }
}
