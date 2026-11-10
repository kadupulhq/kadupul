<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Kadupul\Alerting\Application\Port\IncidentAccess;
use Kadupul\Alerting\Application\Port\IncidentStore;
use Kadupul\Alerting\Application\Query\AcknowledgeOutcome;
use Kadupul\Alerting\Domain\Incident\IncidentStatus;
use Kadupul\Alerting\Domain\Incident\InvalidIncident;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Persistence\AuditedPrimaryWrite;

/**
 * Acknowledgement on the evaluator's connection. The transaction locks the
 * rule row and then the incident row, the order EvaluateRules uses (rule,
 * state, incident, notification), so it waits for a rule being evaluated
 * instead of deadlocking with it, and the evaluator's close of the same
 * incident either precedes it (the incident has ended) or follows it.
 * Every attempt is audited as alerting.incident.acknowledge with the actor
 * and the time; a refused one is audited as failed.
 */
final readonly class DbalIncidentStore implements IncidentStore
{
    private const array WRITE_TABLES = ['alert_rule', 'alert_incident', 'data_local', 'host',
        'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'user_auth_perms', 'user_auth_group_perms', 'settings'];

    private AuditedPrimaryWrite $writes;

    public function __construct(
        private Connection $database,
        private SeriesVisibility $visibility,
        IncidentAccess $access,
        AuditTrail $audit,
        LegacyConfiguration $configuration,
    ) {
        $this->writes = new AuditedPrimaryWrite($database, $audit, $configuration, $access->assertCurrent(...), self::WRITE_TABLES, 'Alert incident', 'Alert incidents', 'alerting.incident', 'alert_incident');
    }

    public function acknowledge(int $actorId, int $incidentId, int $at): AcknowledgeOutcome
    {
        try {
            return $this->writes->run($actorId, 'acknowledge', (string) $incidentId, function () use ($actorId, $incidentId, $at): AcknowledgeOutcome {
                $ruleId = $this->database->fetchOne('SELECT rule_id FROM alert_incident WHERE id = ?', [$incidentId]);
                if ($ruleId === false || $this->database->fetchOne('SELECT id FROM alert_rule WHERE id = ?' . RowLock::forUpdate($this->database), [$ruleId]) === false) {
                    throw new AcknowledgementRefused(AcknowledgeOutcome::NotFound);
                }
                $visible = $this->database->fetchOne('SELECT r.id FROM alert_rule r ' . DbalRuleRepository::SERIES_JOINS . ' WHERE r.id = ? AND ' . $this->visibility->ruleVisible($actorId), [$ruleId]);
                $row = $this->database->fetchAssociative(
                    'SELECT id, rule_id, status, active_rule_id, opened_at, ended_at, acknowledged_at, acknowledged_by FROM alert_incident WHERE id = ?' . RowLock::forUpdate($this->database),
                    [$incidentId],
                );
                if ($visible === false || $row === false) {
                    throw new AcknowledgementRefused(AcknowledgeOutcome::NotFound);
                }
                $incident = DbalIncidentRepository::fromRow($row);
                try {
                    $acknowledged = $incident->acknowledge($actorId, $at);
                } catch (InvalidIncident) {
                    throw new AcknowledgementRefused(AcknowledgeOutcome::Ended);
                }
                if ($acknowledged === $incident) {
                    return AcknowledgeOutcome::AlreadyAcknowledged;
                }
                $changed = $this->database->executeStatement(
                    'UPDATE alert_incident SET status = ?, acknowledged_at = ?, acknowledged_by = ? WHERE id = ? AND status = ? AND active_rule_id = ?',
                    [IncidentStatus::Acknowledged->value, $at, $actorId, $incidentId, IncidentStatus::Open->value, $incident->ruleId],
                );
                if ((int) $changed !== 1) {
                    throw new \RuntimeException('The locked incident did not change.');
                }

                return AcknowledgeOutcome::Acknowledged;
            });
        } catch (AcknowledgementRefused $refused) {
            return $refused->outcome;
        }
    }
}
