<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Port\GraphTemplateInputCatalog;
use Kadupul\Graphing\Domain\GraphTemplateInput;
use Kadupul\Graphing\Domain\GraphTemplateInputItem;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(GraphTemplateInputCatalog::class)]
final readonly class DbalGraphTemplateInputCatalog implements GraphTemplateInputCatalog
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
    ) {}

    public function templateName(int $templateId): ?string
    {
        $name = $this->database->fetchOne('SELECT name FROM graph_templates WHERE id = ?', [$templateId]);
        return $name === false ? null : self::text($name);
    }

    public function find(int $templateId, int $inputId): ?GraphTemplateInput
    {
        return self::input($this->database, $templateId, $inputId, '');
    }

    public function items(int $templateId, ?int $inputId): array
    {
        // One query, as the legacy page read the list; the join is scoped to
        // the input so another input's associations never mark an item.
        $rows = $this->database->fetchAllAssociative('SELECT gti.id, gti.graph_type_id, gti.consolidation_function_id, gtid.graph_template_input_id
            FROM graph_templates_item gti
            LEFT JOIN graph_template_input_defs gtid ON gtid.graph_template_item_id = gti.id AND gtid.graph_template_input_id = ?
            WHERE gti.local_graph_id = 0 AND gti.graph_template_id = ?
            ORDER BY gti.sequence, gti.id', [$inputId ?? 0, $templateId]);
        $items = [];
        foreach ($rows as $index => $row) {
            $items[] = new GraphTemplateInputItem(self::int($row['id']), $index + 1, self::int($row['graph_type_id']), self::int($row['consolidation_function_id']), $row['graph_template_input_id'] !== null);
        }
        return $items;
    }

    /**
     * The input and its item set, read with the caller's lock suffix; shared
     * with the editor so a revision is computed from the same columns.
     */
    public static function input(Connection $database, int $templateId, int $inputId, string $lock): ?GraphTemplateInput
    {
        $row = $database->fetchAssociative('SELECT id, graph_template_id, name, description, column_name FROM graph_template_input WHERE id = ? AND graph_template_id = ?' . $lock, [$inputId, $templateId]);
        if ($row === false) {
            return null;
        }
        $items = array_map(self::int(...), $database->fetchFirstColumn('SELECT graph_template_item_id FROM graph_template_input_defs WHERE graph_template_input_id = ? ORDER BY graph_template_item_id' . $lock, [$inputId]));
        return new GraphTemplateInput(self::int($row['id']), self::int($row['graph_template_id']), self::text($row['name']), self::text($row['description'] ?? ''), self::text($row['column_name']), $items);
    }

    private static function int(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/\A-?[0-9]+\z/D', $value) === 1)) {
            throw new \UnexpectedValueException('The graph template tables returned an unexpected row.');
        }
        return (int) $value;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }
}
