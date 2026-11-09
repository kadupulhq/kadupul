<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Platform\Application\Locale\KnownLocales;
use Kadupul\Platform\Application\Locale\LocaleInput;
use Kadupul\Platform\Application\Locale\LocaleResolver;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets each routed request's locale with the resolver legacy pages use.
 *
 * Symfony routes read the same settings, forced language, preference and
 * Accept-Language, but never the legacy `language` query parameter, and they
 * write no session, user setting or process locale.
 */
final readonly class LocaleSubscriber implements EventSubscriberInterface
{
    /**
     * Routes whose responses are not localized: probes, the installer (which
     * may run before a database exists), the session and login handoffs, and
     * the JSON and CSV representations, whose values and headers are data.
     */
    private const LOCALE_NEUTRAL_ROUTES = [
        'health' => true,
        'platform_installer' => true,
        'platform_console_login_post' => true,
        'session' => true,
        'inventory_devices_json' => true,
        'inventory_devices_csv' => true,
        'inventory_sites_json' => true,
    ];

    public function __construct(
        private LocalePreference $preference,
        private DatabaseConnection $database,
        private LegacyConfiguration $configuration,
        private string $projectDir = __DIR__ . '/../../../..',
        private LocaleResolver $resolver = new LocaleResolver(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        // After routing (32), before Symfony's LocaleListener (16).
        return [KernelEvents::REQUEST => ['onRequest', 20]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (!$event->isMainRequest() || !is_string($route) || isset(self::LOCALE_NEUTRAL_ROUTES[$route])) {
            return;
        }
        // Anonymous requests do not need installation/session access for locale.
        $request->attributes->set('_locale', 'en');
        if ($request->cookies->count() === 0) {
            return;
        }
        $settings = $this->rows("SELECT name, value FROM settings WHERE name IN ('i18n_language_support', 'i18n_auto_detection', 'i18n_default_language')", \PDO::FETCH_KEY_PAIR);
        $forced = $this->configuration->values()['forced_locale'] ?? null;
        $input = new LocaleInput(
            self::text($settings['i18n_language_support'] ?? null),
            self::text($settings['i18n_auto_detection'] ?? null),
            self::text($settings['i18n_default_language'] ?? null),
            static fn(): ?string => null,
            self::text($forced),
            static fn(): ?string => null,
            // The preference is the shared session language, or the saved
            // one when the session has none; either goes through the saved
            // language's repair rule.
            null,
            fn(): ?string => $this->preference->preferredLocale(),
            $request->headers->get('Accept-Language'),
            fn(string $locale): bool => $this->catalog($this->projectDir, $locale),
        );
        $resolution = $this->resolver->resolve($input);
        if ($resolution->showsEnglish() || $this->strictFallback($input, $resolution->locale)) {
            return;
        }
        // Only shipped catalogs are accepted, so no request value names a file.
        $request->attributes->set('_locale', ConsoleTranslations::normalize($resolution->locale, $this->projectDir) ?? 'en');
    }

    private function strictFallback(LocaleInput $input, string $locale): bool
    {
        if (!$this->resolver->strictMode($input)) {
            return false;
        }
        $directories = $this->rows('SELECT directory FROM plugin_config ORDER BY id', \PDO::FETCH_COLUMN);
        $covered = [];
        foreach ($directories as $directory) {
            // A directory that is not a plain name cannot hold a catalog here.
            if (is_string($directory) && preg_match('/\A[A-Za-z0-9_-]+\z/D', $directory) === 1 && $this->catalog($this->projectDir . '/plugins/' . $directory, $locale)) {
                $covered[$directory] = true;
            }
        }

        return $this->resolver->requiresStrictFallback($input, count($directories), count($covered));
    }

    /** The legacy get_mo_language_file() lookup: <locale>.mo, then <filename>.mo. */
    private function catalog(string $base, string $locale): bool
    {
        $directory = $base . '/locales/LC_MESSAGES/';

        return file_exists($directory . $locale . '.mo') || file_exists($directory . KnownLocales::ALL[$locale]['filename'] . '.mo');
    }

    /** @return array<mixed> */
    private function rows(string $sql, int $mode): array
    {
        $statement = $this->database->get()->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Locale settings are unavailable.');
        }

        return $statement->fetchAll($mode);
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
