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

    public function testNewOutputFieldsDefaultToUpdatingRra(): void
    {
        [$kernel, $container] = $this->authorizedKernel();
        try {
            $gateway = $this->createMock(DataInputGateway::class);
            $gateway->method('execute')->willReturn(['method' => ['id' => 3, 'name' => 'Fixture', 'type_id' => 1, 'input_string' => '<x>'], 'fields' => [], 'revision' => str_repeat('a', 64)]);
            $container->set(DataInputGateway::class, $gateway);
            $response = $kernel->handle(Request::create('/data-inputs/3/fields/0?direction=out'));
            self::assertSame(200, $response->getStatusCode());
            self::assertMatchesRegularExpression('/<input[^>]+name="data_input_field\[update_rra\]"[^>]+checked="checked"/', $response->getContent());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testBulkConfirmationUsesOneSelectionWorkerForOneHundredInputs(): void
    {
        [$kernel, $container] = $this->authorizedKernel();
        try {
            $ids = range(1, 100);
            $gateway = $this->createMock(DataInputGateway::class);
            $gateway->expects(self::once())->method('execute')->with(9, 'selection', 0, ['ids' => $ids])->willReturn(['selection' => array_fill_keys($ids, str_repeat('a', 64)), 'names' => array_map(static fn(int $id): string => 'Input ' . $id, $ids)]);
            $container->set(DataInputGateway::class, $gateway);
            $response = $kernel->handle(Request::create('/data-inputs/actions/duplicate?' . http_build_query(['ids' => $ids])));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Input 100', $response->getContent());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testFieldDeletionConfirmationNamesOnlyTheSelectedFieldAndEscapesItsValues(): void
    {
        [$kernel, $container] = $this->authorizedKernel();
        try {
            $gateway = $this->createMock(DataInputGateway::class);
            $gateway->expects(self::once())->method('execute')->willReturn(['method' => ['id' => 3, 'name' => 'Fixture'], 'fields' => [['id' => 7, 'name' => '<script>Friendly</script>', 'data_name' => '<selected>'], ['id' => 8, 'name' => 'Other field', 'data_name' => 'other']], 'revision' => str_repeat('a', 64)]);
            $container->set(DataInputGateway::class, $gateway);
            $response = $kernel->handle(Request::create('/data-inputs/3/field_delete?field=7'));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('&lt;script&gt;Friendly&lt;/script&gt;', $response->getContent());
            self::assertStringContainsString('&lt;selected&gt;', $response->getContent());
            self::assertStringNotContainsString('Other field', $response->getContent());
        } finally {
            $kernel->shutdown();
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('handoffResults')]
    public function testHandoffResultsReportTheirOperationWithoutClaimingLocalPersistence(string $operation, bool $partial, string $message): void
    {
        [$kernel, $container] = $this->authorizedKernel();
        try {
            $state = ['method' => ['id' => 3, 'name' => 'Fixture', 'type_id' => 1, 'input_string' => '/usr/bin/printf 1'], 'fields' => [], 'counts' => ['templates' => 0, 'data_sources' => 0], 'revision' => str_repeat('a', 64), 'whitelist' => 'requires_update'];
            $gateway = $this->createMock(DataInputGateway::class);
            $gateway->expects(self::exactly(3))->method('execute')->willReturnCallback(static function (int $actor, string $action, int $id, array $payload) use ($state, $operation, $partial): array {
                self::assertSame(9, $actor);
                self::assertSame(3, $id);
                if ($action === 'find') {
                    return $state;
                }
                self::assertSame($operation, $action);
                self::assertSame($state['revision'], $payload['revision']);
                return ['id' => 3, 'partial' => $partial];
            });
            $container->set(DataInputGateway::class, $gateway);
            $response = $kernel->handle(Request::create('/data-inputs/3/' . $operation, 'POST', ['data_input_action' => ['revision' => $state['revision'], '_token' => 'csrf-token']], server: ['HTTP_ORIGIN' => 'http://localhost']));
            self::assertSame(303, $response->getStatusCode(), $response->getContent());
            $location = $response->headers->get('Location');
            parse_str(parse_url($location, PHP_URL_QUERY), $query);
            self::assertSame($operation, $query['operation'] ?? null);
            self::assertSame($partial ? 'partial' : '1', $query['saved']);
            $page = $kernel->handle(Request::create($location));
            self::assertSame(200, $page->getStatusCode(), $page->getContent());
            self::assertStringContainsString($message, $page->getContent());
            self::assertStringNotContainsString('Data input saved.', $page->getContent());
            self::assertStringNotContainsString('Local changes were saved.', $page->getContent());
            self::assertSame($partial, str_contains($page->getContent(), 'role="alert"'));
        } finally {
            $kernel->shutdown();
        }
    }

    public static function handoffResults(): iterable
    {
        yield ['propagate', false, 'Collector propagation completed.'];
        yield ['propagate', true, 'Collector propagation is incomplete.'];
        yield ['whitelist', false, 'Whitelist verification and collector propagation completed.'];
        yield ['whitelist', true, 'Whitelist verification or collector propagation is incomplete.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('frenchHandoffResults')]
    public function testFrenchHandoffResultsUseTranslatedOperationMessages(string $operation, bool $partial, string $message): void
    {
        [$kernel, $container] = $this->authorizedKernel();
        try {
            $locale = $this->createMock(LocalePreference::class);
            $locale->method('preferredLocale')->willReturn('fr');
            $container->set(LocalePreference::class, $locale);
            $config = $this->createMock(LegacyConfiguration::class);
            $config->method('values')->willReturn(['forced_locale' => 'fr']);
            $container->set(LegacyConfiguration::class, $config);
            $gateway = $this->createMock(DataInputGateway::class);
            $gateway->expects(self::once())->method('execute')->with(9, 'find', 3, [])->willReturn(['method' => ['id' => 3, 'name' => 'Fixture', 'type_id' => 1, 'input_string' => '/usr/bin/printf 1'], 'fields' => [], 'counts' => ['templates' => 0, 'data_sources' => 0], 'revision' => str_repeat('a', 64), 'whitelist' => 'requires_update']);
            $container->set(DataInputGateway::class, $gateway);
            $response = $kernel->handle(Request::create('/data-inputs/3/edit?' . http_build_query(['operation' => $operation, 'saved' => $partial ? 'partial' : '1']), 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('<html lang="fr">', $response->getContent());
            self::assertStringContainsString($message, $response->getContent());
            self::assertStringNotContainsString('Collector propagation', $response->getContent());
            self::assertStringNotContainsString('Whitelist verification', $response->getContent());
            self::assertSame($partial, str_contains($response->getContent(), 'role="alert"'));
        } finally {
            $kernel->shutdown();
        }
    }

    public static function frenchHandoffResults(): iterable
    {
        yield ['propagate', false, 'Propagation aux collecteurs terminée.'];
        yield ['propagate', true, 'La propagation aux collecteurs est incomplète.'];
        yield ['whitelist', false, 'Vérification de la liste blanche et propagation aux collecteurs terminées.'];
        yield ['whitelist', true, 'La vérification de la liste blanche ou la propagation aux collecteurs est incomplète.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bulkResults')]
    public function testBulkResultsPreserveOperationAndLocalizedCompletionMessage(string $operation, bool $partial, string $language, string $message): void
    {
        [$kernel, $container] = $this->authorizedKernel();
        try {
            $locale = $this->createMock(LocalePreference::class);
            $locale->method('preferredLocale')->willReturn($language);
            $container->set(LocalePreference::class, $locale);
            $config = $this->createMock(LegacyConfiguration::class);
            $config->method('values')->willReturn(['forced_locale' => $language]);
            $container->set(LegacyConfiguration::class, $config);
            $selection = [3 => str_repeat('a', 64)];
            $gateway = $this->createMock(DataInputGateway::class);
            $gateway->expects(self::exactly(3))->method('execute')->willReturnCallback(static function (int $actor, string $action, int $id, array $payload) use ($selection, $operation, $partial): array {
                self::assertSame(9, $actor);
                self::assertSame(0, $id);
                if ($action === 'selection') {
                    self::assertSame([3], $payload['ids']);
                    return ['selection' => $selection, 'names' => ['Fixture']];
                }
                if ($action === 'list') {
                    return ['items' => [], 'total' => 0, 'filter' => '', 'rows' => 10, 'default_rows' => false, 'page' => 1, 'sort' => 'name', 'direction' => 'ASC'];
                }
                self::assertSame('bulk_' . $operation, $action);
                self::assertSame($selection, $payload['selection']);
                return ['ids' => $operation === 'duplicate' ? [37] : [3], 'partial' => $partial];
            });
            $container->set(DataInputGateway::class, $gateway);
            $response = $kernel->handle(Request::create('/data-inputs/actions/' . $operation . '?ids[]=3', 'POST', ['data_input_action' => ['revision' => json_encode($selection, JSON_THROW_ON_ERROR), '_token' => 'csrf-token'] + ($operation === 'duplicate' ? ['title' => '<input_title> (1)'] : [])], ['Cacti' => 'fixture'], server: ['HTTP_ORIGIN' => 'http://localhost']));
            self::assertSame(303, $response->getStatusCode(), $response->getContent());
            $location = $response->headers->get('Location');
            parse_str(parse_url($location, PHP_URL_QUERY), $query);
            self::assertSame('bulk_' . $operation, $query['operation'] ?? null);
            self::assertSame($partial ? 'partial' : '1', $query['saved']);
            $page = $kernel->handle(Request::create($location, 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $page->getStatusCode());
            self::assertStringContainsString('<html lang="' . $language . '">', $page->getContent());
            self::assertStringContainsString($message, $page->getContent());
            self::assertStringNotContainsString('Data input saved.', $page->getContent());
            self::assertStringNotContainsString('Entrée de données enregistrée.', $page->getContent());
            self::assertSame($partial, str_contains($page->getContent(), 'role="alert"'));
            if ($partial) {
                self::assertStringContainsString('/data-inputs/37/propagate', $page->getContent());
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public static function bulkResults(): iterable
    {
        yield ['delete', false, 'en', 'Selected data input methods deleted.'];
        yield ['duplicate', false, 'en', 'Selected data input methods duplicated.'];
        yield ['duplicate', true, 'en', 'Selected data input methods duplicated. Collector or whitelist propagation is incomplete.'];
        yield ['delete', false, 'fr', 'Méthodes d’entrée de données sélectionnées supprimées.'];
        yield ['duplicate', false, 'fr', 'Méthodes d’entrée de données sélectionnées dupliquées.'];
        yield ['duplicate', true, 'fr', 'Méthodes d’entrée de données sélectionnées dupliquées. La propagation aux collecteurs ou à la liste blanche est incomplète.'];
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
