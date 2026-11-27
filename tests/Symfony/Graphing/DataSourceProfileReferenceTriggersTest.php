<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Graphing;

use Kadupul\Graphing\Infrastructure\Persistence\DataSourceProfileReferenceTriggers;
use PHPUnit\Framework\TestCase;

final class DataSourceProfileReferenceTriggersTest extends TestCase
{
    public function testDefinitionsMatchLegacyIntegrityHelper(): void
    {
        require_once dirname(__DIR__, 3) . '/lib/data_source_profile_integrity.php';

        $this->assertSame(
            \data_source_profile_reference_triggers(),
            DataSourceProfileReferenceTriggers::definitions(),
        );
    }

    public function testCustomIdentifiersAreValidated(): void
    {
        $definitions = DataSourceProfileReferenceTriggers::definitions('custom_profiles', 'custom_data', 'custom_prefix', 'custom_rra', 'custom_cf');
        $this->assertCount(8, $definitions);
        $this->assertArrayHasKey('custom_prefix_insert', $definitions);
        $this->assertSame('custom_data', $definitions['custom_prefix_insert']['table']);

        $this->expectException(\InvalidArgumentException::class);
        DataSourceProfileReferenceTriggers::definitions('bad-identifier');
    }
}
