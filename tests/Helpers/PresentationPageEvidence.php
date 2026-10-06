<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class PresentationPageEvidence
{
    public static function measuredSources(): array
    {
        $scenarios = require dirname(__DIR__) . '/Fixtures/legacy-form-golden-scenarios.php';
        return array_values(array_unique(array_merge(array_column($scenarios['pages'], 'page'), array(
            'include/global_settings.php', 'include/global_form.php', 'lib/html.php', 'lib/html_form.php',
            'lib/html_reports.php', 'lib/html_filter.php', 'lib/functions.php', 'lib/html_utility.php',
            'src/Platform/Contract/IconRegistry.php',
        ))));
    }

    public static function sources(): array
    {
        $scenarios = require dirname(__DIR__) . '/Fixtures/legacy-form-golden-scenarios.php';
        $goldens = array_map(static fn($name) => 'tests/Golden/forms/pages/' . $name . '.html', array_keys($scenarios['pages']));
        return array_values(array_unique(array_merge(self::measuredSources(), $goldens, array(
            'composer.json', 'composer.lock', 'tests/composer.json', 'tests/composer.lock', 'config/icons.json', 'cli/refresh_csrf.php',
            'include/vendor/composer/installed.json', 'tests/vendor/composer/installed.json',
            'include/runtime.php', 'lib/database.php', 'lib/headers_secure.php', 'include/global_constants.php',
            'lib/html_validate.php', 'include/global_languages.php', 'lib/auth.php', 'lib/plugins.php',
            'include/plugins.php', 'include/global_arrays.php', 'lib/variables.php', 'lib/mib_cache.php',
            'lib/poller.php', 'lib/snmpagent.php', 'lib/aggregate.php', 'lib/api_automation.php', 'include/csrf.php',
            'include/vendor/csrf/csrf-magic.php', 'include/vendor/csrf/csrf-conf.php',
            'lib/graph_fonts.php', 'src/Graphing/Domain/Font/GraphFontMethod.php', 'src/Graphing/Domain/Font/GraphFont.php',
            'src/Graphing/Domain/Font/GraphFontProfile.php', 'src/Graphing/Domain/Font/GraphFontResolver.php',
            'src/Graphing/Infrastructure/Fontconfig/InstalledFontFamilies.php',

            'include/global_session.php',
            'lib/api_aggregate.php',
            'lib/api_data_source.php',
            'lib/api_device.php',
            'lib/api_graph.php',
            'lib/api_tree.php',
            'lib/data_query.php',
            'lib/data_source_profile_integrity.php',
            'lib/export.php',
            'lib/graph_item_editor.php',
            'lib/graph_template_input.php',
            'lib/graphs.php',
            'lib/html_form_template.php',
            'lib/html_graph.php',
            'lib/html_tree.php',
            'lib/import.php',
            'lib/path_helpers.php',
            'lib/reference_write.php',
            'lib/reports.php',
            'lib/rrd.php',
            'lib/rrd_maintenance.php',
            'lib/snmp.php',
            'lib/template.php',
            'lib/time.php',
            'lib/timespan_settings.php',
            'lib/utility.php',
            'lib/xml.php',
            'src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php',
            'src/Platform/Infrastructure/Legacy/HostDataSubstitution.php',
            'src/Platform/Infrastructure/Legacy/LegacyReferenceWriteTransaction.php',
            'src/Platform/Infrastructure/Legacy/LegacyRequestContext.php',
            'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Helpers/PresentationPageEvidence.php',
            'tests/Fixtures/legacy-form-golden.php', 'tests/Fixtures/legacy-form-golden-scenarios.php',
            'tests/Unit/PresentationPageNativeCoverageTest.php',
        ))));
    }
    public static function contracts(string $html): array
    {
        $document = new \DOMDocument();
        $prior = libxml_use_internal_errors(true);
        try {
            if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html)) {
                throw new RuntimeException('Native presentation DOM parsing failed');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
        $xpath = new \DOMXPath($document);
        $controls = array();
        foreach ($xpath->query('//form//*[self::input or self::select or self::textarea or self::button]') as $node) {
            $options = array();
            foreach ($node->getElementsByTagName('option') as $option) {
                $options[] = array($option->getAttribute('value'), trim(preg_replace('/\s+/u', ' ', $option->textContent)), $option->hasAttribute('selected'));
            }
            $controls[] = array($node->nodeName, $node->getAttribute('name'), $node->getAttribute('id'), $node->getAttribute('type'),
                $node->hasAttribute('disabled'), $node->hasAttribute('readonly'), $node->getAttribute('maxlength'), $node->getAttribute('placeholder'), $node->getAttribute('aria-label'), $options);
        }
        $labels = array();
        foreach ($xpath->query('//form//label') as $node) {
            $labels[] = array($node->getAttribute('for'), trim(preg_replace('/\s+/u', ' ', $node->textContent)));
        }
        $links = array();
        foreach ($xpath->query('//form//a[@href]') as $node) {
            $links[] = array($node->getAttribute('href'), $node->getAttribute('title'), trim(preg_replace('/\s+/u', ' ', $node->textContent)));
        }
        $icons = array();
        foreach ($xpath->query('//form//i | //form//img') as $node) {
            $icons[] = array($node->nodeName, $node->getAttribute('class'), $node->getAttribute('title'),
                $node->getAttribute('alt'), $node->getAttribute('aria-label'), $node->getAttribute('aria-hidden'));
        }
        return array('controls' => $controls, 'labels' => $labels, 'links' => $links, 'icons' => $icons);
    }

    public static function markers(string $case): array
    {
        return array('unmodified-source-files:' . $case, 'page-rendered:' . $case, 'controls-retained:' . $case, 'labels-links-icons-retained:' . $case);
    }

}
