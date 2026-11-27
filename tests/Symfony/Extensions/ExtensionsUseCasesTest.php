<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Extensions;

use Kadupul\Extensions\Application\Port\HookBus;
use Kadupul\Extensions\Application\Port\PluginStore;
use Kadupul\Extensions\Application\UseCase\DispatchHook;
use Kadupul\Extensions\Application\UseCase\ListPlugins;
use Kadupul\Extensions\Contract\HookEvent;
use Kadupul\Extensions\Domain\PluginMetadata;
use Kadupul\Extensions\Domain\PluginSlug;
use Kadupul\Extensions\Domain\PluginStatus;
use Kadupul\Extensions\Domain\RegisteredPlugin;
use PHPUnit\Framework\TestCase;

final class ExtensionsUseCasesTest extends TestCase
{
    public function testDispatchHookPublishesThroughBus(): void
    {
        $bus = new readonly class () implements HookBus {
            #[\Override]
            public function publish(HookEvent $event): HookEvent
            {
                return $event->withPayload(['handled' => true, 'original' => $event->payload]);
            }
        };

        $useCase = new DispatchHook($bus);
        $result = $useCase->dispatch(new HookEvent('device_saved', ['id' => 42], ['actor' => 1]));

        $this->assertSame('device_saved', $result->hook);
        $this->assertSame(['actor' => 1], $result->context);
        $this->assertSame(['handled' => true, 'original' => ['id' => 42]], $result->payload);
    }

    public function testHookEventRejectsInvalidHookNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HookEvent('Invalid-Hook!');
    }

    public function testListPluginsFiltersAndResolvesBySlug(): void
    {
        $enabled = new RegisteredPlugin(
            1,
            new PluginMetadata(PluginSlug::fromString('monitor'), 'Monitor', '2.7', 'Team', 'https://example.org'),
            PluginStatus::Enabled,
        );
        $disabled = new RegisteredPlugin(
            2,
            new PluginMetadata(PluginSlug::fromString('weathermap'), 'Weathermap', '1.0', 'Team', null),
            PluginStatus::Disabled,
        );

        $store = new readonly class ([$enabled, $disabled]) implements PluginStore {
            /** @param list<RegisteredPlugin> $plugins */
            public function __construct(private array $plugins) {}

            #[\Override]
            public function findAll(): array
            {
                return $this->plugins;
            }

            #[\Override]
            public function findBySlug(PluginSlug $slug): ?RegisteredPlugin
            {
                return array_find($this->plugins, static fn(RegisteredPlugin $p): bool => $p->metadata->slug->equals($slug));
            }
        };

        $useCase = new ListPlugins($store);
        $this->assertCount(2, $useCase->listAll());
        $this->assertCount(1, $useCase->list(true));
        $this->assertTrue($useCase->isEnabled('monitor'));
        $this->assertFalse($useCase->isEnabled('weathermap'));
        $this->assertFalse($useCase->isEnabled('missing'));
        $this->assertNull($useCase->findBySlug('../invalid'));
    }
}
