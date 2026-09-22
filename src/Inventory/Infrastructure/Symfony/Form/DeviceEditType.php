<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DeviceEditType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('description', TextType::class, ['label' => 'Name', 'attr' => ['maxlength' => 150]])
            ->add('hostname', TextType::class, ['label' => 'Hostname or IP address', 'attr' => ['maxlength' => 100]])
            ->add('location', TextType::class, ['label' => 'Location', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 40]])
            ->add('external_id', TextType::class, ['label' => 'External ID', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 40]])
            ->add('notes', TextareaType::class, ['label' => 'Notes', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['rows' => 8]])
            ->add('enabled', ChoiceType::class, ['label' => 'Polling', 'choices' => ['Enabled' => true, 'Disabled' => false],
                'choice_value' => static fn(?bool $enabled): string => $enabled === null ? '' : ($enabled ? 'enabled' : 'disabled'),
                'invalid_message' => $this->translator->trans('Choose whether polling is enabled or disabled.', [], 'inventory'),
                'placeholder' => false, 'help' => 'Disabled devices are excluded from polling. Existing graphs and data are retained.'])
            ->add('site_id', ChoiceType::class, ['label' => 'Site', 'choices' => [0, ...array_map('intval', array_keys($options['sites']))],
                'choice_label' => fn(int $id): string => $id === 0 ? $this->translator->trans('Unassigned', [], 'inventory') : $options['sites'][$id],
                'choice_value' => static fn(?int $id): string => $id === null ? '' : (string) $id,
                'choice_translation_domain' => false, 'placeholder' => 'Select a site',
                'invalid_message' => $this->translator->trans('Select a valid site.', [], 'inventory')])
            ->add('polling', DevicePollingType::class, ['label' => 'Polling settings'])
            ->add('revision', HiddenType::class);
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('sites');
        $resolver->setAllowedTypes('sites', 'array');
        $resolver->setDefaults(['translation_domain' => 'inventory', 'csrf_protection' => true, 'csrf_token_id' => 'inventory_device_edit', 'method' => 'POST']);
    }
}
