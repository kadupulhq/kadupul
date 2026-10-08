<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\GraphDefinition\Application\Port\CdefAccess;
use Kadupul\GraphDefinition\Application\Query\CdefAccessDenied;
use Kadupul\GraphDefinition\Domain\CdefFilters;
use Kadupul\GraphDefinition\Domain\CdefFunctions;
use Kadupul\GraphDefinition\Infrastructure\Persistence\DbalCdefStore;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\CdefReferenceReadiness;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class CdefStoreTest extends TestCase
{
    private Connection $database;
    private DbalCdefStore $store;
    private bool $revoked = false;
    /** @var list<bool> */
    private array $checkedInTransaction = [];
    /** @var list<AuditEvent> */
    private array $audit = [];

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE cdef (id INTEGER PRIMARY KEY AUTOINCREMENT, hash TEXT NOT NULL DEFAULT \'\', `system` INT NOT NULL DEFAULT 0, name TEXT NOT NULL)',
            'CREATE TABLE cdef_items (id INTEGER PRIMARY KEY AUTOINCREMENT, hash TEXT NOT NULL DEFAULT \'\', cdef_id INT NOT NULL, sequence INT NOT NULL, type INT NOT NULL, value TEXT NOT NULL)',
            'CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, cdef_id INT, graph_template_id INT, local_graph_id INT)',
            'CREATE TABLE aggregate_graph_templates_item (id INTEGER PRIMARY KEY, cdef_id INT)',
            'CREATE TABLE aggregate_graphs_graph_item (id INTEGER PRIMARY KEY, cdef_id INT)',
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)',
        ] as $statement) {
            $this->database->executeStatement($statement);
        }
        $access = $this->createMock(CdefAccess::class);
        $access->method('assertCurrent')->willReturnCallback(function (): void {
            $native = $this->database->getNativeConnection();
            $this->checkedInTransaction[] = $native instanceof \PDO && $native->inTransaction();
            if ($this->revoked) {
                throw new CdefAccessDenied();
            }
        });
        $audit = $this->createMock(AuditTrail::class);
        $audit->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->audit[] = $event;
        });
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $this->store = new DbalCdefStore($this->database, $access, $audit, $configuration, $this->createMock(CdefReferenceReadiness::class));
    }

    public function testCreateAndEditStoreTheNameAsEnteredAndRefuseStaleForms(): void
    {
        $id = $this->store->save(7, null, '<b>Bits & "bytes"</b>', null);
        $cdef = $this->store->find($id);
        self::assertSame('<b>Bits & "bytes"</b>', $cdef->name);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', (string) $this->database->fetchOne('SELECT hash FROM cdef'));

        $this->store->save(7, $id, 'Fresh', $cdef->revision);
        foreach ([$cdef->revision, null, ''] as $stale) {
            try {
                $this->store->save(7, $id, 'Stale', $stale);
                self::fail('A stale CDEF edit was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('changed since', $error->getMessage());
            }
        }
        self::assertSame('Fresh', $this->store->find($id)->name);
        foreach (['', '   ', str_repeat('n', 256), "line\nbreak", "\xff"] as $invalid) {
            try {
                $this->store->save(7, null, $invalid, null);
                self::fail('An invalid CDEF name was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM cdef'));
        self::assertSame([true, true], array_slice($this->checkedInTransaction, 0, 2));
        self::assertSame('graph_definition.cdef.create', $this->audit[0]->action);
    }

    public function testItemsValidateTheirTypeAndRenderTheLegacyPreview(): void
    {
        $inner = $this->store->save(7, null, 'Inner', null);
        $this->item($inner, CdefFunctions::SPECIAL_DATA_SOURCE, 'CURRENT_DATA_SOURCE');
        $outer = $this->store->save(7, null, 'Outer', null);
        $this->item($outer, CdefFunctions::CDEF, (string) $inner);
        $this->item($outer, CdefFunctions::CUSTOM_STRING, '8');
        $this->item($outer, CdefFunctions::OPERATOR, '3');
        $this->item($outer, CdefFunctions::FUNCTION, '42');

        $cdef = $this->store->find($outer);
        self::assertSame('CURRENT_DATA_SOURCE,8,*,ABS', $cdef->preview);
        self::assertSame(['Inner', '8', '*', 'ABS'], array_map(static fn($item): string => $item->label, $cdef->items));
        self::assertSame([1, 2, 3, 4], array_map(static fn($item): int => $item->sequence, $cdef->items));
        self::assertSame('Current Graph Item Data Source', $this->store->find($inner)->items[0]->label);

        foreach ([
            [CdefFunctions::FUNCTION, '59'], [CdefFunctions::OPERATOR, '6'], [CdefFunctions::SPECIAL_DATA_SOURCE, 'current_data_source'],
            [CdefFunctions::CDEF, '999'], [CdefFunctions::CDEF, '0' . $inner], [CdefFunctions::CUSTOM_STRING, ''],
            [CdefFunctions::CUSTOM_STRING, str_repeat('x', 151)], [3, '1'],
        ] as [$type, $value]) {
            try {
                $this->item($outer, $type, $value);
                self::fail("Item type $type accepted '$value'.");
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertCount(4, $this->store->find($outer)->items);

        $this->database->insert('settings', ['name' => 'rrdtool_version', 'value' => 'rrd-1.8.x']);
        self::assertSame('ROUND', $this->store->functions()['59']);
        $this->item($outer, CdefFunctions::FUNCTION, '59');
        self::assertStringEndsWith(',ROUND', $this->store->find($outer)->preview);
    }

    public function testReferencesCannotFormACycle(): void
    {
        $a = $this->store->save(7, null, 'A', null);
        $b = $this->store->save(7, null, 'B', null);
        $c = $this->store->save(7, null, 'C', null);
        $this->item($a, CdefFunctions::CDEF, (string) $b);
        $this->item($b, CdefFunctions::CDEF, (string) $c);
        foreach ([[$c, $a], [$a, $a], [$c, $c]] as [$owner, $target]) {
            try {
                $this->item($owner, CdefFunctions::CDEF, (string) $target);
                self::fail("A reference from $owner to $target closed a cycle.");
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('cannot include itself', $error->getMessage());
            }
        }
        self::assertSame([(string) $b, (string) $c], array_map('strval', array_keys($this->store->references($a))));
        // An existing self-reference from legacy data does not loop the preview.
        $this->database->insert('cdef_items', ['cdef_id' => $c, 'sequence' => 1, 'type' => 5, 'value' => (string) $c]);
        self::assertSame('[recursive CDEF]', $this->store->find($c)->preview);
    }

    public function testItemsMoveAndDeleteAgainstTheDisplayedRevision(): void
    {
        $id = $this->store->save(7, null, 'Order', null);
        foreach (['1', '2', '3'] as $value) {
            $this->item($id, CdefFunctions::CUSTOM_STRING, $value);
        }
        $cdef = $this->store->find($id);
        [$first, $second, $third] = array_map(static fn($item): int => $item->id, $cdef->items);
        $this->store->moveItem(7, $id, $third, -1, $cdef->revision);
        self::assertSame('1,3,2', $this->store->find($id)->preview);
        foreach ([[$third, -1, $cdef->revision], [$first, -1, $this->store->find($id)->revision], [$second, 1, $this->store->find($id)->revision]] as [$item, $offset, $revision]) {
            try {
                $this->store->moveItem(7, $id, $item, $offset, $revision);
                self::fail('A stale or impossible move was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }
        $revision = $this->store->find($id)->revision;
        $this->store->saveItem(7, $id, $second, CdefFunctions::OPERATOR, '1', $revision);
        self::assertSame('1,3,+', $this->store->find($id)->preview);
        $this->store->deleteItem(7, $id, $first, $this->store->find($id)->revision);
        self::assertSame('3,+', $this->store->find($id)->preview);
        try {
            $this->store->deleteItem(7, $id, $first, $this->store->find($id)->revision);
            self::fail('A missing item was deleted.');
        } catch (\InvalidArgumentException) {
        }
    }

    public function testDuplicateCopiesEveryItemOrNothing(): void
    {
        $source = $this->store->save(7, null, 'Source', null);
        $this->item($source, CdefFunctions::CUSTOM_STRING, '1');
        $this->item($source, CdefFunctions::OPERATOR, '2');
        $other = $this->store->save(7, null, str_repeat('n', 251), null);
        $revisions = [$source => $this->store->find($source)->revision, $other => $this->store->find($other)->revision];
        try {
            $this->store->duplicate(7, [$source, $other], $revisions, '<cdef_title> copy');
            self::fail('An over-long duplicate name was accepted.');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM cdef'));

        $this->store->duplicate(7, [$source], [$source => $revisions[$source]], '<cdef_title> (1)');
        $copy = (int) $this->database->fetchOne("SELECT id FROM cdef WHERE name = 'Source (1)'");
        self::assertSame('1,-', $this->store->find($copy)->preview);
        try {
            $this->store->duplicate(7, [$source], [$source => 'stale'], '<cdef_title> (2)');
            self::fail('A stale duplicate was accepted.');
        } catch (\InvalidArgumentException) {
        }
    }

    public function testDeleteGoesThroughTheReferenceContractAndRechecksInsideItsTransaction(): void
    {
        $free = $this->store->save(7, null, 'Free', null);
        $mutual = $this->store->save(7, null, 'Mutual', null);
        $this->item($free, CdefFunctions::CDEF, (string) $mutual);
        $graph = $this->store->save(7, null, 'Graph', null);
        $nested = $this->store->save(7, null, 'Nested', null);
        $owner = $this->store->save(7, null, 'Owner', null);
        $this->item($owner, CdefFunctions::CDEF, (string) $nested);
        $aggregate = $this->store->save(7, null, 'Aggregate', null);
        $this->database->executeStatement("INSERT INTO graph_templates_item VALUES (1, $graph, 3, 0)");
        $this->database->executeStatement("INSERT INTO aggregate_graphs_graph_item VALUES (1, $aggregate)");

        $listed = $this->store->findMany([$free, $mutual, $graph, $nested, $aggregate]);
        self::assertSame([true, true, false, false, false], array_map(static fn(array $entry): bool => $entry['summary']->isDeletable(), array_values($listed)));
        foreach ([$graph, $nested, $aggregate] as $used) {
            try {
                $this->store->delete(7, [$used], [$used => $listed[$used]['revision']]);
                self::fail('An in-use CDEF was deleted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('cannot be deleted', $error->getMessage());
            }
        }

        $this->revoked = true;
        try {
            $this->store->delete(7, [$free, $mutual], [$free => $listed[$free]['revision'], $mutual => $listed[$mutual]['revision']]);
            self::fail('A revoked actor deleted CDEFs.');
        } catch (CdefAccessDenied) {
        }
        $this->revoked = false;
        try {
            $this->store->delete(7, [$free, $mutual], [$free => 'stale', $mutual => $listed[$mutual]['revision']]);
            self::fail('A stale deletion was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('changed since', $error->getMessage());
        }
        self::assertSame(6, (int) $this->database->fetchOne('SELECT COUNT(*) FROM cdef'));

        $this->checkedInTransaction = [];
        $this->store->delete(7, [$free, $mutual], [$free => $listed[$free]['revision'], $mutual => $listed[$mutual]['revision']]);
        self::assertSame([true], $this->checkedInTransaction);
        self::assertNull($this->store->find($free));
        self::assertSame(0, (int) $this->database->fetchOne("SELECT COUNT(*) FROM cdef_items WHERE cdef_id = $free"));
        self::assertSame(AuditEvent::SUCCEEDED, end($this->audit)->outcome);
        foreach ([[], [$graph, $graph], [2, 1], range(1, 101)] as $invalid) {
            try {
                $this->store->delete(7, $invalid, array_fill_keys($invalid, 'x'));
                self::fail('An invalid selection was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testListFiltersSortsAndCountsDistinctUses(): void
    {
        $names = ['Alpha_1', 'Alpha%2', 'Beta'];
        $ids = array_map(fn(string $name): int => $this->store->save(7, null, $name, null), $names);
        $this->database->executeStatement('INSERT INTO cdef (name, `system`) VALUES (\'System\', 1)');
        $this->database->executeStatement("INSERT INTO graph_templates_item VALUES (1, {$ids[2]}, 3, 0), (2, {$ids[2]}, 3, 0), (3, {$ids[2]}, 3, 9), (4, {$ids[0]}, 4, 0)");
        $page = $this->store->list(CdefFilters::fromQuery(['sort_column' => 'graphs', 'sort_direction' => 'DESC'], 25));
        self::assertSame(3, $page->total);
        self::assertSame(['Beta', 'Alpha_1', 'Alpha%2'], array_map(static fn($cdef): string => $cdef->name, $page->cdefs));
        self::assertSame([1, 1], [$page->cdefs[0]->graphs, $page->cdefs[0]->templates]);
        self::assertSame(['Alpha%2'], array_map(static fn($cdef): string => $cdef->name, $this->store->list(CdefFilters::fromQuery(['filter' => '%'], 25))->cdefs));
        self::assertSame(['Beta'], array_map(static fn($cdef): string => $cdef->name, $this->store->list(CdefFilters::fromQuery(['has_graphs' => 'true'], 25))->cdefs));
        $paged = $this->store->list(CdefFilters::fromQuery(['rows' => '1', 'page' => '2'], 25));
        self::assertSame(['Alpha_1'], array_map(static fn($cdef): string => $cdef->name, $paged->cdefs));
        self::assertTrue($paged->hasPrevious() && $paged->hasNext());
        self::assertNull($this->store->find((int) $this->database->fetchOne("SELECT id FROM cdef WHERE name = 'System'")));
    }

    private function item(int $cdefId, int $type, string $value): void
    {
        $this->store->saveItem(7, $cdefId, null, $type, $value, $this->store->find($cdefId)->revision);
    }
}
