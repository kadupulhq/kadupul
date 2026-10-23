<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Application;

use Kadupul\Monitoring\Application\Port\ExistingMonitoring;
use Kadupul\Monitoring\Contract\BindingCatalog;
use Kadupul\Monitoring\Domain\CollectionPlan;
use Kadupul\Monitoring\Domain\InstalledPack;
use Kadupul\Monitoring\Domain\MonitoredSeries;
use Kadupul\Monitoring\Domain\Overlap;
use Kadupul\Monitoring\Domain\PackGraph;
use Kadupul\Monitoring\Domain\PackMapping;
use Kadupul\Monitoring\Domain\PackMatch;
use Kadupul\Monitoring\Domain\PackState;
use Kadupul\Monitoring\Domain\PlanBinding;
use Kadupul\Monitoring\Domain\PlanChange;
use Kadupul\Monitoring\Domain\PlannedMetric;
use Kadupul\Monitoring\Domain\PlanningProposal;
use Kadupul\Monitoring\Domain\PlanStatus;
use Kadupul\Monitoring\Domain\PlanTarget;
use Kadupul\Monitoring\Domain\ReconciliationReport;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;

/**
 * Works out which installed packs apply to a device and what each would
 * collect, as proposed plans. It holds no store and reads existing monitoring
 * through a port with no write method, so planning cannot activate anything
 * or alter what is already collected.
 *
 * With a binding catalog, a metric whose source yields exactly one resource
 * refers to that resource's approved binding. A wildcard source covers many
 * resources and is left unbound rather than tied to any one of them.
 */
final class MonitoringPlanner
{
    /** Protocols whose paths select list entries with [key=value], so a wildcard can be narrowed. */
    private const PATH_BASED = ['gnmi', 'netconf', 'restconf'];

    public function __construct(
        private readonly ExistingMonitoring $existing,
        private readonly ?BindingCatalog $bindings = null,
    ) {}

    public function plan(PlanningRequest $request): PlanningProposal
    {
        $existing = $this->existing->monitoredSeries($request->deviceId);
        $inUse = [];
        foreach ($request->currentPlans as $plan) {
            if ($plan->status === PlanStatus::Approved || $plan->status === PlanStatus::Active) {
                $inUse[$plan->packName] = true;
            }
        }
        $matches = [];
        $plans = [];
        $overlaps = [];
        foreach ($request->packs as $installed) {
            [$match, $plan, $packOverlaps] = $this->planPack($installed, $request, $existing, isset($inUse[$installed->pack->name]));
            $matches[] = $match;
            if ($plan !== null) {
                $plans[] = $plan;
            }
            array_push($overlaps, ...$packOverlaps);
        }

        return new PlanningProposal($request->deviceId, $matches, $plans, $overlaps);
    }

    /**
     * Compares a fresh proposal with the device's approved and active plans.
     * The current plans are returned as they were; any change still needs
     * approval and activation.
     */
    public function reconcile(PlanningRequest $request): ReconciliationReport
    {
        $proposal = $this->plan($request);
        $current = [];
        foreach ([PlanStatus::Approved, PlanStatus::Active] as $status) {
            foreach ($request->currentPlans as $plan) {
                if ($plan->status === $status) {
                    // Active wins over approved for the same pack: it is what is collecting.
                    $current[$plan->packName] = $plan;
                }
            }
        }
        $proposed = [];
        foreach ($proposal->plans as $plan) {
            $proposed[$plan->packName] = $plan;
        }
        $added = [];
        $changed = [];
        $unchanged = [];
        foreach ($proposed as $pack => $plan) {
            $existing = $current[$pack] ?? null;
            if ($existing === null) {
                $added[] = $plan;
            } elseif ($existing->dedupKey === $plan->dedupKey) {
                $unchanged[] = $existing;
            } else {
                $changed[] = new PlanChange(
                    $existing,
                    $plan,
                    array_values(array_diff($plan->metricRoutes(), $existing->metricRoutes())),
                    array_values(array_diff($existing->metricRoutes(), $plan->metricRoutes())),
                );
            }
        }
        $removed = array_values(array_diff_key($current, $proposed));

        return new ReconciliationReport($proposal, $added, $removed, $changed, $unchanged);
    }

    /**
     * @param list<MonitoredSeries> $existing
     * @return array{PackMatch, ?CollectionPlan, list<Overlap>}
     */
    private function planPack(InstalledPack $installed, PlanningRequest $request, array $existing, bool $inUse): array
    {
        $pack = $installed->pack;
        $version = $pack->version->toString();
        if ($installed->state === PackState::Deprecated && !$inUse) {
            return [new PackMatch($pack->name, $version, false, ['Not selected: the pack is deprecated and is offered only to devices that already use it.']), null, []];
        }

        $needed = $pack->requirement->names();
        $present = [];
        $protocols = [];
        $notes = [];
        foreach ($request->capabilities->capabilities as $capability) {
            $relevant = array_intersect($capability->provides, $needed) !== [];
            if ($request->policy->counts($capability)) {
                array_push($present, ...$capability->provides);
                if ($relevant) {
                    $protocols[$capability->source] = true;
                }
            } elseif ($relevant) {
                $notes[] = sprintf('%s over %s is %s%s', $capability->model->name, $capability->source, $capability->status->value, $capability->reason === null ? '.' : ': ' . $capability->reason);
            }
        }
        $unmet = $pack->requirement->unmet(array_values(array_unique($present)));
        if ($unmet !== null) {
            return [new PackMatch($pack->name, $version, false, ['Not selected: the pack ' . $unmet . '.', ...$notes]), null, []];
        }
        foreach (array_keys($protocols) as $protocol) {
            if (!isset($request->endpoints[$protocol])) {
                unset($protocols[$protocol]);
                $notes[] = sprintf('%s is supported but no credential reference is configured for it.', $protocol);
            }
        }
        $kind = $pack->discovery->resource;
        $resources = array_values(array_filter($request->resources, static fn(ResourceId $r): bool => $r->kind === $kind));
        if ($resources === []) {
            $notes[] = sprintf('Nothing to plan: no %s resources were enumerated for device %d.', $kind, $request->deviceId);

            return [new PackMatch($pack->name, $version, true, $notes), null, []];
        }

        $routes = [];
        $transports = [];
        $excluded = [];
        $overlaps = [];
        foreach ($pack->metrics as $metric) {
            $id = $metric->id->toString();
            $protocol = null;
            foreach ($pack->protocolPreference as $candidate) {
                if (isset($protocols[$candidate]) && $metric->mapping($candidate) !== null) {
                    $protocol = $candidate;
                    break;
                }
            }
            if ($protocol === null) {
                $excluded[$id] = sprintf('no usable protocol maps it (mapped over %s; usable: %s)', implode(', ', array_keys($metric->mappings)), $protocols === [] ? 'none' : implode(', ', array_keys($protocols)));
                continue;
            }
            $mapping = $metric->mapping($protocol);
            assert($mapping !== null);

            $covered = [];
            foreach ($existing as $series) {
                // The pack's own earlier plans are what replanning replaces, not an overlap.
                if ($series->origin === 'pack:' . $pack->name || !$series->metric->equals($metric->id)) {
                    continue;
                }
                foreach ($resources as $resource) {
                    if ($series->resource->equals($resource)) {
                        $covered[$resource->identity][] = $series;
                        $overlaps[] = new Overlap($resource, $metric->id, $series->origin, $series->protocol, $protocol, $pack->name, $request->policy->preferNew);
                    }
                }
            }
            $subscriptions = [$mapping->path];
            $sources = [$mapping->source()];
            $sourceResources = [count($resources) === 1 ? $resources[0] : null];
            if ($covered !== [] && !$request->policy->preferNew) {
                $origins = implode(', ', array_unique(array_map(static fn(MonitoredSeries $s): string => $s->origin, array_merge(...array_values($covered)))));
                $remaining = array_values(array_filter($resources, static fn(ResourceId $r): bool => !isset($covered[$r->identity])));
                if ($remaining === []) {
                    $excluded[$id] = sprintf('already monitored for every %s by %s', $kind, $origins);
                    continue;
                }
                $narrowed = $this->narrow($protocol, $mapping, $pack->discovery->identityKey, $remaining);
                if ($narrowed === null) {
                    $excluded[$id] = sprintf(
                        'already monitored for %s by %s, and a %s source cannot be limited to the remaining %s',
                        implode(', ', array_map('strval', array_keys($covered))),
                        $origins,
                        $protocol,
                        implode(', ', array_map(static fn(ResourceId $r): string => $r->identity, $remaining)),
                    );
                    continue;
                }
                $subscriptions = $sources = $narrowed;
                $sourceResources = $remaining;
            }
            $transports[$id] = $protocol;
            foreach ($sources as $i => $source) {
                $routes[$protocol][] = [
                    new PlannedMetric($metric->id, $metric->type, $metric->unit, $source, $metric->counterBits, $metric->labels, $mapping->scale, $mapping->valueMap, $this->binding($request->deviceId, $metric->id, $sourceResources[$i], $protocol)),
                    $subscriptions[$i],
                ];
            }
        }

        $targets = [];
        foreach ($pack->protocolPreference as $protocol) {
            if (!isset($routes[$protocol])) {
                continue;
            }
            $targets[] = new PlanTarget(
                $protocol,
                $request->endpoints[$protocol],
                $protocol === 'gnmi' ? 'sample' : 'poll',
                $pack->defaultInterval,
                array_values(array_unique(array_map(static fn(array $route): string => $route[1], $routes[$protocol]))),
                array_map(static fn(array $route): PlannedMetric => $route[0], $routes[$protocol]),
            );
        }
        $graphs = array_values(array_filter($pack->graphs, static fn(PackGraph $g): bool => array_diff($g->metrics, array_keys($transports)) === []));
        $match = new PackMatch($pack->name, $version, true, $notes, $transports, $excluded, $resources, $graphs);

        return [$match, $targets === [] ? null : CollectionPlan::propose($request->deviceId, $pack, $targets, $request->generatedAt), $overlaps];
    }

    private function binding(int $deviceId, MetricId $metric, ?ResourceId $resource, string $protocol): ?PlanBinding
    {
        if ($this->bindings === null || $resource === null) {
            return null;
        }
        $resolution = $this->bindings->resolve($deviceId, $metric, $resource, $protocol);

        return $resolution->isResolved() && $resolution->bindingId !== null ? new PlanBinding($resolution->bindingId, $resource) : null;
    }

    /**
     * @param list<ResourceId> $remaining
     * @return list<string>|null one path per remaining resource, or null when the source cannot select resources
     */
    private function narrow(string $protocol, PackMapping $mapping, string $key, array $remaining): ?array
    {
        $wildcard = '[' . $key . '=*]';
        if (!in_array($protocol, self::PATH_BASED, true) || substr_count($mapping->path, $wildcard) !== 1) {
            return null;
        }

        return array_map(
            static fn(ResourceId $r): string => str_replace($wildcard, '[' . $key . '=' . addcslashes($r->identity, '\\]') . ']', $mapping->path),
            $remaining,
        );
    }
}
