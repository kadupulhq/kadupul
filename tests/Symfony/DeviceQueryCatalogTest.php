<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DeviceAssociations;
use Kadupul\Inventory\Domain\DeviceAssociationChange;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceAssociationWriter;
use PHPUnit\Framework\TestCase;

final class DeviceQueryCatalogTest extends TestCase
{
    private function database(bool $catalog): \PDO
    {
        $db = new \PDO('sqlite::memory:');
        $db->exec("CREATE TABLE snmp_query (id INT); CREATE TABLE host (id INT,site_id INT,poller_id INT,host_template_id INT,deleted TEXT); INSERT INTO host VALUES (7,0,2,0,''); CREATE TABLE host_snmp_query (host_id INT,snmp_query_id INT,reindex_method INT); INSERT INTO host_snmp_query VALUES (7,9,2)");
        if ($catalog) {
            $db->exec('INSERT INTO snmp_query VALUES (9)');
        }
        return $db;
    }
    public static function changes(): array
    {
        return [['add',false],['add',true],['change',false],['change',true]];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('changes')]
    public function testEveryCatalogIsValidatedBeforeLegacyEffects(string $operation, bool $remoteMissing): void
    {
        $primary = $this->database($remoteMissing);
        $remote = $this->database(!$remoteMissing);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Data query unavailable');
        (new DeviceAssociationWriter())->apply($primary, $remote, new DeviceAssociations(7, 'Router', 0, 2, 0, [9 => 'Query'], [9 => 2], 'query'), new DeviceAssociationChange('query', $operation, 9, 2));
    }
    public function testAnOrphanMappingCannotConfirmSuccessfulQueryAssignment(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Data query unavailable');
        (new DeviceAssociationWriter())->verify($this->database(true), $this->database(false), new DeviceAssociations(7, 'Router', 0, 2, 0, [9 => 'Query'], [9 => 2], 'query'), new DeviceAssociationChange('query', 'add', 9, 2));
    }
}
