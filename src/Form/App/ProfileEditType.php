<?php

namespace App\Form\App;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

class ProfileEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $inputClass = 'block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition';
        $labelClass = 'block text-xs text-gray-500 mb-1';

        $builder
            ->add('firstName', TextType::class, [
                'label' => 'Prénom',
                'label_attr' => ['class' => $labelClass],
                'attr' => ['class' => $inputClass],
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Nom',
                'label_attr' => ['class' => $labelClass],
                'attr' => ['class' => $inputClass],
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('phone', TextType::class, [
                'label' => 'Téléphone',
                'label_attr' => ['class' => $labelClass],
                'attr' => ['class' => $inputClass, 'type' => 'tel'],
            ])
            ->add('dob', DateType::class, [
                'label' => 'Date de naissance',
                'label_attr' => ['class' => $labelClass],
                'attr' => ['class' => $inputClass],
                'required' => false,
                'widget' => 'single_text',
                'input' => 'string',
                'html5' => true,
            ])
            ->add('gender', ChoiceType::class, [
                'label' => 'Genre',
                'label_attr' => ['class' => $labelClass],
                'attr' => ['class' => $inputClass],
                'required' => false,
                'placeholder' => '—',
                'choices' => [
                    'Femme' => 'female',
                    'Homme' => 'male',
                    'Ne pas préciser' => 'other',
                ],
            ])
            ->add('sizeTop', TextType::class, [
                'label'      => 'Taille haut',
                'label_attr' => ['class' => $labelClass],
                'attr'       => ['class' => $inputClass, 'placeholder' => 'ex: M, L, XL…'],
                'required'   => false,
            ])
            ->add('sizeBottom', TextType::class, [
                'label'      => 'Taille bas',
                'label_attr' => ['class' => $labelClass],
                'attr'       => ['class' => $inputClass, 'placeholder' => 'ex: 38, 40…'],
                'required'   => false,
            ])
            ->add('sizeShoe', TextType::class, [
                'label'      => 'Pointure',
                'label_attr' => ['class' => $labelClass],
                'attr'       => ['class' => $inputClass, 'placeholder' => 'ex: 42, 43…'],
                'required'   => false,
            ])
            ->add('avatar', FileType::class, [
                'label' => 'Photo de profil',
                'label_attr' => ['class' => $labelClass],
                'attr' => ['class' => $inputClass],
                'required' => false,
                'mapped' => false,
                'constraints' => [
                    new File([
                        'maxSize' => '2M',
                        'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                    ]),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id' => 'profile_edit',
            'data_class' => null,
        ]);
    }
}
