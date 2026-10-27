<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Kadupul\Navigation\Contract\AdministrationCatalog;
use Kadupul\Navigation\Contract\Administration\ListFilters;
use Kadupul\Navigation\Contract\Administration\ListScreen;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('kadupul.administration_catalog')]
final readonly class DbalIdentityAccessAdministrationCatalog implements AdministrationCatalog
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
    ) {}

    public function supports(ListScreen $screen): bool
    {
        return in_array($screen, [ListScreen::Users, ListScreen::UserGroups, ListScreen::UserDomains, ListScreen::LoginHistory], true);
    }

    public function read(ListScreen $screen, ListFilters $filters): array
    {
        $source = match ($screen) {
            ListScreen::Users => <<<'SQL'
SELECT u.id, u.username AS name, u.full_name, u.enabled, u.realm AS auth_realm, d.domain_name,
 u.policy_graphs AS graph_policy, u.policy_hosts AS device_policy, u.policy_graph_templates AS template_policy,
 l.last_attempt
 FROM user_auth u
 LEFT JOIN user_domains d ON u.realm = 1000 + d.domain_id
 LEFT JOIN (SELECT user_id, username, MAX(time) AS last_attempt FROM user_log GROUP BY user_id, username) l
 ON l.user_id = u.id AND l.username = u.username
SQL,
            ListScreen::UserGroups => <<<'SQL'
SELECT g.id, g.name, g.description, g.enabled, g.policy_graphs AS graph_policy,
 g.policy_hosts AS device_policy, g.policy_graph_templates AS template_policy, COALESCE(m.members, 0) AS members
 FROM user_auth_group g
 LEFT JOIN (SELECT group_id, COUNT(*) AS members FROM user_auth_group_members GROUP BY group_id) m ON m.group_id = g.id
SQL,
            ListScreen::UserDomains => <<<'SQL'
SELECT d.domain_id AS id, d.domain_name AS name, d.type AS domain_type, d.defdomain AS default_domain,
 u.username AS effective_user, l.cn_full_name, l.cn_email, d.enabled
 FROM user_domains d
 LEFT JOIN user_auth u ON u.id = d.user_id
 LEFT JOIN user_domains_ldap l ON l.domain_id = d.domain_id
SQL,
            ListScreen::LoginHistory => <<<'SQL'
SELECT CONCAT(HEX(l.username), ':', l.user_id, ':', l.time) AS id,
 l.username AS name, u.full_name, u.realm AS auth_realm, l.time AS login_time, l.result, l.ip
 FROM user_log l LEFT JOIN user_auth u ON u.id = l.user_id AND u.username = l.username
SQL,

            default => throw new \InvalidArgumentException('Unsupported administration list.'),
        };
        // Only selected public fields leave this adapter. Sorting uses aliases
        // from the fixed screen definition, never request text as an identifier.
        if (!array_key_exists($filters->sortColumn, $screen->columns())) {
            throw new \InvalidArgumentException('Invalid list sort.');
        }
        $search = match ($screen) {
            ListScreen::Users => ['name', 'full_name'],
            ListScreen::UserGroups => ['name', 'description'],
            ListScreen::UserDomains => ['name', 'domain_type'],
            ListScreen::LoginHistory => ['name', 'full_name', 'login_time', 'ip'],
            default => ['name'],
        };
        $where = implode(' OR ', array_map(static fn(string $column): string => 'listing.' . $column . ' LIKE ?', $search));
        $params = array_fill(0, count($search), '%' . $filters->filter . '%');
        $types = array_fill(0, count($search), ParameterType::STRING);
        return $this->database->fetchAllAssociative(
            'SELECT listing.* FROM (' . $source . ') listing WHERE (' . $where . ') ORDER BY listing.'
                . $filters->sortColumn . ' ' . $filters->sortDirection . ', listing.id ASC LIMIT ? OFFSET ?',
            [...$params, $filters->rows + 1, ($filters->page - 1) * $filters->rows],
            [...$types, ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }
}
