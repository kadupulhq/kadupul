<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\IdentityAccess\Application\Port\PermissionCatalog;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

final readonly class DbalPermissionCatalog implements PermissionCatalog
{
    public function __construct(private Connection $localConnection, private Connection $mainConnection) {}

    #[\Override]
    public function users(OperatorDatabase $database): array
    {
        return array_map(
            static fn(array $row): array => ['id' => self::text($row['id']), 'username' => self::text($row['username']), 'full_name' => self::text($row['full_name'])],
            $this->connection($database)->fetchAllAssociative('SELECT id, username, full_name FROM user_auth ORDER BY id'),
        );
    }

    #[\Override]
    public function trees(OperatorDatabase $database): array
    {
        return array_map(
            static fn(array $row): array => ['id' => self::text($row['id']), 'sort_type' => self::text($row['sort_type']), 'name' => self::text($row['name'])],
            $this->connection($database)->fetchAllAssociative('SELECT id, sort_type, name FROM graph_tree ORDER BY id'),
        );
    }

    #[\Override]
    public function hosts(OperatorDatabase $database): array
    {
        return array_map(
            static fn(array $row): array => ['id' => self::text($row['id']), 'hostname' => self::text($row['hostname']), 'host_template_id' => self::text($row['host_template_id']), 'description' => self::text($row['description'])],
            $this->connection($database)->fetchAllAssociative('SELECT h.id, h.hostname, h.description, h.host_template_id FROM host AS h LEFT JOIN host_template AS ht ON h.host_template_id = ht.id ORDER BY h.id'),
        );
    }

    #[\Override]
    public function hostGraphs(OperatorDatabase $database, int $hostId): array
    {
        return array_map(
            static fn(array $row): array => ['id' => self::text($row['id']), 'name' => self::text($row['name']), 'template_name' => self::text($row['template_name'])],
            $this->connection($database)->fetchAllAssociative(
                'SELECT graph_templates_graph.local_graph_id AS id, graph_templates_graph.title_cache AS name, graph_templates.name AS template_name
                FROM (graph_local, graph_templates_graph)
                LEFT JOIN graph_templates ON (graph_local.graph_template_id = graph_templates.id)
                WHERE graph_local.id = graph_templates_graph.local_graph_id AND graph_local.host_id = ?
                ORDER BY graph_templates_graph.local_graph_id',
                [$hostId],
            ),
        );
    }

    #[\Override]
    public function graphTemplates(OperatorDatabase $database): array
    {
        return array_map(
            static fn(array $row): array => ['id' => self::text($row['id']), 'name' => self::text($row['name'])],
            $this->connection($database)->fetchAllAssociative('SELECT id, name FROM graph_templates ORDER BY id'),
        );
    }

    /** A cell as the text the original printed; a NULL printed as nothing. */
    private static function text(mixed $cell): string
    {
        return is_scalar($cell) ? (string) $cell : '';
    }

    private function connection(OperatorDatabase $database): Connection
    {
        return $database === OperatorDatabase::Main ? $this->mainConnection : $this->localConnection;
    }
}
