<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

final class BrowserRealmDenialCoverageRegistration
{
    public const PRODUCER = 'tests/Fixtures/browser-realm-denial-coverage.php';
    public const MARKERS = ['native-browser-realm-denial-completed'];
    public const SOURCES = [
        'composer.lock','tests/composer.lock','cacti.sql',self::PRODUCER,
        'tests/Symfony/BrowserRealmDenialCoverageRegistration.php','tests/Symfony/BrowserRealmDenialNativeTest.php',
        'tests/Fixtures/browser-realm-denial-global.php','tests/Fixtures/browser-realm-denial-session.php',
        'tests/Fixtures/legacy-browser-realm-denial.php','tests/Fixtures/rrd-process-coverage.php',
        'tests/Helpers/NativeChildCoverageEvidence.php','include/auth.php','include/session.php',
        'lib/session_data.php','lib/session_store.php','lib/session_metadata.php',
        'bin/legacy-console-handoff.php','lib/auth.php','lib/database.php','lib/functions.php','lib/plugins.php',
        'include/global_constants.php','include/global_arrays.php','include/global_languages.php','include/global_settings.php',
        'src/Platform/Infrastructure/Legacy/BrowserWorkerRealmDenied.php',
        'src/Platform/Infrastructure/Legacy/BrowserWorkerSession.php',
        'src/IdentityAccess/Infrastructure/Legacy/NativeAuthenticationSession.php',
        'src/IdentityAccess/Infrastructure/Legacy/AuthenticationDatabaseSessionHandler.php',
        'src/IdentityAccess/Infrastructure/Legacy/AuthenticationFileSessionHandler.php',
        'src/IdentityAccess/Infrastructure/Legacy/BrowserAuthenticationSql.php',
        'src/Platform/Infrastructure/Legacy/LegacySettingsStorage.php',
        'src/Platform/Infrastructure/Legacy/LegacyConnectionRegistry.php',
        'src/Platform/Infrastructure/Legacy/Dbal/LegacyDatabaseConnection.php',
        'lib/variables.php','lib/html.php','lib/html_form.php','lib/html_utility.php','include/cacti_version',
    ];
    public const HITS = ['include/auth.php','bin/legacy-console-handoff.php'];
}
