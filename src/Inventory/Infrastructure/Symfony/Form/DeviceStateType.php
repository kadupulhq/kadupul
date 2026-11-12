<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form for confirming a bulk device operation.
 */
final class DeviceStateType extends AbstractType
{
    /**
     * Adds the hidden selection, and the SNMP or options sub-form when the operation edits them.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('selection', HiddenType::class);
        if ($options['edit_snmp']) {
            $builder->add('snmp', DeviceSnmpType::class, ['label' => 'SNMP settings', 'bulk' => true]);
        }
        if ($options['edit_options']) {
            $builder->add('options', DeviceOptionsType::class, ['label' => 'Device options']);
        }
    }
    /**
     * Accepts the "edit_snmp" and "edit_options" flags, and enables CSRF protection and POST.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['edit_snmp' => false, 'edit_options' => false, 'translation_domain' => 'inventory', 'csrf_protection' => true, 'csrf_token_id' => 'inventory_device_state', 'method' => 'POST']);
        $resolver->setAllowedTypes('edit_options', 'bool');
        $resolver->setAllowedTypes('edit_snmp', 'bool');
    }
}
