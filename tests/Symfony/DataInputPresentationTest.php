<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use Kadupul\DataInput\Application\Port\DataInputAccess;
use Kadupul\DataInput\Application\Port\DataInputGateway;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DataInputPresentationTest extends TestCase
{
    public function testFrenchMethodEditorEscapesRawCommandsAndUsesSessionLocale(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $actor = new Actor(9, 'operator');
            $console = $this->createMock(ConsoleAccess::class);
            $console->method('consoleActor')->willReturn($actor);
            $container->set(ConsoleAccess::class, $console);
            $access = $this->createMock(DataInputAccess::class);
            $access->method('authorize')->willReturn($actor);
            $container->set(DataInputAccess::class, $access);
            $gateway = $this->createMock(DataInputGateway::class);
            $gateway->expects(self::once())->method('execute')->with(9, 'find', 3, [])->willReturn(['method' => ['id' => 3, 'name' => '<script>method</script>', 'type_id' => 1, 'input_string' => '  perl <path_cacti>/script.pl <argument>  '], 'fields' => [], 'counts' => ['templates' => 0, 'data_sources' => 0], 'revision' => str_repeat('a', 64), 'whitelist' => 'disabled']);
            $container->set(DataInputGateway::class, $gateway);
            $config = $this->createMock(LegacyConfiguration::class);
            $config->method('values')->willReturn(['forced_locale' => 'fr']);
            $container->set(LegacyConfiguration::class, $config);
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings(name TEXT,value TEXT)');
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $container->set(DatabaseConnection::class, $database);
            $locale = $this->createMock(LocalePreference::class);
            $locale->method('preferredLocale')->willReturn('fr');
            $container->set(LocalePreference::class, $locale);
            $response = $kernel->handle(Request::create('/data-inputs/3/edit?language=en', 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('<html lang="fr">', $response->getContent());
            self::assertStringContainsString('Enregistrer', $response->getContent());
            self::assertStringContainsString('&lt;script&gt;method&lt;/script&gt;', $response->getContent());
            self::assertStringContainsString('  perl &lt;path_cacti&gt;/script.pl &lt;argument&gt;  ', $response->getContent());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testPartialBulkStatusIncludesRetryLinksOutsideTheVisiblePage(): void
    {
        [$kernel, $container] = $this->authorizedKernel();
        try {
            $gateway = $this->createMock(DataInputGateway::class);
            $gateway->expects(self::once())->method('execute')->with(9, 'list', 0, self::anything())->willReturn(['items' => [], 'total' => 0, 'filter' => '', 'rows' => 10, 'default_rows' => false, 'page' => 1, 'sort' => 'name', 'direction' => 'ASC']);
            $container->set(DataInputGateway::class, $gateway);
            $response = $kernel->handle(Request::create('/data-inputs?saved=partial&retry_ids=37,38'));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('role="alert"', $response->getContent());
            self::assertStringContainsString('propagation is incomplete', $response->getContent());
            foreach ([37, 38] as $id) {
                self::assertStringContainsString('/data-inputs/' . $id . '/propagate', $response->getContent());
                self::assertStringContainsString('Retry propagation for input ' . $id, $response->getContent());
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testWhitelistConfirmationRejectsMissingAndForgedTokensWithoutMutation(): void
    {
        [$kernel, $container] = $this->authorizedKernel();
        try {
            $gateway = $this->createMock(DataInputGateway::class);
            $gateway->expects(self::exactly(3))->method('execute')->with(9, 'find', 3, [])->willReturn(['method' => ['id' => 3, 'name' => 'Fixture', 'type_id' => 1, 'input_string' => '/usr/bin/printf 1'], 'fields' => [], 'counts' => ['templates' => 0, 'data_sources' => 0], 'revision' => str_repeat('a', 64), 'whitelist' => 'requires_update']);
            $container->set(DataInputGateway::class, $gateway);
            foreach ([null, 'forged', ['invalid']] as $token) {
                $payload = ['revision' => str_repeat('a', 64)];
                if ($token !== null) {
                    $payload['_token'] = $token;
                }
                $response = $kernel->handle(Request::create('/data-inputs/3/whitelist?_token=query-only', 'POST', ['data_input_action' => $payload]));
                self::assertSame(422, $response->getStatusCode());
            }
        } finally {
            $kernel->shutdown();
        }
    }

    private function authorizedKernel(): array
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        $actor = new Actor(9, 'operator');
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn($actor);
        $container->set(ConsoleAccess::class, $console);
        $access = $this->createMock(DataInputAccess::class);
        $access->method('authorize')->willReturn($actor);
        $container->set(DataInputAccess::class, $access);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings(name TEXT,value TEXT)');
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $container->set(DatabaseConnection::class, $database);
        return [$kernel, $container];
    }
}
