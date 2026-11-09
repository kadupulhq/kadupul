<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Kadupul\Graphing\Application\Port\GraphTemplateInputAccess;
use Kadupul\Graphing\Application\Port\GraphTemplateInputEditor;
use Kadupul\Graphing\Application\Query\GraphTemplateInputAccessDenied;
use Kadupul\Graphing\Domain\GraphTemplateInputColumn;
use Kadupul\Graphing\Infrastructure\Persistence\DbalGraphTemplateInputAccess;
use Kadupul\Graphing\Infrastructure\Persistence\DbalGraphTemplateInputCatalog;
use Kadupul\Graphing\Infrastructure\Persistence\DbalGraphTemplateInputEditor;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class GraphTemplateInputEditorTest extends TestCase
{
    private Connection $database;
    private DbalGraphTemplateInputEditor $editor;
    private DbalGraphTemplateInputCatalog $catalog;
    private bool $revoked = false;
    /** @var list<AuditEvent> */
    private array $audit = [];
    /** @var list<string> */
    private array $logged = [];
    /** @var list<string> */
    private array $statements = [];

    protected function setUp(): void
    {
        $statements = &$this->statements;
        $logger = new class ($statements) extends AbstractLogger {
            /** @param list<string> $statements */
            public function __construct(private array &$statements) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if (isset($context['sql']) && is_string($context['sql'])) {
                    $this->statements[] = $context['sql'];
                }
            }
        };
        $configuration = new \Doctrine\DBAL\Configuration();
        $configuration->setMiddlewares([new Middleware($logger)]);
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        foreach ([
            "CREATE TABLE graph_templates (id INTEGER PRIMARY KEY, hash TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '')",
            "CREATE TABLE graph_template_input (id INTEGER PRIMARY KEY AUTOINCREMENT, hash TEXT NOT NULL DEFAULT '', graph_template_id INT NOT NULL DEFAULT 0, name TEXT NOT NULL DEFAULT '', description TEXT, column_name TEXT NOT NULL DEFAULT '')",
            'CREATE TABLE graph_template_input_defs (graph_template_input_id INT NOT NULL, graph_template_item_id INT NOT NULL, PRIMARY KEY (graph_template_input_id, graph_template_item_id))',
            "CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, local_graph_template_item_id INT NOT NULL DEFAULT 0, local_graph_id INT NOT NULL DEFAULT 0, graph_template_id INT NOT NULL DEFAULT 0,
                task_item_id INT NOT NULL DEFAULT 0, color_id INT NOT NULL DEFAULT 0, graph_type_id INT NOT NULL DEFAULT 0, consolidation_function_id INT NOT NULL DEFAULT 0,
                text_format TEXT DEFAULT '', sequence INT NOT NULL DEFAULT 0)",
            "INSERT INTO graph_templates VALUES (1, 'aaaa', 'Traffic <b>'), (2, 'bbbb', 'Other')",
            // Template 1 owns items 11 to 13; template 2 owns item 21.
            "INSERT INTO graph_templates_item (id, graph_template_id, graph_type_id, consolidation_function_id, sequence, text_format) VALUES
                (11, 1, 4, 1, 1, 'In'), (12, 1, 9, 4, 2, 'Cur'), (13, 1, 7, 3, 3, 'Max'), (21, 2, 4, 1, 1, 'Foreign')",
            // Graphs 100 and 101 were built from template 1.
            "INSERT INTO graph_templates_item (id, local_graph_template_item_id, local_graph_id, graph_template_id, task_item_id, text_format) VALUES
                (1011, 11, 100, 1, 7, 'Inbound'), (1012, 12, 100, 1, 0, 'Old 12'), (1013, 13, 100, 1, 0, 'Old 13'),
                (1111, 11, 101, 1, 0, 'Second'), (1112, 12, 101, 1, 5, 'Old 12b'), (1113, 13, 101, 1, 0, 'Old 13b')",
            "INSERT INTO graph_template_input VALUES (5, '0123456789abcdef0123456789abcdef', 1, 'Legend', 'Text', 'text_format'), (6, 'cccc', 2, 'Foreign', '', 'text_format')",
            'INSERT INTO graph_template_input_defs VALUES (5, 11), (6, 21)',
        ] as $statement) {
            $this->database->executeStatement($statement);
        }
        $access = $this->createMock(GraphTemplateInputAccess::class);
        $access->method('assertCurrent')->willReturnCallback(function (): void {
            self::assertTrue($this->database->isTransactionActive());
            if ($this->revoked) {
                throw new GraphTemplateInputAccessDenied(false);
            }
        });
        $audit = $this->createMock(AuditTrail::class);
        $audit->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->audit[] = $event;
        });
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $logged = &$this->logged;
        $errors = new class ($logged) extends AbstractLogger {
            /** @param list<string> $logged */
            public function __construct(private array &$logged) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = $level . ': ' . $message;
            }
        };
        $this->editor = new DbalGraphTemplateInputEditor($this->database, $access, $audit, $configuration, $errors);
        $this->catalog = new DbalGraphTemplateInputCatalog($this->database);
    }

    public function testTheColumnChoicesAreTheLegacyAllowlist(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/graph_template_input.php';
        foreach (GraphTemplateInputColumn::cases() as $column) {
            self::assertSame($column->value, \graph_template_input_column($column->value));
        }
        foreach (['local_graph_id', 'id', 'hash', 'graph_template_id', 'local_graph_template_item_id', 'data_template_id'] as $refused) {
            self::assertNull(\graph_template_input_column($refused), $refused);
        }
        self::assertCount(17, GraphTemplateInputColumn::cases());
    }

    public function testTheCatalogReadsOnlyTheTemplatesOwnItemsAndInputs(): void
    {
        self::assertSame('Traffic <b>', $this->catalog->templateName(1));
        self::assertNull($this->catalog->templateName(9));
        self::assertNull($this->catalog->find(1, 6), 'an input of another template is not found');
        $input = $this->catalog->find(1, 5);
        self::assertSame([5, 1, 'Legend', 'Text', 'text_format', [11]], [$input->id, $input->templateId, $input->name, $input->description, $input->column, $input->itemIds]);
        $items = $this->catalog->items(1, 5);
        self::assertSame(
            [[11, 1, true, 'LINE1', 'AVERAGE', true], [12, 2, false, 'GPRINT', 'LAST', false], [13, 3, false, 'AREA', 'MAX', true]],
            array_map(static fn($item): array => [$item->id, $item->position, $item->selected, $item->typeName(), $item->consolidationName(), $item->emphasised()], $items)
        );
        self::assertSame([false, false, false], array_map(static fn($item): bool => $item->selected, $this->catalog->items(1, null)));
        $this->statements = [];
        $this->catalog->items(1, 5);
        self::assertCount(1, $this->statements, 'the item list is one query');
    }

    public function testCreationStoresTheInputWithAFreshHashAndItsItems(): void
    {
        $id = $this->editor->save(7, 1, null, '<i>Name</i>', '', 'color_id', [13, 11, 13], null);
        $row = $this->database->fetchAssociative('SELECT * FROM graph_template_input WHERE id = ?', [$id]);
        self::assertSame(['<i>Name</i>', '', 'color_id', '1'], [$row['name'], $row['description'], $row['column_name'], (string) $row['graph_template_id']]);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $row['hash']);
        self::assertSame([11, 13], $this->defs($id));
        self::assertSame(['Old 12', 'Old 13'], $this->texts(100, [12, 13]), 'a new input pushes nothing');
        self::assertSame(['graphing.graph_template_input.create', AuditEvent::ALLOWED, AuditEvent::SUCCEEDED, '1'], [$this->audit[0]->action, $this->audit[0]->decision, $this->audit[0]->outcome, $this->audit[0]->targetId]);
    }

    public function testNewMembersTakeTheInputsCurrentValueOnEveryGraph(): void
    {
        $input = $this->catalog->find(1, 5);
        self::assertSame(5, $this->editor->save(7, 1, 5, 'Renamed', 'Desc', 'text_format', [11, 12], $input->revision));
        self::assertSame(['Inbound', 'Inbound', 'Old 13'], $this->texts(100, [11, 12, 13]));
        self::assertSame(['Second', 'Second', 'Old 13b'], $this->texts(101, [11, 12, 13]));
        self::assertSame([11, 12], $this->defs(5));
        self::assertSame('0123456789abcdef0123456789abcdef', $this->database->fetchOne('SELECT hash FROM graph_template_input WHERE id = 5'));
        // Dropping a member pushes nothing and leaves its graph values alone.
        $this->editor->save(7, 1, 5, 'Renamed', 'Desc', 'text_format', [12], $this->catalog->find(1, 5)->revision);
        self::assertSame([12], $this->defs(5));
        self::assertSame(['Inbound', 'Inbound'], $this->texts(100, [11, 12]));
    }

    public function testAnEmptyDataSourceIsNeverPushedAndAMalformedHashIsReplaced(): void
    {
        $this->database->executeStatement("UPDATE graph_template_input SET hash = 'not-a-hash', column_name = 'task_item_id' WHERE id = 5");
        $this->editor->save(7, 1, 5, 'Source', '', 'task_item_id', [11, 12], $this->catalog->find(1, 5)->revision);
        // Graph 100 holds data source 7 on item 11; graph 101 holds none, so its item 12 keeps 5.
        self::assertSame([7, 5], array_map(intval(...), $this->database->fetchFirstColumn('SELECT task_item_id FROM graph_templates_item WHERE local_graph_template_item_id = 12 ORDER BY local_graph_id')));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', (string) $this->database->fetchOne('SELECT hash FROM graph_template_input WHERE id = 5'));
    }

    public function testAMalformedStoredValueStopsThePushOutButNotTheSave(): void
    {
        $this->database->executeStatement("UPDATE graph_templates_item SET text_format = ? WHERE id = 1111", [str_repeat('x', 300)]);
        $this->editor->save(7, 1, 5, 'Legend', 'Text', 'text_format', [11, 12], $this->catalog->find(1, 5)->revision);
        self::assertSame([11, 12], $this->defs(5));
        self::assertSame(['Old 12'], $this->texts(100, [12]));
        self::assertSame(['error: Graph item input push-out refused an invalid stored value.'], $this->logged);
    }

    /** @return iterable<string, array{list<int>, ?int, string}> */
    public static function refusals(): iterable
    {
        yield 'item of another template' => [[11, 21], 5, GraphTemplateInputEditor::RELATIONSHIP];
        yield 'local graph item' => [[1011], 5, GraphTemplateInputEditor::RELATIONSHIP];
        yield 'missing item' => [[99], 5, GraphTemplateInputEditor::RELATIONSHIP];
        yield 'input of another template' => [[11], 6, GraphTemplateInputEditor::NOT_FOUND];
        yield 'new input with a foreign item' => [[21], null, GraphTemplateInputEditor::RELATIONSHIP];
    }

    /** @param list<int> $items */
    #[DataProvider('refusals')]
    public function testCrossTemplateReferencesAreRefusedAndRolledBack(array $items, ?int $inputId, string $message): void
    {
        $before = $this->rows();
        $revision = $inputId === null ? null : ($this->catalog->find(1, $inputId)?->revision ?? 'none');
        try {
            $this->editor->save(7, 1, $inputId, 'Forged', '', 'text_format', $items, $revision);
            self::fail('A cross-template reference was saved.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame($message, $error->getMessage());
        }
        self::assertSame($before, $this->rows());
        self::assertSame([AuditEvent::ALLOWED, AuditEvent::FAILED], [$this->audit[0]->decision, $this->audit[0]->outcome]);
    }

    public function testAFailedItemRewriteLeavesTheInputItsItemsAndGraphValuesAsTheyWere(): void
    {
        $this->database->executeStatement("CREATE TRIGGER refuse_13 BEFORE INSERT ON graph_template_input_defs WHEN NEW.graph_template_item_id = 13 BEGIN SELECT RAISE(ABORT, 'refused'); END");
        $before = $this->rows();
        try {
            $this->editor->save(7, 1, 5, 'Changed', 'Changed', 'text_format', [11, 12, 13], $this->catalog->find(1, 5)->revision);
            self::fail('The refused insert was ignored.');
        } catch (\Doctrine\DBAL\Exception) {
        }
        self::assertSame($before, $this->rows());
        self::assertFalse($this->database->isTransactionActive());
    }

    public function testStaleFormsMissingTemplatesAndInvalidValuesWriteNothing(): void
    {
        $before = $this->rows();
        $revision = $this->catalog->find(1, 5)->revision;
        foreach ([
            [1, 5, 'Legend', 'text_format', [11], 'stale', GraphTemplateInputEditor::STALE],
            [1, 5, 'Legend', 'text_format', [11], null, GraphTemplateInputEditor::STALE],
            [9, null, 'Legend', 'text_format', [], null, GraphTemplateInputEditor::TEMPLATE_NOT_FOUND],
            [1, 5, '', 'text_format', [11], $revision, 'Enter a name of 1 to 255 characters.'],
            [1, 5, str_repeat('é', 256), 'text_format', [11], $revision, 'Enter a name of 1 to 255 characters.'],
            [1, 5, "\xff", 'text_format', [11], $revision, 'Enter a name of 1 to 255 characters.'],
            [1, 5, 'Legend', 'local_graph_id', [11], $revision, 'Choose a field type.'],
            [1, 5, 'Legend', 'text_format, sleep(1)', [11], $revision, 'Choose a field type.'],
            [1, 5, 'Legend', 'text_format', [0], $revision, GraphTemplateInputEditor::RELATIONSHIP],
            [1, 5, 'Legend', 'text_format', ['11'], $revision, GraphTemplateInputEditor::RELATIONSHIP],
            [1, 5, 'Legend', 'text_format', [1 => 11], $revision, GraphTemplateInputEditor::RELATIONSHIP],
            [1, 5, 'Legend', 'text_format', range(1, 1001), $revision, GraphTemplateInputEditor::RELATIONSHIP],
        ] as [$template, $input, $name, $column, $items, $rev, $message]) {
            try {
                $this->editor->save(7, $template, $input, $name, '', $column, $items, $rev);
                self::fail('Saved: ' . $message);
            } catch (\InvalidArgumentException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
        try {
            $this->editor->save(7, 1, 5, 'Legend', str_repeat('x', 65536), 'text_format', [], $revision);
            self::fail('An oversized description was saved.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Enter a description of at most 65535 bytes.', $error->getMessage());
        }
        self::assertSame($before, $this->rows());
    }

    public function testRemovalNeedsTheCurrentRevisionAndDeletesTheInputWithItsItems(): void
    {
        $before = $this->rows();
        foreach ([[1, 6, 'x', GraphTemplateInputEditor::NOT_FOUND], [1, 5, 'stale', GraphTemplateInputEditor::STALE], [2, 5, 'x', GraphTemplateInputEditor::NOT_FOUND]] as [$template, $input, $revision, $message]) {
            try {
                $this->editor->remove(7, $template, $input, $revision);
                self::fail('Removed: ' . $message);
            } catch (\InvalidArgumentException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
        self::assertSame($before, $this->rows());
        $this->editor->remove(7, 1, 5, $this->catalog->find(1, 5)->revision);
        self::assertFalse($this->database->fetchOne('SELECT id FROM graph_template_input WHERE id = 5'));
        self::assertSame([], $this->defs(5));
        self::assertSame([21], $this->defs(6), 'another input keeps its items');
        self::assertSame('graphing.graph_template_input.delete', end($this->audit)->action);
    }

    public function testAnInputCanLoseEveryItem(): void
    {
        $this->editor->save(7, 1, 5, 'Legend', 'Text', 'text_format', [], $this->catalog->find(1, 5)->revision);
        self::assertSame([], $this->defs(5));
    }

    public function testUnconfirmedCreationAndRemovalRollBack(): void
    {
        // The next ID would not fit graph_template_input.id (mediumint unsigned).
        $this->database->executeStatement("INSERT INTO graph_template_input (id, graph_template_id, name) VALUES (16777215, 2, 'Last')");
        $before = $this->rows();
        try {
            $this->editor->save(7, 1, null, 'Overflow', '', 'text_format', [11], null);
            self::fail('An unrepresentable ID was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Graph Item Input creation was not confirmed.', $error->getMessage());
        }
        self::assertSame($before, $this->rows());

        $this->database->executeStatement('CREATE TRIGGER keep_5 BEFORE DELETE ON graph_template_input WHEN OLD.id = 5 BEGIN SELECT RAISE(IGNORE); END');
        try {
            $this->editor->remove(7, 1, 5, $this->catalog->find(1, 5)->revision);
            self::fail('An unconfirmed removal was reported.');
        } catch (\RuntimeException $error) {
            self::assertSame('Graph Item Input removal was not confirmed.', $error->getMessage());
        }
        self::assertSame([11], $this->defs(5));
    }

    public function testAnUnexpectedStoredRowIsRefusedInsteadOfRead(): void
    {
        $input = $this->catalog->find(1, 5);
        $this->database->executeStatement("UPDATE graph_templates_item SET local_graph_id = 'x' WHERE id = 1111");
        $before = $this->rows();
        try {
            $this->editor->save(7, 1, 5, 'Renamed', '', 'text_format', [11, 12], $input->revision);
            self::fail('A malformed graph ID was read.');
        } catch (\UnexpectedValueException $error) {
            self::assertSame('The graph template tables returned an unexpected row.', $error->getMessage());
        }
        self::assertSame($before, $this->rows(), 'the save rolled back');

        $this->database->executeStatement("UPDATE graph_templates_item SET graph_type_id = 'LINE1' WHERE id = 12");
        $this->expectException(\UnexpectedValueException::class);
        $this->catalog->items(1, 5);
    }

    public function testRevokedAccessWritesNothing(): void
    {
        $this->revoked = true;
        $before = $this->rows();
        foreach ([fn() => $this->editor->save(7, 1, null, 'Denied', '', 'text_format', [], null), fn() => $this->editor->remove(7, 1, 5, $this->catalog->find(1, 5)->revision)] as $write) {
            try {
                $write();
                self::fail('A revoked actor wrote.');
            } catch (GraphTemplateInputAccessDenied) {
            }
        }
        self::assertSame($before, $this->rows());
        self::assertSame([AuditEvent::DENIED, AuditEvent::DENIED], [$this->audit[0]->decision, $this->audit[0]->outcome]);
    }

    public function testAccessNeedsTheTemplatesRealmAndRechecksTheConsoleRealmForWrites(): void
    {
        foreach ([
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)',
            'CREATE TABLE user_auth (id INT PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT)',
            'CREATE TABLE user_auth_realm (user_id INT, realm_id INT)',
            'CREATE TABLE user_auth_group (id INT, enabled TEXT)',
            'CREATE TABLE user_auth_group_members (group_id INT, user_id INT)',
            'CREATE TABLE user_auth_group_realm (group_id INT, realm_id INT)',
            "INSERT INTO settings VALUES ('auth_method', '1'), ('guest_user', 'guest')",
            "INSERT INTO user_auth VALUES (9, 'operator', 'on', '', ''), (10, 'guest', 'on', '', ''), (11, 'locked', 'on', 'on', '')",
            // Realm 9 (Data Source Profiles) is not the Graph Templates realm.
            'INSERT INTO user_auth_realm VALUES (9, 8), (9, 9), (10, 8), (10, 10), (11, 8), (11, 10)',
        ] as $statement) {
            $this->database->executeStatement($statement);
        }
        $actor = new Actor(9, 'operator');
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturnCallback(static function () use (&$actor): ?Actor {
            return $actor;
        });
        $access = new DbalGraphTemplateInputAccess($console, $this->database);
        $denied = static function (callable $check): bool {
            try {
                $check();
            } catch (GraphTemplateInputAccessDenied) {
                return true;
            }
            return false;
        };
        self::assertTrue($denied(static fn() => $access->authorize()));
        $this->database->executeStatement("INSERT INTO user_auth_group VALUES (3, 'on'); INSERT INTO user_auth_group_members VALUES (3, 9); INSERT INTO user_auth_group_realm VALUES (3, 10)");
        self::assertSame(9, $access->authorize()->id);

        $this->database->beginTransaction();
        $access->assertCurrent(9);
        self::assertTrue($denied(static fn() => $access->assertCurrent(10)), 'another account than the session');
        $this->database->executeStatement('DELETE FROM user_auth_realm WHERE user_id = 9 AND realm_id = 8');
        self::assertTrue($denied(static fn() => $access->assertCurrent(9)), 'the console realm is rechecked');
        $this->database->rollBack();

        foreach ([new Actor(10, 'guest'), new Actor(11, 'locked')] as $actor) {
            self::assertTrue($denied(static fn() => $access->authorize()), $actor->username);
        }
        $actor = new Actor(9, 'operator');
        $this->database->executeStatement("UPDATE settings SET value = '5' WHERE name = 'auth_method'");
        self::assertTrue($denied(static fn() => $access->authorize()), 'an unknown authentication method');
        $actor = null;
        try {
            $access->authorize();
            self::fail('An anonymous request was admitted.');
        } catch (GraphTemplateInputAccessDenied $error) {
            self::assertTrue($error->unauthenticated);
        }
        $this->expectException(\LogicException::class);
        $access->assertCurrent(9);
    }

    /** @return list<int> */
    private function defs(int $inputId): array
    {
        return array_map(intval(...), $this->database->fetchFirstColumn('SELECT graph_template_item_id FROM graph_template_input_defs WHERE graph_template_input_id = ? ORDER BY 1', [$inputId]));
    }

    /**
     * @param list<int> $templateItems
     * @return list<string>
     */
    private function texts(int $graphId, array $templateItems): array
    {
        return array_map(fn(int $item): string => (string) $this->database->fetchOne('SELECT text_format FROM graph_templates_item WHERE local_graph_id = ? AND local_graph_template_item_id = ?', [$graphId, $item]), $templateItems);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function rows(): array
    {
        $rows = [];
        foreach (['graph_template_input', 'graph_template_input_defs', 'graph_templates_item'] as $table) {
            $rows[$table] = $this->database->fetchAllAssociative("SELECT * FROM $table ORDER BY 1, 2");
        }
        return $rows;
    }
}
