<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Form;

use Kadupul\Graphing\Domain\GraphTemplateInput;
use Kadupul\Graphing\Domain\GraphTemplateInputColumn;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<array{name: string, description: string, column_name: string, items: list<int>, revision: string}> */
final class GraphTemplateInputType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $columns = [];
        foreach (GraphTemplateInputColumn::cases() as $column) {
            $columns[$column->label()] = $column->value;
        }
        $builder
            ->add('name', TextType::class, ['label' => 'Name', 'required' => false, 'trim' => false, 'empty_data' => '', 'help' => 'Enter a name for this graph item input, make sure it is something you recognize.', 'attr' => ['maxlength' => GraphTemplateInput::MAX_NAME, 'size' => 40]])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'trim' => false, 'empty_data' => '', 'help' => 'Enter a description for this graph item input to describe what this input is used for.', 'attr' => ['rows' => 5, 'cols' => 40]])
            ->add('column_name', ChoiceType::class, ['label' => 'Field Type', 'placeholder' => false, 'choices' => $columns, 'help' => 'How data is to be represented on the graph.'])
            // Only this template's items are choices; anything else fails validation before the editor.
            ->add('items', ChoiceType::class, ['label' => 'Associated Graph Items', 'multiple' => true, 'expanded' => true, 'required' => false, 'choices' => $options['item_choices'], 'choice_translation_domain' => false])
            ->add('revision', HiddenType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'graph_template_input',
            'csrf_protection' => true,
            'csrf_token_id' => 'graph_template_input',
            'method' => 'POST',
            'allow_extra_fields' => false,
            'item_choices' => [],
        ]);
        $resolver->setAllowedTypes('item_choices', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'graph_template_input';
    }
}
