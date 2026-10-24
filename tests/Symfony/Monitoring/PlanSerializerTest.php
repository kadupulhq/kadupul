<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Application\MonitoringPlanner;
use Kadupul\Monitoring\Domain\CollectionEndpoint;
use Kadupul\Monitoring\Domain\CollectionPlan;
use Kadupul\Monitoring\Domain\InvalidCollectionPlan;
use Kadupul\Monitoring\Domain\MonitoredSeries;
use Kadupul\Monitoring\Domain\PlanStatus;
use Kadupul\Monitoring\Infrastructure\Serialization\PlanSerializer;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;
use Kadupul\Tests\Monitoring\Fakes\RecordingExistingMonitoring;
use PHPUnit\Framework\TestCase;

final class PlanSerializerTest extends TestCase
{
    public function testTheShapeCheckAcceptsTheShippedExamplesAndRejectsBrokenVariants(): void
    {
        foreach (['gnmi-interfaces.plan.json', 'snmp-interfaces.plan.json'] as $example) {
            self::assertSame([], PlanDocumentShape::violations($this->example($example)), $example);
        }
        $broken = [
            'secret in endpoint' => static fn($d) => $d->targets[0]->endpoint->password = 'hunter2',
            'snmp with paths' => static fn($d) => $d->targets[0]->subscription->paths = ['/x'],
            'on_change with interval' => static fn($d) => $d->targets[0]->subscription->mode = 'on_change',
            'unknown status' => static fn($d) => $d->status = 'running',
            'gauge with width' => static fn($d) => $d->targets[0]->metrics[3]->counter_bits = 32,
        ];
        foreach ($broken as $name => $edit) {
            $document = $this->example('snmp-interfaces.plan.json');
            $edit($document);
            self::assertNotSame([], PlanDocumentShape::violations($document), $name);
        }
        $missing = $this->example('gnmi-interfaces.plan.json');
        unset($missing->dedup_key);
        self::assertSame(['/dedup_key is required'], PlanDocumentShape::violations($missing));
    }

    public function testRealPlannerOutputSerializesToTheSchemaShape(): void
    {
        foreach ($this->plannerOutput() as $name => $plan) {
            $json = (new PlanSerializer())->toJson($plan);
            $document = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

            self::assertSame([], PlanDocumentShape::violations($document), $name . ': ' . $json);
            self::assertSame($plan->planId, $document->plan_id);
            self::assertSame($plan->dedupKey, $document->dedup_key);
            self::assertSame($plan->status->value, $document->status);
        }
    }

    public function testRealPlannerOutputPassesTheJsonSchemaValidator(): void
    {
        $reason = JsonSchemaCheck::skipReason();
        if ($reason !== null) {
            self::markTestSkipped($reason);
        }
        $serializer = new PlanSerializer();
        $names = array_keys($this->plannerOutput());
        $documents = array_map(static fn(CollectionPlan $p): string => $serializer->toJson($p), array_values($this->plannerOutput()));
        $documents[] = (string) file_get_contents(dirname(PackDocuments::EXAMPLE) . '/gnmi-interfaces.plan.json');
        $documents[] = (string) file_get_contents(dirname(PackDocuments::EXAMPLE) . '/snmp-interfaces.plan.json');

        $verdicts = JsonSchemaCheck::errors(PackDocuments::PLAN_SCHEMA, $documents);

        foreach ($verdicts as $i => $errors) {
            self::assertSame([], $errors, ($names[$i] ?? 'shipped example ' . $i) . ': ' . $documents[$i]);
        }
    }

    public function testThePlanCarriesACredentialReferenceAndNoAddressOrSecret(): void
    {
        $json = (new PlanSerializer())->toJson($this->plannerOutput()['gnmi proposed']);
        $endpoint = json_decode($json, true, 512, JSON_THROW_ON_ERROR)['targets'][0]['endpoint'];

        self::assertSame(['credential_ref' => 'gnmi/core-sw-01', 'port' => 9339, 'tls' => ['verify' => true]], $endpoint);
        self::assertDoesNotMatchRegularExpression('/password|secret|community|token|address/i', $json);
    }

    public function testACredentialReferenceThatLooksLikeASecretValueIsRefused(): void
    {
        $this->expectException(InvalidCollectionPlan::class);
        $this->expectExceptionMessage('credential reference');

        new CollectionEndpoint('public community');
    }

    /** @return array<string, CollectionPlan> */
    private function plannerOutput(): array
    {
        $planner = new MonitoringPlanner(new RecordingExistingMonitoring([42 => [new MonitoredSeries(new ResourceId('interface', 'eth0'), new MetricId('interface.in_octets'), 'legacy:data-query/SNMP - Interface Statistics', 'snmp')]]));
        $gnmi = $planner->plan(PlanningFixture::request([PlanningFixture::openConfig()], deviceId: 43))->plans[0];

        return [
            'gnmi proposed' => $gnmi,
            'gnmi narrowed by an overlap' => $planner->plan(PlanningFixture::request([PlanningFixture::openConfig()]))->plans[0],
            'snmp approved' => $planner->plan(PlanningFixture::request([PlanningFixture::ifMib()], deviceId: 57))->plans[0]->withStatus(PlanStatus::Approved),
        ];
    }

    private function example(string $name): \stdClass
    {
        return json_decode((string) file_get_contents(dirname(PackDocuments::EXAMPLE) . '/' . $name), false, 512, JSON_THROW_ON_ERROR);
    }
}
