<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\Command\UpdateConversion\UpdateConversionCommand;
use Repas\Repas\Application\Query\ListConversions\ConversionDTO;
use Symfony\Component\Form\FormInterface;
use Traversable;

class UpdateConversionType extends AbstractConversionType
{
    public function mapDataToForms(mixed $viewData, Traversable $forms): void
    {
        if (!$viewData instanceof ConversionDTO) {
            return ;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $forms['startUnit']->setData($viewData->startUnitSlug);
        $forms['endUnit']->setData($viewData->endUnitSlug);
        $forms['coefficient']->setData($viewData->coefficient);
        $forms['ingredient']->setData($viewData->ingredientSlug);
    }

    public function mapFormsToData(Traversable $forms, mixed &$viewData): void
    {
        if (!$viewData instanceof ConversionDTO) {
            return ;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $viewData = new UpdateConversionCommand(
            id: $viewData->id,
            startUnitSlug: $forms['startUnit']->getData(),
            endUnitSlug: $forms['endUnit']->getData(),
            coefficient: $forms['coefficient']->getData(),
            ingredientSlug: $forms['ingredient']->getData(),
        );
    }

}
