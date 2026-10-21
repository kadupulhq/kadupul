<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Form;

use Kadupul\Graphing\Domain\DataSourceProfileRra;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DataSourceProfileRraType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Disabled in the browser only, as on the profile form; the store refuses a forged change.
        $structure = $options['read_only'] ? ['disabled' => 'disabled'] : [];
        $builder
            ->add('name', TextType::class, ['label' => 'Name', 'required' => false, 'trim' => false, 'empty_data' => '', 'help' => 'How data is to be entered in RRAs.', 'attr' => ['maxlength' => DataSourceProfileRra::MAX_NAME, 'size' => 60]])
            ->add('steps', ChoiceType::class, ['label' => 'Aggregation Level', 'required' => false, 'placeholder' => false, 'choices' => $options['level_choices'], 'choice_translation_domain' => false, 'help' => 'The number of samples required prior to filling a row in the RRA specification.  The first RRA should always have a value of 1.', 'attr' => $structure])
            ->add('rows', TextType::class, ['label' => 'Rows', 'required' => false, 'trim' => true, 'help' => 'How many generations data is kept in the RRA.', 'attr' => ['maxlength' => 10, 'size' => 10, 'inputmode' => 'numeric'] + $structure])
            ->add('timespan', ChoiceType::class, ['label' => 'Default Timespan', 'required' => false, 'placeholder' => false, 'choices' => $options['timespan_choices'], 'choice_translation_domain' => false, 'help' => 'When viewing a Graph based upon the RRA in question, the default Timespan to show for that Graph.'])
            ->add('revision', HiddenType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'data_source_profile',
            'csrf_protection' => true,
            'csrf_token_id' => 'data_source_profile_rra',
            'method' => 'POST',
            'allow_extra_fields' => false,
            'read_only' => false,
            'level_choices' => [],
            'timespan_choices' => [],
        ]);
        $resolver->setAllowedTypes('read_only', 'bool');
        $resolver->setAllowedTypes('level_choices', 'array');
        $resolver->setAllowedTypes('timespan_choices', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'data_source_profile_rra';
    }
}
