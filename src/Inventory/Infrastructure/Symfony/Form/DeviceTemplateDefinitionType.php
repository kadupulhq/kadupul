<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Form;

use Kadupul\Inventory\Domain\DeviceTemplateDefinition;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DeviceTemplateDefinitionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, ['label' => 'Name', 'trim' => false, 'attr' => ['maxlength' => DeviceTemplateDefinition::NAME_MAX_LENGTH]])
            ->add('class', ChoiceType::class, ['label' => 'Class', 'choices' => array_combine(DeviceTemplateDefinition::CLASSES, DeviceTemplateDefinition::CLASSES), 'required' => false, 'placeholder' => 'Unassigned', 'empty_data' => ''])
            ->add('revision', HiddenType::class);
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'inventory', 'csrf_token_id' => 'inventory_device_template_definition', 'method' => 'POST']);
    }
}
