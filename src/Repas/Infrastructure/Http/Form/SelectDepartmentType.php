<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Application\Query\ListDepartments\ListDepartmentsQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Tool\StringTool;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SelectDepartmentType extends AbstractType
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $choices = [];
        /** @var list<DepartmentDTO> $departments */
        $departments = $this->queryBus->ask(new ListDepartmentsQuery());
        foreach ($departments as $department) {
            $choices[StringTool::upperCaseFirst($department->name)] = $department->slug;
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
