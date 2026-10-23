<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\ColorTemplateAccess;
use Kadupul\Graphing\Application\Port\ColorTemplatePreferences;
use Kadupul\Graphing\Application\Port\ColorTemplateStore;
use Kadupul\Graphing\Application\Port\ColorTemplateSynchronization;
use Kadupul\Graphing\Application\Query\ColorTemplateAccessDenied;
use Kadupul\Graphing\Domain\ColorTemplate;
use Kadupul\Graphing\Domain\ColorTemplateFilters;
use Kadupul\Graphing\Domain\ColorTemplateItem;
use Kadupul\Graphing\Domain\ColorTemplatePage;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ColorTemplateRoutesTest extends TestCase
{
    private Kernel $kernel;
    private ColorTemplateStore&MockObject $store;
    private ColorTemplateSynchronization&MockObject $synchronization;
    private bool $denied = false;
    /** @var list<ColorTemplate> */
    private array $templates = [];

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $actor = new Actor(42, 'operator');
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn($actor);
        $container->set(ConsoleAccess::class, $console);
        $access = $this->createMock(ColorTemplateAccess::class);
        $access->method('authorize')->willReturnCallback(fn(): Actor => $this->denied ? throw new ColorTemplateAccessDenied(false) : $actor);
        $container->set(ColorTemplateAccess::class, $access);
        $this->templates = [new ColorTemplate(1, 'Free <b>'), new ColorTemplate(2, 'Graphs', 1), new ColorTemplate(3, 'Templates', 0, 2)];
        $this->store = $this->createMock(ColorTemplateStore::class);
        $this->store->method('defaultRows')->willReturn(25);
        $this->store->method('defaultHasGraphs')->willReturn(false);
        $this->store->method('list')->willReturnCallback(fn(ColorTemplateFilters $filters): ColorTemplatePage => new ColorTemplatePage($this->templates, count($this->templates), $filters));
        $this->store->method('find')->willReturnCallback(fn(int $id): ?ColorTemplate => $this->templates[$id - 1] ?? null);
        $this->store->method('findMany')->willReturnCallback(fn(array $ids): array => array_values(array_filter($this->templates, static fn(ColorTemplate $template): bool => in_array($template->id, $ids, true))));
        $this->store->method('selectionRevisions')->willReturnCallback(static fn(array $ids): array => array_combine($ids, array_map(static fn(int $id): string => 'revision-' . $id, $ids)));
        $container->set(ColorTemplateStore::class, $this->store);
        $this->synchronization = $this->createMock(ColorTemplateSynchronization::class);
        $container->set(ColorTemplateSynchronization::class, $this->synchronization);
        $container->set(ColorTemplatePreferences::class, $this->createMock(ColorTemplatePreferences::class));
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $container->set(LegacyConfiguration::class, $configuration);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testReferencedTemplatesStaySelectableForDuplicateAndSync(): void
    {
        $document = new \DOMDocument();
        self::assertTrue(@$document->loadHTML((string) $this->get('/graphing/color-templates')->getContent()));
        $xpath = new \DOMXPath($document);
        self::assertSame(3, $xpath->query('//input[@name="ids[]" and not(@disabled)]')->length);
        self::assertSame(['delete', 'duplicate', 'sync'], array_map(static fn(\DOMElement $option): string => $option->getAttribute('value'), iterator_to_array($xpath->query('//select[@name="action"]/option'))));
        self::assertSame(['Yes', 'No', 'No'], array_map(static fn(\DOMNode $cell): string => trim($cell->textContent), iterator_to_array($xpath->query('//tbody/tr/td[3]'))));
    }

    public function testDeleteNamesBothReferenceKindsAndRefusesUsedTemplates(): void
    {
        $this->store->expects(self::never())->method('delete');
        $body = (string) $this->get('/graphing/color-templates/actions?action=delete&ids[]=2&ids[]=3')->getContent();
        self::assertStringContainsString('used by 1 aggregate graph items and 0 aggregate template items', $body);
        self::assertStringContainsString('used by 0 aggregate graph items and 2 aggregate template items', $body);
        self::assertStringContainsString('aggregate graph or aggregate template cannot be deleted', $body);
        self::assertSame(422, $this->post('/graphing/color-templates/actions?action=delete&ids[]=2', '[2]', [2 => 'revision-2'])->getStatusCode());
    }

    public function testDuplicateCarriesRevisionsAndTheTitleFormat(): void
    {
        $calls = [];
        $this->store->method('duplicate')->willReturnCallback(static function (...$arguments) use (&$calls): void {
            $calls[] = $arguments;
        });
        self::assertStringContainsString('&lt;template_title&gt; (1)', (string) $this->get('/graphing/color-templates/actions?action=duplicate&ids[]=2')->getContent());
        $response = $this->post('/graphing/color-templates/actions?action=duplicate&ids[]=2&filter=red', '[2]', [2 => 'revision-2'], ['title_format' => 'Copy of <template_title>']);
        self::assertSame(303, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('duplicated=1', (string) $response->headers->get('Location'));
        self::assertStringContainsString('filter=red', (string) $response->headers->get('Location'));
        self::assertSame([[42, [2], 'Copy of <template_title>', [2 => 'revision-2']]], $calls);
    }

    public function testSyncReportsEachTemplateAndStopsAtTheFirstFailure(): void
    {
        $this->synchronization->method('synchronize')->willReturnCallback(static fn(int $actor, int $id, string $revision): array => match ($id) {
            1 => ['template' => 'Free <b>', 'aggregate_templates' => 0, 'aggregate_graphs' => 0],
            2 => ['template' => 'Graphs', 'aggregate_templates' => 1, 'aggregate_graphs' => 3],
            default => throw new \RuntimeException('worker failed'),
        });
        $response = $this->post('/graphing/color-templates/actions?action=sync&ids[]=1&ids[]=2', '[1,2]', [1 => 'revision-1', 2 => 'revision-2']);
        $body = (string) $response->getContent();
        self::assertSame(200, $response->getStatusCode(), $body);
        self::assertStringContainsString('Free &lt;b&gt;&#039; had no Aggregate Templates or Graphs', $body);
        self::assertStringContainsString('had 1 Aggregate Templates pushed out and 3 Non-Templated Aggregates pushed out', $body);

        $response = $this->post('/graphing/color-templates/actions?action=sync&ids[]=2&ids[]=3', '[2,3]', [2 => 'revision-2', 3 => 'revision-3']);
        $body = (string) $response->getContent();
        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('had 1 Aggregate Templates pushed out', $body);
        self::assertStringContainsString('Some aggregates may already have been updated', $body);
    }

    public function testSyncCapsItsSelectionAndStopsStartingWorkersAtTheDeadline(): void
    {
        $this->synchronization->expects(self::never())->method('synchronize');
        $ids = implode('&', array_map(static fn(int $id): string => 'ids[]=' . $id, range(1, ColorTemplateSynchronization::MAX_SELECTION + 1)));
        $response = $this->get('/graphing/color-templates/actions?action=sync&' . $ids);
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Synchronize up to 10 Color Templates at a time.', (string) $response->getContent());
    }

    public function testSyncReportsTemplatesLeftUnstartedAfterTheDeadline(): void
    {
        $clock = new MockClock();
        $this->kernel->getContainer()->get('test.service_container')->set('clock', $clock);
        $started = [];
        $this->synchronization->method('synchronize')->willReturnCallback(static function (int $actor, int $id) use ($clock, &$started): array {
            $started[] = $id;
            $clock->sleep(ColorTemplateSynchronization::DEADLINE_SECONDS);
            return ['template' => 'T' . $id, 'aggregate_templates' => 0, 'aggregate_graphs' => 1];
        });
        $response = $this->post('/graphing/color-templates/actions?action=sync&ids[]=1&ids[]=2&ids[]=3', '[1,2,3]', [1 => 'revision-1', 2 => 'revision-2', 3 => 'revision-3']);
        $body = (string) $response->getContent();
        self::assertSame(200, $response->getStatusCode(), $body);
        self::assertSame([1], $started);
        self::assertStringContainsString('The time limit was reached', $body);
        self::assertMatchesRegularExpression('#<li>Graphs</li>\s*<li>Templates</li>#', $body);
    }

    public function testRemovingTheLastColorOfAReferencedTemplateIsRefused(): void
    {
        $item = new ColorTemplateItem(10, 2, 1, 1, 'FF0000');
        $this->store->method('items')->willReturn([$item]);
        $this->store->method('removeItem')->willThrowException(new \InvalidArgumentException('A Color Template used by an aggregate graph or aggregate template must keep at least one color.'));
        $response = $this->kernel->handle(Request::create('/graphing/color-templates/2/items/10/delete', 'POST', ['color_template_item_delete' => ['revision' => $item->revision, '_token' => 'csrf-token']], server: ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT_LANGUAGE' => 'fr']));
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('must keep at least one color', (string) $response->getContent());
    }

    public function testItemOrderButtonsSwapNeighboursUnderTheFormRevision(): void
    {
        $items = [new ColorTemplateItem(10, 1, 1, 1, 'FF0000'), new ColorTemplateItem(11, 1, 2, 2, '00FF00')];
        $this->store->method('items')->willReturn($items);
        $calls = [];
        $this->store->method('reorder')->willReturnCallback(static function (...$arguments) use (&$calls): void {
            $calls[] = $arguments;
        });
        $body = (string) $this->get('/graphing/color-templates/1/edit')->getContent();
        self::assertStringContainsString(ColorTemplateItem::orderRevision($items), $body);
        $order = ['items' => '[10,11]', 'revision' => 'order', '_token' => 'csrf-token'];
        $request = static fn(array $fields): Request => Request::create('/graphing/color-templates/1/items/order', 'POST', ['color_template_order' => $fields], server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertSame(303, $this->kernel->handle($request($order + ['moveDown' => '10']))->getStatusCode());
        self::assertSame([[42, 1, [11, 10], 'order']], $calls);
        foreach ([['moveUp' => '10'], ['moveDown' => '11'], ['items' => '{"0":10,"1":11}'], ['moveUp' => '11', 'moveDown' => '10']] as $invalid) {
            self::assertSame(400, $this->kernel->handle($request($invalid + $order))->getStatusCode(), json_encode($invalid));
        }
        self::assertCount(1, $calls);
    }

    public function testLegacyUrlsRedirectSafeNavigationAndExpirePostedForms(): void
    {
        $this->store->expects(self::never())->method('save');
        $this->store->expects(self::never())->method('delete');
        $locations = [
            '/graphing/color-templates/legacy' => '/graphing/color-templates',
            '/graphing/color-templates/legacy?clear=1&header=false' => '/graphing/color-templates?reset=1',
            '/graphing/color-templates/legacy?action=template_edit' => '/graphing/color-templates/new',
            '/graphing/color-templates/legacy?action=template_edit&color_template_id=3&header=false' => '/graphing/color-templates/3/edit',
            '/graphing/color-templates/items/legacy?action=item&color_template_id=3' => '/graphing/color-templates/3/edit',
            '/graphing/color-templates/items/legacy?action=item_edit&color_template_id=3' => '/graphing/color-templates/3/items/new',
            '/graphing/color-templates/items/legacy?action=item_edit&color_template_id=3&color_template_item_id=9' => '/graphing/color-templates/3/items/9/edit',
            '/graphing/color-templates/items/legacy?action=item_remove_confirm&id=3&color_id=9' => '/graphing/color-templates/3/items/9/delete',
        ];
        foreach ($locations as $uri => $location) {
            $response = $this->get($uri);
            self::assertSame(302, $response->getStatusCode(), $uri);
            self::assertSame($location, $response->headers->get('Location'), $uri);
        }
        self::assertStringContainsString('has_graphs=true', (string) $this->get('/graphing/color-templates/legacy?has_graphs=true')->headers->get('Location'));
        foreach (['/graphing/color-templates/legacy', '/graphing/color-templates/items/legacy'] as $uri) {
            self::assertSame(409, $this->kernel->handle(Request::create($uri, 'POST', ['action' => 'actions', 'drp_action' => '1']))->getStatusCode());
        }
        self::assertSame(405, $this->get('/graphing/color-templates/legacy?action=actions')->getStatusCode());
        self::assertSame(405, $this->get('/graphing/color-templates/items/legacy?action=item_moveup&color_template_id=3')->getStatusCode());
        foreach (['/graphing/color-templates/legacy?action[]=x', '/graphing/color-templates/legacy?action=template_edit&color_template_id=1e2', '/graphing/color-templates/items/legacy?action=item_edit', '/graphing/color-templates/legacy?rows=0'] as $uri) {
            self::assertSame(400, $this->get($uri)->getStatusCode(), $uri);
        }
    }

    public function testDeniedAccessStopsBeforeReadingTheStore(): void
    {
        $this->denied = true;
        $this->store->expects(self::never())->method('find');
        $this->store->expects(self::never())->method('findMany');
        $this->store->expects(self::never())->method('list');
        foreach (['/graphing/color-templates', '/graphing/color-templates/new', '/graphing/color-templates/1/edit', '/graphing/color-templates/1/items/new', '/graphing/color-templates/1/items/10/delete', '/graphing/color-templates/actions?action=sync&ids[]=1', '/graphing/color-templates/legacy', '/graphing/color-templates/items/legacy'] as $uri) {
            self::assertSame(403, $this->get($uri)->getStatusCode(), $uri);
        }
    }

    private function get(string $uri): Response
    {
        return $this->kernel->handle(Request::create($uri));
    }

    /** @param array<int, string> $revisions @param array<string, string> $extra */
    private function post(string $uri, string $selection, array $revisions, array $extra = []): Response
    {
        $data = ['selection' => $selection, 'revisions' => json_encode($revisions, JSON_THROW_ON_ERROR), '_token' => 'csrf-token'] + $extra;
        return $this->kernel->handle(Request::create($uri, 'POST', ['color_template_action' => $data], server: ['HTTP_ORIGIN' => 'http://localhost']));
    }
}
