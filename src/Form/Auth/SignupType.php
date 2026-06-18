<?php

namespace App\Form\Auth;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class SignupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $inputClass = 'block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition';
        $labelClass = 'block text-xs text-gray-500 mb-1';

        $builder
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'label_attr' => ['class' => $labelClass],
                'attr' => ['class' => $inputClass],
                'constraints' => [
                    new NotBlank(),
                    new Email(),
                ],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Mot de passe',
                'label_attr' => ['class' => $labelClass],
                'attr' => [
                    'class' => $inputClass,
                    'always_empty' => false,
                ],
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 8),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id' => 'signup',
            'data_class' => null,
        ]);
    }
}
