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
        // Un administrateur crée des ingrédients communs (sans créateur), un utilisateur des ingrédients personnalisés
        $creatorId = $command->isAdmin ? null : $command->userId;

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

        // Le slug dérive du nom et du créateur : enregistrer sous un slug existant écraserait l'ingrédient
        if ($this->ingredientRepository->exists($ingredient->getSlug())) {
            throw IngredientException::alreadyExists($ingredient->getSlug());
        }

        $this->ingredientRepository->save($ingredient);

        if ($creatorId !== null) {
            // Un alias de l'utilisateur dont la clé est ce slug (ingrédient absorbé par une fusion, puis recréé) détournerait le nouvel
            // ingrédient vers l'ancien : la création le défait (ADR 0028). Un commun n'a pas d'alias : son créateur est un administrateur.
            $this->aliasRepository->deleteByAliasSlug($creatorId, $ingredient->getSlug());
            $this->eventDispatcher->dispatch(new IngredientCreatedEvent($creatorId, $ingredient->getSlug()));
        }

        // On récupère les unités compatibles à la conversion après la création de l'ingrédient
        $compatibleUnit = $this->getCompatibleUnit($ingredient);
        $ingredient->setCompatibleUnits($compatibleUnit);
        $this->ingredientRepository->save($ingredient);

        // Si l'ingrédient n'a pas la même unité dans les recettes qu'à l'achat et qu'on ne peut pas convertir entre les deux,
        // alors on crée une conversion entre les deux unités.
        if (!$cookingUnit->isEqual($purchaseUnit) && !$this->conversionService->canConvertToPurchaseUnit($ingredient)) {
            // L'erreur annule toute la commande (transaction de command.bus) : l'ingrédient n'est pas créé sans sa conversion
            if ($command->coefficient === null) {
                throw IngredientException::coefficientRequired();
            }

            $this->eventDispatcher->dispatch(new CreateIngredientWithConversionEvent(
                $ingredient->getSlug(),
                $command->coefficient,
            ));
        }

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
