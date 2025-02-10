<?php

namespace Repas\Repas\Infrastructure\Http\Form;

use Repas\Repas\Application\CreateRecipe\CreateRecipeRowSubCommand;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CreateRecipeRowType extends AbstractRecipeRowType
{

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }

    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        // Always empty in creation
    }

    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $viewData = new CreateRecipeRowSubCommand(
            ingredientSlug: $forms['ingredientSlug']->getData(),
            unitSlug: $forms['unitSlug']->getData(),
            quantity: $forms['quantity']->getData(),
        );
    }


}
