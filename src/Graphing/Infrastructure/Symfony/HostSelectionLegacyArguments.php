<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;

/**
 * The arguments of the batch scripts that select devices with --host-id:
 * poller_graphs_reapply_names.php, poller_data_sources_reapply_names.php and
 * reorder_data_query.php. Each accepted a flag with no value or an empty one
 * and rejected it later with its own message, so translate() passes empty
 * values through instead of refusing them as the base map does. They shared
 * the device and debug flags, the error for a run without arguments and the
 * layout of their help.
 */
abstract class HostSelectionLegacyArguments extends LegacyArguments
{
    /**
     * The flags beside -id, --host-id, --debug and -d.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    abstract protected function selectionFlags(): array;

    abstract protected function usage(): string;

    abstract protected function description(): string;

    /** @return list<string> */
    abstract protected function required(): array;

    /** @return list<string> */
    abstract protected function optional(): array;

    #[\Override]
    final protected function flags(): array
    {
        return [
            '-id' => ['host-id', true],
            '--host-id' => ['host-id', true],
            ...$this->selectionFlags(),
            '--debug' => ['debug', false],
            '-d' => ['debug', false],
            ...self::versionAndHelp(),
        ];
    }

    #[\Override]
    final public function translate(array $argv): array
    {
        return $this->translateUnchecked($argv);
    }

    #[\Override]
    final public function help(): array
    {
        return ['', $this->usage(), '', $this->description(), '', 'Required:', ...$this->required(), '', 'Optional:', ...$this->optional(), ''];
    }

    /**
     * What the original printed for a run without arguments: an error, its
     * version line and its help.
     *
     * @return list<string>
     */
    final public function noInput(string $versionLine): array
    {
        return ['ERROR: You must supply input parameters', '', $versionLine, ...$this->help()];
    }
}
