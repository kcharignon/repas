<?php

namespace Repas\Repas\Application\Command\RemoveSpokenIngredient;

use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Application\Service\SpokenLineQuantity;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Shared\Application\Interface\RemovedItem;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Retire un article dit à la voix de la liste ACTIVE (ADR 0029 décision 12), l'inverse d'`AddSpokenIngredientHandler` :
 *
 * - jamais de liste créée, jamais une liste envoyée au drive modifiée (elle n'est pas « active » : rien à retirer) ; sans liste active ou sans
 *   ligne pour cet ingrédient, rien n'est écrit et le résultat dit `found` faux ;
 * - la quantité (1 à 99) est ramenée à l'unité de la ligne comme pour l'ajout (`SpokenLineQuantity` : unité dite convertie, ou laissée de côté si
 *   l'ingrédient ne la connaît pas) ;
 * - la ligne perd la quantité ; au moins sa quantité retirée, elle disparaît (`ShoppingList::removeQuantity`), jamais de quantité nulle ou négative ;
 * - le résultat dit ce qui reste dans la ligne, dans son unité, de quoi choisir la phrase d'Alexa.
 *
 * Alias résolus comme partout (un ancien slug désigne l'ingrédient actuel de l'utilisateur) ; un ingrédient d'un autre n'a aucune ligne dans la
 * liste de l'utilisateur : « pas dans la liste », sans rien révéler.
 */
#[AsMessageHandler]
readonly class RemoveSpokenIngredientHandler
{
    private SpokenLineQuantity $lineQuantity;

    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private IngredientSlugResolver $slugResolver,
        private UnitRepository $unitRepository,
        IngredientRepository $ingredientRepository,
        ConversionService $conversionService,
    ) {
        $this->lineQuantity = new SpokenLineQuantity($ingredientRepository, $unitRepository, $conversionService);
    }

    /**
     * @throws ShoppingListException
     * @throws IngredientException
     */
    public function __invoke(RemoveSpokenIngredientCommand $command): RemovedItem
    {
        if ($command->quantity < RemoveSpokenIngredientCommand::MIN_QUANTITY || $command->quantity > RemoveSpokenIngredientCommand::MAX_QUANTITY) {
            throw ShoppingListException::invalidSpokenQuantity($command->quantity);
        }

        $shoppingList = $this->shoppingListRepository->findOneActivateByOwner($command->ownerId);
        if (!$shoppingList instanceof ShoppingList) {
            return new RemovedItem(found: false);
        }

        // Un ancien slug (ancien commun dont l'utilisateur a reçu une copie, ingrédient fusionné) est remplacé par l'ingrédient actuel
        // de l'utilisateur (ADR 0028 décision 4)
        $ingredientSlug = $this->slugResolver->resolve($command->ownerId, [$command->ingredientSlug])[$command->ingredientSlug] ?? $command->ingredientSlug;
        $row = $shoppingList->findRow($ingredientSlug);
        if ($row === null) {
            return new RemovedItem(found: false);
        }

        [$unitSlug, $quantity, $substituted] = $this->lineQuantity->inLineUnit($ingredientSlug, $command->quantity, $command->unitSlug, $row->getUnitSlug(), $row->getUnitSlug());

        $before = $row->getQuantity();
        $remaining = $shoppingList->removeQuantity($ingredientSlug, $quantity) ?? 0.0;
        $this->shoppingListRepository->save($shoppingList);

        return new RemovedItem(
            found: true,
            remaining: $remaining,
            removed: min($quantity, $before),
            unitSlug: $unitSlug,
            unitName: $this->unitRepository->findOneBySlug($unitSlug)->getName(),
            unitSubstituted: $substituted,
        );
    }
}
