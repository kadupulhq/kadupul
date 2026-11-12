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

/**
 * Form for choosing a device's template.
 */
final class DeviceTemplateType extends AbstractType
{
    /**
     * Binds the form to the translator used for validation messages.
     */
    public function __construct(private readonly TranslatorInterface $translator) {}
    /**
     * Adds the template choice, which includes an "Unassigned" entry.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('template_id', ChoiceType::class, ['label' => 'Device template', 'choices' => [0, ...array_map('intval', array_keys($options['templates']))], 'choice_label' => fn(int $id): string => $id === 0 ? $this->translator->trans('Unassigned', [], 'inventory') : $options['templates'][$id], 'choice_value' => static fn(?int $id): string => $id === null ? '' : (string) $id, 'choice_translation_domain' => false, 'placeholder' => 'Select a device template', 'invalid_message' => $this->translator->trans('Select a valid device template.', [], 'inventory')])->add('revision', HiddenType::class);
    }
    /**
     * Requires the "templates" option (names keyed by id), and enables CSRF protection and POST.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('templates');
        $resolver->setAllowedTypes('templates', 'array');
        $resolver->setDefaults(['translation_domain' => 'inventory', 'csrf_protection' => true, 'csrf_token_id' => 'inventory_device_template', 'method' => 'POST']);
    }
}
