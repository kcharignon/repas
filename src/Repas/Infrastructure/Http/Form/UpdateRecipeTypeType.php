<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\UpdateRecipeType\UpdateRecipeTypeCommand;
use Repas\Repas\Domain\Model\RecipeType;
use Symfony\Component\Form\FormInterface;

class UpdateRecipeTypeType extends AbstractRecipeTypeType
{
    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        if (!$viewData instanceof RecipeType) {
            return;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $forms['name']->setData($viewData->getName());
        $forms['image']->setData($viewData->getImage());
        $forms['order']->setData($viewData->getOrder());
    }

    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        if (!$viewData instanceof RecipeType) {
            return;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $viewData = new UpdateRecipeTypeCommand(
            id: $viewData->getId(),
            name: $forms['name']->getData(),
            image: $forms['image']->getData(),
            order: $forms['order']->getData(),
        );
    }

}
