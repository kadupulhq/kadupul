<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Domain\PaletteColor;
use Kadupul\Graphing\Domain\PaletteCsv;
use Kadupul\Graphing\Domain\PaletteColorFilters;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyPaletteColorStore;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class PaletteColorTest extends TestCase
{
    private \PDO $db;
    private LegacyPaletteColorStore $store;
    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->db->exec("CREATE TABLE colors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, hex TEXT COLLATE NOCASE UNIQUE, read_only TEXT DEFAULT ''); CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, color_id INT, graph_template_id INT, local_graph_id INT); CREATE TABLE color_template_items (color_id INT); CREATE TABLE settings(name TEXT, value TEXT)");
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->method('get')->willReturn($this->db);
        $this->store = new LegacyPaletteColorStore($connection, $this->createMock(PaletteColorAccess::class), $this->createMock(AuditTrail::class), $this->createMock(LegacyConfiguration::class));
    }
    public function testWriterDoesNotRollBackCallerOwnedTransaction(): void
    {
        $this->db->beginTransaction();
        $this->db->exec("INSERT INTO colors (name, hex, read_only) VALUES ('caller prior change','987','')");
        try {
            $this->store->save(1, null, 'nested write', '123', null);
            self::fail('nested transaction accepted');
        } catch (\RuntimeException) {
            self::assertTrue($this->db->inTransaction());
            self::assertSame('caller prior change', $this->db->query("SELECT name FROM colors WHERE hex='987'")->fetchColumn());
            self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM colors')->fetchColumn());
        } finally {
            $this->db->rollBack();
        }
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM colors')->fetchColumn());
    }
    public function testCsvRoundTripPreservesQuotesCommasAndNewlines(): void
    {
        $name = "comma, quote\"\nand apostrophe'";
        self::assertSame([['name' => $name, 'hex' => 'aBc']], PaletteCsv::parse(PaletteCsv::export([new PaletteColor(1, $name, 'aBc', false)])));
        self::assertSame([['hex' => '123456', 'name' => '']], PaletteCsv::parse("hex,name\r\n123456,\r\n"));
    }
    public function testSpreadsheetExportsAreLiteralAndRoundTripWithoutChangingLegacyImports(): void
    {
        foreach (["=1+1", "+SUM(1,2)", "-1+2", "@SUM(1,2)", " =1+1", "\t=1+1", "\r=1+1", "\n=1+1", "'already literal", "''two apostrophes", ''] as $name) {
            $export = PaletteCsv::export([new PaletteColor(7, $name, 'aBc', false)]);
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, $export);
            rewind($stream);
            self::assertSame(['name', 'hex', PaletteCsv::LITERAL_MARKER], fgetcsv($stream, null, ',', '"', ''));
            self::assertSame(["'" . $name, "'aBc", '1'], fgetcsv($stream, null, ',', '"', ''));
            fclose($stream);
            self::assertSame([['name' => $name, 'hex' => 'aBc']], PaletteCsv::parse($export));
            $stream = fopen('php://temp', 'w+');
            fputcsv($stream, ['name', 'hex'], ',', '"', '');
            fputcsv($stream, [$name, 'aBc'], ',', '"', '');
            rewind($stream);
            $legacy = stream_get_contents($stream);
            fclose($stream);
            self::assertSame([['name' => $name, 'hex' => 'aBc']], PaletteCsv::parse($legacy));
        }
    }

    public function testUnsupportedOrMalformedLiteralMarkersFailBeforeDecodingNames(): void
    {
        foreach ([
            "name,hex,kadupul_literal_v2\n'name,'abc,1\n",
            "name,hex,kadupul_literal_v1\n'name,'abc,2\n",
            "name,hex,kadupul_literal_v1\nname,'abc,1\n",
            "name,hex,kadupul_literal_v1\n'name,abc,1\n",
            "name,hex,kadupul_literal_v1\n'name,'abc,\n",
            "name,hex,kadupul_literal_v1\n'name,'abc,1,extra\n",
        ] as $csv) {
            try {
                PaletteCsv::parse($csv);
                self::fail('Unsupported or malformed marker was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringStartsWith('CSV ', $error->getMessage());
            }
        }
    }

    /** @dataProvider invalidCsv */
    public function testInvalidCsvFailsBeforeWriting(string $csv): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PaletteCsv::parse($csv);
    }
    public static function invalidCsv(): array
    {
        return [["name,hex\na\"b,123\n"], ["name,hex\n\"unfinished,123\n"], ["name,name\na,b\n"], ["name,hex,read_only\na,123,on\n"], ["name,hex\na,12\n"], ["name,hex\na,123\nb,123\n"], ["name,hex\na,abc\nb,ABC\n"], ["name,hex\na,123,extra\n"], ["name,hex\n"], ["name,hex\na,</x>\n"], ["name,hex\n" . str_repeat('x', 41) . ',123'], [str_repeat('x', PaletteCsv::MAX_BYTES + 1)]];
    }
    public function testEditableColorCanBeUnnamedAndRawNamePersists(): void
    {
        $id = $this->store->save(1, null, '', 'AbC', null);
        $color = $this->store->find($id);
        self::assertSame('', $color->name);
        $name = "<tag>, \"quote\"";
        $this->store->save(1, $id, $name, '123456', $color->revision);
        self::assertSame($name, $this->store->find($id)->name);
    }
    public function testDuplicateHexCreateAndEditAreValidationFailuresAfterRollback(): void
    {
        $first = $this->store->save(1, null, 'first', 'aBc', null);
        $second = $this->store->save(1, null, 'second', '123', null);
        foreach ([null, $second] as $id) {
            try {
                $this->store->save(1, $id, 'replacement', 'ABC', $id === null ? null : $this->store->find($id)->revision);
                self::fail('Duplicate hex was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('A Color with this hex value already exists.', $error->getMessage());
                self::assertInstanceOf(\PDOException::class, $error->getPrevious());
                self::assertFalse($this->db->inTransaction());
                self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM colors')->fetchColumn());
                self::assertSame('first', $this->store->find($first)->name);
                self::assertSame('second', $this->store->find($second)->name);
                self::assertSame('123', $this->store->find($second)->hex);
            }
        }
        $this->store->save(1, $first, 'same identity', 'abc', $this->store->find($first)->revision);
        self::assertSame('same identity', $this->store->find($first)->name);
    }

    public function testFailedRollbackCannotBecomeDuplicateHexValidation(): void
    {
        foreach ([false, true] as $throw) {
            $db = new class ('sqlite::memory:') extends \PDO {
                public bool $throw = false;
                public function rollBack(): bool
                {
                    if ($this->throw) {
                        throw new \PDOException('Injected rollback failure.');
                    }
                    return false;
                }
            };
            $db->throw = $throw;
            $db->exec("CREATE TABLE colors (id INTEGER PRIMARY KEY, name TEXT, hex TEXT UNIQUE, read_only TEXT); INSERT INTO colors VALUES (1,'original','abc','')");
            $connection = $this->createMock(DatabaseConnection::class);
            $connection->method('get')->willReturn($db);
            $store = new LegacyPaletteColorStore($connection, $this->createMock(PaletteColorAccess::class), $this->createMock(AuditTrail::class), $this->createMock(LegacyConfiguration::class));
            try {
                $store->save(1, null, 'duplicate', 'abc', null);
                self::fail('An unconfirmed rollback was reported as validation.');
            } catch (\RuntimeException $error) {
                self::assertNotInstanceOf(\InvalidArgumentException::class, $error);
                self::assertSame('Color operation rollback was not confirmed.', $error->getMessage());
                self::assertTrue($db->inTransaction());
                self::assertSame('original', $db->query('SELECT name FROM colors WHERE id=1')->fetchColumn());
            } finally {
                $db->exec('ROLLBACK');
            }
        }
    }

    public function testFalseMysqlPreconditionsFailBeforeBeginningTheOwnedTransaction(): void
    {
        foreach (['engine', 'isolation'] as $failure) {
            $db = new class ('sqlite::memory:') extends \PDO {
                public string $failure = '';
                public bool $begun = false;
                public function getAttribute(int $attribute): mixed
                {
                    return $attribute === \PDO::ATTR_DRIVER_NAME ? 'mysql' : parent::getAttribute($attribute);
                }
                public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
                {
                    if (str_starts_with($query, 'SHOW CREATE TABLE ')) {
                        return $this->failure === 'engine' ? false
                            : parent::query("SELECT 'fixture', " . $this->quote("CREATE TABLE fixture (\n) ENGINE=InnoDB"));
                    }
                    return parent::query($query);
                }
                public function exec(string $statement): int|false
                {
                    return str_starts_with($statement, 'SET TRANSACTION ISOLATION') ? false : parent::exec($statement);
                }
                public function beginTransaction(): bool
                {
                    $this->begun = true;
                    return parent::beginTransaction();
                }
            };
            $db->failure = $failure;
            $db->exec('CREATE TABLE colors (id INTEGER PRIMARY KEY, name TEXT, hex TEXT)');
            $connection = $this->createMock(DatabaseConnection::class);
            $connection->method('get')->willReturn($db);
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['collector_id' => 1]);
            $access = $this->createMock(PaletteColorAccess::class);
            $access->expects(self::never())->method('assertCurrent');
            $store = new LegacyPaletteColorStore($connection, $access, $this->createMock(AuditTrail::class), $configuration);
            try {
                $store->save(1, null, 'refused', 'abc', null);
                self::fail('An unconfirmed transaction precondition was accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame($failure === 'engine' ? 'Color writes require transactional tables.' : 'Color transaction isolation was not confirmed.', $error->getMessage());
                self::assertFalse($db->begun);
                self::assertFalse($db->inTransaction());
                self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM colors')->fetchColumn());
            }
        }
    }

    public function testStaleEditDoesNotOverwrite(): void
    {
        $id = $this->store->save(1, null, 'before', '123', null);
        $revision = $this->store->find($id)->revision;
        $this->store->save(1, $id, 'fresh', '123', $revision);
        try {
            $this->store->save(1, $id, 'stale', '123', $revision);
            self::fail('stale accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame('fresh', $this->store->find($id)->name);
        }
    }
    public function testNamedColorCannotBeChangedOrDeleted(): void
    {
        $this->db->exec("INSERT INTO colors VALUES (1,'Built in','000000','on')");
        $color = $this->store->find(1);
        self::assertFalse($color->isDeletable());
        foreach (['edit', 'delete'] as $action) {
            try {
                $action === 'edit' ? $this->store->save(1, 1, 'forged', '123', $color->revision) : $this->store->delete(1, [1], [1 => $color->revision]);
                self::fail('named mutation accepted');
            } catch (\InvalidArgumentException) {
                self::assertSame('Built in', $this->store->find(1)->name);
            }
        }
    }
    public function testReferencesProtectWholeDeleteSelection(): void
    {
        $a = $this->store->save(1, null, 'a', '123', null);
        $b = $this->store->save(1, null, 'b', '456', null);
        $this->db->exec("INSERT INTO color_template_items VALUES ($b)");
        self::assertFalse($this->store->find($b)->isDeletable());
        try {
            $this->store->delete(1, [$a, $b], [$a => $this->store->find($a)->revision, $b => $this->store->find($b)->revision]);
            self::fail('referenced delete accepted');
        } catch (\InvalidArgumentException) {
            self::assertNotNull($this->store->find($a));
            self::assertNotNull($this->store->find($b));
        }
    }
    public function testImportSkipsBuiltInsAndExistingRowsUnlessUpdatesAllowed(): void
    {
        $this->db->exec("INSERT INTO colors VALUES (1,'Built in','000000','on'),(2,'Existing','123','')");
        $rows = PaletteCsv::parse("name,hex\nforged,000000\nupdate,123\nnew,456\n");
        self::assertSame(['inserted' => 1, 'updated' => 0, 'skipped' => 2], $this->store->import(1, $rows, false, $this->store->snapshot()));
        self::assertSame(['inserted' => 0, 'updated' => 2, 'skipped' => 1], $this->store->import(1, $rows, true, $this->store->snapshot()));
        self::assertSame('Built in', $this->store->find(1)->name);
        self::assertSame('update', $this->store->find(2)->name);
    }
    public function testImportRollbackOnDatabaseFailureRestoresEarlierRows(): void
    {
        $this->db->exec("CREATE TRIGGER fail_insert BEFORE INSERT ON colors WHEN NEW.hex = '456' BEGIN SELECT RAISE(ABORT,'injected'); END");
        try {
            $this->store->import(1, PaletteCsv::parse("name,hex\na,123\nb,456\n"), false, $this->store->snapshot());
            self::fail('trigger did not fail');
        } catch (\PDOException) {
            self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM colors')->fetchColumn());
        }
    }
    public function testStaleImportSnapshotRejectsConcurrentChanges(): void
    {
        $snapshot = $this->store->snapshot();
        $this->store->save(1, null, 'concurrent', '789', null);
        $this->expectException(\InvalidArgumentException::class);
        $this->store->import(1, PaletteCsv::parse("name,hex\na,123\n"), true, $snapshot);
    }
    public function testFilterAndUsageCountsMatchLegacyAndExportIgnoresPagination(): void
    {
        $this->db->exec("INSERT INTO colors VALUES (1,'Named','000000','on'),(2,'Custom','123',''); INSERT INTO graph_templates_item VALUES (1,2,5,6),(2,2,5,6),(3,2,5,0)");
        $filters = PaletteColorFilters::fromQuery(['named' => 'false', 'has_graphs' => 'true', 'rows' => '1'], 25);
        $page = $this->store->list($filters);
        self::assertSame(1, $page->total);
        self::assertSame(1, $page->presets[0]->graphs);
        self::assertSame(1, $page->presets[0]->templates);
        self::assertCount(2, $this->store->export(PaletteColorFilters::fromQuery(['named' => 'false', 'rows' => '1'], 25)));
    }
}
