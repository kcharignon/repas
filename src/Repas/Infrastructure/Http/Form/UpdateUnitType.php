<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\UpdateUnit\UpdateUnitCommand;
use Repas\Repas\Domain\Model\Unit;
use Symfony\Component\Form\FormInterface;
use Traversable;

class UpdateUnitType extends AbstractUnitType
{
    public function mapDataToForms(mixed $viewData, Traversable $forms): void
    {
        if (!$viewData instanceof Unit) {
            return;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $forms['name']->setData($viewData->getName());
        $forms['symbol']->setData($viewData->getSymbol());
    }

    public function mapFormsToData(Traversable $forms, mixed &$viewData): void
    {
        /** @var FormInterface $forms */
        $forms = iterator_to_array($forms);

        $viewData = new UpdateUnitCommand(
            slug: $viewData->getSlug(),
            name: $forms['name']->getData(),
            symbol: $forms['symbol']->getData(),
        );
    }

}
