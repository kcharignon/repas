<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\CreateRecipeType\CreateRecipeTypeCommand;
use Symfony\Component\Form\FormInterface;

class CreateRecipeTypeType extends AbstractRecipeTypeType
{
    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        // C'est forcement vide a la creation
    }

    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $viewData = new CreateRecipeTypeCommand(
            $forms['name']->getData(),
            $forms['image']->getData(),
            $forms['order']->getData(),
        );
    }

}
