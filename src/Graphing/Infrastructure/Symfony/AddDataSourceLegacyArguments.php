<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\DataSourceAddition;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;

/**
 * add_datasource.php split each argument at the first "=" that was not its
 * first character, kept a value only for its two value flags, and stopped at
 * the first value that was not numeric. A flag it knew took any "=value" and
 * ignored it. A run with no arguments was not a help request: it failed for
 * the missing device id.
 */
final class AddDataSourceLegacyArguments extends LegacyArguments
{
    #[\Override]
    protected function flags(): array
    {
        return ['--host-id' => ['host-id', true], '--data-template-id' => ['data-template-id', true], ...self::versionAndHelp()];
    }

    #[\Override]
    public function translate(array $argv): array
    {
        $flags = $this->flags();
        $input = [];
        foreach ($argv as $argument) {
            [$flag, $value] = strpos($argument, '=') > 0 ? explode('=', $argument, 2) : [$argument, ''];
            if (!array_key_exists($flag, $flags)) {
                throw new InvalidLegacyArgument($argument);
            }
            [$option] = $flags[$flag];
            if ($option === null) {
                return [[], $this->special($flag)];
            }
            $input['--' . $option] = $value;
            // The original exited at the first value that was not numeric, so nothing after it counts.
            if (!is_numeric($flag === '--host-id' ? trim($value) : $value)) {
                break;
            }
        }

        return [$input, null];
    }

    #[\Override]
    public function help(): array
    {
        return ['usage: add_datasource.php --host-id=[ID] --data-template-id=[ID]', '',
            'Kadupul utility for adding datasources via a command line interface.', '',
            '--host-id=id - The host id',
            '--data-template-id=id - The numerical ID of the data template to be added'];
    }

    /** @return list<string> */
    public function badHostId(): array
    {
        return ['ERROR: You must supply a valid host-id to run this script!'];
    }

    /** @return list<string> */
    public function badDataTemplateId(): array
    {
        return ['ERROR: You must supply a numeric data-template-id!'];
    }

    /** @return list<string> */
    public function missingHostId(): array
    {
        return ['ERROR: You must supply a valid host-id!'];
    }

    /** @return list<string> */
    public function missingDataTemplateId(): array
    {
        return ['ERROR: You must supply a valid data-template-id!'];
    }

    /**
     * The outcome as the original printed it, with the ids as the operator typed them.
     *
     * @return list<string>
     */
    public function outcome(DataSourceAddition $addition, string $hostId, string $dataTemplateId): array
    {
        return match ($addition->status) {
            DataSourceAddition::UNKNOWN_DEVICE => ["ERROR: Unknown Host Id ($hostId)"],
            DataSourceAddition::UNKNOWN_TEMPLATE => ["ERROR: Unknown Data Template Id ($dataTemplateId)"],
            DataSourceAddition::FAILED => ["ERROR: Failed to add the data source for host ($hostId) data template ($dataTemplateId)"],
            default => ['DS Added - DS[' . $addition->id . ']'],
        };
    }
}
