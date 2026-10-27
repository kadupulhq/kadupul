<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Diagnostics;

final readonly class DiagnosticFilters
{
    /** @param array<string, string> $query */
    private function __construct(public array $query, public int $page, public int $rows) {}

    public static function fromQuery(array $input, DiagnosticScreen $screen): self
    {
        $defaults = ['filter' => '', 'page' => '1', 'rows' => '25'];
        if ($screen === DiagnosticScreen::PollerCache) {
            $defaults += ['host_id' => '-1', 'template_id' => '-1', 'poller_action' => '-1', 'status' => '-1'];
        } elseif ($screen === DiagnosticScreen::DataQueryCache) {
            $defaults += ['host_id' => '-1', 'snmp_query_id' => '-1', 'with_index' => '0'];
        }
        foreach ($input as $key => $value) {
            if (!array_key_exists($key, $defaults) || !is_string($value)) {
                throw new \InvalidArgumentException('Invalid diagnostic filters.');
            }
        }
        $query = array_replace($defaults, $input);
        if (!preg_match('//u', $query['filter']) || mb_strlen($query['filter'], 'UTF-8') > 512 || preg_match('/[\x00-\x1f\x7f]/', $query['filter'])) {
            throw new \InvalidArgumentException('Invalid diagnostic filters.');
        }
        foreach (['page' => [1, 99999], 'host_id' => [-1, 16777215], 'template_id' => [-1, 16777215], 'snmp_query_id' => [-1, 16777215], 'poller_action' => [-1, 2], 'status' => [-1, 1], 'with_index' => [0, 1]] as $key => [$min, $max]) {
            if (isset($query[$key]) && (!preg_match('/\A(?:0|[1-9][0-9]{0,8}|-1)\z/D', $query[$key]) || (int) $query[$key] < $min || (int) $query[$key] > $max)) {
                throw new \InvalidArgumentException('Invalid diagnostic filters.');
            }
        }
        if (!in_array($query['rows'], ['10', '25', '50', '100'], true)) {
            throw new \InvalidArgumentException('Invalid diagnostic filters.');
        }
        return new self($query, (int) $query['page'], (int) $query['rows']);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->rows;
    }
}
