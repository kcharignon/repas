<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Shared\Domain\Tool\Tab;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\Options;
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
            'label' => false,
            'ingredient' => null, // permet d'avoir l'option disponible
            'choices' => function (Options $options) use ($unitChoices) {
                return $this->getUnitChoices($options['ingredient'] ?? null);
            },
        ]);
    }

    private function getUnitChoices(?Ingredient $ingredient): array
    {
        if ($ingredient instanceof Ingredient) {
            return $this->convertUnitsToOptions($ingredient->getCompatibleUnits());
        } else {
            return $this->convertUnitsToOptions($this->unitRepository->findAll());
        }
    }

    private function convertUnitsToOptions(Tab $units): array
    {
        $unitChoices = [];
        foreach ($units as $unit) {
            $unitChoices[StringTool::upperCaseFirst($unit->getName())] = $unit->getSlug();
        }
        return $unitChoices;
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
