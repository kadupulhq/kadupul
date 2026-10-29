<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// The legacy application is most of the code and the only user of several
// runtime packages, so scanning src/ alone would report them as unused.
return (new Configuration())
    ->addPathToScan(dirname(__DIR__) . '/src', false)
    ->addPathsToScan([
        dirname(__DIR__) . '/bin',
        dirname(__DIR__) . '/cli',
        dirname(__DIR__) . '/config',
        dirname(__DIR__) . '/include',
        dirname(__DIR__) . '/lib',
        dirname(__DIR__) . '/scripts',
        dirname(__DIR__) . '/service',
        dirname(__DIR__) . '/install',
        dirname(__DIR__) . '/plugins',
    ], false)
    ->addPathToScan(dirname(__DIR__) . '/tests/Symfony', true)
    ->addPathsToExclude([
        dirname(__DIR__) . '/include/vendor',
        dirname(__DIR__) . '/include/js',
        dirname(__DIR__) . '/include/themes',
        dirname(__DIR__) . '/include/fonts',
        dirname(__DIR__) . '/include/content',
    ])

    // Legacy procedural functions live in lib/ and are loaded by include, not
    // autoloaded, so the analyser cannot resolve any of them.
    ->ignoreUnknownFunctionsRegex('/.*/')
    // Libraries installed outside composer.json by tools/dependencies/install-legacy.php.
    ->ignoreUnknownClassesRegex('/^(Gettext|PHPMailer|PhpMyAdmin|phpsnmp)\\\\/')
    // Legacy classes and globals-based classes loaded by include, plus test doubles.
    ->ignoreUnknownClasses([
        'CactiSecureHeaders',
        'Installer',
        'Ldap',
        'LdapError',
        'SNMP',
        'SchemaRepairIntegrity',
        'domDocument',
    ])
    ->ignoreUnknownClassesRegex('/^(Cdef(Cli)?(Native)?(Probe)?CoverageRegistration|CredentialGenerationFaultStatement|DisableFailureConnection|NativeChildCoverageEvidence|Site(Creation|Lifecycle)FailureStatement|Kadupul\\\\Inventory\\\\Infrastructure\\\\Legacy\\\\MaintenanceProbeFixture)$/')

    // Declared only to be verified by the installer; used through other
    // loaders than a class reference, so no symbol can be matched.
    ->ignoreErrorsOnExtension('ext-mysqlnd', [ErrorType::UNUSED_DEPENDENCY])
    // Pins that keep phpseclib's and the legacy code's transitive versions fixed.
    ->ignoreErrorsOnPackages([
        'paragonie/constant_time_encoding',
        'paragonie/random_compat',
    ], [ErrorType::UNUSED_DEPENDENCY])
    // Loaded by the framework from config/packages, not by a class reference.
    ->ignoreErrorsOnPackages([
        'symfony/http-client',
        'symfony/yaml',
    ], [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackages([
        'symfony/asset-mapper',
        'symfony/doctrine-messenger',
        'symfony/security-csrf',
        'symfony/translation',
    ], [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV])

    // Registered in config/bundles.php for dev/test only; the production
    // container test verifies that no profiler bundle or service is present.
    ->ignoreErrorsOnPackage('symfony/web-profiler-bundle', [ErrorType::DEV_DEPENDENCY_IN_PROD])

    // Used but not declared. Tracked in the Phase 1 pull request as follow-ups:
    // the extensions ship with PHP builds, so declaring them changes what an
    // installation must provide.
    ->ignoreErrorsOnExtensions([
        'ext-ctype',
        'ext-filter',
        'ext-libxml',
        'ext-session',
        'ext-simplexml',
        'ext-snmp',
        'ext-tokenizer',
        'ext-zlib',
        'ext-bcmath',
    ], [ErrorType::SHADOW_DEPENDENCY])
    // phpunit pulls it in; only the tests reference it.
    ->ignoreErrorsOnPackage('phpunit/php-code-coverage', [ErrorType::SHADOW_DEPENDENCY])
    // The ext-* ignores depend on which extensions the runner loads, so an
    // ignore that never matches on one runner is not stale.
    ->disableReportingUnmatchedIgnores();
