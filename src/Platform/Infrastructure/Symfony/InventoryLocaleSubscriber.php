<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class InventoryLocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(private LocalePreference $preference, private DatabaseConnection $database, private LegacyConfiguration $configuration) {}

    public static function getSubscribedEvents(): array
    {
        // After routing (32), before Symfony's LocaleListener (16).
        return [KernelEvents::REQUEST => ['onRequest', 20]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !in_array($request->attributes->get('_route'), ['inventory_sites', 'inventory_site_edit', 'inventory_site_create', 'inventory_site_action', 'inventory_sites_legacy', 'inventory_devices', 'inventory_device_details', 'inventory_device_edit'], true)) {
            return;
        }
        // Anonymous requests do not need installation/session access for locale.
        $request->attributes->set('_locale', 'en');
        if ($request->cookies->count() === 0) {
            return;
        }
        $settings = $this->database->get()->query("SELECT name, value FROM settings WHERE name IN ('i18n_language_support', 'i18n_auto_detection', 'i18n_default_language')")->fetchAll(\PDO::FETCH_KEY_PAIR);
        if (($settings['i18n_language_support'] ?? '') === '0') {
            return;
        }
        $config = $this->configuration->values();
        $locale = self::normalize($config['forced_locale'] ?? null) ?? self::normalize($this->preference->preferredLocale());
        if ($locale === null && in_array($settings['i18n_auto_detection'] ?? '', ['', '1'], true)) {
            foreach ($request->getLanguages() as $language) {
                if (($locale = self::normalize($language)) !== null) {
                    break;
                }
            }
        }
        $request->attributes->set('_locale', $locale ?? self::normalize($settings['i18n_default_language'] ?? null) ?? 'en');
    }

    private static function normalize(mixed $locale): ?string
    {
        return is_string($locale) && preg_match('/\A(en|fr)(?:[-_][a-z]{2})?\z/iD', $locale, $match) ? strtolower($match[1]) : null;
    }
}
