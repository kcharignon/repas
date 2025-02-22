<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\CreateDepartment\CreateDepartmentCommand;
use Repas\Repas\Application\UpdateDepartment\UpdateDepartmentCommand;
use Repas\Repas\Domain\Model\Department;
use Symfony\Component\Form\FormInterface;
use Traversable;

class UpdateDepartmentType extends AbstractDepartmentType
{
    public function mapDataToForms(mixed $viewData, Traversable $forms): void
    {
        if (!$viewData instanceof Department) {
            return;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $forms['name']->setData($viewData->getName());
        $forms['image']->setData($viewData->getImage());
    }

    public function mapFormsToData(Traversable $forms, mixed &$viewData): void
    {

        if (!$viewData instanceof Department) {
            return;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $viewData = new UpdateDepartmentCommand(
            slug: $viewData->getSlug(),
            name: $forms['name']->getData(),
            image: $forms['image']->getData(),
        );
    }
}
