<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Extensions\Application\UseCase;

use Kadupul\Extensions\Application\Port\PluginStore;
use Kadupul\Extensions\Contract\PluginRegistry;
use Kadupul\Extensions\Contract\PluginSummary;
use Kadupul\Extensions\Domain\PluginSlug;
use Kadupul\Extensions\Domain\RegisteredPlugin;

final readonly class ListPlugins implements PluginRegistry
{
    public function __construct(private PluginStore $store) {}

    /**
     * @return list<PluginSummary>
     */
    public function list(bool $onlyEnabled = false): array
    {
        $plugins = $this->store->findAll();
        $summaries = [];
        foreach ($plugins as $plugin) {
            if ($onlyEnabled && !$plugin->status->isActive()) {
                continue;
            }
            $summaries[] = self::summarize($plugin);
        }

        return $summaries;
    }

    #[\Override]
    public function listAll(): array
    {
        return $this->list(false);
    }

    #[\Override]
    public function findBySlug(string $slug): ?PluginSummary
    {
        try {
            $parsed = PluginSlug::fromString($slug);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $plugin = $this->store->findBySlug($parsed);

        return $plugin === null ? null : self::summarize($plugin);
    }

    #[\Override]
    public function isEnabled(string $slug): bool
    {
        $summary = $this->findBySlug($slug);

        return $summary !== null && $summary->enabled;
    }

    private static function summarize(RegisteredPlugin $plugin): PluginSummary
    {
        return new PluginSummary(
            $plugin->id,
            $plugin->metadata->slug->value(),
            $plugin->metadata->name,
            $plugin->metadata->version,
            $plugin->metadata->author,
            $plugin->metadata->webpage,
            $plugin->status->value,
            $plugin->status->isActive(),
        );
    }
}
