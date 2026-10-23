<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Form;

use Kadupul\Automation\Domain\AutomationTemplate;
use Kadupul\Automation\Domain\AvailabilityMethods;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AutomationTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $templates = $options['device_templates'];
        $builder
            // Choices are IDs, so device templates that share a name stay distinct.
            ->add('host_template', ChoiceType::class, [
                'label' => 'Device Template',
                'help' => 'Select a Device Template that Devices will be matched to.',
                'choices' => array_keys($templates),
                'choice_label' => static fn(int $id): string => $templates[$id],
                'choice_translation_domain' => false,
                'placeholder' => 'Choose a Device Template',
                'required' => false,
            ])
            ->add('availability_method', ChoiceType::class, [
                'label' => 'Availability Method',
                'help' => 'Choose the Availability Method to use for Discovered Devices.',
                'choices' => array_flip(AvailabilityMethods::LABELS),
            ])
            ->add('sysDescr', TextType::class, self::match('System Description Match', 'This is a unique string that will be matched to a devices sysDescr string to pair it to this Automation Template.  Any Perl regular expression can be used in addition to any SQL Where expression.', AutomationTemplate::DESCRIPTION_LENGTH))
            ->add('sysName', TextType::class, self::match('System Name Match', 'This is a unique string that will be matched to a devices sysName string to pair it to this Automation Template.  Any Perl regular expression can be used in addition to any SQL Where expression.', AutomationTemplate::NAME_LENGTH))
            ->add('sysOid', TextType::class, self::match('System OID Match', 'This is a unique string that will be matched to a devices sysOid string to pair it to this Automation Template.  Any Perl regular expression can be used in addition to any SQL Where expression.', AutomationTemplate::OID_LENGTH))
            ->add('revision', HiddenType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'automation',
            'csrf_protection' => true,
            'csrf_token_id' => 'automation_template_edit',
            'method' => 'POST',
            'allow_extra_fields' => false,
            'device_templates' => [],
        ]);
        $resolver->setAllowedTypes('device_templates', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'automation_template';
    }

    /** @return array<string, mixed> */
    private static function match(string $label, string $help, int $length): array
    {
        return ['label' => $label, 'help' => $help, 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => $length, 'size' => 60]];
    }
}
