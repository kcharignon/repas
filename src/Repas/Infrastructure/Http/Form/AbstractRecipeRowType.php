<?php

namespace Repas\Repas\Infrastructure\Http\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;

abstract class AbstractRecipeRowType extends AbstractType implements DataMapperInterface
{

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ingredientSlug', SelectIngredientType::class, [
                'label' => false,
            ])
            ->add('unitSlug', SelectUnitType::class, [
                'label' => false,
            ])
            ->add('quantity', NumberType::class, [
                'label' => false,
            ])
            ->setDataMapper($this);
    }
}
