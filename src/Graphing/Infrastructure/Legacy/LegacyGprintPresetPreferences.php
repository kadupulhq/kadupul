<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\GprintPresetPreferences;
use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;

final readonly class LegacyGprintPresetPreferences implements GprintPresetPreferences
{
    public function __construct(private GprintPresetAccess $access, private DatabaseConnection $database, private LegacyConfiguration $configuration) {}

    public function load(): ?array
    {
        $actor = $this->access->authorize();
        $query = GprintPresetSql::execute($this->database->get(), "SELECT value FROM settings_user WHERE user_id = ? AND name = 'gprint_presets_filters'", [$actor->id]);
        $json = GprintPresetSql::column($query);
        if (!is_string($json) || $json === '') {
            return null;
        }
        try {
            $filters = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $allowed = ['rows', 'page', 'filter', 'sort_column', 'sort_direction', 'has_graphs'];
        if (!is_array($filters) || array_diff(array_keys($filters), $allowed) !== []
            || array_filter($filters, static fn($value): bool => !is_int($value) && !is_string($value)) !== []) {
            return null;
        }
        return array_map(static fn(int|string $value): string => (string) $value, $filters);
    }

    private function prepareMutation(\PDO $database): void
    {
        if (($this->configuration->values()['collector_id'] ?? null) !== 1) {
            throw new \RuntimeException('Filter preferences require the primary collector.');
        }
        $driver = $database->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            return;
        }
        if ($driver !== 'mysql') {
            throw new \RuntimeException('Unsupported GPRINT preference database.');
        }
        $optional = ['user_auth_group', 'user_auth_group_realm', 'user_auth_group_members'];
        foreach (['settings_user', 'settings', 'user_auth', 'user_auth_realm', ...$optional] as $table) {
            try {
                $query = $database->query('SHOW CREATE TABLE `' . $table . '`');
                $definition = $query === false ? false : GprintPresetSql::one($query, \PDO::FETCH_NUM);
            } catch (\PDOException $error) {
                if (in_array($table, $optional, true) && ($error->errorInfo[1] ?? null) === 1146) {
                    continue;
                }
                throw $error;
            }
            if (!is_array($definition) || preg_match('/^\) ENGINE=InnoDB(?:\s|$)/mi', (string) ($definition[1] ?? '')) !== 1) {
                throw new \RuntimeException('Filter preferences require transactional tables: ' . $table);
            }
        }
        if ($database->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false || $database->errorCode() !== '00000') {
            throw new \RuntimeException('GPRINT preference transaction isolation could not be confirmed.');
        }
    }

    public function save(array $filters): void
    {
        $allowed = ['rows', 'page', 'filter', 'sort_column', 'sort_direction', 'has_graphs'];
        if (array_diff(array_keys($filters), $allowed) !== [] || array_filter($filters, static fn($value): bool => !is_int($value) && !is_string($value)) !== []) {
            throw new \InvalidArgumentException('Invalid GPRINT filter preferences.');
        }
        $db = $this->database->get();
        if ($db->inTransaction()) {
            throw new \RuntimeException('Filter preference transaction unavailable.');
        }
        $this->prepareMutation($db);
        if (!$db->beginTransaction()) {
            throw new \RuntimeException('Filter preference transaction unavailable.');
        }
        try {
            $actor = $this->access->authorize();
            $this->access->assertCurrent($actor->id);
            GprintPresetSql::execute($db, "REPLACE INTO settings_user (user_id, name, value) VALUES (?, 'gprint_presets_filters', ?)", [$actor->id, json_encode($filters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            if (!$db->commit()) {
                throw new \RuntimeException('GPRINT preference commit could not be confirmed.');
            }
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                try {
                    $confirmed = $db->rollBack();
                } catch (\Throwable $rollbackError) {
                    throw new \RuntimeException('GPRINT preference rollback could not be confirmed.', 0, $rollbackError);
                }
                if (!$confirmed) {
                    throw new \RuntimeException('GPRINT preference rollback could not be confirmed.', 0, $error);
                }
            }
            throw $error;
        }
    }
}
