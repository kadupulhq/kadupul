<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Closure;
use Doctrine\DBAL\Connection;
use Kadupul\Platform\Contract\JsonFilterPreferences;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(JsonFilterPreferences::class)]
final readonly class DbalJsonFilterPreferences implements JsonFilterPreferences
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        private LegacyConfiguration $configuration,
    ) {}

    public function load(int $actorId, string $name, array $keys, Closure $validateStored): ?array
    {
        $json = $this->database->fetchOne('SELECT value FROM settings_user WHERE user_id = ? AND name = ?', [$actorId, $name]);
        if (!is_string($json) || $json === '') {
            return null;
        }
        try {
            $filters = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($filters) || !self::valid($filters, $keys)) {
            return null;
        }
        try {
            $validateStored($filters);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return $filters;
    }

    public function save(string $name, array $filters, array $keys, string $invalidShape, Closure $authorize): void
    {
        if (!self::valid($filters, $keys)) {
            throw new \InvalidArgumentException($invalidShape);
        }
        if (($this->configuration->values()['collector_id'] ?? null) !== 1) {
            throw new \RuntimeException('Filter preferences require the primary collector.');
        }
        if ($this->database->isTransactionActive()) {
            throw new \RuntimeException('Filter preference transaction unavailable.');
        }
        $this->database->beginTransaction();
        try {
            $actorId = $authorize();
            $this->database->executeStatement('REPLACE INTO settings_user (user_id, name, value) VALUES (?, ?, ?)', [$actorId, $name, json_encode($filters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            $this->database->commit();
        } catch (\Throwable $error) {
            if ($this->database->isTransactionActive()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    /**
     * @param array<array-key, mixed> $filters
     * @param list<string> $keys
     * @phpstan-assert-if-true =array<string, string> $filters
     */
    private static function valid(array $filters, array $keys): bool
    {
        return array_diff(array_keys($filters), $keys) === []
            && array_filter($filters, static fn(mixed $value): bool => !is_string($value)) === [];
    }
}
