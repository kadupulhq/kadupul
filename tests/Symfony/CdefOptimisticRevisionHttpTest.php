<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\GraphDefinition\Application\Port\CdefRealmAccess;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CdefOptimisticRevisionHttpTest extends TestCase
{
    /** @dataProvider mutations */
    public function testStaleDisplayedStateReturnsConflictWithoutAnyWrite(string $mutation): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
            foreach ([
                'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)',
                "INSERT INTO settings VALUES ('auth_method', '1'), ('guest_user', 'guest')",
                'CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT)',
                "INSERT INTO user_auth VALUES (42, 'operator', 'on', '', '')",
                'CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)',
                'INSERT INTO user_auth_realm VALUES (42, 8), (42, 14)',
                'CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)',
                'CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)',
                'CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled TEXT)',
                'CREATE TABLE cdef (id INTEGER PRIMARY KEY, hash TEXT, system INTEGER, name TEXT)',
                'CREATE TABLE cdef_items (id INTEGER PRIMARY KEY, hash TEXT, cdef_id INTEGER, sequence INTEGER, type INTEGER, value TEXT)',
                'CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, cdef_id INTEGER, local_graph_id INTEGER, graph_template_id INTEGER)',
                "INSERT INTO cdef VALUES (2, 'parent-hash', 0, 'Displayed name')",
                "INSERT INTO cdef_items VALUES (21, 'child-hash', 2, 1, 6, 'Displayed value')",
            ] as $sql) {
                $database->executeStatement($sql);
            }
            $container->set(Connection::class, $database);
            $container->set('doctrine.dbal.web_connection', $database);
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['collector_id' => 1]);
            $container->set(LegacyConfiguration::class, $configuration);
            $console = $this->createMock(ConsoleAccess::class);
            $console->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $container->set(ConsoleAccess::class, $console);
            $realm = $this->createMock(CdefRealmAccess::class);
            $realm->method('canManageDefinitions')->willReturn(true);
            $container->set(CdefRealmAccess::class, $realm);
            $base = '/graph-definitions/cdefs/2';
            [$url, $group, $data] = match ($mutation) {
                'parent' => [$base . '/edit', 'cdef_edit', ['id' => '2', 'name' => 'Stale rename']],
                'item-create' => [$base . '/items/0?type=6', 'cdef_item', ['id' => '0', 'cdef_id' => '2', 'type' => '6', 'value' => 'Stale create']],
                'item-edit' => [$base . '/items/21', 'cdef_item', ['id' => '21', 'cdef_id' => '2', 'type' => '6', 'value' => 'Stale edit']],
                'item-delete' => [$base . '/items/21/delete', 'confirm', []],
                'reorder' => [$base . '/edit', 'order', ['items' => '[21]']],
                'duplicate', 'delete' => ['/graph-definitions/cdefs/actions/' . $mutation . '?ids[]=2', 'cdef_action', ['selection' => '[2]', 'title_format' => '<cdef_title> copy']],
            };
            $page = $kernel->handle(Request::create($url));
            self::assertSame(200, $page->getStatusCode(), $page->getContent());
            $dom = new \DOMDocument();
            @$dom->loadHTML($page->getContent());
            $xpath = new \DOMXPath($dom);
            $revisionField = $group === 'cdef_action' ? 'revisions' : 'revision';
            $input = $xpath->query('//input[@name="' . $group . '[' . $revisionField . ']"]')->item(0);
            if ($input instanceof \DOMElement) {
                $data[$revisionField] = $input->getAttribute('value');
            }
            $tokenId = $group === 'cdef_action' ? 'graph_cdef_action' : 'graph_cdef_edit';
            $data['_token'] = $container->get(CsrfTokenManagerInterface::class)->getToken($tokenId)->getValue();
            $database->executeStatement("UPDATE cdef_items SET value = 'First writer value' WHERE id = 21");
            $before = $database->fetchAllAssociative('SELECT * FROM cdef ORDER BY id');
            $items = $database->fetchAllAssociative('SELECT * FROM cdef_items ORDER BY id');
            $post = Request::create($mutation === 'reorder' ? $base . '/items/reorder' : $url, 'POST', [$group => $data]);
            $post->headers->set('Origin', 'http://localhost');
            $response = $kernel->handle($post);
            self::assertSame(409, $response->getStatusCode(), $response->getContent());
            self::assertStringContainsString('The CDEF changed. Reload the form.', $response->getContent());
            self::assertSame($before, $database->fetchAllAssociative('SELECT * FROM cdef ORDER BY id'));
            self::assertSame($items, $database->fetchAllAssociative('SELECT * FROM cdef_items ORDER BY id'));
        } finally {
            $kernel->shutdown();
        }
    }

    public static function mutations(): array
    {
        return array_map(static fn(string $mutation): array => [$mutation], ['parent', 'item-create', 'item-edit', 'item-delete', 'reorder', 'duplicate', 'delete']);
    }
}
