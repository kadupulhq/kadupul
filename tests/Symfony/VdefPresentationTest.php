<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use Kadupul\GraphDefinition\Infrastructure\Persistence\DoctrineVdefCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class VdefPresentationTest extends TestCase
{
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

    public function testVdefRoutesEnforceRealmEscapeNamesAndUseStatelessCsrf(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
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
