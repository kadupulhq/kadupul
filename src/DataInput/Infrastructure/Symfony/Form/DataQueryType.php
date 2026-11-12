<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\DataInput\Infrastructure\Symfony\Form;

use Kadupul\DataInput\Domain\DataQuerySettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The settings of a data_queries.php data query. The worker that saves checks
 * the XML file and the Data Input Method again against what it reads.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class DataQueryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $inputs = [];
        foreach (is_array($options['inputs']) ? $options['inputs'] : [] as $id => $name) {
            if (is_int($id) && is_string($name)) {
                $inputs[$id] = $name;
            }
        }
        $text = static fn(string $label, string $help, int $length, int $size): array => ['label' => $label, 'help' => $help, 'required' => false,
            'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => $length, 'size' => $size]];
        $builder
            ->add('name', TextType::class, $text('Name', 'A name for this data query.', DataQuerySettings::NAME_LENGTH, 60))
            ->add('description', TextType::class, $text('Description', 'A description for this data query.', DataQuerySettings::DESCRIPTION_LENGTH, 80))
            ->add('xml_path', TextType::class, $text('XML Path', 'The full path to the XML file containing definitions for this data query.', DataQuerySettings::XML_PATH_LENGTH, 80))
            // Choices are IDs, so methods that share a name stay distinct.
            ->add('data_input_id', ChoiceType::class, ['label' => 'Data Input Method',
                'help' => 'Choose the input method for this Data Query.  This input method defines how data is collected for each Device associated with the Data Query.',
                'choices' => array_keys($inputs), 'choice_label' => static fn(int $id): string => $inputs[$id], 'choice_translation_domain' => false,
                'placeholder' => 'Choose a Data Input Method', 'required' => false])
            ->add('revision', HiddenType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'data_query',
            'csrf_protection' => true,
            'csrf_token_id' => 'data_query_edit',
            'method' => 'POST',
            'allow_extra_fields' => false,
            'inputs' => [],
        ]);
        $resolver->setAllowedTypes('inputs', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'data_query';
    }
}
