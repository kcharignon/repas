<?php

namespace Repas\Repas\Application\Command\CreateIngredient;


use Repas\Repas\Domain\Event\CreateIngredientWithConversionEvent;
use Repas\Shared\Domain\Event\IngredientCreatedEvent;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\QuantityBounds;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Shared\Application\Interface\IngredientCatalogOwner;
use Repas\Shared\Domain\Tool\Tab;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;


#[AsMessageHandler]
readonly class CreateIngredientHandler
{
    public function __construct(
        private DepartmentRepository $departmentRepository,
        private UnitRepository $unitRepository,
        private IngredientRepository $ingredientRepository,
        private IngredientAliasRepository $aliasRepository,
        private EventDispatcherInterface $eventDispatcher,
        private ConversionService $conversionService,
        private IngredientCatalogOwner $catalogOwner,
    ) {
    }

    /**
     * @throws DepartmentException
     * @throws UnitException
     */
    public function __invoke(CreateIngredientCommand $command): string
    {
        $cookingUnit = $this->unitRepository->findOneBySlug($command->defaultCookingUnitSlug);
        $purchaseUnit = $this->unitRepository->findOneBySlug($command->defaultPurchaseUnitSlug);
        // Tout ingrédient est à celui qui le crée, administrateur compris : plus aucun nouvel ingrédient commun (ADR 0028, amendement)
        $creatorId = $command->userId;

        $ingredient = Ingredient::create(
            name: $command->name,
            image: $command->image,
            department: $this->departmentRepository->findOneBySlug($command->departmentSlug),
            defaultCookingUnit: $cookingUnit,
            defaultPurchaseUnit: $purchaseUnit,
            creatorId: $creatorId,
        );

        // Le marqueur « de base » (ADR 0028, amendement) : seul le propriétaire du catalogue le pose, sinon `403` et rien n'est écrit. Un
        // ingrédient naît à faux : sans le demander, le propriétaire du catalogue n'est pas lu (aucune requête de plus).
        if ($command->isBase) {
            $ingredient->assertBaseMarkerSettableBy($command->userId, $this->catalogOwner->ownerId());
            $ingredient->changeBase(true);
        }

        // Le slug dérive du nom et du créateur : enregistrer sous un slug existant écraserait l'ingrédient. Le nom normalisé du créateur est
        // vérifié dans la même requête : un ancien commun passé à son propriétaire a un slug sans identifiant du compte.
        if ($this->ingredientRepository->existsForCreator($ingredient->getSlug(), $creatorId, $ingredient->getSluggedName())) {
            throw IngredientException::alreadyExists($ingredient->getSlug());
        }

        $this->ingredientRepository->save($ingredient);

        // Un alias de l'utilisateur dont la clé est ce slug (ingrédient absorbé par une fusion, puis recréé) détournerait le nouvel
        // ingrédient vers l'ancien : la création le défait (ADR 0028).
        $this->aliasRepository->deleteByAliasSlug($creatorId, $ingredient->getSlug());
        $this->eventDispatcher->dispatch(new IngredientCreatedEvent($creatorId, $ingredient->getSlug()));

        // Si l'ingrédient n'a pas la même unité dans les recettes qu'à l'achat et que le graphe ne relie pas les deux, une conversion est
        // obligatoire (le coefficient). L'erreur annule toute la commande (transaction de command.bus) : l'ingrédient n'est pas créé sans sa conversion.
        $unitsDiffer = !$cookingUnit->isEqual($purchaseUnit);
        if ($unitsDiffer && !$this->conversionService->canConvertToPurchaseUnit($ingredient)) {
            if ($command->coefficient === null) {
                throw IngredientException::coefficientRequired();
            }
            if (!QuantityBounds::isCoefficient($command->coefficient)) {
                throw IngredientException::invalidCoefficient();
            }

            $this->eventDispatcher->dispatch(new CreateIngredientWithConversionEvent(
                $ingredient->getSlug(),
                $command->coefficient,
                $creatorId,
            ));
            // Le graphe en cache est périmé : la garantie ci-dessous et les unités compatibles se calculent sur le graphe FINAL
            $this->conversionService->clear();
        }

        // La règle « l'unité des recettes se convertit TOUJOURS en l'unité d'achat » tient ici par construction : soit le graphe relie déjà les deux
        // unités, soit le coefficient (strictement positif, contrôlé plus haut) crée la conversion directe achat -> recettes par l'écouteur synchrone de
        // l'événement. La modification d'un ingrédient la contrôle en plus sur le graphe final (`UpdateIngredientHandler`).

        // On récupère les unités compatibles à la conversion après la création de l'ingrédient et de sa conversion
        $ingredient->setCompatibleUnits($this->getCompatibleUnit($ingredient));
        $this->ingredientRepository->save($ingredient);

        return $ingredient->getSlug();
    }

    /**
     * @return Tab<Unit>
     */
    private function getCompatibleUnit(Ingredient $ingredient): Tab
    {
        // Si c'est la meme unite, inutile de chercher depuis l'unité de cuisine et l'unité d'achat
        if ($ingredient->hasSameUnitInCookingAndPurchase()) {
            return $this->conversionService->getConvertibleUnits($ingredient, $ingredient->getDefaultCookingUnit());
        }

        // Récupération des unités compatibles à partir des unités de cuisine et d'achat
        $compatibleFromCooking = $this->conversionService->getConvertibleUnits($ingredient, $ingredient->getDefaultCookingUnit());
        $compatibleFromPurchase = $this->conversionService->getConvertibleUnits($ingredient, $ingredient->getDefaultPurchaseUnit());

        // Suppression des doublons
        return $compatibleFromCooking
            ->merge($compatibleFromPurchase)
            ->uniqueObject(fn(Unit $unit) => $unit->getSlug());
    }
}
