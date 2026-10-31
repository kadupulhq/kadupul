<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** What add_graphs.php reads before and while it creates graphs. */
interface GraphCreationCatalog
{
    /** @return list<CatalogDevice> ordered by id */
    public function devices(OperatorDatabase $database): array;

    /** @return list<CatalogChoice> ordered by id */
    public function graphTemplates(OperatorDatabase $database): array;

    /**
     * The graph templates of a device template, ordered by name; null when the
     * id is not a number, which the original answered like an empty result.
     *
     * @return ?list<CatalogChoice>
     */
    public function graphTemplatesOfDeviceTemplate(OperatorDatabase $database, string $hostTemplateId): ?array;

    /**
     * @param string $templateId as the operator typed it
     * @return list<InputField> once each, in the order found
     */
    public function inputFields(OperatorDatabase $database, string $templateId): array;

    /** @return list<CatalogChoice> ordered by id */
    public function queries(OperatorDatabase $database): array;

    /** @return list<CatalogChoice> ordered by id */
    public function queryTypes(OperatorDatabase $database, int $queryId): array;

    /** @return list<string> the distinct field names, ordered */
    public function snmpFields(OperatorDatabase $database, int $deviceId, ?int $queryId): array;

    /** @return list<string> the distinct values of a field, ordered */
    public function snmpValues(OperatorDatabase $database, int $deviceId, string $field, ?int $queryId): array;

    public function queryAssociated(OperatorDatabase $database, int $deviceId, int $queryId): bool;

    /**
     * A graph of the template on the device that is not one of several; for a data query graph, of that index.
     */
    public function existingGraph(OperatorDatabase $database, int $templateId, int $deviceId, ?int $queryId, ?string $queryIndex): ?int;

    public function firstDataSource(OperatorDatabase $database, int $graphId): ?int;

    /**
     * The data query indexes every filter selects, in the order the database found them.
     * An empty filter list selects every index.
     *
     * @param list<SnmpFilter> $filters
     * @return list<string>
     */
    public function snmpIndexes(OperatorDatabase $database, int $deviceId, int $queryId, array $filters): array;
}
