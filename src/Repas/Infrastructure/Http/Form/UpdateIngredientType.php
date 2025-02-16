<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\UpdateIngredient\UpdateIngredientCommand;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\FormInterface;
use Traversable;

class UpdateIngredientType extends AbstractIngredientType implements DataMapperInterface
{
    public function __construct(
        readonly ConversionRepository $conversionRepository,
    ) {
    }

    /**
     * @param mixed $viewData
     * @param Traversable $forms
     * @return void
     */
    public function mapDataToForms(mixed $viewData, Traversable $forms): void
    {
        if (!$viewData instanceof Ingredient) {
            return;
        }
        $ingredient = $viewData;

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $forms['name']->setData($ingredient->getName());
        $forms['department']->setData($ingredient->getDepartment()->getSlug());
        $forms['defaultCookingUnit']->setData($ingredient->getDefaultCookingUnit()->getSlug());
        $forms['defaultPurchaseUnit']->setData($ingredient->getDefaultPurchaseUnit()->getSlug());
        if (!$ingredient->hasSameUnitInCookingAndPurchase()) {
            $forms['coefficient']->setData($this->findCoefficient($ingredient));
        }
    }

    /**
     * @param UpdateIngredientCommand $viewData
     */
    public function mapFormsToData(Traversable $forms, mixed &$viewData): void
    {
        if (!$viewData instanceof Ingredient) {
            return;
        }

        $forms = iterator_to_array($forms);

        $viewData = new UpdateIngredientCommand(
            slug: $viewData->getSlug(),
            name: $forms['name']->getData(),
            image: '',
            departmentSlug: $forms['department']->getData(),
            defaultCookingUnitSlug: $forms['defaultCookingUnit']->getData(),
            defaultPurchaseUnitSlug: $forms['defaultPurchaseUnit']->getData(),
            coefficient: $forms['coefficient']->getData(),
        );
    }

    private function findCoefficient(Ingredient $ingredient): float
    {
        return $this->conversionRepository->findByIngredientAndStartUnitAndEndUnit(
            $ingredient,
            $ingredient->getDefaultPurchaseUnit(),
            $ingredient->getDefaultCookingUnit()
        )?->getCoefficient();
    }
}
