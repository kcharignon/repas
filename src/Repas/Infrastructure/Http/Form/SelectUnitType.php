<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Shared\Domain\Tool\StringTool;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SelectUnitType extends AbstractType
{
    public function __construct(
        private readonly UnitRepository $unitRepository,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $unitChoices = [];
        foreach ($this->unitRepository->findAll() as $unit) {
            $unitChoices[StringTool::upperCaseFirst($unit->getName())] = $unit->getSlug();
        }

        $resolver->setDefaults([
            'choices' => $unitChoices,
            'label' => false,
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
