<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain\Policy;

use Kadupul\IdentityAccess\Domain\GraphAuthMode;

/**
 * Evaluates graph visibility across user and enabled group profiles under the
 * four installation authorization modes (Permissive, Restrictive, DeviceOnly,
 * GraphTemplateOnly).
 */
final readonly class GraphVisibilityPolicy
{
    /**
     * @param list<PrincipalVisibilityProfile> $profiles User profile plus enabled group profiles
     */
    public function canViewGraph(
        int $graphId,
        int $deviceId,
        int $graphTemplateId,
        GraphAuthMode $mode,
        array $profiles,
    ): bool {
        if ($graphId < 1 || $profiles === []) {
            return false;
        }

        return array_any(
            $profiles,
            static function (PrincipalVisibilityProfile $profile) use ($graphId, $deviceId, $graphTemplateId, $mode): bool {
                $graph = $profile->graphs->allows($graphId);
                $device = $deviceId > 0 && $profile->devices->allows($deviceId);
                $template = $graphTemplateId > 0 && $profile->graphTemplates->allows($graphTemplateId);

                return match ($mode) {
                    GraphAuthMode::Permissive => $graph || $device || $template,
                    GraphAuthMode::Restrictive => $graph || ($device && $template),
                    GraphAuthMode::DeviceOnly => $graph || $device,
                    GraphAuthMode::GraphTemplateOnly => $graph || $template,
                };
            },
        );
    }
}
