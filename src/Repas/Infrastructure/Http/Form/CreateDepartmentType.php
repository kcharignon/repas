<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\CreateDepartment\CreateDepartmentCommand;
use Symfony\Component\Form\FormInterface;

class CreateDepartmentType extends AbstractDepartmentType
{
    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        // Always empty in creation
    }

    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $viewData = new CreateDepartmentCommand(
            name: $forms['name']->getData(),
            image: $forms['image']->getData(),
        );
    }
}
