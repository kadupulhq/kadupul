<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/**
 * A stored pack. Draft and validated are not stored states: a document is
 * either rejected by PackValidator or stored here as installed.
 */
final readonly class InstalledPack
{
    public function __construct(public MonitoringPack $pack, public PackState $state) {}

    public function withState(PackState $state): self
    {
        return new self($this->pack, $state);
    }

    /**
     * The newer version of this pack, in the same state, plus what changed
     * between them. Breaking changes without a major version are refused.
     */
    public function upgradeTo(MonitoringPack $next): PackUpgrade
    {
        $current = $this->pack;
        if ($next->name !== $current->name) {
            throw new PackLifecycleViolation(sprintf('Pack %s cannot be upgraded with pack %s.', $current->name, $next->name));
        }
        if ($next->version->compare($current->version) <= 0) {
            throw new PackLifecycleViolation(sprintf('Pack %s %s is not newer than %s, which is installed; versions only move forward.', $next->name, $next->version->toString(), $current->version->toString()));
        }
        $removed = array_values(array_diff($current->metricIds(), $next->metricIds()));
        $added = array_values(array_diff($next->metricIds(), $current->metricIds()));
        $changed = [];
        foreach ($current->metrics as $metric) {
            $replacement = $next->metric($metric->id->toString());
            if ($replacement !== null && $replacement->signature() !== $metric->signature()) {
                $changed[] = $metric->id->toString();
            }
        }
        $breaking = [...$removed, ...$changed];
        if ($breaking !== [] && $next->version->major === $current->version->major) {
            throw new PackLifecycleViolation(sprintf(
                'Pack %s %s removes or changes the type, unit or counter width of %s; stored history refers to these ids, so this needs a new major version, not %s.',
                $next->name,
                $current->version->toString(),
                implode(', ', $breaking),
                $next->version->toString(),
            ));
        }

        return new PackUpgrade($current->version, new self($next, $this->state), $added, $removed, $changed);
    }
}
