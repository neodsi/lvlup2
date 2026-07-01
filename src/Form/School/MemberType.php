<?php

namespace App\Form\School;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MemberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $inputClass = 'block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition';
        $labelClass = 'block text-xs text-gray-500 mb-1';

        $builder
            ->add('firstName', TextType::class, [
                'label' => 'Prénom',
                'required' => false,
                'attr' => ['class' => $inputClass],
                'label_attr' => ['class' => $labelClass],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Nom',
                'required' => false,
                'attr' => ['class' => $inputClass],
                'label_attr' => ['class' => $labelClass],
            ])
            ->add('dob', DateType::class, [
                'label' => 'Date de naissance',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => true,
                'attr' => ['class' => $inputClass, 'max' => date('Y-m-d'), 'data-dob' => 'true'],
                'label_attr' => ['class' => $labelClass],
            ])
            ->add('phone', TextType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'attr' => ['class' => $inputClass, 'type' => 'tel'],
                'label_attr' => ['class' => $labelClass],
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'required' => false,
                'mapped' => false,
                'attr' => ['class' => $inputClass],
                'label_attr' => ['class' => $labelClass],
            ])
            ->add('addressText', TextType::class, [
                'label' => 'Adresse',
                'required' => false,
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
            ->add('note', TextareaType::class, [
                'label' => 'Note interne',
                'required' => false,
                'attr' => ['class' => $inputClass, 'rows' => 4, 'maxlength' => 500],
                'label_attr' => ['class' => $labelClass],
            ])
            ->add('registrationStatus', ChoiceType::class, [
                'label' => "Statut d'inscription",
                'required' => false,
                'placeholder' => '— Aucun statut —',
                'choices' => [
                    'En attente' => 'waiting',
                    'Accepté'    => 'accepted',
                    'Refusé'     => 'refused',
                    'Suspendu'   => 'suspended',
                ],
                'attr' => ['class' => $inputClass],
                'label_attr' => ['class' => $labelClass],
            ])
            ->add('injuryWarning', TextareaType::class, [
                'label' => 'Avertissement blessure',
                'required' => false,
                'attr' => ['class' => $inputClass, 'rows' => 2, 'maxlength' => 200],
                'label_attr' => ['class' => $labelClass],
            ])
            ->add('emergencyName', TextType::class, [
                'label' => 'Nom contact urgence',
                'required' => false,
                'mapped' => false,
                'attr' => ['class' => $inputClass],
                'label_attr' => ['class' => $labelClass],
            ])
            ->add('emergencyRelationship', TextType::class, [
                'label' => 'Relation',
                'required' => false,
                'mapped' => false,
                'attr' => ['class' => $inputClass],
                'label_attr' => ['class' => $labelClass],
            ])
            ->add('emergencyEmail', EmailType::class, [
                'label' => 'Email contact urgence',
                'required' => false,
                'mapped' => false,
                'attr' => ['class' => $inputClass],
                'label_attr' => ['class' => $labelClass],
            ])
            ->add('emergencyPhone', TextType::class, [
                'label' => 'Téléphone contact urgence',
                'required' => false,
                'mapped' => false,
                'attr' => ['class' => $inputClass, 'type' => 'tel'],
                'label_attr' => ['class' => $labelClass],
            ])
            ->add('consentAccepted', ChoiceType::class, [
                'label' => 'Consentements signés',
                'required' => false,
                'mapped' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => [
                    'Certificat médical' => 'medical_certificate',
                    'Attestation RC' => 'civil_liability_insurance_certificate',
                    "Droit à l'image" => 'image_rights_authorization',
                    'Données personnelles' => 'personal_data_storage_authorization',
                    'Consentement médical urgence' => 'medical_emergency_action_consent',
                ],
                'label_attr' => ['class' => $labelClass],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id' => 'member_form',
            'data_class' => null,
        ]);
    }
}
