<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\TreeCatalog;
use Kadupul\Graphing\Application\Port\TreeWriter;

final readonly class AddTree
{
    private const string ACTION = 'tree.create';

    public function __construct(private GraphCommandAccess $access, private TreeCatalog $catalog, private TreeWriter $writer) {}

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param string $name not empty; one of TreeLabels::SORT_METHODS' keys is $sortMethod
     * @throws GraphManagementDenied
     */
    public function __invoke(bool $local, ?string $operator, string $name, string $sortMethod): TreeCreation
    {
        $scope = $this->access->select(self::ACTION, $local, $operator, true, ManagedArea::Trees);
        $existing = $this->catalog->treeIdByName($scope->database, $name);
        if ($existing !== null) {
            return new TreeCreation($scope->database, TreeCreation::EXISTS, $existing);
        }
        $correlation = $this->access->correlation();
        $treeId = null;
        try {
            $treeId = $this->writer->createTree($scope->database, $name, TreeLabels::SORT_METHODS[$sortMethod]);
        } catch (\RuntimeException) {
            // Reported as a failure; the audit record below says the same.
        } finally {
            $this->access->change($correlation, $scope, self::ACTION, 'tree', (string) ($treeId ?? 0), $treeId !== null);
        }

        return $treeId === null ? new TreeCreation($scope->database, TreeCreation::FAILED) : new TreeCreation($scope->database, TreeCreation::CREATED, $treeId);
    }
}
