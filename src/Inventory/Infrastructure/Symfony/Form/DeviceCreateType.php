<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Form;

use Kadupul\Inventory\Domain\NewDevice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DeviceCreateType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['description' => 'Description', 'hostname' => 'Hostname or IP address', 'location' => 'Location', 'external_id' => 'External ID', 'device_threads' => 'Device threads', 'snmp_username' => 'SNMP username', 'snmp_context' => 'SNMP context', 'snmp_engine_id' => 'SNMP engine ID', 'snmp_port' => 'SNMP port', 'snmp_timeout' => 'SNMP timeout (ms)', 'max_oids' => 'Maximum OIDs', 'bulk_walk_size' => 'Bulk walk size', 'ping_port' => 'Ping port', 'ping_timeout' => 'Ping timeout (ms)', 'ping_retries' => 'Ping retries'] as $field => $label) {
            $builder->add($field, TextType::class, ['label' => $label, 'required' => in_array($field, ['description', 'hostname'], true), 'trim' => false, 'empty_data' => '']);
        }
        $builder->add('notes', TextareaType::class, ['label' => 'Notes', 'required' => false, 'trim' => false, 'empty_data' => '']);
        foreach (['host_template_id' => 'Device template', 'site_id' => 'Site', 'poller_id' => 'Poller'] as $field => $label) {
            $choices = $options[$field];
            $builder->add($field, ChoiceType::class, ['label' => $label, 'invalid_message' => $this->translator->trans('Select a valid device option.', [], 'inventory'), 'choices' => array_keys($choices), 'choice_label' => static fn($id): string => (string) ($choices[$id] ?? ''), 'choice_translation_domain' => false]);
        }
        foreach ([
            'snmp_version' => ['SNMP version', ['Disabled' => '0', 'Version 1' => '1', 'Version 2' => '2', 'Version 3' => '3']],
            'availability_method' => ['Availability method', ['None' => '0', 'SNMP and ping' => '1', 'SNMP' => '2', 'Ping' => '3', 'SNMP or ping' => '4', 'SNMP system description' => '5', 'SNMP GETNEXT' => '6']],
            'ping_method' => ['Ping method', ['Not configured (legacy)' => '0', 'ICMP' => '1', 'UDP' => '2', 'TCP' => '3', 'TCP closed' => '5']],
            'snmp_auth_protocol' => ['SNMP authentication protocol', array_combine(NewDevice::AUTH_PROTOCOLS, NewDevice::AUTH_PROTOCOLS)],
            'snmp_priv_protocol' => ['SNMP privacy protocol', array_combine(NewDevice::PRIVACY_PROTOCOLS, NewDevice::PRIVACY_PROTOCOLS)],
            'enabled' => ['Polling', ['Enabled' => true, 'Disabled' => false]],
        ] as $field => [$label, $choices]) {
            $builder->add($field, ChoiceType::class, ['label' => $label, 'invalid_message' => $this->translator->trans('Select a valid device option.', [], 'inventory'), 'choices' => $choices]);
        }
        $builder->add('use_default_credentials', CheckboxType::class, ['label' => 'Use configured credentials', 'required' => false]);
        foreach (['snmp_community' => 'SNMP community', 'snmp_password' => 'SNMP authentication passphrase', 'snmp_priv_passphrase' => 'SNMP privacy passphrase'] as $field => $label) {
            $builder->add($field, PasswordType::class, ['label' => $label, 'required' => false, 'trim' => false, 'empty_data' => '', 'always_empty' => true, 'attr' => ['autocomplete' => 'new-password']]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'inventory', 'csrf_protection' => true, 'csrf_token_id' => 'inventory_device_create', 'method' => 'POST', 'host_template_id' => [], 'site_id' => [], 'poller_id' => []]);
        foreach (['host_template_id', 'site_id', 'poller_id'] as $key) {
            $resolver->setAllowedTypes($key, 'array');
        }
    }
}
