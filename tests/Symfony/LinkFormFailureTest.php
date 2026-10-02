<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Navigation\Application\Query\LinkAccessDenied;
use Kadupul\Navigation\Domain\LinkConflict;
use Kadupul\Navigation\Infrastructure\Symfony\LinkFormFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LinkFormFailureTest extends TestCase
{
    public static function failures(): iterable
    {
        yield 'anonymous denial' => [new LinkAccessDenied(true), 401, 'Access denied.'];
        yield 'authenticated denial' => [new LinkAccessDenied(false), 403, 'Access denied.'];
        yield 'stale write' => [new LinkConflict('Links changed since you opened this form. Reload before saving.'), 409, 'Links changed since you opened this form. Reload before saving.'];
        yield 'invalid input' => [new \InvalidArgumentException('Invalid link fields.'), 422, 'Invalid link fields.'];
        yield 'write not confirmed' => [new \RuntimeException('internal database failure'), 502, 'Link operation could not be confirmed. Reload before retrying.'];
    }

    #[DataProvider('failures')]
    public function testExpectedFailuresPreserveStatusAndTranslatedPublicMessages(\RuntimeException|\InvalidArgumentException $error, int $status, string $message): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')->with($message, [], 'navigation')->willReturn('translated: ' . $message);
        $form = $this->createMock(FormInterface::class);
        if ($error instanceof LinkAccessDenied) {
            $form->expects(self::never())->method('addError');
        } else {
            $form->expects(self::once())->method('addError')->with(self::callback(static fn(FormError $formError): bool => $formError->getMessage() === 'translated: ' . $message))->willReturnSelf();
        }
        $result = (new LinkFormFailure($translator))($error, $form);
        if ($result instanceof Response) {
            self::assertSame($status, $result->getStatusCode());
            self::assertSame('translated: ' . $message, $result->getContent());
            self::assertStringContainsString('no-store', $result->headers->get('Cache-Control'));
        } else {
            self::assertSame($status, $result);
        }
    }

    public function testFrenchOperationFailureUsesTheApplicationCatalog(): void
    {
        $translator = new \Symfony\Component\Translation\Translator('fr');
        $translator->addLoader('yaml', new \Symfony\Component\Translation\Loader\YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 2) . '/config/translations/navigation.fr.yaml', 'fr', 'navigation');
        $form = $this->createMock(FormInterface::class);
        $form->expects(self::once())->method('addError')->with(self::callback(static fn(FormError $error): bool => $error->getMessage() === 'L’opération sur le lien n’a pas été confirmée. Rechargez avant de réessayer.'))->willReturnSelf();
        self::assertSame(502, (new LinkFormFailure($translator))(new \RuntimeException('private SQL detail'), $form));
    }
}
