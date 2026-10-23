<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Form;

use Kadupul\Graphing\Domain\DataSourceProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DataSourceProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // A read-only profile renders its structural fields disabled in the
        // browser only. The browser then omits them, which keeps the stored
        // values; a forged value still reaches the store, which refuses it.
        $structure = $options['read_only'] ? ['disabled' => 'disabled'] : [];
        $builder
            ->add('name', TextType::class, ['label' => 'Name', 'required' => false, 'trim' => false, 'empty_data' => '', 'help' => 'A useful name for this Data Storage and Polling Profile.', 'attr' => ['maxlength' => DataSourceProfile::MAX_NAME, 'size' => 80]])
            ->add('step', ChoiceType::class, ['label' => 'Polling Interval', 'required' => false, 'placeholder' => false, 'choices' => $options['step_choices'], 'choice_translation_domain' => false, 'help' => 'The frequency that data will be collected from the Data Source?', 'attr' => $structure])
            ->add('heartbeat', ChoiceType::class, ['label' => 'Heartbeat', 'required' => false, 'placeholder' => false, 'choices' => $options['heartbeat_choices'], 'choice_translation_domain' => false, 'help' => 'How long can data be missing before RRDtool records unknown data.  Increase this value if your Data Source is unstable and you wish to carry forward old data rather than show gaps in your graphs.  This value is multiplied by the X-Files Factor to determine the actual amount of time.'])
            ->add('x_files_factor', TextType::class, ['label' => 'X-Files Factor', 'required' => false, 'trim' => false, 'help' => 'The amount of unknown data that can still be regarded as known.', 'attr' => ['maxlength' => 12, 'size' => 7] + $structure])
            ->add('consolidation_function_id', ChoiceType::class, ['label' => 'Consolidation Functions', 'required' => false, 'multiple' => true, 'expanded' => true, 'choices' => array_flip(DataSourceProfile::CONSOLIDATION_FUNCTIONS), 'choice_translation_domain' => false, 'help' => 'How data is to be entered in RRAs.', 'choice_attr' => static fn(): array => $structure])
            ->add('default', CheckboxType::class, ['label' => 'Default', 'required' => false, 'help' => 'Is this the default storage profile?', 'attr' => $options['is_default'] ? ['disabled' => 'disabled'] : []])
            ->add('revision', HiddenType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'data_source_profile',
            'csrf_protection' => true,
            'csrf_token_id' => 'data_source_profile_edit',
            'method' => 'POST',
            'allow_extra_fields' => false,
            'read_only' => false,
            'is_default' => false,
            'step_choices' => [],
            'heartbeat_choices' => [],
        ]);
        $resolver->setAllowedTypes('read_only', 'bool');
        $resolver->setAllowedTypes('is_default', 'bool');
        $resolver->setAllowedTypes('step_choices', 'array');
        $resolver->setAllowedTypes('heartbeat_choices', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'data_source_profile';
    }
}
