<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\Graphing\Application\Port\GraphTemplateInputAccess;
use Kadupul\Graphing\Application\Port\GraphTemplateInputEditor;
use Kadupul\Graphing\Domain\GraphTemplateInput;
use Kadupul\Graphing\Domain\GraphTemplateInputColumn;
use Kadupul\Graphing\Domain\GraphTemplateInputItem;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Persistence\AuditedPrimaryWrite;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The write path of graph_templates_inputs.php. The input row, the value
 * push-out to existing graphs and the delete-then-reinsert of the item set
 * run in one audited transaction on the web connection, so a failure at any
 * step leaves the previous input and graph values in place.
 */
#[AsAlias(GraphTemplateInputEditor::class)]
final readonly class DbalGraphTemplateInputEditor implements GraphTemplateInputEditor
{
    // Every table a write changes or locks, with the permission tables the actor recheck reads.
    private const array WRITE_TABLES = ['graph_templates', 'graph_template_input', 'graph_template_input_defs', 'graph_templates_item', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'];
    /** More items than any template holds; it bounds the IN list a forged form can send. */
    public const int MAX_ITEMS = 1000;

    private AuditedPrimaryWrite $writes;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        GraphTemplateInputAccess $access,
        AuditTrail $audit,
        LegacyConfiguration $configuration,
        private LoggerInterface $logger,
    ) {
        $this->writes = new AuditedPrimaryWrite($database, $audit, $configuration, $access->assertCurrent(...), self::WRITE_TABLES, 'Graph Item Input', 'Graph Item Inputs', 'graphing.graph_template_input', 'graph_template_input');
    }

    public function save(int $actorId, int $templateId, ?int $inputId, string $name, string $description, string $column, array $itemIds, ?string $revision): int
    {
        GraphTemplateInput::validateName($name);
        GraphTemplateInput::validateDescription($description);
        // GraphTemplateInputColumn is graph_template_input_column()'s allowlist;
        // GraphTemplateInputEditorTest holds the two equal.
        $field = GraphTemplateInputColumn::tryFrom($column);
        if ($field === null) {
            throw new \InvalidArgumentException('Choose a field type.');
        }
        // Only the enum's literal reaches SQL as an identifier.
        $column = $field->value;
        $itemIds = self::itemIds($itemIds);
        return $this->writes->run($actorId, $inputId === null ? 'create' : 'edit', (string) $templateId, function () use ($templateId, $inputId, $name, $description, $field, $column, $itemIds, $revision): int {
            $this->lockTemplate($templateId);
            $previous = [];
            if ($inputId === null) {
                $this->database->insert('graph_template_input', ['hash' => bin2hex(random_bytes(16)), 'graph_template_id' => $templateId, 'name' => $name, 'description' => $description, 'column_name' => $column]);
                $inputId = (int) $this->database->lastInsertId();
                if ($inputId < 1 || $inputId > GraphTemplateInput::MAX_ID) {
                    throw new \RuntimeException('Graph Item Input creation was not confirmed.');
                }
            } else {
                $input = $this->lockInput($templateId, $inputId, $revision);
                $previous = $input->itemIds;
                // Keep a stored hash get_hash_graph_template() accepted; replace any other.
                $hash = $this->database->fetchOne('SELECT hash FROM graph_template_input WHERE id = ?', [$inputId]);
                if (!is_string($hash) || preg_match('/[a-fA-F0-9]{32}/', $hash) !== 1) {
                    $hash = bin2hex(random_bytes(16));
                }
                $this->database->executeStatement('UPDATE graph_template_input SET hash = ?, name = ?, description = ?, column_name = ? WHERE id = ? AND graph_template_id = ?', [$hash, $name, $description, $column, $inputId, $templateId]);
            }
            $this->assertItemsBelong($templateId, $itemIds);
            $this->pushOut($templateId, $field, $previous, array_values(array_diff($itemIds, $previous)));
            $this->database->executeStatement('DELETE FROM graph_template_input_defs WHERE graph_template_input_id = ?', [$inputId]);
            if ($itemIds !== []) {
                $this->database->executeStatement(
                    'INSERT INTO graph_template_input_defs (graph_template_input_id, graph_template_item_id) VALUES ' . implode(', ', array_fill(0, count($itemIds), '(?, ?)')),
                    array_merge(...array_map(static fn(int $itemId): array => [$inputId, $itemId], $itemIds)),
                );
            }
            return $inputId;
        });
    }

    public function remove(int $actorId, int $templateId, int $inputId, string $revision): void
    {
        $this->writes->run($actorId, 'delete', (string) $templateId, function () use ($templateId, $inputId, $revision): void {
            $this->lockTemplate($templateId);
            $this->lockInput($templateId, $inputId, $revision);
            if ($this->database->executeStatement('DELETE FROM graph_template_input WHERE id = ? AND graph_template_id = ?', [$inputId, $templateId]) !== 1) {
                throw new \RuntimeException('Graph Item Input removal was not confirmed.');
            }
            $this->database->executeStatement('DELETE FROM graph_template_input_defs WHERE graph_template_input_id = ?', [$inputId]);
        });
    }

    /**
     * New members of an input take the value the input's existing members
     * hold on each graph built from the template, as push_out_graph_input()
     * did before the item set was rewritten. A new input, or one without
     * members, has no value to push. Legacy read an arbitrary row per graph;
     * the lowest template item ID is used here so the result is deterministic.
     *
     * @param list<int> $previous
     * @param list<int> $added
     */
    private function pushOut(int $templateId, GraphTemplateInputColumn $field, array $previous, array $added): void
    {
        $column = $field->value;
        if ($previous === [] || $added === []) {
            return;
        }
        $rows = $this->database->fetchAllAssociative(
            'SELECT local_graph_id, `' . $column . '` AS value FROM graph_templates_item
            WHERE graph_template_id = ? AND local_graph_id > 0 AND local_graph_template_item_id IN (?)
            ORDER BY local_graph_id, local_graph_template_item_id, id' . $this->forUpdate(),
            [$templateId, $previous],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER],
        );
        $values = [];
        foreach ($rows as $row) {
            $graphId = $row['local_graph_id'];
            if (!is_int($graphId) && !(is_string($graphId) && ctype_digit($graphId))) {
                throw new \UnexpectedValueException('The graph template tables returned an unexpected row.');
            }
            $values[(int) $graphId] ??= $row['value'];
        }
        // A stored value comes from earlier saves and imports; refuse the
        // whole push-out if any is malformed, and keep the save, as legacy did.
        foreach ($values as $value) {
            if ($value !== null && !$field->allows($value)) {
                $this->logger->error('Graph item input push-out refused an invalid stored value.', ['column' => $column, 'graph_template_id' => $templateId]);
                return;
            }
        }
        foreach ($values as $graphId => $value) {
            // An empty data source would detach the graph item from its data.
            if ($field === GraphTemplateInputColumn::DataSource && in_array($value, [null, '', '0', 0], true)) {
                continue;
            }
            $this->database->executeStatement(
                'UPDATE graph_templates_item SET `' . $column . '` = ? WHERE local_graph_id = ? AND local_graph_template_item_id IN (?)',
                [$value, $graphId, $added],
                [ParameterType::STRING, ParameterType::INTEGER, ArrayParameterType::INTEGER],
            );
        }
    }

    private function lockTemplate(int $templateId): void
    {
        if ($this->database->fetchOne('SELECT id FROM graph_templates WHERE id = ?' . $this->forUpdate(), [$templateId]) === false) {
            throw new \InvalidArgumentException(self::TEMPLATE_NOT_FOUND);
        }
    }

    private function lockInput(int $templateId, int $inputId, ?string $revision): GraphTemplateInput
    {
        $input = DbalGraphTemplateInputCatalog::input($this->database, $templateId, $inputId, $this->forUpdate());
        if ($input === null) {
            throw new \InvalidArgumentException(self::NOT_FOUND);
        }
        if ($revision === null || !hash_equals($input->revision, $revision)) {
            throw new \InvalidArgumentException(self::STALE);
        }
        return $input;
    }

    /**
     * Every submitted item must be a template item (local_graph_id 0) of this
     * template; the shared lock keeps it from being deleted before commit.
     *
     * @param list<int> $itemIds
     */
    private function assertItemsBelong(int $templateId, array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }
        $lock = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' LOCK IN SHARE MODE' : '';
        $found = $this->database->fetchOne(
            'SELECT COUNT(*) FROM graph_templates_item WHERE graph_template_id = ? AND local_graph_id = 0 AND id IN (?)' . $lock,
            [$templateId, $itemIds],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER],
        );
        if (!is_numeric($found) || (int) $found !== count($itemIds)) {
            throw new \InvalidArgumentException(self::RELATIONSHIP);
        }
    }

    /**
     * @param array<mixed> $itemIds
     * @return list<int> sorted and unique
     */
    private static function itemIds(array $itemIds): array
    {
        if (count($itemIds) > self::MAX_ITEMS || !array_is_list($itemIds)) {
            throw new \InvalidArgumentException(self::RELATIONSHIP);
        }
        $unique = [];
        foreach ($itemIds as $id) {
            if (!is_int($id) || $id < 1 || $id > GraphTemplateInputItem::MAX_ID) {
                throw new \InvalidArgumentException(self::RELATIONSHIP);
            }
            $unique[$id] = $id;
        }
        ksort($unique);
        return array_values($unique);
    }

    private function forUpdate(): string
    {
        return $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' FOR UPDATE' : '';
    }
}
