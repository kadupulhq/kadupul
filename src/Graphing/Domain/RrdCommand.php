<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

/** One RRDtool invocation kept as separate arguments; each transport quotes them its own way. */
final readonly class RrdCommand
{
    /** @param list<string> $arguments */
    public function __construct(public string $verb, public array $arguments)
    {
        // Transports send the verb unquoted, so it has to be a bare word.
        if (preg_match('/^[a-z]+$/D', $verb) !== 1) {
            throw new \InvalidArgumentException('An RRDtool verb is a lowercase word.');
        }
        if (!array_is_list($arguments)) {
            throw new \InvalidArgumentException('RRDtool arguments are an ordered list.');
        }
        foreach ($arguments as $argument) {
            if (!is_string($argument)) {
                throw new \InvalidArgumentException('RRDtool arguments are strings.');
            }
        }
    }
}
