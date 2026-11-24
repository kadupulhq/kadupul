<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Automation\Infrastructure\Symfony\Form\DiscoveryNetworkType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/** The Discovery Threads and Run Limit choices, as the form's choice labels render in a locale. */
final class DiscoveryNetworkCountLabelsTest extends TestCase
{
    /** @return iterable<string, array{string, string, list<string>}> */
    public static function labels(): iterable
    {
        yield 'English threads' => ['en', 'threads', ['1 Thread', '2 Threads', '3 Threads', '4 Threads', '5 Threads', '6 Threads', '7 Threads', '8 Threads', '9 Threads', '10 Threads', '20 Threads', '50 Threads']];
        yield 'English run limit' => ['en', 'run_limit', ['1 Minute', '5 Minutes', '10 Minutes', '20 Minutes', '30 Minutes', '1 Hour', '2 Hours', '4 Hours', '8 Hours']];
        yield 'French run limit' => ['fr', 'run_limit', ['1 minute', '5 minutes', '10 minutes', '20 minutes', '30 minutes', '1 heure', '2 heures', '4 heures', '8 heures']];
    }

    /** @param list<string> $expected */
    #[DataProvider('labels')]
    public function testChoiceLabelsKeepTheirSingularAndPluralForms(string $locale, string $field, array $expected): void
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['discovery_network', 'discovery_network+intl-icu'] as $domain) {
            $translator->addResource('yaml', dirname(__DIR__, 2) . '/config/translations/' . $domain . '.' . $locale . '.yaml', $locale, $domain);
        }
        $form = Forms::createFormFactory()->create(DiscoveryNetworkType::class, null, ['csrf_protection' => false]);
        $config = $form->get($field)->getConfig();
        $parameters = $config->getOption('choice_translation_parameters');
        $label = $config->getOption('choice_label');

        $labels = [];
        foreach ($config->getOption('choices') as $value) {
            $labels[] = $translator->trans($label($value), $parameters($value), $config->getOption('choice_translation_domain'));
        }

        self::assertSame($expected, $labels);
    }
}
