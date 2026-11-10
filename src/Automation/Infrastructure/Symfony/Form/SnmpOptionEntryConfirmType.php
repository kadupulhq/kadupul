<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Carries the revision or order digest the page displayed; the token ID names the action.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class SnmpOptionEntryConfirmType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('revision', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'automation',
            'csrf_protection' => true,
            'csrf_token_id' => 'automation_snmp_entry_order',
            'method' => 'POST',
            'allow_extra_fields' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'automation_snmp_entry_confirm';
    }
}
