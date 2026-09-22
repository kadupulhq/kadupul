<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SiteCreateType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['name' => 'Name', 'address1' => 'Address line 1', 'address2' => 'Address line 2', 'city' => 'City', 'state' => 'State', 'postal_code' => 'Postal code', 'country' => 'Country', 'latitude' => 'Latitude', 'longitude' => 'Longitude', 'zoom' => 'Map zoom', 'alternate_id' => 'Alternate name'] as $field => $label) {
            $builder->add($field, TextType::class, ['label' => $label, 'required' => $field === 'name', 'trim' => false, 'empty_data' => '']);
        }
        $zones = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC);
        $builder->add('timezone', ChoiceType::class, ['label' => 'Timezone', 'required' => false, 'placeholder' => 'Default timezone', 'invalid_message' => $this->translator->trans('Select a valid timezone.', [], 'inventory'), 'choices' => array_combine($zones, $zones), 'choice_translation_domain' => false, 'empty_data' => '']);
        $builder->add('notes', TextareaType::class, ['label' => 'Notes', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['rows' => 5]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'inventory', 'csrf_protection' => true, 'csrf_token_id' => 'inventory_site_create', 'method' => 'POST']);
    }
}
