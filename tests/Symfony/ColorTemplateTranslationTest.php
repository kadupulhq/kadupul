<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\MoFileLoader;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

final class ColorTemplateTranslationTest extends TestCase
{
    public function testReachableLiteralMessagesHaveFrenchEntries(): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = Yaml::parseFile($root . '/config/translations/color_templates.fr.yaml');
        $messages = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src/ColorTemplates')) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                preg_match_all("/(?:trans\\(|InvalidArgumentException\\(|'label' => )'([^']+)'/", file_get_contents($file->getPathname()), $matches);
                $messages = [...$messages, ...$matches[1]];
            }
        }
        foreach (glob($root . '/templates/color_templates/*.html.twig') as $file) {
            preg_match_all("/'([^']+)'\\|trans/", file_get_contents($file), $matches);
            $messages = [...$messages, ...$matches[1]];
        }
        self::assertNotEmpty($messages);
        foreach (array_unique($messages) as $message) {
            self::assertArrayHasKey($message, $catalog, $message);
        }
        $translator = new Translator('fr');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', $root . '/config/translations/color_templates.fr.yaml', 'fr', 'color_templates');
        foreach (['Select a valid color template action.', 'Color template order changed. Reload before reordering.', 'Color templates referenced by aggregate graphs or templates cannot be deleted.'] as $message) {
            self::assertNotSame($message, $translator->trans($message, [], 'color_templates'));
        }
    }

    public function testAggregateFailureMessagesAreExtractedMergedAndTranslatedInTheCompiledFrenchCatalog(): void
    {
        $root = dirname(__DIR__, 2);
        $messages = [];
        foreach (['aggregate_graphs.php', 'aggregate_templates.php', 'graphs.php'] as $file) {
            preg_match_all("/__\\('(Aggregate settings may[^']+|An aggregate graph may[^']+)'\\)/", file_get_contents($root . '/' . $file), $matches);
            self::assertCount(1, $matches[1]);
            $messages = [...$messages, ...$matches[1]];
        }
        $catalog = (new MoFileLoader())->load($root . '/locales/LC_MESSAGES/fr-FR.mo', 'fr');
        foreach (array_unique($messages) as $message) {
            $record = 'msgid "' . $message . '"';
            self::assertStringContainsString($record, file_get_contents($root . '/locales/po/cacti.pot'));
            foreach (glob($root . '/locales/po/*.po') as $file) {
                self::assertStringContainsString($record, file_get_contents($file), $file);
            }
            self::assertTrue($catalog->has($message));
            self::assertNotSame($message, $catalog->get($message));
            self::assertStringContainsString('confirmé', $catalog->get($message));
        }
    }
}
