<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Form;

use Kadupul\Automation\Domain\SnmpOptionSet;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Confirms deleting or duplicating selected SNMP Option Sets; the "duplicate" option adds the name format.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class SnmpOptionSetActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('selection', HiddenType::class)->add('revisions', HiddenType::class);
        // Only duplication names anything; a delete form must not render a stray input.
        if ($options['duplicate'] === true) {
            $builder->add('name_format', TextType::class, [
                'label' => 'Name Format',
                'help' => 'Every <name> is replaced by the name of the SNMP Option Set being copied.',
                'required' => false,
                'trim' => false,
                'empty_data' => '',
                'attr' => ['maxlength' => SnmpOptionSet::NAME_FORMAT_LENGTH, 'size' => 30],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'automation',
            'csrf_protection' => true,
            'csrf_token_id' => 'automation_snmp_option_action',
            'method' => 'POST',
            'allow_extra_fields' => false,
            'duplicate' => false,
        ]);
        $resolver->setAllowedTypes('duplicate', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'automation_snmp_option_action';
    }
}
