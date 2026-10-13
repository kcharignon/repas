<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\Command\UpdateUnit\UpdateUnitCommand;
use Repas\Repas\Application\Query\ListUnits\UnitDTO;
use Symfony\Component\Form\FormInterface;
use Traversable;

class UpdateUnitType extends AbstractUnitType
{
    public function mapDataToForms(mixed $viewData, Traversable $forms): void
    {
        if (!$viewData instanceof UnitDTO) {
            return;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $forms['name']->setData($viewData->name);
        $forms['symbol']->setData($viewData->symbol);
    }

    public function mapFormsToData(Traversable $forms, mixed &$viewData): void
    {
        /** @var FormInterface $forms */
        $forms = iterator_to_array($forms);

        $viewData = new UpdateUnitCommand(
            slug: $viewData->slug,
            name: $forms['name']->getData(),
            symbol: $forms['symbol']->getData(),
        );
    }

}
