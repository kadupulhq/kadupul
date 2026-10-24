<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Domain\InvalidPack;
use Kadupul\Monitoring\Domain\PackValidator;
use Kadupul\Monitoring\Domain\PackViolation;
use Kadupul\Telemetry\Contract\MetricType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PackValidatorTest extends TestCase
{
    private const RUNNING = '1.2.35';

    public function testTheShippedExampleIsValidAndParses(): void
    {
        $running = trim((string) file_get_contents(__DIR__ . '/../../../include/cacti_version'));
        $pack = (new PackValidator($running))->parse(PackDocuments::example(), 'openconfig-interfaces.pack.json');

        self::assertSame('openconfig-interfaces', $pack->name);
        self::assertSame('1.0.0', $pack->version->toString());
        self::assertSame([], $pack->requirement->all);
        self::assertSame(['openconfig-interfaces', 'snmp:if-mib'], $pack->requirement->any);
        self::assertSame(['interface.in_octets', 'interface.out_octets', 'interface.in_errors', 'interface.oper_status'], $pack->metricIds());
        self::assertSame(['gnmi', 'snmp'], $pack->protocolPreference);
        self::assertSame(10, $pack->minimumInterval);
        self::assertSame(60, $pack->defaultInterval);
        self::assertSame('interface', $pack->discovery->resource);
        self::assertSame('name', $pack->discovery->identityKey);
        $errors = $pack->metric('interface.in_errors');
        self::assertNotNull($errors);
        self::assertSame(MetricType::Counter, $errors->type);
        self::assertSame(32, $errors->counterBits);
        self::assertSame('1.3.6.1.2.1.2.2.1.14', $errors->mapping('snmp')?->path);
        $status = $pack->metric('interface.oper_status');
        self::assertNull($status?->counterBits);
        self::assertSame(1, $status?->mapping('gnmi')?->valueMap['UP']);
        self::assertSame(['Interface traffic', 'Interface errors'], array_map(static fn($g): string => $g->name, $pack->graphs));
    }

    public function testExtensionFieldsAreAcceptedWhereTheSchemaAllowsThem(): void
    {
        $document = PackDocuments::example();
        $document->{'x-vendor'} = ['team' => 'netops'];
        $document->metrics[0]->{'x-note'} = 'from vendor docs';
        $document->metrics[0]->mappings->gnmi->{'x-encoding'} = 'proto';

        self::assertSame([], (new PackValidator(self::RUNNING))->validate($document));
    }

    /**
     * @return iterable<string, array{\Closure(\stdClass): mixed, string, string}>
     */
    public static function invalidDocuments(): iterable
    {
        // Structure: one case per kind of schema rule.
        yield 'unknown top-level field' => [static fn($d) => $d->owner = 'netops', '/owner', 'unknown field'];
        yield 'unknown field in a mapping' => [static fn($d) => $d->metrics[0]->mappings->snmp->community = 'public', '/metrics/0/mappings/snmp/community', 'unknown field'];
        yield 'extension under mappings, which is closed' => [static fn($d) => $d->metrics[0]->mappings->{'x-cli'} = new \stdClass(), '/metrics/0/mappings/x-cli', 'unknown field'];
        yield 'extension under interval_seconds, which is closed' => [static fn($d) => $d->collection->interval_seconds->{'x-jitter'} = 5, '/collection/interval_seconds/x-jitter', 'unknown field'];
        yield 'unknown field in legacy templates' => [static fn($d) => $d->compatibility->legacy_templates->rrd = [], '/compatibility/legacy_templates/rrd', 'unknown field'];
        yield 'missing required section' => [static function ($d) {
            unset($d->collection);
        }, '/collection', 'is required'];
        yield 'missing required metric field' => [static function ($d) {
            unset($d->metrics[1]->unit);
        }, '/metrics/1/unit', 'is required'];
        yield 'missing redfish property' => [static fn($d) => $d->metrics[0]->mappings->redfish = (object) ['resource_path' => '/redfish/v1/Chassis/1'], '/metrics/0/mappings/redfish/property', 'is required'];
        yield 'schema version' => [static fn($d) => $d->schema_version = 2, '/schema_version', 'must be 1'];
        yield 'string expected' => [static fn($d) => $d->name = 5, '/name', 'must be a string'];
        yield 'array expected' => [static fn($d) => $d->metrics[0]->labels = 'name', '/metrics/0/labels', 'must be an array'];
        yield 'object expected' => [static fn($d) => $d->discovery = [], '/discovery', 'must be an object'];
        yield 'pack name pattern' => [static fn($d) => $d->name = 'OpenConfig Interfaces', '/name', 'lowercase'];
        yield 'trailing newline is not accepted by an anchored pattern' => [static fn($d) => $d->name = "openconfig-interfaces\n", '/name', 'lowercase'];
        yield 'semantic version pattern' => [static fn($d) => $d->version = '1.0', '/version', 'semantic version'];
        yield 'metric id pattern' => [static fn($d) => $d->metrics[0]->id = 'in_octets', '/metrics/0/id', 'dotted lowercase'];
        yield 'numeric OID pattern' => [static fn($d) => $d->metrics[0]->mappings->snmp->oid = 'ifHCInOctets', '/metrics/0/mappings/snmp/oid', 'numeric OID'];
        yield 'redfish path pattern' => [static fn($d) => $d->metrics[0]->mappings->redfish = (object) ['resource_path' => '/v1/Chassis', 'property' => 'Power'], '/metrics/0/mappings/redfish/resource_path', '/redfish/v1/'];
        yield 'kadupul range pattern' => [static fn($d) => $d->compatibility->kadupul = '>=1.0; rm -rf', '/compatibility/kadupul', 'version constraint'];
        yield 'maximum length' => [static fn($d) => $d->description = str_repeat('a', 501), '/description', 'at most 500 characters'];
        yield 'minimum length' => [static fn($d) => $d->metrics[0]->unit = '', '/metrics/0/unit', 'at least 1 character'];
        yield 'metric type enum' => [static fn($d) => $d->metrics[0]->type = 'derive', '/metrics/0/type', 'must be one of counter, gauge'];
        yield 'counter width enum' => [static fn($d) => $d->metrics[0]->counter_bits = 16, '/metrics/0/counter_bits', 'must be one of 32, 64'];
        yield 'counter width on a gauge' => [static fn($d) => $d->metrics[3]->counter_bits = 32, '/metrics/3/counter_bits', 'not allowed when type is gauge'];
        yield 'model family enum' => [static fn($d) => $d->model_families[] = 'mib', '/model_families/2', 'must be one of'];
        yield 'alert operator enum' => [static fn($d) => $d->alerts[0]->condition->operator = 'above', '/alerts/0/condition/operator', 'must be one of'];
        yield 'empty metric list' => [static fn($d) => $d->metrics = [], '/metrics', 'at least 1 item'];
        yield 'repeated model family' => [static fn($d) => $d->model_families = ['openconfig', 'openconfig'], '/model_families', 'must not repeat'];
        yield 'repeated capability' => [static fn($d) => $d->capabilities->any = ['snmp:if-mib', 'snmp:if-mib'], '/capabilities/any', 'must not repeat'];
        yield 'empty capability section' => [static fn($d) => $d->capabilities = new \stdClass(), '/capabilities', 'at least 1 field'];
        yield 'empty mappings' => [static fn($d) => $d->metrics[0]->mappings = new \stdClass(), '/metrics/0/mappings', 'at least 1 field'];
        yield 'interval minimum' => [static fn($d) => $d->collection->interval_seconds->min = 0, '/collection/interval_seconds/min', 'at least 1'];
        yield 'negative alert duration' => [static fn($d) => $d->alerts[0]->condition->for_seconds = -1, '/alerts/0/condition/for_seconds', 'at least 0'];
        yield 'scale must be positive' => [static fn($d) => $d->metrics[0]->mappings->gnmi->scale = 0, '/metrics/0/mappings/gnmi/scale', 'greater than 0'];
        yield 'value map entries are integers' => [static fn($d) => $d->metrics[3]->mappings->gnmi->value_map->UP = 'one', '/metrics/3/mappings/gnmi/value_map/UP', 'must be an integer'];
        yield 'a fractional integer' => [static fn($d) => $d->collection->interval_seconds->default = 60.5, '/collection/interval_seconds/default', 'must be an integer'];
        yield 'an integer too large for PHP stays a string and is rejected' => [static fn($d) => $d->collection->interval_seconds->default = '99999999999999999999', '/collection/interval_seconds/default', 'must be an integer'];
        yield 'pointer escapes slashes in keys' => [static fn($d) => $d->metrics[3]->mappings->gnmi->value_map->{'UP/DOWN'} = 'x', '/metrics/3/mappings/gnmi/value_map/UP~1DOWN', 'must be an integer'];
        // Semantic rules the schema cannot express.
        yield 'duplicate metric id' => [static fn($d) => $d->metrics[1]->id = 'interface.in_octets', '/metrics/1/id', 'duplicates /metrics/0/id'];
        yield 'graph names an unknown metric' => [static fn($d) => $d->graphs[0]->metrics[1] = 'interface.out_bits', '/graphs/0/metrics/1', 'not a metric of this pack'];
        yield 'alert names an unknown metric' => [static fn($d) => $d->alerts[0]->metric = 'interface.admin_status', '/alerts/0/metric', 'not a metric of this pack'];
        yield 'default interval below minimum' => [static fn($d) => $d->collection->interval_seconds->default = 5, '/collection/interval_seconds/default', 'at least min (10)'];
        yield 'preferred protocol nothing maps' => [static fn($d) => $d->collection->protocol_preference = ['redfish', 'gnmi', 'snmp'], '/collection/protocol_preference/0', 'no metric maps redfish'];
        yield 'kadupul range excludes the running version' => [static fn($d) => $d->compatibility->kadupul = '>=2.0', '/compatibility/kadupul', 'not satisfied by the running Kadupul version 1.2.35'];
        yield 'kadupul range cannot be parsed' => [static fn($d) => $d->compatibility->kadupul = '>=one', '/compatibility/kadupul', 'cannot be parsed'];
    }

    #[DataProvider('invalidDocuments')]
    public function testEachViolationNamesItsPointerAndRule(\Closure $edit, string $pointer, string $rule): void
    {
        $document = PackDocuments::example();
        $edit($document);

        $violations = (new PackValidator(self::RUNNING))->validate($document);

        $matching = array_filter($violations, static fn(PackViolation $v): bool => $v->pointer === $pointer && str_contains($v->message, $rule));
        self::assertNotEmpty($matching, 'Expected "' . $pointer . ': ...' . $rule . '...", got: ' . implode(' | ', array_map(static fn(PackViolation $v): string => $v->toString(), $violations)));
    }

    public function testADocumentThatIsNotAnObjectIsRejectedAtTheRoot(): void
    {
        $violations = (new PackValidator(self::RUNNING))->validate([]);

        self::assertEquals([new PackViolation('(document)', 'must be an object')], $violations);
    }

    public function testEveryViolationIsReportedAtOnceAndNothingIsBuilt(): void
    {
        $document = PackDocuments::example();
        $document->owner = 'netops';
        $document->metrics[3]->counter_bits = 32;
        $document->collection->interval_seconds->default = 5;

        try {
            (new PackValidator(self::RUNNING))->parse($document, 'packs/interfaces.pack.json');
            self::fail('An invalid pack was accepted.');
        } catch (InvalidPack $e) {
            self::assertSame('packs/interfaces.pack.json', $e->source);
            self::assertSame(
                ['/owner', '/metrics/3/counter_bits', '/collection/interval_seconds/default'],
                array_map(static fn(PackViolation $v): string => $v->pointer, $e->violations),
            );
            self::assertStringContainsString('packs/interfaces.pack.json: /metrics/3/counter_bits: not allowed when type is gauge', $e->getMessage());
        }
    }

    public function testARunningVersionThatIsNotAVersionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PackValidator('unknown');
    }
}
