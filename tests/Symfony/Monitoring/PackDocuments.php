<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

/**
 * Pack documents decoded the way JsonPackReader decodes them: objects stay
 * objects, so an empty {} and an empty [] remain distinguishable.
 */
final class PackDocuments
{
    public const EXAMPLE = __DIR__ . '/../../../docs/schemas/examples/openconfig-interfaces.pack.json';
    public const PACK_SCHEMA = __DIR__ . '/../../../docs/schemas/monitoring-pack.schema.json';
    public const PLAN_SCHEMA = __DIR__ . '/../../../docs/schemas/collection-plan.schema.json';
    public const PLAN_SCHEMA_V2 = __DIR__ . '/../../../docs/schemas/collection-plan.v2.schema.json';

    public static function example(): \stdClass
    {
        return json_decode((string) file_get_contents(self::EXAMPLE), false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }

    /** The example at another version, optionally changed further by $edit. */
    public static function exampleAt(string $version, ?\Closure $edit = null): \stdClass
    {
        $document = self::example();
        $document->version = $version;
        if ($edit !== null) {
            $edit($document);
        }

        return $document;
    }

    public static function removingErrors(): \Closure
    {
        return static function (\stdClass $d): void {
            $d->metrics = array_values(array_filter($d->metrics, static fn(\stdClass $m): bool => $m->id !== 'interface.in_errors'));
            $d->graphs = array_values(array_filter($d->graphs, static fn(\stdClass $g): bool => $g->name !== 'Interface errors'));
        };
    }

    public static function addingOutDiscards(): \Closure
    {
        return static function (\stdClass $d): void {
            $d->metrics[] = json_decode('{"id": "interface.out_discards", "type": "counter", "unit": "packets", "counter_bits": 32, "labels": ["name"], "mappings": {"snmp": {"oid": "1.3.6.1.2.1.2.2.1.19"}, "gnmi": {"path": "/interfaces/interface[name=*]/state/counters/out-discards"}}}', false, 512, JSON_THROW_ON_ERROR);
        };
    }
}
