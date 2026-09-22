<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Form;

use Kadupul\Inventory\Domain\DeviceSnmpConfiguration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DeviceSnmpType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $invalid = $this->translator->trans('Select a valid device option.', [], 'inventory');
        $builder->add('snmp_version', ChoiceType::class, ['label' => 'SNMP version', 'choices' => ['Disabled' => '0', 'Version 1' => '1', 'Version 2' => '2', 'Version 3' => '3'], 'invalid_message' => $invalid]);
        foreach (['snmp_auth_protocol' => ['Authentication protocol', DeviceSnmpConfiguration::AUTH_PROTOCOLS], 'snmp_priv_protocol' => ['Privacy protocol', DeviceSnmpConfiguration::PRIVACY_PROTOCOLS]] as $field => [$label, $choices]) {
            $builder->add($field, ChoiceType::class, ['label' => $label, 'choices' => array_combine($choices, $choices), 'invalid_message' => $invalid]);
        }
        foreach (['snmp_context' => 'SNMP context', 'snmp_engine_id' => 'SNMP engine ID'] as $field => $label) {
            $builder->add($field, TextType::class, ['label' => $label, 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 64]]);
        }
        $builder->add('keep_credentials', ChoiceType::class, ['label' => 'Credentials', 'choices' => ['Use stored credentials' => true, 'Replace credentials' => false], 'choice_value' => static fn(?bool $keep): string => $keep === null ? '' : ($keep ? 'keep' : 'replace'), 'placeholder' => false, 'invalid_message' => $invalid, 'help' => 'Stored credentials are never displayed. Leaving SNMP version 3 clears its credentials and protocol settings.']);
        foreach (['snmp_community' => ['SNMP community', 100], 'snmp_username' => ['SNMP username', 50], 'snmp_password' => ['Authentication password', 50], 'snmp_priv_passphrase' => ['Privacy passphrase', 200]] as $field => [$label, $length]) {
            $builder->add($field, PasswordType::class, ['label' => $label, 'required' => false, 'trim' => false, 'empty_data' => '', 'always_empty' => true, 'attr' => ['maxlength' => $length, 'autocomplete' => 'new-password']]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'inventory', 'csrf_protection' => false]);
    }
}
