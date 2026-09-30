<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Symfony\Form;

use Kadupul\GraphDefinition\Domain\VdefFunctions;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class VdefItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $type = (string) ($options['item_type'] ?? '1');
        $valueChoices = match ($type) {
            '1' => array_combine(array_values(VdefFunctions::FUNCTIONS), array_keys(VdefFunctions::FUNCTIONS)),
            '4' => array_combine(array_values(VdefFunctions::DATA_SOURCES), array_keys(VdefFunctions::DATA_SOURCES)),
            default => [],
        };
        $builder->add('id', HiddenType::class)->add('vdef_id', HiddenType::class)->add('revision', HiddenType::class)
            ->add('type', ChoiceType::class, ['label' => 'Item type', 'choices' => array_combine(array_values(VdefFunctions::TYPES), array_keys(VdefFunctions::TYPES)), 'choice_translation_domain' => 'graph_definition'])
            ->add('value', $type === '6' ? TextType::class : ChoiceType::class, $type === '6'
                ? ['label' => 'Value', 'trim' => false, 'attr' => ['maxlength' => 150]]
                : ['label' => 'Value', 'choices' => $valueChoices, 'choice_translation_domain' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'graph_definition', 'csrf_protection' => true, 'csrf_token_id' => 'graph_vdef_edit', 'method' => 'POST', 'item_type' => '1']);
        $resolver->setAllowedTypes('item_type', 'string');
    }
}
