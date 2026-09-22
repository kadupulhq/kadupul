<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DevicePollingType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['device_threads' => 'Device threads', 'snmp_port' => 'SNMP port', 'snmp_timeout' => 'SNMP timeout (ms)', 'max_oids' => 'Maximum OIDs', 'bulk_walk_size' => 'Bulk walk size', 'ping_port' => 'Ping port', 'ping_timeout' => 'Ping timeout (ms)', 'ping_retries' => 'Ping retries'] as $field => $label) {
            $builder->add($field, TextType::class, ['label' => $label, 'trim' => false, 'empty_data' => '']);
        }
        $builder->add('availability_method', ChoiceType::class, ['label' => 'Availability method', 'choices' => ['None' => '0', 'SNMP and ping' => '1', 'SNMP' => '2', 'Ping' => '3', 'SNMP or ping' => '4', 'SNMP system description' => '5', 'SNMP GETNEXT' => '6'], 'invalid_message' => $this->translator->trans('Select a valid device option.', [], 'inventory')]);
        $builder->add('ping_method', ChoiceType::class, ['label' => 'Ping method', 'choices' => ['Not configured (legacy)' => '0', 'ICMP' => '1', 'UDP' => '2', 'TCP' => '3', 'TCP closed' => '5'], 'invalid_message' => $this->translator->trans('Select a valid device option.', [], 'inventory')]);
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'inventory', 'csrf_protection' => false]);
    }
}
