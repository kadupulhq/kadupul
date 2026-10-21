<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DataSourceProfileActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('selection', HiddenType::class)->add('revisions', HiddenType::class);
        // Only duplication has a title; a delete form must not render a stray input.
        if ($options['duplicate']) {
            $builder->add('title_format', TextType::class, ['label' => 'Title Format', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 255, 'size' => 30]]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'data_source_profile',
            'csrf_protection' => true,
            'csrf_token_id' => 'data_source_profile_action',
            'method' => 'POST',
            'allow_extra_fields' => false,
            'duplicate' => false,
        ]);
        $resolver->setAllowedTypes('duplicate', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'data_source_profile_action';
    }
}
