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

final class DeviceTemplateType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator) {}
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('template_id', ChoiceType::class, ['label' => 'Device template', 'choices' => [0, ...array_map('intval', array_keys($options['templates']))], 'choice_label' => fn(int $id): string => $id === 0 ? $this->translator->trans('Unassigned', [], 'inventory') : $options['templates'][$id], 'choice_value' => static fn(?int $id): string => $id === null ? '' : (string) $id, 'choice_translation_domain' => false, 'placeholder' => 'Select a device template', 'invalid_message' => $this->translator->trans('Select a valid device template.', [], 'inventory')])->add('revision', HiddenType::class);
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('templates');
        $resolver->setAllowedTypes('templates', 'array');
        $resolver->setDefaults(['translation_domain' => 'inventory', 'csrf_protection' => true, 'csrf_token_id' => 'inventory_device_template', 'method' => 'POST']);
    }
}
