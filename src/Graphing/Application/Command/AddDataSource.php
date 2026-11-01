<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\DataSourceCreationCatalog;
use Kadupul\Graphing\Application\Port\DataSourceCreator;

final readonly class AddDataSource
{
    private const string ACTION = 'data-source.add';

    public function __construct(private GraphCommandAccess $access, private DataSourceCreationCatalog $catalog, private DataSourceCreator $creator) {}

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param string $deviceId as typed and trimmed, already known to be numeric; a value of zero means no device
     * @param string $dataTemplateId as typed, already known to be numeric; a value of zero means no template
     * @throws GraphManagementDenied
     */
    public function __invoke(bool $local, ?string $operator, string $deviceId, string $dataTemplateId): DataSourceAddition
    {
        // data_sources.php and host.php are the web pages that add a data source: realm 3.
        $scope = $this->access->select(self::ACTION, $local, $operator, true, ManagedArea::Devices);
        $device = null;
        if ((float) $deviceId !== 0.0) {
            $device = $this->catalog->findDevice($scope->database, $deviceId);
            if ($device === null) {
                return new DataSourceAddition($scope->database, DataSourceAddition::UNKNOWN_DEVICE);
            }
        }
        $template = null;
        if ((float) $dataTemplateId !== 0.0) {
            $template = $this->catalog->findDataTemplate($scope->database, $dataTemplateId);
            if ($template === null) {
                return new DataSourceAddition($scope->database, DataSourceAddition::UNKNOWN_TEMPLATE, $device);
            }
        }
        $correlation = $this->access->correlation();
        $id = null;
        try {
            // The original pushed the device's fields out unless its id was the text "0"; "0.0" still did.
            $id = $this->creator->create($scope->database, $device->id ?? 0, $template->id ?? 0, $deviceId !== '0');
        } catch (\RuntimeException) {
            // Reported as a failure; the audit record below says the same.
        } finally {
            $this->access->change($correlation, $scope, self::ACTION, 'data-source', (string) ($id ?? 0), $id !== null);
        }

        return $id === null
            ? new DataSourceAddition($scope->database, DataSourceAddition::FAILED, $device, $template)
            : new DataSourceAddition($scope->database, DataSourceAddition::ADDED, $device, $template, $id);
    }
}
