<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Form;

use Kadupul\Platform\Domain\NotificationReceiverChange;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The General tab of managers.php. Stored credentials are never rendered: an
 * edit keeps them or replaces all four.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class NotificationReceiverType extends AbstractType
{
    /** Legacy asked for each SNMPv3 passphrase twice. */
    public const array CONFIRMATIONS = ['snmp_password' => 'snmp_password_confirm', 'snmp_priv_passphrase' => 'snmp_priv_passphrase_confirm'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $invalid = 'Select a supported notification receiver setting.';
        $text = static fn(string $label, int $length, bool $required = true): array => ['label' => $label, 'required' => $required, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => $length]];
        $builder
            ->add('description', TextType::class, $text('Description', NotificationReceiverChange::DESCRIPTION_LENGTH))
            ->add('hostname', TextType::class, $text('Hostname', NotificationReceiverChange::HOSTNAME_LENGTH))
            ->add('disabled', CheckboxType::class, ['label' => 'Disable SNMP Notification Receiver', 'required' => false])
            ->add('max_log_size', ChoiceType::class, ['label' => 'Maximum Log Size', 'choices' => array_combine(array_map('strval', range(1, NotificationReceiverChange::LOG_SIZE_MAX)), range(1, NotificationReceiverChange::LOG_SIZE_MAX)), 'choice_translation_domain' => false, 'invalid_message' => $invalid])
            ->add('snmp_version', ChoiceType::class, ['label' => 'SNMP Version', 'choices' => ['Version 1' => 1, 'Version 2' => 2, 'Version 3' => 3], 'invalid_message' => $invalid])
            ->add('snmp_auth_protocol', ChoiceType::class, ['label' => 'SNMP Auth Protocol (v3)', 'choices' => array_combine(NotificationReceiverChange::AUTH_PROTOCOLS, NotificationReceiverChange::AUTH_PROTOCOLS), 'choice_translation_domain' => false, 'invalid_message' => $invalid])
            ->add('snmp_priv_protocol', ChoiceType::class, ['label' => 'SNMP Privacy Protocol (v3)', 'choices' => array_combine(NotificationReceiverChange::PRIVACY_PROTOCOLS, NotificationReceiverChange::PRIVACY_PROTOCOLS), 'choice_translation_domain' => false, 'invalid_message' => $invalid])
            ->add('snmp_engine_id', TextType::class, $text('SNMP Engine ID (v3)', NotificationReceiverChange::ENGINE_ID_LENGTH, false))
            ->add('snmp_port', IntegerType::class, ['label' => 'SNMP Port', 'required' => false, 'invalid_message' => 'Enter a whole number.', 'attr' => ['min' => 1, 'max' => 65535]])
            ->add('snmp_message_type', ChoiceType::class, ['label' => 'SNMP Message Type', 'choices' => ['NOTIFICATIONS' => 1, 'INFORMS' => 2], 'choice_translation_domain' => false, 'invalid_message' => $invalid])
            ->add('notes', TextareaType::class, ['label' => 'Notes', 'required' => false, 'trim' => false, 'empty_data' => '']);
        if ($options['existing'] === true) {
            $builder->add('keep_credentials', ChoiceType::class, [
                'label' => 'Credentials',
                'choices' => ['Use stored credentials' => true, 'Replace credentials' => false],
                'choice_value' => static fn(?bool $keep): string => $keep === null ? '' : ($keep ? 'keep' : 'replace'),
                'placeholder' => false,
                'invalid_message' => $invalid,
                'help' => 'Stored credentials are never displayed. Replacing them sets all four credential fields; leaving SNMP version 3 clears its username and passphrases.',
            ]);
        }
        $labels = ['snmp_community' => 'SNMP Community', 'snmp_username' => 'SNMP Username (v3)', 'snmp_password' => 'SNMP Auth Password (v3)', 'snmp_priv_passphrase' => 'SNMP Privacy Passphrase (v3)'];
        foreach (NotificationReceiverChange::CREDENTIALS as $field => $length) {
            $builder->add($field, PasswordType::class, self::secret($labels[$field], $length));
            if (isset(self::CONFIRMATIONS[$field])) {
                $builder->add(self::CONFIRMATIONS[$field], PasswordType::class, self::secret(['snmp_password' => 'Confirm SNMP Auth Password (v3)', 'snmp_priv_passphrase' => 'Confirm SNMP Privacy Passphrase (v3)'][$field], $length));
            }
        }
        $builder->add('revision', HiddenType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'notification_receiver',
            'csrf_protection' => true,
            'csrf_token_id' => 'notification_receiver_edit',
            'method' => 'POST',
            'allow_extra_fields' => false,
            'existing' => false,
        ]);
        $resolver->setAllowedTypes('existing', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'notification_receiver';
    }

    /** @return array<string, mixed> */
    private static function secret(string $label, int $length): array
    {
        return ['label' => $label, 'required' => false, 'trim' => false, 'empty_data' => '', 'always_empty' => true, 'attr' => ['maxlength' => $length, 'autocomplete' => 'new-password']];
    }
}
