<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Collection\Contract\CollectorCatalog;
use Kadupul\Collection\Contract\PollerCacheInvalidator;
use Kadupul\Extensions\Contract\HookDispatcher;
use Kadupul\Extensions\Contract\PluginRegistry;
use Kadupul\IdentityAccess\Contract\RealmGrantManager;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\HttpClient;
use Kadupul\Platform\Contract\SettingsWriter;
use Kadupul\Platform\Contract\UserPreferenceStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Verifies Phase 0 bounded-context and cross-context contracts resolve from the
 * compiled Symfony container without opening a database connection.
 */
final class Phase0WiringTest extends TestCase
{
    private Kernel $kernel;
    private ContainerInterface $services;

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $this->services = $this->kernel->getContainer()->get('test.service_container');
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testAllPhase0ContractsResolveFromContainer(): void
    {
        $this->assertInstanceOf(HookDispatcher::class, $this->services->get('test.phase0.hook_dispatcher'));
        $this->assertInstanceOf(PluginRegistry::class, $this->services->get('test.phase0.plugin_registry'));
        $this->assertInstanceOf(CollectorCatalog::class, $this->services->get('test.phase0.collector_catalog'));
        $this->assertInstanceOf(PollerCacheInvalidator::class, $this->services->get('test.phase0.poller_cache_invalidator'));
        $this->assertInstanceOf(HttpClient::class, $this->services->get('test.phase0.http_client'));
        $this->assertInstanceOf(SettingsWriter::class, $this->services->get('test.phase0.settings_writer'));
        $this->assertInstanceOf(UserPreferenceStore::class, $this->services->get('test.phase0.user_preference_store'));
        $this->assertInstanceOf(RealmGrantManager::class, $this->services->get('test.phase0.realm_grant_manager'));
    }
}
