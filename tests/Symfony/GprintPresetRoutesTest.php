<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Application\Port\GprintPresetPreferences;
use Kadupul\Graphing\Application\Port\GprintPresetStore;
use Kadupul\Graphing\Application\Query\GprintPresetAccessDenied;
use Kadupul\Graphing\Domain\GprintPreset;
use Kadupul\Graphing\Domain\GprintPresetFilters;
use Kadupul\Graphing\Domain\GprintPresetPage;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class GprintPresetRoutesTest extends TestCase
{
    private Kernel $kernel;
    private GprintPresetStore&MockObject $store;
    private GprintPresetPreferences&MockObject $preferences;
    private GprintPresetAccess&MockObject $access;
    /** @var list<array<string, int|string>> */
    private array $savedPreferences = [];
    private bool $denied = false;
    /** @var list<GprintPreset> */
    private array $listed = [];

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $actor = new Actor(42, 'operator');
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn($actor);
        $container->set(ConsoleAccess::class, $console);
        $this->access = $this->createMock(GprintPresetAccess::class);
        $this->access->method('authorize')->willReturnCallback(fn(): Actor => $this->denied ? throw new GprintPresetAccessDenied(false) : $actor);
        $container->set(GprintPresetAccess::class, $this->access);
        $this->store = $this->createMock(GprintPresetStore::class);
        $this->store->method('defaultRows')->willReturn(25);
        $this->store->method('defaultHasGraphs')->willReturn(false);
        $this->store->method('list')->willReturnCallback(fn(GprintPresetFilters $filters): GprintPresetPage => new GprintPresetPage($this->listed, count($this->listed), $filters));
        $container->set(GprintPresetStore::class, $this->store);
        $this->preferences = $this->createMock(GprintPresetPreferences::class);
        $this->preferences->method('save')->willReturnCallback(function (array $filters): void {
            $this->savedPreferences[] = $filters;
        });
        $container->set(GprintPresetPreferences::class, $this->preferences);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $container->set(LegacyConfiguration::class, $configuration);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testLegacyMenuVisitKeepsSavedFiltersAndClearResetsThem(): void
    {
        $this->store->expects(self::never())->method('save');
        $this->store->expects(self::never())->method('delete');
        $response = $this->get('/graphing/gprint-presets/legacy');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/graphing/gprint-presets', $response->headers->get('Location'));
        self::assertSame('/graphing/gprint-presets?reset=1', $this->get('/graphing/gprint-presets/legacy?clear=1&header=false')->headers->get('Location'));
        self::assertSame('/graphing/gprint-presets/new', $this->get('/graphing/gprint-presets/legacy?action=edit')->headers->get('Location'));
        self::assertSame('/graphing/gprint-presets/12/edit', $this->get('/graphing/gprint-presets/legacy?action=edit&id=12&header=false')->headers->get('Location'));
        $location = $this->get('/graphing/gprint-presets/legacy?filter=cpu&has_graphs=true&header=false')->headers->get('Location');
        self::assertStringContainsString('filter=cpu', $location);
        self::assertStringContainsString('has_graphs=true', $location);

        $this->preferences->expects(self::once())->method('load')->willReturn(['filter' => 'saved', 'rows' => '10']);
        $html = (string) $this->get('/graphing/gprint-presets')->getContent();
        self::assertSame([], $this->savedPreferences);
        self::assertStringContainsString('value="saved"', $html);
        self::assertStringContainsString('<option value="10" selected>', $html);
    }

    public function testLegacyFormsAndActionsNeverMutate(): void
    {
        $this->store->expects(self::never())->method('save');
        $this->store->expects(self::never())->method('delete');
        $request = Request::create('/graphing/gprint-presets/legacy', 'POST', ['action' => 'actions', 'drp_action' => '1', 'selected_items' => serialize([1])]);
        self::assertSame(409, $this->kernel->handle($request)->getStatusCode());
        foreach (['actions', 'save'] as $action) {
            self::assertSame(405, $this->get('/graphing/gprint-presets/legacy?action=' . $action)->getStatusCode());
        }
        foreach (['action=remove', 'action[]=edit', 'action=edit&id=../1', 'clear=2', 'rows=0'] as $query) {
            self::assertSame(400, $this->get('/graphing/gprint-presets/legacy?' . $query)->getStatusCode(), $query);
        }
    }

    public function testExplicitFiltersAreSavedOnThePrimaryCollector(): void
    {
        $response = $this->get('/graphing/gprint-presets?filter=cpu&rows=10&has_graphs=true');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([['filter' => 'cpu', 'rows' => '10', 'page' => 1, 'sort_column' => 'name', 'sort_direction' => 'ASC', 'has_graphs' => 'true']], $this->savedPreferences);
        self::assertStringContainsString('<option value="10" selected>', (string) $response->getContent());
        self::assertSame(400, $this->get('/graphing/gprint-presets?named=true')->getStatusCode());
    }

    public function testArrayValuedQueryFlagsAreRejectedWithoutAServerError(): void
    {
        $this->store->method('find')->willReturn(new GprintPreset(3, 'Preset', '%s', 'hash'));
        self::assertSame(200, $this->get('/graphing/gprint-presets/3/edit?saved[]=1')->getStatusCode());
        self::assertSame(400, $this->get('/graphing/gprint-presets?saved[]=1')->getStatusCode());
    }

    public function testDeniedAccessStopsBeforeReadingTheStore(): void
    {
        $this->denied = true;
        $this->store->expects(self::never())->method('findMany');
        $this->store->expects(self::never())->method('find');
        $this->preferences->expects(self::never())->method('load');
        foreach (['/graphing/gprint-presets', '/graphing/gprint-presets/new', '/graphing/gprint-presets/3/edit', '/graphing/gprint-presets/actions/delete?ids[]=3', '/graphing/gprint-presets/legacy'] as $path) {
            self::assertSame(403, $this->get($path)->getStatusCode(), $path);
        }
    }

    public function testListOffersOnlyDeletableRowsWithinTheSelectionLimit(): void
    {
        $this->listed = array_map(static fn(int $id): GprintPreset => new GprintPreset($id, 'Preset ' . $id, '%s', 'hash', $id === 2 ? 1 : 0, $id === 3 ? 1 : 0), range(1, 150));
        $document = new \DOMDocument();
        self::assertTrue(@$document->loadHTML((string) $this->get('/graphing/gprint-presets?rows=5000')->getContent()));
        $xpath = new \DOMXPath($document);
        self::assertSame(98, $xpath->query('//input[@name="ids[]" and not(@disabled)]')->length);
        foreach ([2, 3, 101, 150] as $id) {
            self::assertSame(1, $xpath->query('//input[@name="ids[]" and @value="' . $id . '" and @disabled]')->length);
        }
        self::assertSame(1, $xpath->query('//form[@aria-describedby="gprint-selection-help"]')->length);
        self::assertSame(150, $xpath->query('//tbody/tr')->length);
    }

    public function testDeleteRefusesOversizedAndInUseSelectionsAndDeletesTheConfirmedOne(): void
    {
        $oversized = implode('&', array_map(static fn(int $id): string => 'ids[]=' . $id, range(1, GprintPresetStore::MAX_DELETE_SELECTION + 1)));
        self::assertSame(400, $this->get('/graphing/gprint-presets/actions/delete?' . $oversized)->getStatusCode());

        $free = new GprintPreset(1, 'Free <b>', '%s', 'hash');
        $used = new GprintPreset(2, 'Used', '%s', 'hash', 1);
        $this->store->method('findMany')->willReturnCallback(static fn(array $ids): array => array_values(array_filter([$free, $used], static fn(GprintPreset $preset): bool => in_array($preset->id, $ids, true))));
        $calls = [];
        $this->store->method('delete')->willReturnCallback(static function (...$arguments) use (&$calls): void {
            $calls[] = $arguments;
        });

        $confirmation = $this->get('/graphing/gprint-presets/actions/delete?ids[]=1&ids[]=2');
        self::assertSame(200, $confirmation->getStatusCode());
        self::assertStringContainsString('Free &lt;b&gt;', (string) $confirmation->getContent());
        self::assertStringContainsString('cannot be deleted', (string) $confirmation->getContent());
        self::assertSame(422, $this->post('/graphing/gprint-presets/actions/delete?ids[]=1&ids[]=2', '[1,2]', [1 => $free->revision, 2 => $used->revision])->getStatusCode());
        self::assertSame(422, $this->post('/graphing/gprint-presets/actions/delete?ids[]=1', '{"0":1}', [1 => $free->revision])->getStatusCode());
        self::assertSame([], $calls);

        $response = $this->post('/graphing/gprint-presets/actions/delete?ids[]=1&filter=cpu', '[1]', [1 => $free->revision]);
        self::assertSame(303, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('deleted=1', (string) $response->headers->get('Location'));
        self::assertStringContainsString('filter=cpu', (string) $response->headers->get('Location'));
        self::assertSame([[42, [1], [1 => $free->revision]]], $calls);
    }

    private function get(string $uri): Response
    {
        return $this->kernel->handle(Request::create($uri));
    }

    /** @param array<int, string> $revisions */
    private function post(string $uri, string $selection, array $revisions): Response
    {
        $data = ['selection' => $selection, 'revisions' => json_encode($revisions, JSON_THROW_ON_ERROR), '_token' => 'csrf-token'];
        return $this->kernel->handle(Request::create($uri, 'POST', ['gprint_preset_delete' => $data], server: ['HTTP_ORIGIN' => 'http://localhost']));
    }
}
