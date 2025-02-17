<?php

namespace Repas\Repas\Infrastructure\Http\Form;



use Repas\Repas\Application\CreateUnit\CreateUnitCommand;
use Symfony\Component\Form\FormInterface;
use Traversable;

class CreateUnitType extends AbstractUnitType
{
    public function mapDataToForms(mixed $viewData, Traversable $forms): void
    {
        // Nothing to do in creation
    }

    public function mapFormsToData(Traversable $forms, mixed &$viewData): void
    {
        /** @var FormInterface $forms */
        $forms = iterator_to_array($forms);

        $viewData = new CreateUnitCommand(
            name: $forms['name']->getData(),
            symbol: $forms['symbol']->getData(),
        );
    }
}
