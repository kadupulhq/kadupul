<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\DeviceTemplateDefinitions;
use Kadupul\Inventory\Domain\DeviceTemplateDefinition;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\Process\Process;

final readonly class LegacyDeviceTemplateDefinitions implements DeviceTemplateDefinitions
{
    public function __construct(private DatabaseConnection $database, private string $projectDir, private LegacyConfiguration $configuration) {}
    public function authorize(int $actor): void
    {
        DeviceTemplateAuthorization::authorize($this->database->get(), $actor);
    }
    public function defaults(int $actor, bool $reset = false): array
    {
        $db = $this->database->get();
        $settings = DeviceTemplateStatement::fetchAll(DeviceTemplateStatement::query($db, "SELECT name, value FROM settings WHERE name IN ('num_rows_table', 'default_has')"), \PDO::FETCH_KEY_PAIR);
        $defaults = ['size' => in_array((int) ($settings['num_rows_table'] ?? 25), \Kadupul\Inventory\Infrastructure\Symfony\DeviceTemplateFilters::SIZES, true) ? (int) ($settings['num_rows_table'] ?? 25) : 25, 'has_hosts' => ($settings['default_has'] ?? '') === 'on' ? 'true' : 'false'];
        if ($reset) {
            return $defaults;
        }
        $query = DeviceTemplateStatement::prepare($db, "SELECT value FROM settings_user WHERE user_id = ? AND name = 'twig_device_template_filters'");
        DeviceTemplateStatement::execute($query, [$actor]);
        $saved = DeviceTemplateStatement::fetchColumn($query);
        if (is_string($saved)) {
            try {
                $saved = json_decode($saved, true, 8, JSON_THROW_ON_ERROR);
                if (is_array($saved)) {
                    return \Kadupul\Inventory\Infrastructure\Symfony\DeviceTemplateFilters::parse($saved, $defaults);
                }
            } catch (\InvalidArgumentException|\JsonException) {
            }
        }
        return $defaults;
    }
    public function remember(int $actor, array $filters): void
    {
        $db = $this->database->get();
        DeviceTemplateTransaction::begin($db, $this->configuration->values(), ['settings_user']);
        try {
            DeviceTemplateAuthorization::authorize($db, $actor, true);
            $query = DeviceTemplateStatement::prepare($db, "INSERT INTO settings_user (user_id, name, value) VALUES (?, 'twig_device_template_filters', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
            DeviceTemplateStatement::execute($query, [$actor, json_encode($filters, JSON_THROW_ON_ERROR)]);
            DeviceTemplateTransaction::commit($db);
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                DeviceTemplateTransaction::rollback($db);
            } throw $error;
        }
    }
    public function list(array $filters): array
    {
        $where = ['ht.id > 0'];
        if ($filters['has_hosts'] === 'true') {
            $where[] = "EXISTS (SELECT 1 FROM host h WHERE h.host_template_id = ht.id)";
        }
        $params = [];
        if ($filters['q'] !== '') {
            $where[] = 'ht.name LIKE ?';
            $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']) . '%';
            $where[count($where) - 1] .= " ESCAPE '!'";
        }
        if ($filters['class'] !== '-1') {
            $where[] = 'ht.class = ?';
            $params[] = $filters['class'];
        }
        if ($filters['graph'] > 0) {
            $where[] = '(EXISTS (SELECT 1 FROM host_template_graph g WHERE g.host_template_id = ht.id AND g.graph_template_id = ?) OR EXISTS (SELECT 1 FROM host_template_snmp_query q INNER JOIN snmp_query_graph g ON g.snmp_query_id = q.snmp_query_id WHERE q.host_template_id = ht.id AND g.graph_template_id = ?))';
            $params[] = $filters['graph'];
            $params[] = $filters['graph'];
        }
        $sort = ['name' => 'ht.name', 'id' => 'ht.id', 'class' => 'ht.class', 'hosts' => 'hosts'][$filters['sort']];
        $sql = "SELECT ht.id, ht.name, ht.class, (SELECT COUNT(*) FROM host h WHERE h.host_template_id = ht.id) AS hosts FROM host_template ht WHERE " . implode(' AND ', $where) . ' ORDER BY ' . $sort . ' ' . strtoupper($filters['direction']) . ', ht.id ASC LIMIT ' . ($filters['size'] + 1) . ' OFFSET ' . (($filters['page'] - 1) * $filters['size']);
        $query = DeviceTemplateStatement::prepare($this->database->get(), $sql);
        DeviceTemplateStatement::execute($query, $params);
        $rows = DeviceTemplateStatement::fetchAll($query, \PDO::FETCH_ASSOC);
        return ['rows' => array_slice($rows, 0, $filters['size']), 'hasNext' => count($rows) > $filters['size']];
    }
    public function find(int $id): ?DeviceTemplateDefinition
    {
        return self::read($this->database->get(), $id);
    }
    public static function read(\PDO $db, int $id, bool $lock = false): ?DeviceTemplateDefinition
    {
        $query = DeviceTemplateStatement::prepare($db, 'SELECT id, name, class FROM host_template WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        DeviceTemplateStatement::execute($query, [$id]);
        $row = DeviceTemplateStatement::fetch($query, \PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $children = [];
        foreach (['graph' => 'graph_template_id', 'snmp_query' => 'snmp_query_id'] as $table => $key) {
            $query = DeviceTemplateStatement::prepare($db, 'SELECT ' . $key . ' FROM host_template_' . $table . ' WHERE host_template_id = ? ORDER BY ' . $key . ($lock ? ' FOR UPDATE' : ''));
            DeviceTemplateStatement::execute($query, [$id]);
            $children[] = array_map('intval', DeviceTemplateStatement::fetchAll($query, \PDO::FETCH_COLUMN));
        }
        return new DeviceTemplateDefinition((int) $row['id'], $row['name'], $row['class'], ...$children);
    }
    public function choices(): array
    {
        $db = $this->database->get();
        return ['add_graphs' => DeviceTemplateStatement::fetchAll(DeviceTemplateStatement::query($db, 'SELECT id, name FROM graph_templates WHERE id > 0 AND id NOT IN (SELECT graph_template_id FROM snmp_query_graph) ORDER BY name, id'), \PDO::FETCH_KEY_PAIR), 'graphs' => DeviceTemplateStatement::fetchAll(DeviceTemplateStatement::query($db, 'SELECT id, name FROM graph_templates WHERE id > 0 ORDER BY name, id'), \PDO::FETCH_KEY_PAIR), 'queries' => DeviceTemplateStatement::fetchAll(DeviceTemplateStatement::query($db, 'SELECT id, name FROM snmp_query WHERE id > 0 ORDER BY name, id'), \PDO::FETCH_KEY_PAIR)];
    }
    public function hooks(int $actor, int $id): array
    {
        return $this->execute($actor, 'hooks', ['id' => $id])['hooks'] ?? [];
    }
    public function execute(int $actor, string $action, array $command): array
    {
        $correlation = bin2hex(random_bytes(16));
        $command = ['actor' => $actor, 'action' => $action, 'correlation' => $correlation] + $command;
        $configured = DeviceTemplateStatement::fetchColumn(DeviceTemplateStatement::query($this->database->get(), "SELECT value FROM settings WHERE name = 'path_php_binary'"));
        $binary = is_string($configured) && trim($configured) !== '' ? trim($configured) : PHP_BINDIR . '/php';
        $process = new Process([$binary, $this->projectDir . '/bin/legacy-device-template-definition.php'], $this->projectDir);
        $process->setTimeout(180);
        $process->setInput(json_encode($command, JSON_THROW_ON_ERROR));
        $process->run();
        if (preg_match_all('/^KADUPUL_DEVICE_DEFINITION_RESULT=/m', $process->getOutput()) > 1) {
            throw new \RuntimeException('Device template outcome could not be verified.');
        }
        if (!preg_match('/^KADUPUL_DEVICE_DEFINITION_RESULT=(\{[^\r\n]+\})$/m', $process->getOutput(), $match)) {
            throw new \RuntimeException('Device template outcome is unknown. Reload before retrying.');
        }
        try {
            $wire = json_decode($match[1], false, 16, JSON_THROW_ON_ERROR);
            if (!$wire instanceof \stdClass || !is_array($wire->ids ?? null)) {
                throw new \RuntimeException('Device template outcome could not be verified.');
            }
            $result = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Device template outcome could not be verified.', 0, $error);
        }
        if (($result['actor'] ?? null) !== $actor || ($result['action'] ?? null) !== $action || ($result['correlation'] ?? null) !== $correlation) {
            throw new \RuntimeException('Device template outcome could not be verified.');
        }
        $ids = $result['ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || count(array_unique($ids, SORT_REGULAR)) !== count($ids) || array_filter($ids, static fn($id): bool => !is_int($id) || $id < 1 || $id > 16777215) !== []) {
            throw new \RuntimeException('Device template outcome could not be verified.');
        }
        if (($result['status'] ?? '') === 'ok' && $action !== 'hooks') {
            $expected = in_array($action, ['save', 'association'], true) ? [(int) ($command['id'] ?? 0)] : array_map('intval', array_keys($command['revisions'] ?? []));
            sort($expected, SORT_NUMERIC);
            $actual = $ids;
            sort($actual, SORT_NUMERIC);
            if (count($expected) !== count($actual) || ($action !== 'duplicate' && $expected !== [0] && $expected !== $actual)) {
                throw new \RuntimeException('Device template outcome could not be verified.');
            }
        }
        if (($result['status'] ?? '') === 'conflict') {
            throw new DeviceEditConflict('Device template changed. Reload before saving.');
        }
        if (($result['status'] ?? '') === 'denied') {
            throw new InventoryAccessDenied(false);
        }
        if (($result['status'] ?? '') === 'invalid') {
            throw new \InvalidArgumentException('Invalid device template fields.');
        }
        if (($result['status'] ?? '') === 'partial' && $action !== 'sync') {
            throw new \RuntimeException('Device template outcome could not be verified.');
        }
        if (!$process->isSuccessful() || !in_array($result['status'] ?? '', ['ok', 'partial'], true)) {
            throw new \RuntimeException('Device template outcome is unknown. Reload before retrying.');
        }
        return $result;
    }
}
