<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Form;

use Kadupul\Automation\Domain\SnmpOptionEntryChange;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Stored credentials are never rendered: an edit keeps them or replaces all four.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class SnmpOptionEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $invalid = 'Select a supported SNMP setting.';
        $builder
            ->add('snmp_version', ChoiceType::class, ['label' => 'SNMP Version', 'choices' => ['Version 1' => 1, 'Version 2' => 2, 'Version 3' => 3], 'invalid_message' => $invalid])
            ->add('snmp_port', IntegerType::class, self::integer('SNMP Port', 'Enter the UDP port number to use for SNMP (default is 161).', 1, 65535))
            ->add('snmp_timeout', IntegerType::class, self::integer('SNMP Timeout', 'The maximum number of milliseconds Cacti will wait for an SNMP response (does not work with php-snmp support).', 0, SnmpOptionEntryChange::TIMEOUT_MAX))
            ->add('snmp_retries', IntegerType::class, self::integer('SNMP Retries', 'The maximum number of attempts to reach a device via an SNMP readstring prior to giving up.', 0, SnmpOptionEntryChange::RETRIES_MAX))
            ->add('max_oids', ChoiceType::class, ['label' => 'Maximum OIDs Per Get Request', 'choices' => array_combine(array_map('strval', SnmpOptionEntryChange::MAX_OIDS), SnmpOptionEntryChange::MAX_OIDS), 'choice_translation_domain' => false, 'invalid_message' => $invalid])
            ->add('bulk_walk_size', ChoiceType::class, ['label' => 'Bulk Walk Maximum Repetitions', 'choices' => ['Auto Detect on Re-Index' => -1, 'Auto Detect/Set on first Re-Index' => 0] + array_combine(array_map('strval', array_slice(SnmpOptionEntryChange::BULK_WALK_SIZES, 2)), array_slice(SnmpOptionEntryChange::BULK_WALK_SIZES, 2)), 'invalid_message' => $invalid])
            ->add('snmp_auth_protocol', ChoiceType::class, ['label' => 'SNMP Auth Protocol (v3)', 'choices' => array_combine(SnmpOptionEntryChange::AUTH_PROTOCOLS, SnmpOptionEntryChange::AUTH_PROTOCOLS), 'choice_translation_domain' => false, 'invalid_message' => $invalid])
            ->add('snmp_priv_protocol', ChoiceType::class, ['label' => 'SNMP Privacy Protocol (v3)', 'choices' => array_combine(SnmpOptionEntryChange::PRIVACY_PROTOCOLS, SnmpOptionEntryChange::PRIVACY_PROTOCOLS), 'choice_translation_domain' => false, 'invalid_message' => $invalid])
            ->add('snmp_context', TextType::class, ['label' => 'SNMP Context (v3)', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 64]])
            ->add('snmp_engine_id', TextType::class, ['label' => 'SNMP Engine ID (v3)', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 64]]);
        if ($options['existing'] === true) {
            $builder->add('keep_credentials', ChoiceType::class, [
                'label' => 'Credentials',
                'choices' => ['Use stored credentials' => true, 'Replace credentials' => false],
                'choice_value' => static fn(?bool $keep): string => $keep === null ? '' : ($keep ? 'keep' : 'replace'),
                'placeholder' => false,
                'invalid_message' => $invalid,
                'help' => 'Stored credentials are never displayed. Replacing them sets all four fields below; leaving SNMP version 3 clears its username and passphrases.',
            ]);
        }
        foreach (SnmpOptionEntryChange::CREDENTIALS as $field => $length) {
            $label = ['snmp_community' => 'SNMP Community', 'snmp_username' => 'SNMP Username (v3)', 'snmp_password' => 'SNMP Auth Password (v3)', 'snmp_priv_passphrase' => 'SNMP Privacy Passphrase (v3)'][$field];
            $builder->add($field, PasswordType::class, ['label' => $label, 'required' => false, 'trim' => false, 'empty_data' => '', 'always_empty' => true, 'attr' => ['maxlength' => $length, 'autocomplete' => 'new-password']]);
        }
        $builder->add('revision', HiddenType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'automation',
            'csrf_protection' => true,
            'csrf_token_id' => 'automation_snmp_entry_edit',
            'method' => 'POST',
            'allow_extra_fields' => false,
            'existing' => false,
        ]);
        $resolver->setAllowedTypes('existing', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'automation_snmp_entry';
    }

    /** @return array<string, mixed> */
    private static function integer(string $label, string $help, int $min, int $max): array
    {
        return ['label' => $label, 'help' => $help, 'required' => false, 'invalid_message' => 'Enter a whole number.', 'attr' => ['min' => $min, 'max' => $max]];
    }
}
