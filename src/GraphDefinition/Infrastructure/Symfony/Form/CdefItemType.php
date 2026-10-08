<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Symfony\Form;

use Kadupul\GraphDefinition\Domain\CdefFunctions;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The item type is fixed by the page, not a field: function, operator and CDEF
 * choices share numeric values, so a changed type must reload its own choices.
 */
final class CdefItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $text = $options['item_type'] === CdefFunctions::CUSTOM_STRING;
        $builder
            ->add('value', $text ? TextType::class : ChoiceType::class, $text
                ? ['label' => 'CDEF Item Value', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 150, 'size' => 30]]
                : ['label' => 'CDEF Item Value', 'choices' => array_flip($options['value_choices']), 'choice_translation_domain' => $options['item_type'] === CdefFunctions::SPECIAL_DATA_SOURCE ? 'cdef' : false])
            ->add('revision', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'cdef',
            'csrf_protection' => true,
            'csrf_token_id' => 'graph_cdef_item',
            'method' => 'POST',
            'allow_extra_fields' => false,
            'value_choices' => [],
        ]);
        $resolver->setRequired('item_type');
        $resolver->setAllowedValues('item_type', array_keys(CdefFunctions::TYPES));
        $resolver->setAllowedTypes('value_choices', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'cdef_item';
    }
}
