<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Application\MonitoringPlanner;
use Kadupul\Monitoring\Application\ResolveBinding;
use Kadupul\Monitoring\Domain\BindingStatus;
use Kadupul\Monitoring\Domain\CollectionPlan;
use Kadupul\Monitoring\Domain\MonitoredSeries;
use Kadupul\Monitoring\Domain\PlannedMetric;
use Kadupul\Monitoring\Domain\WriterOwnership;
use Kadupul\Monitoring\Infrastructure\Persistence\InMemoryBindingRepository;
use Kadupul\Monitoring\Infrastructure\Persistence\WriterOwnershipRegistry;
use Kadupul\Monitoring\Infrastructure\Serialization\PlanSerializer;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;
use Kadupul\Tests\Monitoring\Fakes\FixedWriterOwnershipProbe;
use Kadupul\Tests\Monitoring\Fakes\RecordingExistingMonitoring;
use PHPUnit\Framework\TestCase;

final class PlanSchemaV2Test extends TestCase
{
    private const V2_EXAMPLES = ['gnmi-interfaces.plan.v2.json', 'snmp-interfaces.plan.v2.json'];

    public function testAPlanWithAnApprovedBindingIsWrittenAsVersion2WithALogicalReference(): void
    {
        $plan = $this->boundPlan(BindingStatus::Approved);
        $document = json_decode((new PlanSerializer())->toJson($plan), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $document['plan_schema_version']);
        $bound = array_values(array_filter($document['targets'][0]['metrics'], static fn(array $m): bool => isset($m['binding'])));
        self::assertSame([[
            'id' => 'interface.in_octets',
            'type' => 'counter',
            'unit' => 'octets',
            'source' => '/interfaces/interface[name=eth1]/state/counters/in-octets',
            'counter_bits' => 64,
            'labels' => ['name'],
            'binding' => ['binding_id' => BindingFixture::key(identity: 'eth1', protocol: 'gnmi')->bindingId(), 'resource' => ['kind' => 'interface', 'identity' => 'eth1']],
        ]], $bound);
    }

    public function testTheBoundPlanCarriesNoStorageDetail(): void
    {
        $json = (new PlanSerializer())->toJson($this->boundPlan(BindingStatus::Approved));

        self::assertSame([], PlanDocumentShape::violations(json_decode($json, false, 512, JSON_THROW_ON_ERROR)), $json);
        self::assertDoesNotMatchRegularExpression('/rrd|local_data|traffic_in|path_rra|legacy_poller|sql/i', $json);
    }

    public function testAnUnapprovedBindingIsNotReferencedAndThePlanStaysVersion1(): void
    {
        $plan = $this->boundPlan(BindingStatus::Proposed);
        $document = json_decode((new PlanSerializer())->toJson($plan), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $document['plan_schema_version']);
        self::assertSame([], array_filter($plan->targets[0]->metrics, static fn(PlannedMetric $m): bool => $m->binding !== null));
    }

    public function testAWildcardSourceIsNeverBoundToOneResource(): void
    {
        $plan = $this->boundPlan(BindingStatus::Approved);

        foreach ($plan->targets[0]->metrics as $metric) {
            if ($metric->binding !== null) {
                self::assertStringNotContainsString('=*]', $metric->source);
            }
        }
        self::assertCount(1, array_filter($plan->targets[0]->metrics, static fn(PlannedMetric $m): bool => $m->binding !== null));
    }

    public function testBindingAMetricChangesTheDedupKeyButUnboundPlansKeepTheirKey(): void
    {
        $unbound = (new MonitoringPlanner($this->existing()))->plan(PlanningFixture::request([PlanningFixture::openConfig()]))->plans[0];
        $withoutApproval = $this->boundPlan(BindingStatus::Proposed);

        self::assertSame($unbound->dedupKey, $withoutApproval->dedupKey);
        self::assertNotSame($unbound->dedupKey, $this->boundPlan(BindingStatus::Approved)->dedupKey);
    }

    public function testTheShapeCheckAcceptsTheV2ExamplesAndRefusesStorageKeys(): void
    {
        foreach (self::V2_EXAMPLES as $example) {
            self::assertSame([], PlanDocumentShape::violations($this->example($example)), $example);
        }
        foreach ($this->storageLeaks() as $name => $edit) {
            $document = $this->example('snmp-interfaces.plan.v2.json');
            $edit($document);
            self::assertNotSame([], PlanDocumentShape::violations($document), $name);
        }
        $v1 = $this->example('snmp-interfaces.plan.json');
        $v1->targets[0]->metrics[0]->binding = $this->example('snmp-interfaces.plan.v2.json')->targets[0]->metrics[0]->binding;
        self::assertContains('/targets/0/metrics/0/binding is not allowed', PlanDocumentShape::violations($v1), 'version 1 has no bindings');
    }

    public function testTheJsonSchemaValidatorAgreesForV2AndKeepsV1Valid(): void
    {
        $reason = JsonSchemaCheck::skipReason();
        if ($reason !== null) {
            self::markTestSkipped($reason);
        }
        $examples = dirname(PackDocuments::EXAMPLE);
        $valid = array_map(static fn(string $name): string => (string) file_get_contents($examples . '/' . $name), self::V2_EXAMPLES);
        $valid[] = (new PlanSerializer())->toJson($this->boundPlan(BindingStatus::Approved));
        foreach (JsonSchemaCheck::errors(PackDocuments::PLAN_SCHEMA_V2, $valid) as $i => $errors) {
            self::assertSame([], $errors, 'v2 document ' . $i);
        }

        $leaks = [];
        foreach ($this->storageLeaks() as $edit) {
            $document = $this->example('snmp-interfaces.plan.v2.json');
            $edit($document);
            $leaks[] = json_encode($document, JSON_THROW_ON_ERROR);
        }
        foreach (JsonSchemaCheck::errors(PackDocuments::PLAN_SCHEMA_V2, $leaks) as $i => $errors) {
            self::assertNotSame([], $errors, array_keys($this->storageLeaks())[$i]);
        }

        $v1 = [(string) file_get_contents($examples . '/snmp-interfaces.plan.json'), (string) file_get_contents($examples . '/gnmi-interfaces.plan.json')];
        foreach (JsonSchemaCheck::errors(PackDocuments::PLAN_SCHEMA, $v1) as $errors) {
            self::assertSame([], $errors);
        }
        self::assertNotSame([], JsonSchemaCheck::errors(PackDocuments::PLAN_SCHEMA, [$valid[0]])[0], 'a v2 document is not a valid v1 document');
    }

    /** @return array<string, \Closure(\stdClass): void> */
    private function storageLeaks(): array
    {
        return [
            'local_data_id on a metric' => static fn(\stdClass $d) => $d->targets[0]->metrics[0]->local_data_id = 7,
            'rrd name in a binding' => static fn(\stdClass $d) => $d->targets[0]->metrics[0]->binding->rrd_name = 'traffic_in',
            'path in a binding resource' => static fn(\stdClass $d) => $d->targets[0]->metrics[0]->binding->resource->path = '/var/lib/rra/x.rrd',
            'extension rrd path on a target' => static fn(\stdClass $d) => $d->targets[0]->{'x-rrd_path'} = '<path_rra>/x.rrd',
            'extension path at the root' => static fn(\stdClass $d) => $d->{'x-path'} = '/var/lib/rra',
            'uppercase extension on the endpoint' => static fn(\stdClass $d) => $d->targets[0]->endpoint->{'x-RRD'} = 'x',
            'sql extension on the subscription' => static fn(\stdClass $d) => $d->targets[0]->subscription->{'x-sql'} = 'SELECT 1',
            'empty binding id' => static fn(\stdClass $d) => $d->targets[0]->metrics[0]->binding->binding_id = '',
        ];
    }

    private function boundPlan(BindingStatus $status): CollectionPlan
    {
        $bindings = new InMemoryBindingRepository();
        $binding = BindingFixture::binding(key: BindingFixture::key(identity: 'eth1', protocol: 'gnmi'));
        $bindings->save($status === BindingStatus::Proposed ? $binding : $binding->withStatus($status));
        $catalog = new ResolveBinding($bindings, new WriterOwnershipRegistry(new FixedWriterOwnershipProbe([BindingFixture::field()->toString() => WriterOwnership::LegacyPoller])));

        // eth0 is collected by legacy polling, so in_octets is narrowed to eth1 alone.
        return (new MonitoringPlanner($this->existing(), $catalog))->plan(PlanningFixture::request([PlanningFixture::openConfig()]))->plans[0];
    }

    private function existing(): RecordingExistingMonitoring
    {
        return new RecordingExistingMonitoring([42 => [new MonitoredSeries(new ResourceId('interface', 'eth0'), new MetricId('interface.in_octets'), 'legacy:data-query/SNMP - Interface Statistics', 'snmp')]]);
    }

    private function example(string $name): \stdClass
    {
        return json_decode((string) file_get_contents(dirname(PackDocuments::EXAMPLE) . '/' . $name), false, 512, JSON_THROW_ON_ERROR);
    }
}
