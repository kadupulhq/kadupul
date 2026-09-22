<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony;

use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Consistent safe presentation of single-device write failures. */
final readonly class DeviceFormFailure
{
    public function __construct(private TranslatorInterface $translator) {}

    public function apply(FormInterface $form, \RuntimeException|\InvalidArgumentException $error): int|Response
    {
        if ($error instanceof InventoryAccessDenied) {
            return new Response($this->translator->trans('Access denied.', [], 'inventory'), $error->unauthenticated ? 401 : 403, ['Cache-Control' => 'private, no-store']);
        }
        $status = match (true) {
            $error instanceof DeviceEditConflict => 409,
            $error instanceof \InvalidArgumentException => 422,
            default => 502,
        };
        $message = $status === 502 ? 'Save outcome is uncertain. Reload the device before retrying.' : $error->getMessage();
        $form->addError(new FormError($this->translator->trans($message, [], 'inventory')));
        return $status;
    }
}
