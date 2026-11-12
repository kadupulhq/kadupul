<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormError;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form for confirming device removal and choosing what happens to the devices' graphs and data sources.
 */
final class DeviceRemovalType extends AbstractType
{
    /**
     * Binds the form to the translator used for validation messages.
     */
    public function __construct(private readonly TranslatorInterface $translator) {}
    /**
     * Adds the hidden selection and the removal policy choice.
     * Submitting without a policy is an error.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('selection', HiddenType::class)->add('policy', ChoiceType::class, ['label' => 'Graphs and data sources', 'choices' => ['Retain graphs and disable data sources' => 'retain', 'Remove graphs and data sources' => 'purge'], 'expanded' => true, 'invalid_message' => $this->translator->trans('Select a removal policy.', [], 'inventory')]);
        $builder->get('policy')->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            if ($event->getData() === null || $event->getData() === '') {
                $event->getForm()->addError(new FormError($this->translator->trans('Select a removal policy.', [], 'inventory')));
            }
        });
    }
    /**
     * Enables CSRF protection and POST.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'inventory', 'csrf_protection' => true, 'csrf_token_id' => 'inventory_device_remove', 'method' => 'POST']);
    }
}
