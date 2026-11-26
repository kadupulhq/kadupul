<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain\Policy;

use Kadupul\IdentityAccess\Domain\GraphAuthMode;

/**
 * Single domain source of truth for device visibility across user and enabled
 * group policies. No shortcut may bypass explicit device exceptions.
 */
final readonly class DeviceVisibilityPolicy
{
    /**
     * Evaluates whether a device is visible under the configured auth mode.
     * When a device owns graphs, $deviceGraphs carries each graph's
     * (graphId, graphTemplateId) pair so Permissive/Restrictive/GraphTemplateOnly
     * modes evaluate identically to VisibilitySql::predicate().
     *
     * @param list<PrincipalVisibilityProfile> $profiles User profile plus enabled group profiles
     * @param list<array{graphId: int, graphTemplateId: int}> $deviceGraphs
     */
    public function canViewDevice(
        int $deviceId,
        GraphAuthMode $mode,
        array $profiles,
        array $deviceGraphs = [],
    ): bool {
        if ($deviceId < 1 || $profiles === []) {
            return false;
        }

        if ($deviceGraphs === []) {
            return array_any(
                $profiles,
                static fn(PrincipalVisibilityProfile $profile): bool => self::allowsWithoutGraphs($deviceId, $mode, $profile),
            );
        }

        return array_any(
            $profiles,
            static fn(PrincipalVisibilityProfile $profile): bool => array_any(
                $deviceGraphs,
                static fn(array $graph): bool => self::allowsWithGraph($deviceId, $graph['graphId'], $graph['graphTemplateId'], $mode, $profile),
            ),
        );
    }

    private static function allowsWithoutGraphs(int $deviceId, GraphAuthMode $mode, PrincipalVisibilityProfile $profile): bool
    {
        return match ($mode) {
            GraphAuthMode::Permissive, GraphAuthMode::DeviceOnly => $profile->devices->allows($deviceId),
            GraphAuthMode::Restrictive, GraphAuthMode::GraphTemplateOnly => false,
        };
    }

    private static function allowsWithGraph(
        int $deviceId,
        int $graphId,
        int $graphTemplateId,
        GraphAuthMode $mode,
        PrincipalVisibilityProfile $profile,
    ): bool {
        $graph = $profile->graphs->allows($graphId);
        $device = $profile->devices->allows($deviceId);
        $template = $profile->graphTemplates->allows($graphTemplateId);

        return match ($mode) {
            GraphAuthMode::Permissive => $graph || $device || $template,
            GraphAuthMode::Restrictive => $graph || ($device && $template),
            GraphAuthMode::DeviceOnly => $graph || $device,
            GraphAuthMode::GraphTemplateOnly => $graph || $template,
        };
    }
}
