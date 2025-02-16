<?php

namespace Repas\Repas\Infrastructure\Http\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

abstract class AbstractIngredientType extends AbstractType implements DataMapperInterface
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'required' => true,
                'label' => 'Nom',
            ])
            ->add('department', SelectDepartmentType::class, [
                'label' => 'Rayon',
                'required' => true,
            ])
            ->add('defaultCookingUnit', SelectUnitType::class, [
                'label' => 'Unité dans les recettes',
                'required' => true,
            ])
            ->add('defaultPurchaseUnit', SelectUnitType::class, [
                'label' => "Unité à l'achat",
                'required' => true,
            ])
            ->add('coefficient', NumberType::class, [
                'label' => false,
                'required' => false,
            ])
            ->setDataMapper($this)
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'empty_data' => null,
        ]);
    }
}
