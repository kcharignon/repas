<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

abstract class AbstractConversionType extends AbstractType implements DataMapperInterface
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('startUnit', SelectUnitType::class, [
                'label' => 'Unité de départ',
                'required' => true,
            ])
            ->add('endUnit', SelectUnitType::class, [
                'label' => 'Unité de fin',
                'required' => true,
            ])
            ->add('coefficient', NumberType::class, [
                'label' => 'Coefficient',
                'required' => true,
            ])
            ->add('ingredient', SelectIngredientType::class, [
                'label' => 'Ingredient',
                'required' => false,
            ])
            ->setDataMapper($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'empty_value' => null,
        ]);
    }
}
