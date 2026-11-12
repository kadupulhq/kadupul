<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Form;

use Kadupul\Automation\Domain\TreeRuleChange;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The settings of an automation_tree_rules.php rule. Sub-tree headers are
 * grouped by tree, so the form needs no script; the store checks the pair.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class TreeRuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $trees = [];
        foreach (is_array($options['trees']) ? $options['trees'] : [] as $id => $name) {
            if (is_int($id) && is_string($name)) {
                $trees[$id] = $name;
            }
        }
        $headers = [];
        foreach (is_array($options['headers']) ? $options['headers'] : [] as $id => $header) {
            if (is_int($id) && is_array($header) && is_int($header['tree'] ?? null) && is_string($header['title'] ?? null) && is_int($header['depth'] ?? null)) {
                $headers[$id] = ['tree' => $header['tree'], 'title' => $header['title'], 'depth' => $header['depth']];
            }
        }
        $builder
            ->add('name', TextType::class, ['label' => 'Name', 'help' => 'A useful name for this Rule.', 'required' => false, 'trim' => false, 'empty_data' => '',
                'attr' => ['maxlength' => TreeRuleChange::NAME_LENGTH, 'size' => 80]])
            // Choices are IDs, so records that share a name stay distinct.
            ->add('tree_id', ChoiceType::class, ['label' => 'Tree', 'help' => 'Choose a Tree for the new Tree Items.',
                'choices' => array_keys($trees), 'choice_label' => static fn(int $id): string => $trees[$id], 'choice_translation_domain' => false])
            ->add('tree_item_id', ChoiceType::class, ['label' => 'Optional: Sub-Tree Item',
                'help' => 'Choose a header of the selected Tree to hook in, or its root. Make sure that it still exists when this rule is executed.',
                'choices' => [0, ...array_keys($headers)], 'choice_translation_domain' => false,
                // The dashes show depth, as the legacy drop-down did.
                'choice_label' => static fn(int $id): string => $id === 0 ? '[root]' : str_repeat('-', $headers[$id]['depth']) . ' ' . $headers[$id]['title'],
                'group_by' => static fn(int $id): ?string => $id === 0 ? null : ($trees[$headers[$id]['tree']] ?? '#' . $headers[$id]['tree'])])
            ->add('leaf_type', ChoiceType::class, ['label' => 'Leaf Item Type', 'help' => 'The Item Type that shall be dynamically added to the tree.',
                'choices' => ['Graph' => 2, 'Device' => 3]])
            ->add('host_grouping_type', ChoiceType::class, ['label' => 'Graph Grouping Style',
                'help' => 'Choose how graphs are grouped when drawn for this particular host on the tree. Used for Device leaves.',
                'choices' => ['Graph Template' => 1, 'Data Query Index' => 2]])
            ->add('enabled', CheckboxType::class, ['label' => 'Enable Rule', 'help' => 'Check this box to enable this rule.', 'required' => false])
            ->add('revision', HiddenType::class, ['required' => false]);
        if ($options['existing'] === true) {
            $builder->add('remove_graph_criteria', CheckboxType::class, ['label' => 'Remove graph-based criteria',
                'help' => 'Device leaves cannot use criteria on graph or graph template columns. When changing the Leaf Item Type to Device, check this box to remove those criteria; they cannot be recovered.',
                'required' => false]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'tree_rule',
            'csrf_protection' => true,
            'csrf_token_id' => 'automation_tree_rule_edit',
            'method' => 'POST',
            'allow_extra_fields' => false,
            'trees' => [],
            'headers' => [],
            'existing' => false,
        ]);
        $resolver->setAllowedTypes('trees', 'array');
        $resolver->setAllowedTypes('headers', 'array');
        $resolver->setAllowedTypes('existing', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'tree_rule';
    }
}
