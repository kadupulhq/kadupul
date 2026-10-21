<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\AutomationTemplatePreferences;
use Kadupul\Automation\Domain\AutomationTemplateFilters;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(AutomationTemplatePreferences::class)]
final readonly class DbalAutomationTemplatePreferences implements AutomationTemplatePreferences
{
    private const string NAME = 'automation_template_filters';

    public function __construct(
        private AutomationTemplateAccess $access,
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        private LegacyConfiguration $configuration,
    ) {}

    public function load(): ?array
    {
        $actor = $this->access->authorize();
        $json = $this->database->fetchOne('SELECT value FROM settings_user WHERE user_id = ? AND name = ?', [$actor->id, self::NAME]);
        if (!is_string($json) || $json === '') {
            return null;
        }
        try {
            $filters = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($filters) || !self::valid($filters)) {
            return null;
        }
        try {
            // A stored value that is no longer in range must not lock the list.
            AutomationTemplateFilters::fromQuery($filters, 1);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return $filters;
    }

    public function save(array $filters): void
    {
        if (!self::valid($filters)) {
            throw new \InvalidArgumentException('Invalid automation template filters.');
        }
        if (($this->configuration->values()['collector_id'] ?? null) !== 1) {
            throw new \RuntimeException('Filter preferences require the primary collector.');
        }
        if ($this->database->isTransactionActive()) {
            throw new \RuntimeException('Filter preference transaction unavailable.');
        }
        $this->database->beginTransaction();
        try {
            $actor = $this->access->authorize();
            $this->access->assertCurrent($actor->id);
            $this->database->executeStatement('REPLACE INTO settings_user (user_id, name, value) VALUES (?, ?, ?)', [$actor->id, self::NAME, json_encode($filters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            $this->database->commit();
        } catch (\Throwable $error) {
            if ($this->database->isTransactionActive()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    private static function valid(array $filters): bool
    {
        return array_diff(array_keys($filters), AutomationTemplateFilters::KEYS) === []
            && array_filter($filters, static fn(mixed $value): bool => !is_string($value)) === [];
    }
}
