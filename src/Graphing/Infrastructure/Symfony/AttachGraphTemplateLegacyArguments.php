<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\GraphTemplateAttachment;
use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;

/**
 * add_graph_template.php split each argument at the first "=" that was not its
 * first character, kept a value only for the two id flags, and stopped at the
 * first id that was not numeric. A flag it knew took any "=value" and ignored it.
 * --quiet is the console's own option, so it reaches the command as --no-headers.
 */
final class AttachGraphTemplateLegacyArguments extends LegacyArguments
{
    #[\Override]
    protected function flags(): array
    {
        return ['-d' => [null, false], '--host-id' => ['host-id', true], '--graph-template-id' => ['graph-template-id', true],
            '--list-hosts' => ['list-hosts', false], '--list-graph-templates' => ['list-graph-templates', false], '--quiet' => ['no-headers', false],
            ...self::versionAndHelp()];
    }

    #[\Override]
    public function translate(array $argv): array
    {
        if ($argv === []) {
            return [[], LegacyRequest::Help];
        }
        $flags = $this->flags();
        $input = [];
        foreach ($argv as $argument) {
            [$flag, $value] = strpos($argument, '=') > 0 ? explode('=', $argument, 2) : [$argument, ''];
            if (!array_key_exists($flag, $flags)) {
                throw new InvalidLegacyArgument($argument);
            }
            if ($flag === '-d') {
                continue;
            }
            [$option, $takesValue] = $flags[$flag];
            if ($option === null) {
                return [[], $this->special($flag)];
            }
            $input['--' . $option] = $takesValue ? $value : true;
            // The original exited at the first id that was not numeric, so nothing after it counts.
            if ($flag === '--host-id' && !is_numeric(trim($value)) || $flag === '--graph-template-id' && !is_numeric($value)) {
                break;
            }
        }

        return [$input, null];
    }

    #[\Override]
    public function invalid(string $argument): array
    {
        return ['ERROR: Invalid Argument: (' . (strpos($argument, '=') > 0 ? explode('=', $argument, 2)[0] : $argument) . ')', ''];
    }

    #[\Override]
    public function help(): array
    {
        return ['', 'usage: add_graph_template.php --host-id=[ID] --graph-template-id=[ID]', '    [--quiet]', '',
            'Required:',
            '    --host-id             the numerical ID of the host',
            '    --graph-template-id   the numerical ID of the graph template to be added', '',
            'List Options:',
            '    --list-hosts',
            '    --list-graph-templates',
            '    --quiet - batch mode value return', ''];
    }

    /** @return list<string> */
    public function badHostId(): array
    {
        return ['ERROR: You must supply a valid host-id to run this script!'];
    }

    /** @return list<string> */
    public function badGraphTemplateId(): array
    {
        return ['ERROR: You must supply a numeric graph-template-id for all hosts!'];
    }

    /** @return list<string> */
    public function missingHostId(): array
    {
        return ['ERROR: You must supply a valid host-id for all hosts!'];
    }

    /** @return list<string> */
    public function missingGraphTemplateId(): array
    {
        return ['ERROR: You must supply a valid data-query-id for all hosts!'];
    }

    /**
     * @param list<CatalogDevice> $devices
     * @return list<string>
     */
    public function devices(array $devices, bool $quiet): array
    {
        return [...$quiet ? [] : ['Known Devices: (id, hostname, template, description)'],
            ...array_map(static fn(CatalogDevice $device): string => $device->id . "\t" . $device->hostname . "\t" . $device->hostTemplateId . "\t" . $device->description, $devices),
            ...$quiet ? [] : ['']];
    }

    /**
     * @param list<CatalogChoice> $templates
     * @return list<string>
     */
    public function graphTemplates(array $templates, bool $quiet): array
    {
        return [...$quiet ? [] : ['Known Graph Templates: (id, name)'],
            ...array_map(static fn(CatalogChoice $template): string => $template->id . "\t" . $template->name, $templates),
            ...$quiet ? [] : ['']];
    }

    /**
     * The outcome as the original printed it, with the ids as the operator typed them.
     *
     * @return list<string>
     */
    public function outcome(GraphTemplateAttachment $attachment, string $hostId, string $graphTemplateId): array
    {
        $pair = static fn(string $label): string => $label . ' (' . $hostId . ': ' . ($attachment->device->hostname ?? '') . ') - graph-template: (' . $graphTemplateId . ': ' . ($attachment->template->name ?? '') . ')';

        return match ($attachment->status) {
            GraphTemplateAttachment::UNKNOWN_DEVICE => ["ERROR: Unknown Host Id ($hostId)"],
            GraphTemplateAttachment::UNKNOWN_TEMPLATE => ["ERROR: Unknown Graph Template Id ($graphTemplateId)"],
            GraphTemplateAttachment::ALREADY => [$pair('ERROR: Graph Template is already associated for host:')],
            GraphTemplateAttachment::FAILED => [$pair('ERROR: Failed to add this graph template for host:')],
            default => [$pair('Success: Graph Template associated for host:')],
        };
    }
}
