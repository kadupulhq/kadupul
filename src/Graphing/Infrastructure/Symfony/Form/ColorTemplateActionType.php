<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Form;

use Kadupul\Graphing\Domain\ColorTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ColorTemplateActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('selection', HiddenType::class)
            ->add('revisions', HiddenType::class);
        if ($options['title_format']) {
            $builder->add('title_format', TextType::class, ['label' => 'Title Format', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => ColorTemplate::MAX_LENGTH]]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'color_template',
            'csrf_protection' => true,
            'csrf_token_id' => 'color_template_action',
            'method' => 'POST',
            'allow_extra_fields' => false,
            'title_format' => false,
        ]);
        $resolver->setAllowedTypes('title_format', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'color_template_action';
    }
}
