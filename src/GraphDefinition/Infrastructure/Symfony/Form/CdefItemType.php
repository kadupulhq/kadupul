<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Symfony\Form;

use Kadupul\GraphDefinition\Domain\CdefFunctions;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CdefItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $itemType = (string) $options['item_type'];
        $values = match ($itemType) {
            '1' => array_combine(array_values($options['functions']), array_keys($options['functions'])),
            '2' => array_combine(array_values(CdefFunctions::OPERATORS), array_keys(CdefFunctions::OPERATORS)),
            '4' => array_combine(array_values(CdefFunctions::DATA_SOURCES), array_keys(CdefFunctions::DATA_SOURCES)),
            '5' => array_combine(array_map(static fn(array $cdef): string => $cdef['name'] . ' (#' . $cdef['id'] . ')', $options['cdef_choices']), array_map('strval', array_column($options['cdef_choices'], 'id'))),
            default => [],
        };
        $builder->add('id', HiddenType::class)
            ->add('cdef_id', HiddenType::class)
            ->add('type', ChoiceType::class, [
                'label' => 'Item type',
                'choices' => array_combine(array_values(CdefFunctions::TYPES), array_map('strval', array_keys(CdefFunctions::TYPES))),
                'choice_translation_domain' => 'graph_definition',
            ])
            ->add('value', $itemType === '6' ? TextType::class : ChoiceType::class, $itemType === '6'
                ? ['label' => 'Value', 'trim' => false, 'attr' => ['maxlength' => 150]]
                : ['label' => 'Value', 'choices' => $values, 'choice_translation_domain' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'graph_definition', 'csrf_protection' => true,
            'csrf_token_id' => 'graph_cdef_edit', 'method' => 'POST', 'item_type' => '1', 'cdef_choices' => [], 'functions' => CdefFunctions::functions(false),
        ]);
        $resolver->setAllowedTypes('item_type', 'string');
        $resolver->setAllowedTypes('cdef_choices', 'array');
        $resolver->setAllowedTypes('functions', 'array');
    }
}
