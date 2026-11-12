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
 * Form for choosing the site, template or collector to assign to the selected devices.
 */
final class DeviceBulkAssignmentType extends AbstractType
{
    /**
     * Binds the form to the translator used for validation messages.
     */
    public function __construct(private readonly TranslatorInterface $translator) {}
    /**
     * Adds the hidden selection and the target choice.
     * A "None" target is offered for every kind except collectors.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $targets = $options['targets'];
        if ($options['kind'] !== 'collector') {
            $targets = [0 => $this->translator->trans('None', [], 'inventory')] + $targets;
        }
        $builder->add('selection', HiddenType::class)->add('target', ChoiceType::class, [
            'label' => 'Assignment target',
            'choices' => array_map('intval', array_keys($targets)),
            'choice_label' => static fn(int $id): string => $targets[$id],
            'choice_value' => static fn(?int $id): string => $id === null ? '' : (string) $id,
            'choice_translation_domain' => false,
            'placeholder' => 'Select an assignment target',
            'invalid_message' => $this->translator->trans('Select a valid assignment target.', [], 'inventory'),
        ]);
    }
    /**
     * Requires the "targets" option (names keyed by id) and "kind" ("site", "template" or "collector"), and enables CSRF protection and POST.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['targets', 'kind']);
        $resolver->setAllowedTypes('targets', 'array');
        $resolver->setAllowedValues('kind', ['site', 'template', 'collector']);
        $resolver->setDefaults(['translation_domain' => 'inventory', 'csrf_protection' => true, 'csrf_token_id' => 'inventory_device_bulk_assignment', 'method' => 'POST']);
    }
}
