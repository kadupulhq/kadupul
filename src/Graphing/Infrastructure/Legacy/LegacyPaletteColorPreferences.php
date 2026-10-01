<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\PaletteColorPreferences;
use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacyPaletteColorPreferences implements PaletteColorPreferences
{
    public function __construct(private PaletteColorAccess $access, private DatabaseConnection $database) {}

    public function load(): ?array
    {
        $actor = $this->access->authorize();
        $query = PaletteSql::execute($this->database->get(), "SELECT value FROM settings_user WHERE user_id = ? AND name = 'palette_colors_filters'", [$actor->id]);
        $json = PaletteSql::column($query);
        if (!is_string($json) || $json === '') {
            return null;
        }
        try {
            $filters = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $allowed = ['rows', 'page', 'filter', 'sort_column', 'sort_direction', 'has_graphs', 'named'];
        if (!is_array($filters) || array_diff(array_keys($filters), $allowed) !== []
            || array_filter($filters, static fn($value): bool => !is_int($value) && !is_string($value)) !== []) {
            return null;
        }
        return array_map(static fn(int|string $value): string => (string) $value, $filters);
    }

    public function save(array $filters): void
    {
        $allowed = ['rows', 'page', 'filter', 'sort_column', 'sort_direction', 'has_graphs', 'named'];
        if (array_diff(array_keys($filters), $allowed) !== [] || array_filter($filters, static fn($value): bool => !is_int($value) && !is_string($value)) !== []) {
            throw new \InvalidArgumentException('Invalid color filter preferences.');
        }
        $db = $this->database->get();
        if ($db->inTransaction() || !$db->beginTransaction()) {
            throw new \RuntimeException('Filter preference transaction unavailable.');
        }
        try {
            $actor = $this->access->authorize();
            $this->access->assertCurrent($actor->id);
            PaletteSql::execute($db, "REPLACE INTO settings_user (user_id, name, value) VALUES (?, 'palette_colors_filters', ?)", [$actor->id, json_encode($filters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
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
