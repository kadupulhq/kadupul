<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Symfony\InventoryLocaleSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class LinkLocaleTest extends TestCase
{
    public function testAllLinkRoutesUseTheAuthenticatedPreferenceBeforeQueryLocale(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE settings (name TEXT,value TEXT)');
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($db);
        $preference = $this->createMock(LocalePreference::class);
        $preference->method('preferredLocale')->willReturn('fr-FR');
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn([]);
        $subscriber = new InventoryLocaleSubscriber($preference, $database, $configuration);
        foreach (['navigation_links', 'navigation_links_legacy', 'navigation_link_action', 'navigation_link_create', 'navigation_link_edit'] as $route) {
            $request = Request::create('/links', 'GET', ['language' => 'en', '_locale' => 'en'], ['Cacti' => 'fixture']);
            $request->attributes->set('_route', $route);
            $subscriber->onRequest(new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
            self::assertSame('fr', $request->attributes->get('_locale'), $route);
        }
    }
}
