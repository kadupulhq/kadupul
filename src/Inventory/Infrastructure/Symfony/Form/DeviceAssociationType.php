<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DeviceAssociationType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator) {}
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $targets = $options['targets'];
        $builder->add('revision', HiddenType::class)->add('operation', ChoiceType::class, ['label' => 'Association change', 'choices' => ['Add association' => 'add', 'Remove association' => 'remove'], 'placeholder' => 'Select a change'])->add('target', ChoiceType::class, [
            'label' => 'Assignment target',
            'choices' => array_map('intval', array_keys($targets)),
            'choice_label' => static fn(int $id): string => $targets[$id],
            'choice_value' => static fn(?int $id): string => $id === null ? '' : (string) $id,
            'choice_translation_domain' => false,
            'placeholder' => 'Select an assignment target',
            'invalid_message' => $this->translator->trans('Select a valid assignment target.', [], 'inventory'),
        ]);
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['targets']);
        $resolver->setAllowedTypes('targets', 'array');
        $resolver->setDefaults(['translation_domain' => 'inventory', 'csrf_protection' => true, 'csrf_token_id' => 'inventory_device_associations', 'method' => 'POST']);
    }
}
