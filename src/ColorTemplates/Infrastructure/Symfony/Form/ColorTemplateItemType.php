<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ColorTemplateItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('color_id', ChoiceType::class, ['label' => 'Color', 'choices' => $options['color_choices'], 'choice_value' => static fn($value): string => $value === null ? '' : (string) $value])
            ->add('revision', HiddenType::class, ['required' => false])
            ->add('save', SubmitType::class, ['label' => 'Save Color Item']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => true, 'csrf_token_id' => 'color_template_item', 'translation_domain' => 'color_templates']);
        $resolver->setRequired('color_choices');
        $resolver->setAllowedTypes('color_choices', 'array');
    }
}
