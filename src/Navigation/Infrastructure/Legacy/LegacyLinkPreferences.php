<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Legacy;

use Kadupul\Navigation\Application\Port\LinkPreferences;
use Kadupul\Navigation\Application\Port\LinkAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;

final readonly class LegacyLinkPreferences implements LinkPreferences
{
    public function __construct(private LinkAccess $access, private DatabaseConnection $database, private LegacyConfiguration $configuration) {}

    public function load(): ?array
    {
        $actor = $this->access->authorize();
        $query = $this->database->get()->prepare("SELECT value FROM settings_user WHERE user_id = ? AND name = 'external_links_filters'");
        $query->execute([$actor->id]);
        $json = $query->fetchColumn();
        if (!is_string($json) || $json === '') {
            return null;
        }
        try {
            $filters = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $allowed = ['rows', 'page', 'filter', 'sort_column', 'sort_direction'];
        if (!is_array($filters) || array_diff(array_keys($filters), $allowed) !== []
            || array_filter($filters, static fn($value): bool => !is_int($value) && !is_string($value)) !== []) {
            return null;
        }
        return array_map(static fn(int|string $value): string => (string) $value, $filters);
    }

    public function save(array $filters): void
    {
        $allowed = ['rows', 'page', 'filter', 'sort_column', 'sort_direction'];
        if (array_diff(array_keys($filters), $allowed) !== [] || array_filter($filters, static fn($value): bool => !is_int($value) && !is_string($value)) !== []) {
            throw new \InvalidArgumentException('Invalid link filter preferences.');
        }
        $db = $this->database->get();
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            if (($this->configuration->values()['collector_id'] ?? null) !== 1) {
                throw new \RuntimeException('Filter preferences require the primary collector.');
            }
            $engines = $db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            foreach (['settings_user', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'] as $table) {
                $engines->execute([$table]);
                if (strcasecmp((string) $engines->fetchColumn(), 'InnoDB') !== 0) {
                    throw new \RuntimeException('Filter preferences require transactional tables.');
                }
            }
        }
        if ($db->inTransaction() || !$db->beginTransaction()) {
            throw new \RuntimeException('Filter preference transaction unavailable.');
        }
        try {
            $actor = $this->access->authorize();
            $this->access->assertCurrent($actor->id);
            $query = $db->prepare("REPLACE INTO settings_user (user_id, name, value) VALUES (?, 'external_links_filters', ?)");
            $query->execute([$actor->id, json_encode($filters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            if (!$db->commit()) {
                throw new \RuntimeException('Filter preference commit was not confirmed.');
            }
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }
}
