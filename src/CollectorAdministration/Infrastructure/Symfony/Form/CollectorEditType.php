<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CollectorEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Name', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 30]])
            ->add('hostname', TextType::class, ['label' => 'Data collector hostname', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 100]])
            ->add('timezone', TextType::class, ['label' => 'Time zone', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 40, 'list' => 'collector-timezones']])
            ->add('notes', TextareaType::class, ['label' => 'Notes', 'required' => false, 'trim' => false, 'empty_data' => ''])
            ->add('revision', HiddenType::class, ['required' => false])
            ->add('processes', IntegerType::class, ['label' => 'Processes', 'required' => false, 'empty_data' => ''])
            ->add('threads', IntegerType::class, ['label' => 'Threads', 'required' => false, 'empty_data' => '']);

        if ($options['remote']) {
            $builder->add('sync_interval', ChoiceType::class, ['label' => 'Sync interval', 'choices' => [
                'Disabled / manual' => 0, 'Every 30 minutes' => 1800, 'Every hour' => 3600, 'Every 2 hours' => 7200,
                'Every 4 hours' => 14400, 'Every 8 hours' => 28800, 'Every 16 hours' => 57600, 'Every day' => 86400,
            ]]);

            $builder
                ->add('dbhost', TextType::class, ['label' => 'Remote database hostname', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 64]])
                ->add('dbdefault', TextType::class, ['label' => 'Remote database name', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 20]])
                ->add('dbuser', TextType::class, ['label' => 'Remote database user', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 20]])
                ->add('dbpass', PasswordType::class, ['label' => 'Remote database password', 'required' => false, 'trim' => false, 'empty_data' => '', 'always_empty' => true, 'attr' => ['maxlength' => 64]])
                ->add('dbport', IntegerType::class, ['label' => 'Remote database port', 'required' => false, 'attr' => ['maxlength' => 5]])
                ->add('dbretries', IntegerType::class, ['label' => 'Connection retries', 'required' => false, 'attr' => ['maxlength' => 5]])
                ->add('dbssl', CheckboxType::class, ['label' => 'Remote database SSL', 'required' => false])
                ->add('dbsslkey', TextType::class, ['label' => 'Remote database SSL key path', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 255]])
                ->add('dbsslcert', TextType::class, ['label' => 'Remote database SSL certificate path', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 255]])
                ->add('dbsslca', TextType::class, ['label' => 'Remote database SSL CA path', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 255]]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'collectors', 'csrf_protection' => true, 'csrf_token_id' => 'collector_edit',
            'method' => 'POST', 'remote' => true, 'allow_extra_fields' => false,
        ]);
        $resolver->setAllowedTypes('remote', 'bool');
    }
}
