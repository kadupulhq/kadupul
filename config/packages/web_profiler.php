<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// PHP rather than YAML because the bundle is a dev dependency: a no-dev
// install has no web_profiler extension, and its test kernel must still boot.
return static function (ContainerConfigurator $container): void {
    if (!class_exists(WebProfilerBundle::class)) {
        return;
    }

    if ($container->env() === 'dev') {
        $container->extension('web_profiler', ['toolbar' => true, 'intercept_redirects' => false]);
        $container->extension('framework', ['profiler' => ['only_exceptions' => false]]);
    }

    // The test environment must collect so tests can read profiles back.
    if ($container->env() === 'test') {
        $container->extension('web_profiler', ['toolbar' => false, 'intercept_redirects' => false]);
        $container->extension('framework', ['profiler' => ['collect' => true, 'only_exceptions' => false]]);
    }
};
