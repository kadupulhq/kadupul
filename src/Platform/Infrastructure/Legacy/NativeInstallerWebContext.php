<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Infrastructure\Symfony\InstallerWebContext;
use Kadupul\Platform\Application\Installer\InstallerBackend;

final class NativeInstallerWebContext implements InstallerWebContext
{
    public function __construct(
        private readonly NativeInstallerFunctions $functions,
        private readonly InstallerBackend $backend,
    ) {}

    /** @return array{action: string, root: string, csrf: string, locale: string} */
    public function view(): array
    {
        if (!defined('KADUPUL_INSTALLER_WEB')) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Use the installation entry point.');
        }
        $this->backend->authorize();
        $values = $this->functions->configuration();
        $root = $values['url_path'] ?? '/';
        if (!is_string($root) || !str_starts_with($root, '/') || str_starts_with($root, '//')
            || preg_match('/[\x00-\x20\\\\?#]/', $root) !== 0) {
            throw new \RuntimeException('Invalid installation URL path.');
        }
        // Native bootstrap has already applied forced and per-user locale
        // selection. Carry that effective choice into Symfony as well.
        $locale = $_SESSION['sess_user_language'] ?? null;
        if (!is_string($locale) || $locale === '') {
            $locale = $this->functions->call('read_config_option', 'install_language', true);
        }
        if (!is_string($locale) || $locale === '') {
            $locale = $this->functions->call('read_config_option', 'i18n_default_language', true);
        }
        $locale = is_string($locale) && preg_match('/\A[a-z]{2}(?:[-_][a-zA-Z]{2})?\z/D', $locale) === 1 ? str_replace('_', '-', $locale) : 'en';
        return ['action' => rtrim($root, '/') . '/install/install.php', 'root' => rtrim($root, '/'),
            'csrf' => $this->functions->text($this->functions->call('csrf_get_tokens')), 'locale' => $locale];
    }

    public function validPost(): bool
    {
        return defined('KADUPUL_INSTALLER_WEB') && is_string($_POST['__csrf_magic'] ?? null) && $this->functions->call('csrf_check', false) === true;
    }
}
