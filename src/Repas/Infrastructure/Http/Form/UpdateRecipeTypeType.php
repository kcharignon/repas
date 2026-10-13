<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\Command\UpdateRecipeType\UpdateRecipeTypeCommand;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;
use Symfony\Component\Form\FormInterface;

class UpdateRecipeTypeType extends AbstractRecipeTypeType
{
    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        if (!$viewData instanceof RecipeTypeDTO) {
            return;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $forms['name']->setData($viewData->name);
        $forms['image']->setData($viewData->image);
        $forms['order']->setData($viewData->order);
    }

    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        if (!$viewData instanceof RecipeTypeDTO) {
            return;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $viewData = new UpdateRecipeTypeCommand(
            id: $viewData->slug,
            name: $forms['name']->getData(),
            image: $forms['image']->getData(),
            order: $forms['order']->getData(),
        );
    }

}
