<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\Security;

use Kadupul\Graphing\Domain\TemplateEditableColumns;
use Kadupul\Platform\Domain\HostSubstitutionScope;
use PHPUnit\Framework\TestCase;

final class GraphingHardeningTest extends TestCase
{
    public function testTemplateEditableColumnsAllowlistsPermittedFieldsAndRejectsSensitiveColumns(): void
    {
        $this->assertSame('title', TemplateEditableColumns::graphTemplateColumn('title'));
        $this->assertSame('upper_limit', TemplateEditableColumns::graphTemplateColumn('upper_limit'));
        $this->assertNull(TemplateEditableColumns::graphTemplateColumn('local_graph_id'));
        $this->assertNull(TemplateEditableColumns::graphTemplateColumn('id; DROP TABLE host'));

        $this->assertSame('text_format', TemplateEditableColumns::graphTemplateItemColumn('text_format'));
        $this->assertNull(TemplateEditableColumns::graphTemplateItemColumn('task_item_id'));
        $this->assertNull(TemplateEditableColumns::graphTemplateItemColumn('local_graph_id'));

        $this->assertSame('name', TemplateEditableColumns::dataTemplateDataColumn('name'));
        $this->assertNull(TemplateEditableColumns::dataTemplateDataColumn('data_source_path'));
        $this->assertNull(TemplateEditableColumns::dataTemplateDataColumn('data_input_id'));
        $this->assertNull(TemplateEditableColumns::dataTemplateDataColumn('local_data_id'));

        $this->assertSame('rrd_maximum', TemplateEditableColumns::dataTemplateRrdColumn('rrd_maximum'));
        $this->assertNull(TemplateEditableColumns::dataTemplateRrdColumn('local_data_id'));

        $this->assertTrue(TemplateEditableColumns::isSuggestedDataSourceFieldAllowed('name'));
        $this->assertTrue(TemplateEditableColumns::isSuggestedDataSourceFieldAllowed('rrd_maximum'));
        $this->assertFalse(TemplateEditableColumns::isSuggestedDataSourceFieldAllowed('data_source_path'));
        $this->assertFalse(TemplateEditableColumns::isSuggestedDataSourceFieldAllowed('data_input_id'));
    }

    public function testHostSubstitutionScopeExcludesSnmpCredentialsInDisplayMode(): void
    {
        $this->assertFalse(HostSubstitutionScope::Display->allowsToken('host_snmp_community'));
        $this->assertFalse(HostSubstitutionScope::Display->allowsToken('host_snmp_username'));
        $this->assertFalse(HostSubstitutionScope::Display->allowsToken('host_snmp_password'));
        $this->assertFalse(HostSubstitutionScope::Display->allowsToken('host_snmp_priv_passphrase'));
        $this->assertTrue(HostSubstitutionScope::Display->allowsToken('host_hostname'));
        $this->assertTrue(HostSubstitutionScope::Display->allowsToken('host_description'));

        $this->assertTrue(HostSubstitutionScope::Execution->allowsToken('host_snmp_community'));
        $this->assertTrue(HostSubstitutionScope::Execution->allowsToken('host_snmp_username'));
        $this->assertTrue(HostSubstitutionScope::Execution->allowsToken('host_snmp_password'));
        $this->assertTrue(HostSubstitutionScope::Execution->allowsToken('host_snmp_priv_passphrase'));
    }

    public function testGraphXportNeutralizesNthPercentileAndSummationCells(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/graph_xport.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('graph_xport_csv_cell($nth_val)', $source);
        $this->assertStringContainsString('graph_xport_csv_cell($nth_fmt)', $source);
        $this->assertStringContainsString('graph_xport_csv_cell($sum_val)', $source);
        $this->assertStringContainsString('graph_xport_csv_cell($sum_fmt)', $source);
        $this->assertStringContainsString("str_replace(array(\"\\r\", \"\\n\"), ' '", $source);
    }
}
