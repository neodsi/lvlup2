<?php

namespace App\Form\App;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class SetupProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $inputClass = 'block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition';
        $labelClass = 'block text-xs text-gray-500 mb-1';

        $builder
            ->add('firstName', TextType::class, [
                'label' => 'Prénom',
                'required' => true,
                'constraints' => [new NotBlank()],
                'attr' => ['class' => $inputClass],
                'label_attr' => ['class' => $labelClass],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Nom',
                'required' => true,
                'constraints' => [new NotBlank()],
                'attr' => ['class' => $inputClass],
                'label_attr' => ['class' => $labelClass],
            ])
            ->add('phone', TextType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'attr' => ['class' => $inputClass, 'type' => 'tel'],
                'label_attr' => ['class' => $labelClass],
            ])
            ->add('dob', DateType::class, [
                'label' => 'Date de naissance',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'attr' => ['class' => $inputClass],
                'label_attr' => ['class' => $labelClass],
            ])
            ->add('gender', ChoiceType::class, [
                'label' => 'Genre',
                'required' => false,
                'placeholder' => '—',
                'choices' => [
                    'Femme' => 'female',
                    'Homme' => 'male',
                    'Ne pas préciser' => 'other',
                ],
                'attr' => ['class' => $inputClass],
                'label_attr' => ['class' => $labelClass],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id' => 'setup_profile',
            'data_class' => null,
        ]);
    }
}
