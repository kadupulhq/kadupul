<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\ColorTemplateAccess;
use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;

/**
 * One list's saved filters in settings_user. Each list keeps its own setting
 * name, keys, SQL helper and messages; the setting name is a constant from the
 * subclass and stays in the SQL text, where read-failure tests look for it.
 */
abstract readonly class LegacyFilterPreferences
{
    /**
     * @param class-string<PaletteSql|GprintPresetSql|ColorTemplateSql> $sql
     * @param list<string> $keys
     */
    protected function __construct(
        private PaletteColorAccess|GprintPresetAccess|ColorTemplateAccess $access,
        private DatabaseConnection $database,
        private LegacyConfiguration $configuration,
        private string $sql,
        private string $setting,
        private array $keys,
        private string $unsupported,
        private string $isolation,
        private string $invalid,
    ) {}

    /** @return array<string, string>|null */
    public function load(): ?array
    {
        $actor = $this->access->authorize();
        $query = $this->sql::execute($this->database->get(), "SELECT value FROM settings_user WHERE user_id = ? AND name = '" . $this->setting . "'", [$actor->id]);
        $json = $this->sql::column($query);
        if (!is_string($json) || $json === '') {
            return null;
        }
        try {
            $filters = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($filters) || array_diff(array_keys($filters), $this->keys) !== []
            || array_any($filters, static fn(mixed $value): bool => !is_int($value) && !is_string($value))) {
            return null;
        }
        return array_map(static fn(int|string $value): string => (string) $value, $filters);
    }

    private function prepareMutation(\PDO $db): void
    {
        if (($this->configuration->values()['collector_id'] ?? null) !== 1) {
            throw new \RuntimeException('Filter preferences require the primary collector.');
        }
        $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            return;
        }
        if ($driver !== 'mysql') {
            throw new \RuntimeException($this->unsupported);
        }
        $optional = ['user_auth_group', 'user_auth_group_realm', 'user_auth_group_members'];
        foreach (['settings_user', 'settings', 'user_auth', 'user_auth_realm', ...$optional] as $table) {
            try {
                $query = $db->query('SHOW CREATE TABLE `' . $table . '`');
                if ($query === false && in_array($table, $optional, true) && ($db->errorInfo()[1] ?? null) === 1146) {
                    continue;
                }
                $definition = $query === false ? false : $this->sql::one($query, \PDO::FETCH_NUM);
            } catch (\PDOException $error) {
                if (in_array($table, $optional, true) && ($error->errorInfo[1] ?? null) === 1146) {
                    continue;
                }
                throw $error;
            }
            if (!is_array($definition) || !is_string($definition[1] ?? null)
                || preg_match('/\ACREATE TABLE /i', $definition[1]) !== 1
                || preg_match('/^\) ENGINE=InnoDB(?:\s|$)/mi', $definition[1]) !== 1) {
                throw new \RuntimeException('Filter preferences require transactional tables: ' . $table);
            }
        }
        if ($db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false || $db->errorCode() !== '00000') {
            throw new \RuntimeException($this->isolation);
        }
    }

    /** @param array<string, int|string> $filters */
    public function save(array $filters): void
    {
        if (array_diff(array_keys($filters), $this->keys) !== [] || array_any($filters, static fn(mixed $value): bool => !is_int($value) && !is_string($value))) {
            throw new \InvalidArgumentException($this->invalid);
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
            $this->sql::execute($db, "REPLACE INTO settings_user (user_id, name, value) VALUES (?, '" . $this->setting . "', ?)", [$actor->id, json_encode($filters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            if (!$db->commit()) {
                throw new \RuntimeException('Filter preference commit was not confirmed.');
            }
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                try {
                    $rolledBack = $db->rollBack();
                } catch (\Throwable $rollbackError) {
                    throw new \RuntimeException('Filter preference rollback was not confirmed.', 0, $rollbackError);
                }
                if (!$rolledBack) {
                    throw new \RuntimeException('Filter preference rollback was not confirmed.', 0, $error);
                }
            }
            throw $error;
        }
    }
}
