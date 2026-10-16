<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Called only by the isolated, authenticated browser worker. */
final class ConsoleExtensionRuntime
{
    /** @return array<string, mixed> */
    public function capture(): array
    {
        global $config, $local_db_cnn_id;

        $fullPage = !\isset_request_var('header') || \get_nfilter_request_var('header') == 'true';
        $headerContent = \isset_request_var('headercontent');
        $title = \api_plugin_hook_function('page_title', \draw_navigation_text('title'));
        $head = $fullPage && !$headerContent ? $this->output(static fn() => \html_common_header($title)) : '';
        $chrome = [
            'title' => \html_escape($title),
            'tabs' => $fullPage && !$headerContent ? $this->output(static fn() => \html_show_tabs_left()) : '',
            'graph_tabs' => $fullPage && !$headerContent ? (string) \html_graph_tabs_right() : '',
            'breadcrumb' => $fullPage ? (string) \draw_navigation_text() : '',
            'login' => $fullPage && !$headerContent && \read_config_option('auth_method') != 0 ? (string) \draw_login_status(false) : '',
            'menu' => $fullPage ? $this->output(static fn() => \draw_menu()) : '',
        ];
        $before = $this->output(function (): void {
            \api_plugin_hook('console_before');
            $this->links('FRONTTOP');
        });
        $show = \read_config_option('hide_console') !== 'on';
        $collector = null;
        if ($show && (int) $config['poller_id'] > 1) {
            $state = $config['connection'];
            $queued = $state === 'online' ? null : \db_fetch_cell('SELECT COUNT(*) FROM poller_output_boost', '', true, $local_db_cnn_id);
            if ($queued === false) {
                throw new \RuntimeException('Collector queue could not be read.');
            }
            $collector = ['connection' => $state, 'queued' => $queued === null ? null : (int) $queued];
        }
        $after = $this->output(function (): void {
            $this->links('FRONT');
            \api_plugin_hook('console_after');
        });

        $bottom = $this->output(static function () use ($fullPage): void {
            require_once __DIR__ . '/../../../../include/global_session.php';
            if ($fullPage && !\isset_request_var('pagecontent')) {
                \api_plugin_hook('page_bottom');
            }
            \kill_session_var('sess_field_values');
            \debug_log_clear();
        });
        $fragments = $this->protectForms([
            'head' => $head, ...$chrome, 'before' => $before, 'after' => $after,
            'bottom' => $bottom,
        ]);

        foreach (array_keys($chrome) as $part) {
            $chrome[$part] = $fragments[$part];
            unset($fragments[$part]);
        }

        return [
            'full_page' => $fullPage,
            'header_content' => $headerContent,
            'chrome' => $chrome,
            'show_introduction' => $show,
            'collector' => $collector,
            'policies' => \CactiSecureHeaders::contentSecurityPolicies(),
            'nonce' => \CactiSecureHeaders::getNonce(),
        ] + $fragments;
    }

    /**
     * Run the existing origin-aware CSRF rewriter once across the complete
     * extension document, including its base URL and forms spanning fragments.
     * The Twig-owned console body has no forms. Random boundaries let us retain
     * the rewritten head and footer scripts without parsing/rebuilding plugin HTML.
     *
     * @param array<string, string> $fragments
     * @return array<string, string>
     */
    private function protectForms(array $fragments): array
    {
        if (!empty($GLOBALS['csrf']['disable']) || empty($GLOBALS['csrf']['rewrite'])) {
            return $fragments;
        }
        $boundary = bin2hex(random_bytes(24));
        $start = static fn(string $part): string => '<!--' . $boundary . ':' . $part . ':start-->';
        $end = static fn(string $part): string => '<!--' . $boundary . ':' . $part . ':end-->';
        $document = '<html>' . $start('head') . '<head>' . $fragments['head'] . '</head>' . $end('head') . '<body>';
        foreach ($fragments as $part => $html) {
            if ($part !== 'head' && $part !== 'bottom') {
                $document .= $start($part) . $html . $end($part);
            }
        }
        $document .= $start('bottom') . $fragments['bottom'] . '</body>' . $end('bottom') . '</html>';
        $document = \csrf_ob_handler($document, PHP_OUTPUT_HANDLER_FINAL);
        foreach (array_keys($fragments) as $part) {
            $from = strpos($document, $start($part));
            $to = strpos($document, $end($part));
            if ($from === false || $to === false || $to < $from) {
                throw new \RuntimeException('Console CSRF rewriting could not be confirmed.');
            }
            $from += strlen($start($part));
            $fragments[$part] = substr($document, $from, $to - $from);
        }
        $fragments['head'] = substr($fragments['head'], strlen('<head>'), -strlen('</head>'));
        $fragments['bottom'] = substr($fragments['bottom'], 0, -strlen('</body>'));

        return $fragments;
    }

    private function output(\Closure $render): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            $render();
            if (ob_get_level() <= $level) {
                throw new \RuntimeException('Console extension closed its output boundary.');
            }
            while (ob_get_level() > $level + 1) {
                if (!ob_end_flush()) {
                    throw new \RuntimeException('Console extension output could not be collected.');
                }
            }

            return (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                if (!ob_end_clean()) {
                    break;
                }
            }
        }
    }

    private function links(string $style): void
    {
        global $config;

        $consoles = \db_fetch_assoc_prepared('SELECT id, contentfile FROM external_links WHERE enabled = "on" AND style = ?', [$style]);
        if ($consoles === false) {
            throw new \RuntimeException('Console links could not be read.');
        }
        foreach ($consoles as $page) {
            if (!\is_realm_allowed((int) $page['id'] + 10000)) {
                continue;
            }
            if (preg_match('/^((((ht|f)tp(s?))\:\/\/){1}\S+)/i', $page['contentfile'])) {
                print '<iframe class="content" src="' . \html_escape($page['contentfile']) . '" frameborder="0"></iframe>';
            } else {
                print '<div id="content">';
                $file = $config['base_path'] . '/include/content/' . $page['contentfile'];
                if (\cacti_path_is_within($file, $config['base_path'] . '/include/content')) {
                    include_once realpath($file);
                } else {
                    print '<h1>The file \'' . \html_escape($page['contentfile']) . '\' does not exist!!</h1>';
                }
                print '</div>';
            }
        }
    }
}
