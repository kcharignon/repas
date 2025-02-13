<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\UpdateConversion\UpdateConversionCommand;
use Repas\Repas\Domain\Model\Conversion;
use Symfony\Component\Form\FormInterface;
use Traversable;

class UpdateConversionType extends AbstractConversionType
{
    public function mapDataToForms(mixed $viewData, Traversable $forms): void
    {
        if (!$viewData instanceof Conversion) {
            return ;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $forms['startUnit']->setData($viewData->getStartUnit()->getSlug());
        $forms['endUnit']->setData($viewData->getEndUnit()->getSlug());
        $forms['coefficient']->setData($viewData->getCoefficient());
        $forms['ingredient']->setData($viewData->getIngredient()?->getSlug());
    }

    public function mapFormsToData(Traversable $forms, mixed &$viewData): void
    {
        if (!$viewData instanceof Conversion) {
            return ;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $viewData = new UpdateConversionCommand(
            id: $viewData->getId(),
            startUnitSlug: $forms['startUnit']->getData(),
            endUnitSlug: $forms['endUnit']->getData(),
            coefficient: $forms['coefficient']->getData(),
            ingredientSlug: $forms['ingredient']->getData(),
        );
    }

}
