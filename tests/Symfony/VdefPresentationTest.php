<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\GraphDefinition\Application\Port\VdefCatalog;
use Kadupul\GraphDefinition\Application\Port\VdefRealmAccess;
use Kadupul\GraphDefinition\Domain\VdefSummary;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\GraphDefinition\Infrastructure\Persistence\DoctrineVdefCatalog;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class VdefPresentationTest extends TestCase
{
    public function testExternalHandlerUsesConfiguredAssetBaseAcrossFrontControllers(): void
    {
        foreach ([
            ['/app.php', '/', 200], ['/public/index.php', '/', 200],
            ['/cacti/app.php', '/cacti/', 200], ['/cacti/public/index.php', '/cacti/', 200],
            ['/app.php', '//outside.invalid/', 502], ['/app.php', 'https://outside.invalid/', 502],
            ['/app.php', '/bad\\prefix/', 502], ['/app.php', '/bad?query/', 502],
            ['/app.php', '/bad#fragment/', 502], ['/app.php', "/bad\nprefix/", 502],
        ] as [$front, $prefix, $expectedStatus]) {
            $kernel = new Kernel('test', true);
            try {
                $kernel->boot();
                $container = $kernel->getContainer()->get('test.service_container');
                $configuration = $this->createMock(LegacyConfiguration::class);
                $configuration->method('values')->willReturn(['url_path' => $prefix]);
                $container->set(LegacyConfiguration::class, $configuration);
                $console = $this->createMock(ConsoleAccess::class);
                $console->method('consoleActor')->willReturn(new Actor(42, 'operator'));
                $container->set(ConsoleAccess::class, $console);
                $realm = $this->createMock(VdefRealmAccess::class);
                $realm->method('canManageDefinitions')->willReturn(true);
                $container->set(VdefRealmAccess::class, $realm);
                $catalog = $this->createMock(VdefCatalog::class);
                $catalog->method('find')->willReturn(['id' => 1, 'name' => 'Asset fixture', 'revision' => 'fixture', 'items' => []]);
                $catalog->method('preview')->willReturn('CURRENT_DATA_SOURCE');
                $container->set(VdefCatalog::class, $catalog);
                $request = Request::create($front . '/graph-definitions/vdefs/1/items/0', 'GET', [], [], [], [
                    'SCRIPT_NAME' => $front,
                    'SCRIPT_FILENAME' => dirname(__DIR__, 2) . (str_ends_with($front, '/public/index.php') ? '/public/index.php' : '/app.php'),
                    'PHP_SELF' => $front . '/graph-definitions/vdefs/1/items/0',
                ]);
                self::assertSame(dirname($front) === '/' ? '' : dirname($front), $request->getBasePath());
                $response = $kernel->handle($request);
                self::assertSame($expectedStatus, $response->getStatusCode(), $front . $response->getContent());
                if ($expectedStatus === 200) {
                    self::assertStringContainsString('src="' . rtrim($prefix, '/') . '/public/js/vdef-item.js" defer', $response->getContent(), $front);
                } else {
                    self::assertStringNotContainsString('<script', $response->getContent());
                }
            } finally {
                $kernel->shutdown();
            }
        }
    }

    #[DataProvider('malformedListFields')]
    public function testMalformedListArraysAreRefusedBeforeCatalogReads(string $field): void
    {
        $kernel = new Kernel('test', true);
        $queries = 0;
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $console = $this->createMock(ConsoleAccess::class);
            $console->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $container->set(ConsoleAccess::class, $console);
            $realm = $this->createMock(VdefRealmAccess::class);
            $realm->method('canManageDefinitions')->willReturn(true);
            $container->set(VdefRealmAccess::class, $realm);
            $catalog = $this->createMock(VdefCatalog::class);
            $catalog->method('list')->willReturnCallback(static function () use (&$queries): array {
                $queries++;
                return [];
            });
            $catalog->method('count')->willReturnCallback(static function () use (&$queries): int {
                $queries++;
                return 0;
            });
            $container->set(VdefCatalog::class, $catalog);
            $request = Request::create('/graph-definitions/vdefs', 'GET', [$field => ['malformed']]);
            $response = $kernel->handle($request);
            self::assertSame(400, $response->getStatusCode(), $field);
            self::assertStringContainsString('Invalid VDEF list options.', $response->getContent());
            self::assertSame(0, $queries, 'Malformed input must not reach the catalog.');
        } finally {
            $kernel->shutdown();
        }
    }

    public static function malformedListFields(): iterable
    {
        foreach (['filter', 'sort', 'direction', 'has_graphs'] as $field) {
            yield $field => [$field];
        }
    }

    public function testLegacyRedirectsUseExactRouteIdentityBounds(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $console = $this->createMock(ConsoleAccess::class);
            $console->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $container->set(ConsoleAccess::class, $console);
            $realm = $this->createMock(VdefRealmAccess::class);
            $realm->method('canManageDefinitions')->willReturn(true);
            $container->set(VdefRealmAccess::class, $realm);
            foreach (['100000000', '99999999999999999999999999', '01', '-1', "1\n", ['1']] as $invalid) {
                foreach ([['action' => 'edit', 'id' => $invalid], ['action' => 'item_edit', 'vdef_id' => $invalid, 'id' => '1'], ['action' => 'item_edit', 'vdef_id' => '1', 'id' => $invalid]] as $query) {
                    $response = $kernel->handle(Request::create('/graph-definitions/vdefs/legacy', 'GET', $query));
                    self::assertSame(302, $response->getStatusCode());
                    self::assertSame('/graph-definitions/vdefs', $response->headers->get('Location'));
                }
            }
            foreach ([
                [['action' => 'edit', 'id' => '0'], '/graph-definitions/vdefs/new'],
                [['action' => 'edit', 'id' => '99999999'], '/graph-definitions/vdefs/99999999/edit'],
                [['action' => 'item_edit', 'vdef_id' => '99999999', 'id' => '99999999'], '/graph-definitions/vdefs/99999999/items/99999999'],
                [['action' => 'item_edit', 'vdef_id' => '1', 'id' => '0'], '/graph-definitions/vdefs/1/items/0'],
            ] as [$query, $location]) {
                $response = $kernel->handle(Request::create('/graph-definitions/vdefs/legacy', 'GET', $query));
                self::assertSame(302, $response->getStatusCode());
                self::assertSame($location, $response->headers->get('Location'));
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testFrenchPagesTranslateControlsAndPreserveRpnAndStoredNames(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['forced_locale' => 'fr-FR']);
            $container->set(LegacyConfiguration::class, $configuration);
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $container->set(DatabaseConnection::class, $database);
            $console = $this->createMock(ConsoleAccess::class);
            $console->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $container->set(ConsoleAccess::class, $console);
            $realm = $this->createMock(VdefRealmAccess::class);
            $realm->method('canManageDefinitions')->willReturn(true);
            $container->set(VdefRealmAccess::class, $realm);
            $catalog = $this->createMock(VdefCatalog::class);
            $catalog->method('list')->willReturn([new VdefSummary(1, '<router-Ø>', 2, 3)]);
            $catalog->method('count')->willReturn(1);
            $catalog->method('find')->willReturn(['id' => 1, 'name' => '<router-Ø>', 'revision' => 'fixture', 'items' => [['id' => 99, 'type' => 99, 'value' => 'legacy', 'label' => 'legacy']]]);
            $catalog->method('selected')->willReturn([1 => ['id' => 1, 'name' => '<router-Ø>', 'revision' => 'fixture']]);
            $catalog->method('preview')->willReturn('CURRENT_DATA_SOURCE,MAXIMUM');
            $container->set(VdefCatalog::class, $catalog);
            $list = $kernel->handle(Request::create('/graph-definitions/vdefs', 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $list->getStatusCode());
            self::assertStringContainsString('<html lang="fr">', $list->getContent());
            self::assertStringContainsString('Définitions VDEF', $list->getContent());
            self::assertStringContainsString('Graphiques utilisant', $list->getContent());
            self::assertStringNotContainsString('Graphs using', $list->getContent());
            self::assertStringContainsString('&lt;router-Ø&gt;', $list->getContent());
            $item = $kernel->handle(Request::create('/graph-definitions/vdefs/1/items/0', 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $item->getStatusCode(), $item->getContent());
            self::assertStringContainsString('Type d’élément', $item->getContent());
            self::assertStringContainsString('Enregistrer l’élément', $item->getContent());
            self::assertStringContainsString('CURRENT_DATA_SOURCE,MAXIMUM', $item->getContent());
            self::assertStringContainsString('>MAXIMUM</option>', $item->getContent());
            $special = $kernel->handle(Request::create('/graph-definitions/vdefs/1/items/0?type=4', 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $special->getStatusCode());
            self::assertStringContainsString('Source de données de l’élément du graphique actuel', $special->getContent());
            self::assertStringContainsString('value="CURRENT_DATA_SOURCE"', $special->getContent());
            self::assertStringNotContainsString('Current Graph Item Data Source', $special->getContent());
            $delete = $kernel->handle(Request::create('/graph-definitions/vdefs/1/items/99/delete', 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $delete->getStatusCode(), $delete->getContent());
            self::assertStringContainsString('Élément VDEF', $delete->getContent());
            self::assertStringNotContainsString('VDEF item', $delete->getContent());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testLegacyEntryRejectsPostedActionsInsteadOfReplayingThem(): void
    {
        $previousServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        ob_start();
        include dirname(__DIR__, 2) . '/vdef.php';
        $body = ob_get_clean();
        $_SERVER = $previousServer;

        self::assertSame(409, http_response_code());
        self::assertStringContainsString('submitted through the Symfony forms', (string) $body);
        http_response_code(200);
    }

    public function testUnauthenticatedListRejectsMalformedQueryBeforeCatalogAccess(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $access = $this->createMock(ConsoleAccess::class);
            $access->expects(self::once())->method('consoleActor')->willReturn(null);
            $container->set(ConsoleAccess::class, $access);
            $catalog = $this->createMock(VdefCatalog::class);
            $catalog->expects(self::never())->method('list');
            $catalog->expects(self::never())->method('count');
            $container->set(VdefCatalog::class, $catalog);

            $response = $kernel->handle(Request::create('/graph-definitions/vdefs?page[]=invalid'));
            self::assertSame(401, $response->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testVdefRoutesEnforceRealmEscapeNamesAndUseStatelessCsrf(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['collector_id' => 1, 'url_path' => '/']);
            $container->set(LegacyConfiguration::class, $configuration);
            $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
            foreach ([
                'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)',
                "INSERT INTO settings VALUES ('auth_method', '1'), ('guest_user', 'guest')",
                'CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT, password_change TEXT)',
                "INSERT INTO user_auth VALUES (42, 'operator', 'on', '', '', '')",
                'CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)',
                'INSERT INTO user_auth_realm VALUES (42, 8), (42, 14)',
                'CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)',
                'CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)',
                'CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled TEXT)',
                'CREATE TABLE vdef (id INTEGER PRIMARY KEY AUTOINCREMENT, hash TEXT, name TEXT)',
                'CREATE TABLE vdef_items (id INTEGER PRIMARY KEY AUTOINCREMENT, hash TEXT, vdef_id INTEGER, sequence INTEGER, type INTEGER, value TEXT)',
                'CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, vdef_id INTEGER, local_graph_id INTEGER, graph_template_id INTEGER)',
                "INSERT INTO vdef VALUES (1, 'hash', '<VDEF>')",
                "INSERT INTO vdef_items VALUES (1, 'item', 1, 1, 4, 'CURRENT_DATA_SOURCE')",
                'INSERT INTO graph_templates_item VALUES (1, 1, 0, 1)',
            ] as $sql) {
                $database->executeStatement($sql);
            }
            $container->set(Connection::class, $database);
            $container->set('doctrine.dbal.web_connection', $database);
            $catalog = new DoctrineVdefCatalog($database);
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $container->set(ConsoleAccess::class, $access);

            $denied = $kernel->handle(Request::create('/graph-definitions/vdefs'));
            self::assertSame(200, $denied->getStatusCode());
            self::assertStringContainsString('&lt;VDEF&gt;', $denied->getContent());
            self::assertStringNotContainsString('<VDEF>', $denied->getContent());
            self::assertStringContainsString('Graphs using', $denied->getContent());
            self::assertMatchesRegularExpression('/name="ids\[\]" value="1"[^>]*>/', $denied->getContent());
            self::assertDoesNotMatchRegularExpression('/name="ids\[\]" value="1"[^>]*disabled/', $denied->getContent());

            $legacyEdit = $kernel->handle(Request::create('/graph-definitions/vdefs/legacy?action=edit&id=1'));
            self::assertSame(302, $legacyEdit->getStatusCode());
            self::assertSame('/graph-definitions/vdefs/1/edit', $legacyEdit->headers->get('Location'));
            $legacyItem = $kernel->handle(Request::create('/graph-definitions/vdefs/legacy?action=item_edit&vdef_id=1&type_select=6'));
            self::assertSame('/graph-definitions/vdefs/1/items/0?type=6', $legacyItem->headers->get('Location'));

            $database->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 14');
            self::assertSame(403, $kernel->handle(Request::create('/graph-definitions/vdefs'))->getStatusCode());
            $database->executeStatement("INSERT INTO user_auth_group VALUES (7, 'on')");
            $database->executeStatement('INSERT INTO user_auth_group_realm VALUES (7, 14)');
            $database->executeStatement('INSERT INTO user_auth_group_members VALUES (7, 42)');
            self::assertSame(200, $kernel->handle(Request::create('/graph-definitions/vdefs'))->getStatusCode());
            $database->executeStatement("UPDATE user_auth_group SET enabled = '' WHERE id = 7");
            self::assertSame(403, $kernel->handle(Request::create('/graph-definitions/vdefs'))->getStatusCode());
            $database->executeStatement('INSERT INTO user_auth_realm VALUES (42, 14)');

            $token = $container->get(CsrfTokenManagerInterface::class)->getToken('graph_vdef_edit')->getValue();
            $database->executeStatement("INSERT INTO vdef_items VALUES (901, 'nested-reference', 1, 2, 5, '1')");
            $referenceRevision = $catalog->find(1)['revision'];
            $reference = $kernel->handle(Request::create('/graph-definitions/vdefs/1/items/901?type=1'));
            self::assertSame(200, $reference->getStatusCode());
            self::assertStringContainsString('Its stored reference is preserved.', $reference->getContent());
            self::assertStringContainsString('<dd>1</dd>', $reference->getContent());
            self::assertStringNotContainsString('<form', $reference->getContent());
            $rewriteReference = Request::create('/graph-definitions/vdefs/1/items/901', 'POST', [
                'vdef_item' => ['id' => '901', 'vdef_id' => '1', 'revision' => $referenceRevision, 'type' => '1', 'value' => '1', '_token' => $token],
            ]);
            $rewriteReference->headers->set('Origin', 'http://localhost');
            self::assertSame(409, $kernel->handle($rewriteReference)->getStatusCode());
            self::assertSame(5, (int) $database->fetchOne('SELECT type FROM vdef_items WHERE id=901'));
            self::assertSame('1', $database->fetchOne('SELECT value FROM vdef_items WHERE id=901'));
            self::assertSame($referenceRevision, $catalog->find(1)['revision']);
            $database->executeStatement('DELETE FROM vdef_items WHERE id=901');
            $queryRevision = $catalog->find(1)['revision'];
            $malformedQuery = $kernel->handle(Request::create('/graph-definitions/vdefs/1/items/0?type[]=6'));
            self::assertSame(400, $malformedQuery->getStatusCode(), $malformedQuery->getContent());
            self::assertStringContainsString('Invalid VDEF item type.', $malformedQuery->getContent());
            self::assertSame($queryRevision, $catalog->find(1)['revision']);
            $itemPage = $kernel->handle(Request::create('/graph-definitions/vdefs/1/items/0'));
            self::assertSame(200, $itemPage->getStatusCode(), $itemPage->getContent());
            self::assertStringContainsString('src="/public/js/vdef-item.js" defer', $itemPage->getContent());
            self::assertStringNotContainsString('addEventListener', $itemPage->getContent());
            $post = Request::create('/graph-definitions/vdefs/new', 'POST', [
                'vdef_edit' => ['id' => '0', 'name' => 'New VDEF', '_token' => $token],
            ]);
            $post->headers->set('Origin', 'http://localhost');
            $response = $kernel->handle($post);
            self::assertSame(303, $response->getStatusCode(), $response->getContent());
            self::assertSame('New VDEF', $database->fetchOne('SELECT name FROM vdef WHERE id = 2'));

            $missingToken = Request::create('/graph-definitions/vdefs/new', 'POST', [
                'vdef_edit' => ['id' => '0', 'name' => 'Blocked'],
            ]);
            $missingToken->headers->set('Origin', 'http://localhost');
            self::assertSame(422, $kernel->handle($missingToken)->getStatusCode());
            self::assertFalse($database->fetchOne("SELECT id FROM vdef WHERE name = 'Blocked'"));

            $edit = $kernel->handle(Request::create('/graph-definitions/vdefs/1/edit'));
            self::assertSame(200, $edit->getStatusCode());
            self::assertStringContainsString('vdef=CURRENT_DATA_SOURCE', $edit->getContent());
            self::assertStringContainsString('&lt;VDEF&gt;', $edit->getContent());

            $itemPost = Request::create('/graph-definitions/vdefs/1/items/0', 'POST', [
                'vdef_item' => ['id' => '0', 'vdef_id' => '1', 'revision' => $catalog->find(1)['revision'], 'type' => '6', 'value' => 'custom item', '_token' => $token],
            ]);
            $itemPost->headers->set('Origin', 'http://localhost');
            self::assertSame(303, $kernel->handle($itemPost)->getStatusCode());
            $itemId = (int) $database->fetchOne("SELECT id FROM vdef_items WHERE vdef_id = 1 AND value = 'custom item'");
            self::assertGreaterThan(1, $itemId);

            $malformedType = Request::create('/graph-definitions/vdefs/1/items/0', 'POST', [
                'vdef_item' => ['id' => '0', 'vdef_id' => '1', 'revision' => $catalog->find(1)['revision'], 'type' => ['6'], 'value' => 'malformed', '_token' => $token],
            ]);
            $malformedType->headers->set('Origin', 'http://localhost');
            self::assertSame(400, $kernel->handle($malformedType)->getStatusCode());

            $reorder = Request::create('/graph-definitions/vdefs/1/items/reorder', 'POST', [
                'order' => ['items' => json_encode([$itemId, 1]), 'revision' => $catalog->find(1)['revision'], '_token' => $token],
            ]);
            $reorder->headers->set('Origin', 'http://localhost');
            $reorderResponse = $kernel->handle($reorder);
            self::assertSame(303, $reorderResponse->getStatusCode(), $reorderResponse->getContent());
            self::assertSame([$itemId, 1], array_map('intval', $database->fetchFirstColumn('SELECT id FROM vdef_items WHERE vdef_id = 1 ORDER BY sequence')));

            $remove = Request::create('/graph-definitions/vdefs/1/items/' . $itemId . '/delete', 'POST', [
                'confirm' => ['revision' => $catalog->find(1)['revision'], '_token' => $token],
            ]);
            $remove->headers->set('Origin', 'http://localhost');
            self::assertSame(303, $kernel->handle($remove)->getStatusCode());
            self::assertFalse($database->fetchOne('SELECT id FROM vdef_items WHERE id = ?', [$itemId]));

            $deleteUrl = '/graph-definitions/vdefs/actions/delete?ids[]=1';
            $revisions = json_encode(['1' => $catalog->find(1)['revision']]);
            $tamperedDelete = Request::create($deleteUrl, 'POST', [
                'vdef_action' => ['selection' => '[2]', 'revisions' => $revisions, 'title_format' => '', '_token' => $token],
            ]);
            $tamperedDelete->headers->set('Origin', 'http://localhost');
            self::assertSame(409, $kernel->handle($tamperedDelete)->getStatusCode());

            $blockedDelete = Request::create($deleteUrl, 'POST', [
                'vdef_action' => ['selection' => '[1]', 'revisions' => $revisions, 'title_format' => '', '_token' => $token],
            ]);
            $blockedDelete->headers->set('Origin', 'http://localhost');
            self::assertSame(409, $kernel->handle($blockedDelete)->getStatusCode());
            self::assertSame('<VDEF>', $database->fetchOne('SELECT name FROM vdef WHERE id = 1'));

            $duplicate = Request::create('/graph-definitions/vdefs/actions/duplicate?ids[]=1', 'POST', [
                'vdef_action' => ['selection' => '[1]', 'revisions' => $revisions, 'title_format' => '<vdef_title> copy', '_token' => $token],
            ]);
            $duplicate->headers->set('Origin', 'http://localhost');
            self::assertSame(303, $kernel->handle($duplicate)->getStatusCode());
            self::assertSame(1, (int) $database->fetchOne("SELECT COUNT(*) FROM vdef WHERE name = '<VDEF> copy'"));
        } finally {
            $kernel->shutdown();
        }
    }
}
