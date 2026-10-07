<?php

declare(strict_types=1);

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

/** Safe form errors for device writes, preserving each operation's response contract. */
final readonly class DeviceFormFailure
{
    public function __construct(private TranslatorInterface $translator) {}

    public function apply(FormInterface $form, \RuntimeException|\InvalidArgumentException $error, int $validationStatus = 422, string $uncertainMessage = 'Save outcome is uncertain. Reload the device before retrying.'): int|Response
    {
        if ($error instanceof InventoryAccessDenied) {
            return new Response($this->translator->trans('Access denied.', [], 'inventory'), $error->unauthenticated ? 401 : 403, ['Cache-Control' => 'private, no-store']);
        }
        $status = match (true) {
            $error instanceof DeviceEditConflict => 409,
            $error instanceof \InvalidArgumentException => $validationStatus,
            default => 502,
        };
        $message = $error instanceof DeviceEditConflict || $error instanceof \InvalidArgumentException ? $error->getMessage() : $uncertainMessage;
        $form->addError(new FormError($this->translator->trans($message, [], 'inventory')));
        return $status;
    }
}
