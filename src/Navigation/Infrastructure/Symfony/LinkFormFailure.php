<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Symfony;

use Kadupul\Navigation\Application\Query\LinkAccessDenied;
use Kadupul\Navigation\Domain\LinkConflict;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Maps expected command failures to a denial response or a form error status. */
final readonly class LinkFormFailure
{
    public function __construct(private TranslatorInterface $translator) {}

    public function __invoke(\RuntimeException|\InvalidArgumentException $error, FormInterface $form): Response|int
    {
        if ($error instanceof LinkAccessDenied) {
            return new Response($this->translator->trans('Access denied.', [], 'navigation'), $error->unauthenticated ? 401 : 403, ['Cache-Control' => 'private, no-store']);
        }
        if ($error instanceof LinkConflict) {
            $status = 409;
            $message = $error->getMessage();
        } elseif ($error instanceof \InvalidArgumentException) {
            $status = 422;
            $message = $error->getMessage();
        } else {
            $status = 502;
            $message = 'Link operation could not be confirmed. Reload before retrying.';
        }
        $form->addError(new FormError($this->translator->trans($message, [], 'navigation')));
        return $status;
    }
}
