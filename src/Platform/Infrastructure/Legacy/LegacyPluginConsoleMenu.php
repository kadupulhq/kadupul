<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Contract\PluginConsoleMenu;
use Kadupul\Platform\Infrastructure\Symfony\ConsoleTranslations;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsAlias(PluginConsoleMenu::class)]
final readonly class LegacyPluginConsoleMenu implements PluginConsoleMenu
{
    private const int MAX_ENTRIES = 1000;
    private const int MAX_LENGTH = 1024;
    // A failing worker would otherwise cost its full timeout on every page.
    private const int FAILURE_SECONDS = 60;

    /**
     * Everything a config_arrays hook can reasonably depend on, read in one
     * query. HEX() keeps the UNION free of column collation conflicts on
     * MariaDB and works unchanged on SQLite. The internal hook only adds
     * plugins.php, which the Symfony menu already lists.
     */
    private const string FINGERPRINT = "SELECT 'h', HEX(name), HEX(function), HEX(file) FROM plugin_hooks
            WHERE hook = 'config_arrays' AND status = 1 AND name <> 'internal'
        UNION ALL SELECT 'c', HEX(directory), HEX(status), HEX(version) FROM plugin_config
        UNION ALL SELECT 'r', HEX(id), HEX(plugin), HEX(file) FROM plugin_realms
        UNION ALL SELECT 'u', HEX(realm_id), '', '' FROM user_auth_realm WHERE user_id = ?
        UNION ALL SELECT 'g', HEX(r.realm_id), '', '' FROM user_auth_group_realm r
            INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
            INNER JOIN user_auth_group g ON g.id = r.group_id
            WHERE g.enabled = 'on' AND m.user_id = ?";

    /**
     * @param \Closure(): LegacyBrowserWorker $worker built only on a cache
     *     miss, so every page does not construct the worker's services
     */
    public function __construct(
        #[AutowireServiceClosure(LegacyBrowserWorker::class)]
        private \Closure $worker,
        private DatabaseConnection $database,
        #[Autowire(service: 'cache.plugin_console_menu')]
        private CacheInterface $cache,
        private RequestStack $requests,
        private LocalePreference $locale,
        private LegacyConfiguration $configuration,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {}

    #[\Override]
    public function entries(int $actorId, array $sections): array
    {
        try {
            $query = $this->database->get()->prepare(self::FINGERPRINT);
            $query->execute([$actorId, $actorId]);
            $rows = [];
            // Every fingerprint SELECT returns its kind and three HEX strings.
            $query->fetchAll(\PDO::FETCH_FUNC, static function (string $kind, ?string $first, ?string $second, ?string $third) use (&$rows): void {
                $rows[] = implode(':', [$kind, $first ?? '', $second ?? '', $third ?? '']);
            });
        } catch (\PDOException) {
            // api_plugin_hook() runs nothing without the plugin tables either.
            return [];
        }
        if (!array_any($rows, static fn(string $row): bool => str_starts_with($row, 'h:'))) {
            return [];
        }
        sort($rows);
        try {
            $connection = $this->configuration->values()['connection_status'] ?? 'online';
        } catch (\RuntimeException) {
            $connection = 'unavailable';
        }
        $request = $this->requests->getCurrentRequest();
        // Per user: a hook may branch on who is signed in, not only on realms.
        // Accept-Language enters only as the catalogue locale it selects, so a
        // client cannot mint a new cache entry with every header spelling.
        $key = hash('xxh128', json_encode([
            $actorId, $rows, $sections, $connection, $request?->getLocale(), $this->locale->preferredLocale(),
            $this->acceptedLocale(),
        ], JSON_THROW_ON_ERROR));

        $failure = null;
        $entries = $this->cache->get($key, function (ItemInterface $item, bool &$save) use ($actorId, $sections, &$failure): array|false {
            try {
                $result = ($this->worker)()->consoleMenu($actorId, $sections);
                if ($result === null) {
                    // A request that cannot hand off its session is not an answer.
                    $save = false;
                    return [];
                }

                return self::validated($result['entries'] ?? null);
            } catch (\RuntimeException $error) {
                $failure = $error;
                $item->expiresAfter(self::FAILURE_SECONDS);

                return false;
            }
        });
        if ($failure !== null) {
            throw $failure;
        }
        if ($entries === false) {
            throw new \RuntimeException('The plugin console menu worker failed recently.');
        }

        return self::validated($entries);
    }

    private function acceptedLocale(): ?string
    {
        foreach ($this->requests->getCurrentRequest()?->getLanguages() ?? [] as $language) {
            $locale = ConsoleTranslations::normalize($language, $this->projectDir);
            if ($locale !== null) {
                return $locale;
            }
        }

        return null;
    }

    /** @return list<array{section: string, label: string, path: string, realm: ?int}> */
    private static function validated(mixed $entries): array
    {
        if (!is_array($entries) || !array_is_list($entries) || count($entries) > self::MAX_ENTRIES) {
            throw new \RuntimeException('Invalid plugin console menu.');
        }
        foreach ($entries as $entry) {
            if (!is_array($entry) || array_keys($entry) !== ['section', 'label', 'path', 'realm']
                || !is_int($entry['realm']) && $entry['realm'] !== null) {
                throw new \RuntimeException('Invalid plugin console menu entry.');
            }
            foreach (['section', 'label', 'path'] as $field) {
                if (!is_string($entry[$field]) || strlen($entry[$field]) > self::MAX_LENGTH) {
                    throw new \RuntimeException('Invalid plugin console menu entry.');
                }
            }
        }

        return $entries;
    }
}
