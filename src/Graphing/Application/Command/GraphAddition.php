<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Graphing\Application\Port\InputField;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** How a run of add_graphs.php ended, with whatever the command prints for that ending. */
final readonly class GraphAddition
{
    public const string DONE = 'done';
    public const string FAILED = 'failed';
    public const string UNKNOWN_QUERY = 'unknown-query';
    public const string UNKNOWN_QUERY_TYPE = 'unknown-query-type';
    public const string UNKNOWN_HOST = 'unknown-host';
    public const string UNKNOWN_TEMPLATE = 'unknown-template';
    public const string UNKNOWN_FIELD = 'unknown-field';
    public const string UNKNOWN_VALUE = 'unknown-value';
    public const string UNKNOWN_INPUT = 'unknown-input';
    public const string BAD_HOST_TEMPLATE = 'bad-host-template';
    public const string NEEDS_TEMPLATE = 'needs-template';
    public const string BOTH_VALUE_KINDS = 'both-value-kinds';
    public const string VALUE_COUNT = 'value-count';
    public const string EXPRESSION_COUNT = 'expression-count';
    public const string NEEDS_VALUE = 'needs-value';
    public const string NEEDS_FIELD = 'needs-field';
    public const string NEEDS_OPTIONS = 'needs-options';
    public const string NO_INDEXES = 'no-indexes';
    public const string BAD_TYPE = 'bad-type';
    public const string EXISTS = 'exists';
    public const string DEVICES = 'devices';
    public const string TEMPLATES = 'templates';
    public const string INPUT_FIELDS = 'input-fields';
    public const string QUERIES = 'queries';
    public const string QUERY_TYPES = 'query-types';
    public const string FIELDS = 'fields';
    public const string VALUES = 'values';

    /**
     * @param string $subject the typed text an error names: an id, a field, a value
     * @param string $detail a second typed text: the hostname of a device with no matching index
     * @param list<CatalogChoice> $choices
     * @param list<CatalogDevice> $devices
     * @param list<InputField> $inputFields
     * @param list<string> $names field names, or the values of a field
     * @param int $repeats how many times the values were printed
     * @param list<GraphResult> $graphs
     */
    public function __construct(
        public OperatorDatabase $database,
        public string $status,
        public string $subject = '',
        public string $detail = '',
        public array $choices = [],
        public array $devices = [],
        public array $inputFields = [],
        public array $names = [],
        public int $repeats = 0,
        public array $graphs = [],
    ) {}
}
