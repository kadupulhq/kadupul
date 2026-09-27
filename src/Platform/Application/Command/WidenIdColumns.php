<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Command;

use Kadupul\Platform\Application\Port\ColumnWidening;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\ReadModel\WideningEvent;
use Kadupul\Platform\Application\ReadModel\WideningReport;
use Kadupul\Platform\Domain\Schema\ColumnDefinition;
use Kadupul\Platform\Domain\Schema\ColumnVerdict;
use Kadupul\Platform\Domain\Schema\IdColumnPlan;

final readonly class WidenIdColumns
{
    private const string ACTION = 'database.widen-id-columns';

    public function __construct(
        private MaintenanceTarget $target,
        private ColumnWidening $columns,
        private SchemaChangeAudit $audit,
    ) {}

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param bool $apply false builds every statement and runs none
     */
    public function __invoke(bool $local, ?string $operator, bool $apply): WideningReport
    {
        $scope = $this->audit->select($this->target, self::ACTION, $local, $operator, $apply);
        // One read of the target's own schema, so every name sent comes from it
        // and every decision is made before the first statement runs.
        $catalog = $this->columns->catalog($scope->target);
        $tables = $catalog->tables();
        $decisions = IdColumnPlan::decide(array_combine($tables, array_map($catalog->columns(...), $tables)));
        $correlation = $this->audit->correlation();
        $steps = array_map(
            fn(array $decision): array => $decision['verdict'] === null
                ? $this->alter($scope, $decision['table'], $decision['columns'], $apply, $correlation)
                : self::step($decision['table'], $decision['column'], match ($decision['verdict']) {
                    ColumnVerdict::AlreadyWide => WideningEvent::AlreadyWide,
                    ColumnVerdict::Skipped => WideningEvent::Skipped,
                    ColumnVerdict::MissingColumn => WideningEvent::MissingColumn,
                }),
            $decisions,
        );

        return new WideningReport($scope->target === DatabaseTarget::Main, !$apply, $steps);
    }

    /**
     * @param non-empty-list<ColumnDefinition> $columns
     * @return array{table: string, column: ?string, event: WideningEvent, statement: ?string}
     */
    private function alter(MaintenanceScope $scope, string $table, array $columns, bool $apply, string $correlation): array
    {
        $statement = $this->columns->statement($scope->target, $table, $columns);
        if (!$apply) {
            return self::step($table, null, WideningEvent::Planned, $statement);
        }
        $ok = $this->columns->widen($scope->target, $table, $columns);
        $this->audit->statement($correlation, $scope->actor->id, self::ACTION, $scope->target, $table, $ok);

        return self::step($table, null, $ok ? WideningEvent::Widened : WideningEvent::Failed, $statement);
    }

    /** @return array{table: string, column: ?string, event: WideningEvent, statement: ?string} */
    private static function step(string $table, ?string $column, WideningEvent $event, ?string $statement = null): array
    {
        return ['table' => $table, 'column' => $column, 'event' => $event, 'statement' => $statement];
    }
}
