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

final class DeviceEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('description', TextType::class, ['label' => 'Name', 'attr' => ['maxlength' => 150]])
            ->add('hostname', TextType::class, ['label' => 'Hostname or IP address', 'attr' => ['maxlength' => 100]])
            ->add('location', TextType::class, ['required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 40]])
            ->add('external_id', TextType::class, ['label' => 'External ID', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 40]])
            ->add('notes', TextareaType::class, ['required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['rows' => 8]])
            ->add('enabled', ChoiceType::class, ['label' => 'Polling', 'choices' => ['Enabled' => true, 'Disabled' => false],
                'choice_value' => static fn(?bool $enabled): string => $enabled === null ? '' : ($enabled ? 'enabled' : 'disabled'),
                'placeholder' => false, 'help' => 'Disabled devices are excluded from polling. Existing graphs and data are retained.'])
            ->add('revision', HiddenType::class);
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => true, 'csrf_token_id' => 'inventory_device_edit', 'method' => 'POST']);
    }
}
