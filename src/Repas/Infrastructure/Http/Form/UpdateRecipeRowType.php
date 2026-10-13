<?php

namespace Repas\Repas\Infrastructure\Http\Form;

use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Application\Query\GetRecipe\RecipeRowDTO;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UpdateRecipeRowType extends AbstractRecipeRowType
{

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }

    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        if (!$viewData instanceof RecipeRowDTO) {
            return;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $forms['ingredientSlug']->setData($viewData->ingredientSlug);
        $forms['unitSlug']->setData($viewData->unitSlug);
        $forms['quantity']->setData($viewData->quantity);
    }

    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $viewData = new UpdateRecipeRowSubCommand(
            ingredientSlug: $forms['ingredientSlug']->getData(),
            unitSlug: $forms['unitSlug']->getData(),
            quantity: $forms['quantity']->getData(),
        );
    }


}
