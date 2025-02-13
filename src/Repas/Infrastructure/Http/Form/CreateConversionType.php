<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\CreateConversion\CreateConversionCommand;
use Symfony\Component\Form\FormInterface;

class CreateConversionType extends AbstractConversionType
{
    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        // Nothing to do on creation
    }

    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $viewData = new CreateConversionCommand(
            $forms['startUnit']->getData(),
            $forms['endUnit']->getData(),
            $forms['coefficient']->getData(),
            $forms['ingredient']?->getData(),
        );
    }

}
