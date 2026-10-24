<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Kadupul\Kernel;
use Kadupul\Platform\Application\Port\HelpTopics;
use Kadupul\Platform\Application\Query\ViewHelp;
use Kadupul\Platform\Application\ReadModel\HelpTopic;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\LegacyHelpTopics;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class HelpPresentationTest extends TestCase
{
    public function testAnonymousCannotReadDocumentationSettingsOrTopics(): void
    {
        $access = self::createStub(AuthenticatedAccess::class);
        $access->method('authenticatedActor')->willReturn(null);
        $topics = $this->createMock(HelpTopics::class);
        $topics->expects(self::never())->method('all');
        self::assertNull((new ViewHelp($access, $topics))());
    }

    public function testTopicAdapterUsesTheExistingMapAndHonorsLocalOnlyWithoutNetworkRequests(): void
    {
        $root = sys_get_temp_dir() . '/help-topics-' . bin2hex(random_bytes(8));
        foreach (['include', 'lib', 'docs'] as $directory) {
            mkdir($root . '/' . $directory, 0700, true);
        }
        file_put_contents($root . '/include/config.php', '<?php $url_path = "/kadupul/";');
        symlink(dirname(__DIR__, 2) . '/lib/documentation.php', $root . '/lib/documentation.php');
        file_put_contents($root . '/docs/Devices.html', '<h1>Devices</h1>');
        try {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $database = self::createStub(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $configuration = new InstallationConfiguration($root);
            $topics = new LegacyHelpTopics($configuration->platform(), $database, $configuration);
            self::assertSame('https://kadupul.org/guides/worked-example/', $topics->all()[0]->url);
            self::assertCount(8, $topics->all());
            foreach ($topics->all() as $topic) {
                self::assertStringStartsWith('https://kadupul.org/', $topic->url);
            }
            $pdo->exec("INSERT INTO settings VALUES ('local_documentation', 'on')");
            self::assertSame('/kadupul/docs/Devices.html', $topics->all()[0]->url);
            self::assertNull($topics->all()[1]->url);
        } finally {
            foreach (['include/config.php', 'lib/documentation.php', 'docs/Devices.html'] as $file) {
                unlink($root . '/' . $file);
            }
            foreach (['include', 'lib', 'docs', ''] as $directory) {
                rmdir($root . '/' . $directory);
            }
        }
    }

    public function testHttpRouteSelectsFrenchAndEscapesTopicTextAndAttributes(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $access = self::createStub(AuthenticatedAccess::class);
            $access->method('authenticatedActor')->willReturn(new Actor(7, 'operator'));
            $container->set(AuthenticatedAccess::class, $access);
            $configuration = self::createStub(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['url_path' => '/kadupul/', 'forced_locale' => 'fr-FR']);
            $container->set(LegacyConfiguration::class, $configuration);
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $database = self::createStub(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $container->set(DatabaseConnection::class, $database);
            $topics = self::createStub(HelpTopics::class);
            $topics->method('all')->willReturn([new HelpTopic('Graphs', null), new HelpTopic('<img src=x>', 'https://kadupul.org/?x="<tag>')]);
            $container->set(HelpTopics::class, $topics);
            $response = $kernel->handle(Request::create('/help', 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            foreach (['<html lang="fr">', '<h1>Aide</h1>', 'Graphiques', 'Indisponible localement.', '&lt;img src=x&gt;', '&quot;&lt;tag&gt;', '/kadupul/index.php'] as $text) {
                self::assertStringContainsString($text, $response->getContent());
            }
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        } finally {
            $kernel->shutdown();
        }
    }
}
