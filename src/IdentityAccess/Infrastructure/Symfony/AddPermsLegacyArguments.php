<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony;

use Kadupul\IdentityAccess\Application\Command\GrantOutcome;
use Kadupul\IdentityAccess\Application\Command\ListOutcome;
use Kadupul\IdentityAccess\Application\Command\PermissionListing;
use Kadupul\IdentityAccess\Domain\ItemType;
use Kadupul\IdentityAccess\Domain\ListTarget;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;

/**
 * add_perms.php read its arguments in order, split at the first "=", and
 * exited on the first it did not know, so translate() is its own loop. The
 * listing flags select kadupul:permission:list; everything else is a grant.
 */
final class AddPermsLegacyArguments extends LegacyArguments
{
    private const array LISTS = [
        '--list-groups' => ListTarget::Groups,
        '--list-users' => ListTarget::Users,
        '--list-trees' => ListTarget::Trees,
        '--list-hosts' => ListTarget::Hosts,
        '--list-graphs' => ListTarget::Graphs,
        '--list-graph-templates' => ListTarget::GraphTemplates,
    ];

    #[\Override]
    protected function flags(): array
    {
        return ['--user-id' => ['user-id', true], '--item-type' => ['item-type', true], '--item-id' => ['item-id', true],
            '--host-id' => ['host-id', true], '--quiet' => ['no-headings', false], '--as' => ['as', true], ...self::versionAndHelp()];
    }

    #[\Override]
    public function translate(array $argv): array
    {
        if ($argv === []) {
            return [[], LegacyRequest::Usage];
        }
        $grant = [];
        $list = null;
        $hostId = null;
        $quiet = false;
        $as = null;
        foreach ($argv as $argument) {
            // strpos() was truthy only for an "=" after the first character.
            $at = strpos($argument, '=');
            [$flag, $value] = $at !== false && $at > 0 ? [substr($argument, 0, $at), substr($argument, $at + 1)] : [$argument, ''];
            switch ($flag) {
                case '--user-id':
                    $grant['--user-id'] = $value;
                    break;
                case '--item-type':
                    if (ItemType::fromOption($value) === null) {
                        throw new InvalidLegacyArgument($argument);
                    }
                    $grant['--item-type'] = $value;
                    break;
                case '--item-id':
                    $grant['--item-id'] = $value;
                    break;
                case '--host-id':
                    $hostId = $value;
                    break;
                case '--quiet':
                    $quiet = true;
                    break;
                case '--as':
                    if ($value === '') {
                        throw new InvalidLegacyArgument($argument);
                    }
                    $as = $value;
                    break;
                case '--version':
                case '-V':
                case '-v':
                    return [[], LegacyRequest::Version];
                case '--help':
                case '-H':
                case '-h':
                    return [[], LegacyRequest::Help];
                default:
                    if (!isset(self::LISTS[$flag])) {
                        throw new InvalidLegacyArgument($argument);
                    }
                    // Only the first listing in the original's own order ran.
                    $list = $this->firstList($list, self::LISTS[$flag]);
            }
        }
        $operator = $as === null ? [] : ['--as' => $as];
        if ($list !== null) {
            return [['target' => $list->value] + ($hostId === null ? [] : ['--host-id' => $hostId]) + ($quiet ? ['--no-headings' => true] : []) + $operator, null];
        }

        return [$grant + $operator, null];
    }

    #[\Override]
    public function route(array $input, string $default): string
    {
        return isset($input['target']) ? 'kadupul:permission:list' : $default;
    }

    #[\Override]
    public function invalid(string $argument): array
    {
        [$flag, $value] = str_contains($argument, '=') ? explode('=', $argument, 2) : [$argument, ''];

        return [$flag === '--item-type' ? 'ERROR: Invalid Item Type: (' . $value . ')' : 'ERROR: Invalid Argument: (' . $flag . ')', ''];
    }

    /** The original's help text after its version line, which the caller prepends. */
    #[\Override]
    public function help(): array
    {
        return ['', 'usage: add_perms.php [ --user-id=[ID] ]', '    --item-type=[graph|tree|host|graph_template]', '    --item-id [--quiet]', '',
            'Where item-id is the id of the object of type item-type', '', 'List Options:', '    --list-users', '    --list-trees',
            '    --list-graph-templates', '    --list-graphs --host-id=[ID]'];
    }

    /**
     * Lines for a grant that stopped before writing. The original printed its
     * error, a blank line and its help, which starts with the version line.
     *
     * @return list<string>
     */
    public function refusal(GrantOutcome $outcome, string $versionLine, ?string $user, ?ItemType $type, ?string $item): array
    {
        $error = match ($outcome) {
            // The original printed the last argument's value here, not the user id.
            GrantOutcome::UserInvalid => 'ERROR: Invalid Userid: (' . ($user ?? '') . ')',
            GrantOutcome::ItemTypeMissing => 'ERROR: --item-type missing. Please specify.',
            GrantOutcome::ItemIdMissing => 'ERROR: --item-id missing. Please specify.',
            GrantOutcome::ItemInvalid => 'ERROR: Invalid ' . ($type?->label() ?? '') . ' item id: (' . ($item ?? '') . ')',
            default => throw new \LogicException('Only a refused grant has a refusal.'),
        };

        return [$error, '', $versionLine, ...$this->help()];
    }

    /**
     * @return array{0: list<string>, 1: bool} the lines and whether the last ends with a newline
     */
    public function listing(PermissionListing $listing, bool $quiet, string $versionLine, ?string $hostId): array
    {
        if ($listing->outcome === ListOutcome::NotImplemented) {
            return [['This option has not yet been implemented'], false];
        }
        if ($listing->outcome === ListOutcome::HostInvalid) {
            return [['ERROR: You must supply a valid host_id before you can list its graphs', 'Try --list-hosts', $versionLine, ...$this->help()], true];
        }
        $lines = $quiet ? [] : [$listing->target->heading()];
        foreach ($listing->rows as $row) {
            // displayHostGraphs() ended each row with a tab as well.
            $lines[] = implode("\t", $row) . ($listing->target === ListTarget::Graphs ? "\t" : '');
        }

        return [$quiet ? $lines : [...$lines, ''], true];
    }

    private function firstList(?ListTarget $current, ListTarget $next): ListTarget
    {
        $order = array_values(self::LISTS);

        return $current === null || array_search($next, $order, true) < array_search($current, $order, true) ? $next : $current;
    }
}
