<?php

namespace Repas\Repas\Infrastructure\Http\Form;

use Repas\Repas\Application\Query\ListUnits\ListUnitsQuery;
use Repas\Repas\Application\Query\ListUnits\UnitDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Tool\StringTool;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SelectUnitType extends AbstractType
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $choices = [];
        /** @var list<UnitDTO> $units */
        $units = $this->queryBus->ask(new ListUnitsQuery());
        foreach ($units as $unit) {
            $choices[StringTool::upperCaseFirst($unit->name)] = $unit->slug;
        }

        $resolver->setDefaults([
            'label' => false,
            'choices' => $choices,
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
