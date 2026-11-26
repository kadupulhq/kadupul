<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Extensions\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Extensions\Application\Port\PluginStore;
use Kadupul\Extensions\Domain\PluginMetadata;
use Kadupul\Extensions\Domain\PluginSlug;
use Kadupul\Extensions\Domain\PluginStatus;
use Kadupul\Extensions\Domain\RegisteredPlugin;

/**
 * Reads plugin registrations from the legacy plugin_config table via DBAL.
 */
final readonly class DbalPluginRegistry implements PluginStore
{
    private const string SELECT_COLUMNS = 'SELECT id, directory, name, status, author, webpage, version FROM plugin_config';

    public function __construct(private Connection $database) {}

    #[\Override]
    public function findAll(): array
    {
        $rows = $this->database->fetchAllAssociative(self::SELECT_COLUMNS . ' ORDER BY directory ASC, id ASC');
        $plugins = [];
        foreach ($rows as $row) {
            $plugin = self::hydrate($row);
            if ($plugin !== null) {
                $plugins[] = $plugin;
            }
        }

        return $plugins;
    }

    #[\Override]
    public function findBySlug(PluginSlug $slug): ?RegisteredPlugin
    {
        $row = $this->database->fetchAssociative(
            self::SELECT_COLUMNS . ' WHERE directory = ? ORDER BY id ASC LIMIT 1',
            [$slug->value()],
        );

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): ?RegisteredPlugin
    {
        $id = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
        if ($id < 1) {
            return null;
        }

        $directory = is_string($row['directory'] ?? null) ? $row['directory'] : '';
        try {
            $slug = PluginSlug::fromString($directory);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $rawName = is_string($row['name'] ?? null) ? trim($row['name']) : '';
        $name = $rawName !== '' && preg_match('/[\x00-\x1F\x7F]/', $rawName) !== 1 ? $rawName : $slug->value();
        $version = is_string($row['version'] ?? null) ? (string) preg_replace('/[\x00-\x1F\x7F]/', '', $row['version']) : '';
        $author = is_string($row['author'] ?? null) ? (string) preg_replace('/[\x00-\x1F\x7F]/', '', $row['author']) : '';
        $webpage = is_string($row['webpage'] ?? null) ? PluginMetadata::sanitizeWebpage($row['webpage']) : null;
        $statusCode = is_numeric($row['status'] ?? null) ? (int) $row['status'] : 0;

        return new RegisteredPlugin(
            $id,
            new PluginMetadata($slug, $name, $version, $author, $webpage),
            PluginStatus::fromLegacyStatus($statusCode),
        );
    }
}
