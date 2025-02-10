<?php

namespace Repas\Repas\Infrastructure\Http\Form;

use Repas\Repas\Application\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Domain\Model\RecipeRow;
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
        if (!$viewData instanceof RecipeRow) {
            return;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $forms['ingredientSlug']->setData($viewData->getIngredient()->getSlug());
        $forms['unitSlug']->setData($viewData->getUnit()->getSlug());
        $forms['quantity']->setData($viewData->getQuantity());
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
