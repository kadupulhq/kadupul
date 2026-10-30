<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/** What an operator can list before granting access; the case values are the option names. */
enum ListTarget: string
{
    /** Listed nothing: add_perms.php announced it and never built it. */
    case Groups = 'groups';
    case Users = 'users';
    case Trees = 'trees';
    case Hosts = 'hosts';
    case Graphs = 'graphs';
    case GraphTemplates = 'graph-templates';

    /** The listing's heading in the original, printed unless --quiet. */
    public function heading(): string
    {
        return match ($this) {
            self::Groups => '',
            self::Users => 'Known Users: (id, username, full_name)',
            self::Trees => 'Known Trees: (id, sort method, name)',
            self::Hosts => 'Known Devices: (id, hostname, template, description)',
            self::Graphs => 'Known Device Graphs: (id, name, template)',
            self::GraphTemplates => 'Known Graph Templates: (id, name)',
        };
    }

    /**
     * The keys of a row in --json output, in the order the original printed its columns.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::Groups => [],
            self::Users => ['id', 'username', 'full_name'],
            self::Trees => ['id', 'sort_method', 'name'],
            self::Hosts => ['id', 'hostname', 'host_template_id', 'description'],
            self::Graphs => ['id', 'name', 'template'],
            self::GraphTemplates => ['id', 'name'],
        };
    }
}
