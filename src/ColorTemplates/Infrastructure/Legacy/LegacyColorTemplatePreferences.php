<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Infrastructure\Legacy;

use Kadupul\ColorTemplates\Application\Port\ColorTemplateAccess;
use Kadupul\ColorTemplates\Application\Port\ColorTemplatePreferences;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacyColorTemplatePreferences implements ColorTemplatePreferences
{
    public function __construct(private ColorTemplateAccess $access, private DatabaseConnection $database) {}

    public function load(): ?array
    {
        $actor = $this->access->authorize();
        $query = $this->database->get()->prepare("SELECT value FROM settings_user WHERE user_id=? AND name='color_templates_filters'");
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
        $allowed = ['filter', 'rows', 'page', 'sort_column', 'sort_direction', 'has_graphs'];
        if (!is_array($filters) || array_diff(array_keys($filters), $allowed) !== []
            || array_filter($filters, static fn($value): bool => !is_int($value) && !is_string($value)) !== []) {
            return null;
        }
        return array_map(static fn(int|string $value): string => (string) $value, $filters);
    }

    public function save(array $filters): void
    {
        $allowed = ['filter', 'rows', 'page', 'sort_column', 'sort_direction', 'has_graphs'];
        if (array_diff(array_keys($filters), $allowed) !== [] || array_filter($filters, static fn($value): bool => !is_int($value) && !is_string($value)) !== []) {
            throw new \InvalidArgumentException('Invalid color template preferences.');
        }
        $db = $this->database->get();
        if ($db->inTransaction() || !$db->beginTransaction()) {
            throw new \RuntimeException('Filter preference transaction unavailable.');
        }
        try {
            $actor = $this->access->authorize();
            $this->access->assertCurrent($actor->id);
            $query = $db->prepare("REPLACE INTO settings_user (user_id,name,value) VALUES (?,'color_templates_filters',?)");
            $query->execute([$actor->id, json_encode($filters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }
}
