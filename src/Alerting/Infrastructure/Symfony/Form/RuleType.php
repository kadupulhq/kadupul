<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Symfony\Form;

use Kadupul\Alerting\Application\Rule\RuleDefinition;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The rule form. Every number is a text field, so its grammar is checked by
 * RuleSubmission and its range by the domain, not by the browser or a
 * locale-dependent number widget. Choices are fixed allowlists; a value
 * outside them makes the form invalid. Both kinds' fields are rendered and
 * submitted; only the selected kind's fields are read.
 */
/** @extends AbstractType<array<string, string>> */
final class RuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $text = static fn(string $label, int $maxlength, string $help = ''): array => ['label' => $label, 'required' => false, 'trim' => true, 'empty_data' => '', 'attr' => ['maxlength' => $maxlength, 'autocomplete' => 'off'], 'help' => $help === '' ? null : $help];
        $builder
            ->add('kind', ChoiceType::class, ['label' => 'Rule kind', 'expanded' => true, 'choices' => ['Threshold' => RuleDefinition::THRESHOLD, 'Hour-of-week baseline' => RuleDefinition::BASELINE]])
            ->add('local_data_id', TextType::class, $text('Data source ID', 10, 'The ID shown on the Data Sources page.') + ['attr' => ['maxlength' => 10, 'inputmode' => 'numeric', 'autocomplete' => 'off']])
            ->add('rrd_name', TextType::class, $text('RRD data source name', 19, 'The data source item name, for example traffic_in.'))
            ->add('for_cycles', TextType::class, $text('Consecutive cycles', 5, 'Breaches needed to open an incident, and recoveries needed to close it.') + ['attr' => ['maxlength' => 5, 'inputmode' => 'numeric', 'autocomplete' => 'off']])
            ->add('comparator', ChoiceType::class, ['label' => 'Breaches when the rate is', 'choices' => ['greater than' => 'gt', 'greater than or equal to' => 'ge', 'less than' => 'lt', 'less than or equal to' => 'le']])
            ->add('trigger_threshold', TextType::class, $text('Trigger threshold', 64))
            ->add('clear_threshold', TextType::class, $text('Clear threshold', 64, 'On the non-breaching side of the trigger threshold, or equal to it.'))
            ->add('baseline_direction', ChoiceType::class, ['label' => 'Breaches', 'choices' => ['above or below the band' => 'both', 'above the band' => 'above', 'below the band' => 'below']])
            ->add('baseline_deviations', TextType::class, $text('Deviation multiplier', 64, 'The band is the mean plus or minus this many standard deviations.'))
            ->add('baseline_clear_deviations', TextType::class, $text('Clear multiplier', 64, 'Greater than zero and no larger than the deviation multiplier.'))
            ->add('baseline_floor', TextType::class, $text('Deviation floor', 64, 'The smallest deviation used, in the series\' own unit; greater than zero.'))
            ->add('baseline_min_samples', TextType::class, $text('Minimum sample weeks', 1, 'Weeks of the last 8 that must have this hour, 2 to 8.') + ['attr' => ['maxlength' => 1, 'inputmode' => 'numeric', 'autocomplete' => 'off']])
            ->add('revision', HiddenType::class, ['required' => false, 'empty_data' => '']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'alerting',
            'csrf_protection' => true,
            'csrf_token_id' => 'alerting_rule_edit',
            'method' => 'POST',
            'allow_extra_fields' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'alert_rule';
    }
}
