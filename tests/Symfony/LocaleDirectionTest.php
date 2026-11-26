<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Infrastructure\Symfony\LoginPageRenderer;
use Kadupul\Kernel;
use Kadupul\Platform\Infrastructure\Symfony\LocaleDirection;
use Kadupul\Platform\Infrastructure\Symfony\NativeLogoutPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Markup;

final class LocaleDirectionTest extends TestCase
{
    /** @return iterable<string, array{string|\Stringable|null, string}> */
    public static function localeDirections(): iterable
    {
        yield 'null defaults to ltr' => [null, 'ltr'];
        yield 'empty defaults to ltr' => ['', 'ltr'];
        yield 'en-US is ltr' => ['en-US', 'ltr'];
        yield 'fr short tag is ltr' => ['fr', 'ltr'];
        yield 'ar-SA hyphenated is rtl' => ['ar-SA', 'rtl'];
        yield 'ar_EG underscored is rtl' => ['ar_EG', 'rtl'];
        yield 'ar-MA mixed case is rtl' => ['AR-ma', 'rtl'];
        yield 'fa-IR is rtl' => ['fa-IR', 'rtl'];
        yield 'he_IL is rtl' => ['he_IL', 'rtl'];
        yield 'ur-PK is rtl' => ['ur-PK', 'rtl'];
        yield 'bare ar resolves via LocaleResolver to rtl' => ['ar', 'rtl'];
        yield 'Twig Markup rtl locale' => [new Markup('he-IL', 'UTF-8'), 'rtl'];
        yield 'unknown locale defaults to ltr' => ['xx-YY', 'ltr'];
    }

    #[DataProvider('localeDirections')]
    public function testResolvesDirectionFromKnownLocales(string|\Stringable|null $locale, string $expected): void
    {
        $helper = new LocaleDirection();

        self::assertSame($expected, $helper->direction($locale));
    }

    public function testTwigRootTemplatesRenderDirAttributeForRtlAndLtrLocales(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $twig = $container->get('twig');
            self::assertInstanceOf(Environment::class, $twig);

            $login = $container->get(LoginPageRenderer::class);
            self::assertInstanceOf(LoginPageRenderer::class, $login);
            self::assertStringContainsString("<html lang='ar-SA' dir='rtl'>", $login->begin(new Markup('ar-SA', 'UTF-8')));
            self::assertStringContainsString("<html lang='fr-FR'>", $login->begin(new Markup('fr-FR', 'UTF-8')));

            $logoutRtl = $twig->render('platform/logout_message.html.twig', [
                'page' => new NativeLogoutPage('', '', 'msg', 'prompt', 'heading', '1.0', '2026', 'nonce', [], null, 'he-IL'),
            ]);
            self::assertStringContainsString('<html lang="he-IL" dir="rtl">', $logoutRtl);

            $logoutDefault = $twig->render('platform/logout_message.html.twig', [
                'page' => new NativeLogoutPage('', '', 'msg', 'prompt', 'heading', '1.0', '2026', 'nonce', []),
            ]);
            self::assertStringContainsString('<html lang="en-US">', $logoutDefault);
            self::assertStringNotContainsString('dir="rtl"', $logoutDefault);
        } finally {
            $kernel->shutdown();
        }
    }
}
