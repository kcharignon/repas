<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Shared\Domain\Tool\StringTool;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SelectDepartmentType extends AbstractType
{
    public function __construct(
        private readonly DepartmentRepository $departmentRepository,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $choices = [];
        foreach ($this->departmentRepository->findAll() as $department) {
            $choices[StringTool::upperCaseFirst($department->getName())] = $department->getSlug();
        }

        $resolver->setDefaults([
            'choices' => $choices,
            'label' => false,
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
