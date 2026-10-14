<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\Command\UpdateIngredient\UpdateIngredientCommand;
use Repas\Repas\Application\Query\GetIngredient\IngredientDTO;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\FormInterface;
use Traversable;

class UpdateIngredientType extends AbstractIngredientType implements DataMapperInterface
{
    /**
     * @param mixed $viewData
     * @param Traversable $forms
     * @return void
     */
    public function mapDataToForms(mixed $viewData, Traversable $forms): void
    {
        if (!$viewData instanceof IngredientDTO) {
            return;
        }
        $ingredient = $viewData;

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $forms['name']->setData($ingredient->name);
        $forms['department']->setData($ingredient->departmentSlug);
        $forms['defaultCookingUnit']->setData($ingredient->cookingUnitSlug);
        $forms['defaultPurchaseUnit']->setData($ingredient->purchaseUnitSlug);
        if (!$ingredient->hasSameUnit) {
            $forms['coefficient']->setData($ingredient->coefficient);
        }
    }

    /**
     * @param IngredientDTO $viewData en entrée, remplacé par la commande de modification
     * @param-out UpdateIngredientCommand $viewData
     */
    public function mapFormsToData(Traversable $forms, mixed &$viewData): void
    {
        if (!$viewData instanceof IngredientDTO) {
            return;
        }

        $forms = iterator_to_array($forms);

        $viewData = new UpdateIngredientCommand(
            slug: $viewData->slug,
            name: $forms['name']->getData(),
            image: null,
            departmentSlug: $forms['department']->getData(),
            defaultCookingUnitSlug: $forms['defaultCookingUnit']->getData(),
            defaultPurchaseUnitSlug: $forms['defaultPurchaseUnit']->getData(),
            coefficient: $forms['coefficient']->getData(),
        );
    }
}
