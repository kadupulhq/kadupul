<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony;

use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Extra-field and scalar choice validation shared by assignment forms. */
final readonly class DeviceAssignmentForm
{
    public function __construct(private TranslatorInterface $translator) {}

    public function validate(FormInterface $form, string $field, string $invalidMessage): void
    {
        if ($form->getExtraData() !== []) {
            $form->addError(new FormError($this->translator->trans('Unexpected fields were submitted.', [], 'inventory')));
        }
        $choice = $form->get($field);
        if ($choice->isSynchronized() && !is_int($choice->getData())) {
            $choice->addError(new FormError($this->translator->trans($invalidMessage, [], 'inventory')));
        }
    }
}
