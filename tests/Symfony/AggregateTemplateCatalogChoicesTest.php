<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\DriverManager;
use Kadupul\AggregateTemplate\Infrastructure\Persistence\DoctrineAggregateTemplateCatalog;
use PHPUnit\Framework\TestCase;

final class AggregateTemplateCatalogChoicesTest extends TestCase
{
    public function testDuplicateNamesRetainEveryIdentityAndTheLegacyStackDefault(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (['graph_templates' => 'id', 'graph_templates_gprint' => 'id', 'color_templates' => 'color_template_id'] as $table => $id) {
            $database->executeStatement("CREATE TABLE $table ($id INTEGER PRIMARY KEY, name TEXT, gprint_text TEXT)");
            foreach ([3, 4] as $value) {
                $database->insert($table, [$id => $value, 'name' => 'Duplicate name', 'gprint_text' => '%8.2lf']);
            }
        }
        try {
            $data = (new DoctrineAggregateTemplateCatalog($database))->editData(null);
            self::assertSame(8, $data['template']['graph_type']);
            self::assertContains(8, $data['graphTypes']);
            foreach ([$data['graphTemplates'], array_filter($data['colorTemplates']), array_filter($data['graphFieldMetadata']['right_axis_format']['choices'])] as $choices) {
                self::assertSame([3, 4], array_values($choices));
                self::assertSame(['Duplicate name (#3)', 'Duplicate name (#4)'], array_keys($choices));
            }
        } finally {
            $database->close();
        }
    }
}
