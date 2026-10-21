<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../include/vendor/autoload.php';

use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Translation\TwigExtractor;
use Symfony\Component\Translation\Dumper\PoFileDumper;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Translation\MessageCatalogue;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

$root = $argv[1] ?? dirname(__DIR__, 2);
$twig = new Environment(new FilesystemLoader($root . '/templates'));
$twig->addExtension(new TranslationExtension(new IdentityTranslator()));
// Extraction only parses these names; it never renders a page or reads settings.
$twig->addFunction(new TwigFunction('installation_path'));
$twig->addFunction(new TwigFunction('asset'));
$twig->addFilter(new TwigFilter('console_number'));
$templates = ['templates/platform/console_home_body.html.twig', 'templates/platform/permission_denied.html.twig'];
$catalogue = new MessageCatalogue('en');
$sources = [];
foreach ($templates as $template) {
    $path = $root . '/' . $template;
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Console translation source is unavailable.');
    }
    // TwigExtractor skips syntax errors. Refuse one explicitly before extracting.
    $twig->parse($twig->tokenize(new Source($contents, $path)));
    $extracted = new MessageCatalogue('en');
    (new TwigExtractor($twig))->extract([$path], $extracted);
    foreach (array_keys($extracted->all('console')) as $message) {
        $sources[$message][] = $template;
    }
}
if (count($sources) < 15) {
    throw new RuntimeException('Console translation extraction is incomplete.');
}
foreach ($sources as $message => $files) {
    $catalogue->set($message, '');
    $metadata = ['sources' => $files];
    if (str_contains($message, '%s')) {
        $metadata['flags'] = ['php-format'];
    }
    $catalogue->setMetadata($message, $metadata);
}
echo (new PoFileDumper())->formatCatalogue($catalogue, 'messages');
