<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Navigation\Application\Port\LinkAccess;
use Kadupul\Navigation\Application\Port\LinkPreferences;
use Kadupul\Navigation\Application\Port\LinkStore;
use Kadupul\Navigation\Application\Query\LinkAccessDenied;
use Kadupul\Navigation\Infrastructure\Symfony\Form\LinkType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

final class LinkReviewRegressionTest extends TestCase
{
    public static function revocations(): iterable
    {
        foreach (['/links/new', '/links/1/edit', '/links/action/delete?ids[]=1', '/links'] as $path) {
            foreach ([false, true] as $unauthenticated) {
                yield [$path, 'query', $unauthenticated];
            }
        }
        foreach (['load', 'save'] as $stage) {
            foreach ([false, true] as $unauthenticated) {
                yield ['/links', $stage, $unauthenticated];
            }
        }
    }

    #[DataProvider('revocations')]
    public function testLaterAuthorizationDenialsReturnControlledResponses(string $path, string $stage, bool $unauthenticated): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['collector_id' => 1]);
            $container->set(LegacyConfiguration::class, $configuration);
            $actor = new Actor(1, 'admin');
            $console = $this->createMock(ConsoleAccess::class);
            $console->method('consoleActor')->willReturn($actor);
            $container->set(ConsoleAccess::class, $console);
            $access = $this->createMock(LinkAccess::class);
            $calls = 0;
            $access->method('authorize')->willReturnCallback(static function () use (&$calls, $stage, $actor, $unauthenticated): Actor {
                if (++$calls > 1 && $stage === 'query') {
                    throw new LinkAccessDenied($unauthenticated);
                }
                return $actor;
            });
            $container->set(LinkAccess::class, $access);
            $store = $this->createMock(LinkStore::class);
            $store->method('defaultRows')->willReturn(10);
            $store->expects(self::never())->method('snapshot');
            $store->expects(self::never())->method('list');
            $container->set(LinkStore::class, $store);
            $preferences = $this->createMock(LinkPreferences::class);
            if ($stage === 'load') {
                $preferences->method('load')->willThrowException(new LinkAccessDenied($unauthenticated));
                $preferences->expects(self::never())->method('save');
            } else {
                $preferences->method('load')->willReturn(null);
                if ($stage === 'save') {
                    $preferences->method('save')->willThrowException(new LinkAccessDenied($unauthenticated));
                }
            }
            $container->set(LinkPreferences::class, $preferences);
            $response = $kernel->handle(Request::create($path));
            self::assertSame($unauthenticated ? 401 : 403, $response->getStatusCode());
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            self::assertSame('Access denied.', $response->getContent());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testRemoteCollectorRendersFiltersWithoutPreferenceWrites(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $actor = new Actor(1, 'admin');
            $console = $this->createMock(ConsoleAccess::class);
            $console->method('consoleActor')->willReturn($actor);
            $container->set(ConsoleAccess::class, $console);
            $access = $this->createMock(LinkAccess::class);
            $access->method('authorize')->willReturn($actor);
            $container->set(LinkAccess::class, $access);
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['collector_id' => 2]);
            $container->set(LegacyConfiguration::class, $configuration);
            $preferences = $this->createMock(LinkPreferences::class);
            $preferences->method('load')->willReturn(['filter' => 'remembered']);
            $preferences->expects(self::never())->method('save');
            $container->set(LinkPreferences::class, $preferences);
            $store = $this->createMock(LinkStore::class);
            $store->method('defaultRows')->willReturn(10);
            $store->expects(self::once())->method('list')->with(self::callback(static fn(array $filters): bool => $filters['filter'] === 'requested'))->willReturn(['links' => [], 'total' => 0]);
            $container->set(LinkStore::class, $store);
            $response = $kernel->handle(Request::create('/links?filter=requested'));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('value="requested"', $response->getContent());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testExistingSectionWithSyntheticLabelRemainsSelectable(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $factory = $kernel->getContainer()->get('test.service_container')->get(FormFactoryInterface::class);
            $form = $factory->create(LinkType::class, null, ['files' => [], 'sections' => ['New Name Below'], 'csrf_protection' => false]);
            $choices = $form->get('consolesection')->createView()->vars['choices'];
            self::assertSame(['External Links', 'New Name Below', '__NEW__'], array_map(static fn($choice) => $choice->value, $choices));
            $form->submit(['title' => 'Example', 'style' => 'CONSOLE', 'filename' => '0', 'fileurl' => 'https://example.org', 'consolesection' => 'New Name Below', 'consolenewsection' => '', 'enabled' => '1', 'refresh' => '0', 'revision' => str_repeat('a', 64)]);
            self::assertTrue($form->isValid(), (string) $form->getErrors(true));
            self::assertSame('New Name Below', $form->getData()['consolesection']);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testSortDirectionHasLocalizedLabel(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $container->get('translator')->setLocale('fr');
            $html = $container->get('twig')->render('navigation/links.html.twig', ['links' => [], 'filters' => ['filter' => '', 'rows' => 10, 'sort_column' => 'sortorder', 'sort_direction' => 'ASC', 'page' => 1, 'limit' => 10], 'total' => 0, 'viewPath' => '/link.php']);
            $document = new \DOMDocument();
            self::assertTrue(@$document->loadHTML('<?xml encoding="UTF-8">' . $html));
            $labels = (new \DOMXPath($document))->query('//label[select[@name="sort_direction"]]');
            self::assertSame(1, $labels->length);
            self::assertStringContainsString('Sens du tri', $labels->item(0)->textContent);
        } finally {
            $kernel->shutdown();
        }
    }
}
