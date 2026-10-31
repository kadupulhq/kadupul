<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

/**
 * What the operator asked add_graphs.php for. The ids and texts stay as typed,
 * because the original looked them up by PHP's array-key and loose-comparison
 * rules; an option not given is the empty string.
 */
final readonly class GraphRequest
{
    /**
     * @param list<string> $snmpFields
     * @param list<string> $snmpValues
     * @param list<string> $snmpValueExpressions
     */
    public function __construct(
        public string $graphType = '',
        public string $graphTitle = '',
        public string $templateId = '',
        public string $hostTemplateId = '',
        public string $hostId = '',
        public string $inputFields = '',
        public string $queryId = '',
        public string $queryType = '',
        public array $snmpFields = [],
        public array $snmpValues = [],
        public array $snmpValueExpressions = [],
        public int $reindexMethod = 1,
        public bool $force = false,
        public bool $listTemplates = false,
        public bool $listInputFields = false,
        public bool $listHosts = false,
        public bool $listQueries = false,
        public bool $listQueryTypes = false,
        public bool $listFields = false,
        public bool $listValues = false,
    ) {}

    /** Whether the run only reads. */
    public function listsOnly(): bool
    {
        return $this->listTemplates || $this->listInputFields || $this->listHosts || $this->listQueries || $this->listQueryTypes || $this->listFields || $this->listValues;
    }
}
