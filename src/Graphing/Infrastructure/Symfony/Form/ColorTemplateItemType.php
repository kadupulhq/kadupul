<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ColorTemplateItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Labels hold operator-chosen palette names, so they are not message keys.
            ->add('color_id', ChoiceType::class, ['label' => 'Color', 'choices' => $options['colors'], 'choice_translation_domain' => false, 'placeholder' => false])
            ->add('revision', HiddenType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'color_template',
            'csrf_protection' => true,
            'csrf_token_id' => 'color_template_item',
            'method' => 'POST',
            'allow_extra_fields' => false,
        ]);
        $resolver->setRequired('colors');
        $resolver->setAllowedTypes('colors', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'color_template_item';
    }
}
