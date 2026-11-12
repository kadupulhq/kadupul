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
 * Form for choosing a device's collector.
 */
final class DeviceCollectorType extends AbstractType
{
    /**
     * Binds the form to the translator used for validation messages.
     */
    public function __construct(private readonly TranslatorInterface $translator) {}
    /**
     * Adds the collector choice.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('collector_id', ChoiceType::class, ['label' => 'Device collector', 'choices' => array_map('intval', array_keys($options['collectors'])), 'choice_label' => fn(int $id): string => $options['collectors'][$id], 'choice_value' => static fn(?int $id): string => $id === null ? '' : (string) $id, 'choice_translation_domain' => false, 'placeholder' => 'Select a device collector', 'invalid_message' => $this->translator->trans('Select a valid device collector.', [], 'inventory')])->add('revision', HiddenType::class);
    }
    /**
     * Requires the "collectors" option (names keyed by id), and enables CSRF protection and POST.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('collectors');
        $resolver->setAllowedTypes('collectors', 'array');
        $resolver->setDefaults(['translation_domain' => 'inventory', 'csrf_protection' => true, 'csrf_token_id' => 'inventory_device_collector', 'method' => 'POST']);
    }
}
