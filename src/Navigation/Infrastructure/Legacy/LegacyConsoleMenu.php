<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\RealmGrants;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The console menu for Symfony pages, exposed to Twig as console_menu().
 *
 * The menu is display only. Every route still authorizes its own request, so
 * hiding an entry here never grants or removes access.
 */
final class LegacyConsoleMenu
{
    /**
     * The online $menu from include/global_arrays.php, keyed by
     * page with the realm $user_auth_realm_filenames assigns it. Symfony
     * requests do not load the legacy bootstrap that builds that array, so
     * ConsoleMenuTest keeps this copy in step with the source.
     */
    public const array SECTIONS = [
        'Main Console' => [
            'index.php' => ['Console Page', 8],
        ],
        'Create' => [
            'graphs_new.php' => ['New Graphs', 5],
            'app.php/inventory/devices/new' => ['New Device', 3],
        ],
        'Management' => [
            'host.php' => ['Devices', 3],
            'sites.php' => ['Sites', 3],
            'tree.php' => ['Trees', 4],
            'graphs.php' => ['Graphs', 5],
            'data_sources.php' => ['Data Sources', 3],
            'aggregate_graphs.php' => ['Aggregates', 5],
        ],
        'Data Collection' => [
            'pollers.php' => ['Data Collectors', 3],
            'data_queries.php' => ['Data Queries', 13],
            'data_input.php' => ['Data Input Methods', 2],
        ],
        'Templates' => [
            'host_templates.php' => ['Device', 12],
            'graph_templates.php' => ['Graph', 10],
            'data_templates.php' => ['Data Source', 11],
            'aggregate_templates.php' => ['Aggregate', 5],
            'color_templates.php' => ['Color', 5],
        ],
        'Automation' => [
            'automation_networks.php' => ['Networks', 23],
            'automation_devices.php' => ['Discovered Devices', 23],
            'automation_templates.php' => ['Device Rules', 23],
            'automation_graph_rules.php' => ['Graph Rules', 23],
            'automation_tree_rules.php' => ['Tree Rules', 23],
        ],
        'Presets' => [
            'data_source_profiles.php' => ['Data Profiles', 9],
            'automation_snmp.php' => ['SNMP', 23],
            'cdef.php' => ['CDEFs', 14],
            'vdef.php' => ['VDEFs', 14],
            'color.php' => ['Colors', 5],
            'gprint_presets.php' => ['GPRINTs', 5],
        ],
        'Import/Export' => [
            'templates_import.php' => ['Import Templates', 17],
            'package_import.php' => ['Import Packages', 17],
            'templates_export.php' => ['Export Templates', 16],
        ],
        'Configuration' => [
            'settings.php' => ['Settings', 15],
            'user_admin.php' => ['Users', 1],
            'user_group_admin.php' => ['User Groups', 1],
            'user_domains.php' => ['User Domains', 1],
        ],
        'Utilities' => [
            'utilities.php' => ['System Utilities', 15],
            'links.php' => ['External Links', 15],
        ],
        'Troubleshooting' => [
            'data_debug.php' => ['Data Sources', 15],
        ],
    ];

    /** The $menu global_arrays.php builds for a collector that is not online. */
    public const array OFFLINE_SECTIONS = [
        'Management' => [
            'app.php/inventory/devices' => ['Devices', 3],
        ],
        'Data Collection' => [
            'pollers.php' => ['Data Collectors', 3],
        ],
        'Configuration' => [
            'settings.php' => ['Settings', 15],
        ],
        'Utilities' => [
            'utilities.php' => ['System Utilities', 15],
        ],
    ];

    /** Console-style external links use realm 10000 + link id, as in draw_menu(). */
    private const int LINK_REALM_OFFSET = 10000;

    /** @var list<array{label: string, items: list<array{label: string, path: string}>}>|null */
    private ?array $sections = null;

    public function __construct(
        private readonly ConsoleAccess $console,
        private readonly LegacyConfiguration $configuration,
        private readonly DatabaseConnection $database,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * Sections the signed-in console user may see, with translated labels.
     * Sections without a visible item are omitted.
     *
     * @return list<array{label: string, items: list<array{label: string, path: string}>}>
     */
    #[AsTwigFunction('console_menu')]
    public function sections(): array
    {
        return $this->sections ??= $this->build();
    }

    /**
     * Accept only a page below the installation root: a PHP script name with an
     * optional route path and query. Schemes, protocol-relative and absolute
     * paths, traversal, backslashes and control characters are rejected.
     */
    public static function isAppPath(string $path): bool
    {
        return preg_match('~\A[a-z0-9_]+\.php(?:/[a-z0-9_-]+)*(?:\?[a-z0-9_]+=[0-9]+)?\z~D', $path) === 1;
    }

    /** @return list<array{label: string, items: list<array{label: string, path: string}>}> */
    private function build(): array
    {
        // Grants come from the adapter that identified the console actor, so a
        // replacement that cannot report them hides the menu.
        if (!$this->console instanceof RealmGrants) {
            return [];
        }
        $definition = $this->primaryOnline() ? self::SECTIONS : self::OFFLINE_SECTIONS;
        try {
            $actor = $this->console->consoleActor();
            if ($actor === null) {
                return [];
            }
            $links = $this->consoleLinks();
            $realms = [];
            foreach ($definition as $items) {
                foreach ($items as [, $realm]) {
                    $realms[] = $realm;
                }
            }
            foreach ($links as $link) {
                $realms[] = self::LINK_REALM_OFFSET + $link['id'];
            }
            $granted = array_flip($this->console->grantedRealms($actor, $realms));
        } catch (\RuntimeException) {
            // The page's own authorization has already passed; a failed
            // display lookup hides the menu instead of failing the page.
            return [];
        }

        $sections = [];
        foreach ($definition as $section => $items) {
            $visible = [];
            foreach ($items as $path => [$label, $realm]) {
                if (isset($granted[$realm])) {
                    $visible[] = ['label' => $this->translator->trans($label, [], 'menu'), 'path' => $path];
                }
            }
            $sections[$section] = ['label' => $this->translator->trans($section, [], 'menu'), 'items' => $visible];
        }

        foreach ($links as $link) {
            if (!isset($granted[self::LINK_REALM_OFFSET + $link['id']])) {
                continue;
            }
            // Operator-entered names are shown as stored, never translated.
            $section = $link['section'] !== '' ? $link['section'] : 'External Links';
            $sections[$section] ??= ['label' => $link['section'] !== '' ? $link['section'] : $this->translator->trans('External Links', [], 'menu'), 'items' => []];
            $sections[$section]['items'][] = ['label' => $link['title'], 'path' => 'link.php?id=' . $link['id']];
        }

        $menu = [];
        foreach ($sections as $section) {
            $section['items'] = array_values(array_filter($section['items'], static fn(array $item): bool => self::isAppPath($item['path'])));
            if ($section['items'] !== []) {
                $menu[] = $section;
            }
        }

        return $menu;
    }

    /**
     * Legacy pages show the full menu on the primary and on a remote collector
     * whose $config['connection'] is online. For a remote collector, values()
     * returns only after confirming the primary is reachable and no recovery is
     * pending, and refuses otherwise, so a refusal selects the offline menu.
     */
    private function primaryOnline(): bool
    {
        try {
            $this->configuration->values();
        } catch (\RuntimeException) {
            return false;
        }

        return true;
    }

    /** @return list<array{id: int, title: string, section: string}> */
    private function consoleLinks(): array
    {
        try {
            $query = $this->database->get()->query("SELECT id, title, extendedstyle FROM external_links
                WHERE style = 'CONSOLE' AND enabled = 'on'
                ORDER BY extendedstyle, sortorder, id");
        } catch (\RuntimeException) {
            // draw_menu() skips links when the table is absent; so does this.
            return [];
        }
        $links = [];
        foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $links[] = ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'section' => (string) ($row['extendedstyle'] ?? '')];
        }

        return $links;
    }
}
