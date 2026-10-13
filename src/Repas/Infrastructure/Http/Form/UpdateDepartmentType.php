<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\Command\UpdateDepartment\UpdateDepartmentCommand;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Symfony\Component\Form\FormInterface;
use Traversable;

class UpdateDepartmentType extends AbstractDepartmentType
{
    public function mapDataToForms(mixed $viewData, Traversable $forms): void
    {
        if (!$viewData instanceof DepartmentDTO) {
            return;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $forms['name']->setData($viewData->name);
        $forms['image']->setData($viewData->image);
    }

    public function mapFormsToData(Traversable $forms, mixed &$viewData): void
    {

        if (!$viewData instanceof DepartmentDTO) {
            return;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $viewData = new UpdateDepartmentCommand(
            slug: $viewData->slug,
            name: $forms['name']->getData(),
            image: $forms['image']->getData(),
        );
    }
}
