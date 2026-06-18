<?php

namespace App\Form\App;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class CreateSchoolType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $inputClass = 'block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition';
        $labelClass = 'block text-xs text-gray-500 mb-1';

        $builder
            ->add('name', TextType::class, [
                'label' => "Nom de l'école",
                'label_attr' => ['class' => $labelClass],
                'attr' => ['class' => $inputClass],
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 255),
                ],
            ])
            ->add('type', TextType::class, [
                'label' => 'Type (danse, gym…)',
                'label_attr' => ['class' => $labelClass],
                'attr' => ['class' => $inputClass],
                'required' => false,
                'constraints' => [
                    new Length(max: 100),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id' => 'create_school',
            'data_class' => null,
        ]);
    }
}
