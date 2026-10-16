<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Application\Port\HelpTopics;
use Kadupul\Platform\Application\ReadModel\HelpTopic;
use Kadupul\Platform\Contract\DatabaseConnection;

/** Reuse the existing documentation map and the Local Page Help Only setting. */
final readonly class LegacyHelpTopics implements HelpTopics
{
    private const array TOPICS = [
        'Get started' => 'Devices.html',
        'Graphs' => 'Graphs.html',
        'Templates' => 'Graph-Templates.html',
        'Data queries' => 'Data-Queries.html',
        'Remote data collectors' => 'Data-Collectors.html',
        'Users and permissions' => 'User-Management.html',
        'Plugins' => 'Plugins.html',
        'Troubleshoot missing data' => 'Data-Debug.html',
    ];

    public function __construct(private string $projectDir, private DatabaseConnection $database, private InstallationConfiguration $configuration) {}

    public function all(): array
    {
        require_once __DIR__ . '/../../../../lib/documentation.php';
        $local = $this->database->get()->query("SELECT value FROM settings WHERE name = 'local_documentation'")->fetchColumn() === 'on';
        $topics = [];
        foreach (self::TOPICS as $label => $page) {
            $url = $local
                ? (is_file($this->projectDir . '/docs/' . $page) ? $this->configuration->urlPath() . '/docs/' . $page : null)
                : \cacti_documentation_url($page);
            $topics[] = new HelpTopic($label, $url);
        }

        return $topics;
    }
}
