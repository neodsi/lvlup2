<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Enum\SchoolStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SchoolStatusType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('status', ChoiceType::class, [
                'label' => 'Statut',
                'required' => true,
                'choices' => [
                    'En attente' => SchoolStatus::Waiting->value,
                    'Accepté'    => SchoolStatus::Accepted->value,
                    'Refusé'     => SchoolStatus::Refused->value,
                    'Désactivé'  => SchoolStatus::Disabled->value,
                ],
                'attr' => [
                    'class' => 'block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition',
                ],
                'label_attr' => [
                    'class' => 'block text-xs text-gray-500 mb-1',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'     => null,
            'csrf_protection' => true,
            'csrf_token_id'  => 'school_status',
        ]);
    }
}
