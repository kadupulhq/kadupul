<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\GraphTemplateAssignmentCatalog;
use Kadupul\Graphing\Application\Port\GraphTemplateAttacher;

final readonly class AttachGraphTemplate
{
    private const string ACTION = 'device.graph-template.attach';

    public function __construct(private GraphCommandAccess $access, private GraphTemplateAssignmentCatalog $catalog, private GraphTemplateAttacher $attacher) {}

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param string $deviceId as typed, already known to be numeric
     * @param string $graphTemplateId as typed, already known to be numeric
     * @throws GraphManagementDenied
     */
    public function __invoke(bool $local, ?string $operator, string $deviceId, string $graphTemplateId): GraphTemplateAttachment
    {
        $scope = $this->access->select(self::ACTION, $local, $operator, true, ManagedArea::Devices);
        $device = $this->catalog->findDevice($scope->database, $deviceId);
        if ($device === null) {
            return new GraphTemplateAttachment($scope->database, GraphTemplateAttachment::UNKNOWN_DEVICE);
        }
        $template = $this->catalog->findGraphTemplate($scope->database, $graphTemplateId);
        if ($template === null) {
            return new GraphTemplateAttachment($scope->database, GraphTemplateAttachment::UNKNOWN_TEMPLATE, $device);
        }
        if ($this->catalog->isAssociated($scope->database, $device->id, $template->id)) {
            return new GraphTemplateAttachment($scope->database, GraphTemplateAttachment::ALREADY, $device, $template);
        }
        $correlation = $this->access->correlation();
        $status = GraphTemplateAttachment::FAILED;
        try {
            $this->attacher->attach($scope->database, $device->id, $template->id);
            $status = GraphTemplateAttachment::ATTACHED;
        } catch (\RuntimeException) {
            // Reported as the original's "Failed to add" line; the audit record below says the same.
        } finally {
            $this->access->change($correlation, $scope, self::ACTION, 'device-graph-template', $device->id . '.' . $template->id, $status === GraphTemplateAttachment::ATTACHED);
        }

        return new GraphTemplateAttachment($scope->database, $status, $device, $template);
    }
}
