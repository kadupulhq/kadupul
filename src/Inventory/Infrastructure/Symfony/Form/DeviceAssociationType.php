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
 * Form for adding, removing or changing one graph or data query association on a device.
 */
final class DeviceAssociationType extends AbstractType
{
    /**
     * Binds the form to the translator used for validation messages.
     */
    public function __construct(private readonly TranslatorInterface $translator) {}
    /**
     * Adds the hidden revision, the operation and target choices, and for data queries the reindex method.
     * The uptime reindex method is offered only when the device uses SNMP.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $targets = $options['targets'];
        $operations = ['Add association' => 'add', 'Remove association' => 'remove'];
        if ($options['kind'] === 'query') {
            $operations['Change reindex method'] = 'change';
            $methods = ['None' => 0, 'Index count' => 2, 'Verify all' => 3];
            if ($options['snmp_enabled']) {
                $methods['Uptime'] = 1;
            }
            $builder->add('reindex', ChoiceType::class, ['label' => 'Reindex method', 'choices' => $methods, 'invalid_message' => $this->translator->trans('Select a valid association change.', [], 'inventory')]);
        }
        $builder->add('revision', HiddenType::class)->add('operation', ChoiceType::class, ['label' => 'Association change', 'choices' => $operations, 'placeholder' => 'Select a change'])->add('target', ChoiceType::class, [
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
     * Requires the "targets" option (names keyed by id). Accepts "kind" ("graph" or "query") and "snmp_enabled", and enables CSRF protection and POST.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['targets']);
        $resolver->setAllowedTypes('targets', 'array');
        $resolver->setDefaults(['kind' => 'graph', 'snmp_enabled' => true, 'translation_domain' => 'inventory', 'csrf_protection' => true, 'csrf_token_id' => 'inventory_device_associations', 'method' => 'POST']);
        $resolver->setAllowedTypes('snmp_enabled', 'bool');
        $resolver->setAllowedValues('kind', ['graph', 'query']);
    }
}
