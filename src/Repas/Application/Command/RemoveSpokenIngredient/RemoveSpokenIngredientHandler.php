<?php

namespace Repas\Repas\Application\Command\RemoveSpokenIngredient;

use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Application\Service\SpokenLineQuantity;
use Repas\Repas\Application\Service\SpokenListName;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Model\RemovedItem;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Retire un article dit à la voix de la liste ACTIVE (ADR 0029 décision 12), l'inverse d'`AddSpokenIngredientHandler` :
 *
 * - la ligne est retirée de la liste EN COURS qui la porte : la liste principale d'abord, sinon la plus ancienne liste d'un type qui la porte (ADR 0036,
 *   LST-008 ; le résultat nomme cette liste quand ce n'est pas la principale) ;
 * - jamais de liste créée, jamais une liste envoyée au drive modifiée (elle n'est pas « active » : rien à retirer) ; sans liste en cours ou sans
 *   ligne pour cet ingrédient, rien n'est écrit et le résultat dit `found` faux ;
 * - la quantité (1 à 99) est ramenée à l'unité de la ligne comme pour l'ajout (`SpokenLineQuantity` : unité dite convertie, ou laissée de côté si
 *   l'ingrédient ne la connaît pas) ;
 * - la ligne perd la quantité ; au moins sa quantité retirée, elle disparaît (`ShoppingList::removeQuantity`), jamais de quantité nulle ou négative ;
 * - le résultat dit ce qui reste dans la ligne, dans son unité, de quoi choisir la phrase d'Alexa.
 *
 * Alias résolus comme partout (un ancien slug désigne l'ingrédient actuel de l'utilisateur) ; un ingrédient d'un autre n'a aucune ligne dans la
 * liste de l'utilisateur : « pas dans la liste », sans rien révéler.
 *
 * Une PARTIE d'ingrédient (« Jaune d'œuf », ADR 0038 décisions 11 et 33) n'a pas de ligne : ce que la liste en a gardé est une entrée de son registre. Un
 * retrait suit la LISTE, jamais ce que l'ingrédient est aujourd'hui (décision 9) : la liste qui porte la ligne ou l'entrée du registre
 * (`findOneActiveHolding`, une seule requête, comme avant). Une entrée se diminue
 * PROPORTIONNELLEMENT (`ShoppingList::subtractPart`), la ligne du parent perd la différence de son plus grand équivalent, et le résultat décrit la ligne du
 * PARENT (`RemovedItem::$foldedInto` : « Il te reste 4 œufs. »). Un ingrédient ordinaire fait les mêmes lectures et les mêmes écritures qu'avant.
 */
#[AsMessageHandler]
readonly class RemoveSpokenIngredientHandler
{
    private SpokenLineQuantity $lineQuantity;

    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private IngredientSlugResolver $slugResolver,
        private UnitRepository $unitRepository,
        private IngredientRepository $ingredientRepository,
        ConversionService $conversionService,
        private SpokenListName $listName,
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

        // Un ancien slug (ancien commun dont l'utilisateur a reçu une copie, ingrédient fusionné) est remplacé par l'ingrédient actuel
        // de l'utilisateur (ADR 0028 décision 4)
        $ingredientSlug = $this->slugResolver->resolve($command->ownerId, [$command->ingredientSlug])[$command->ingredientSlug] ?? $command->ingredientSlug;

        // La liste en cours qui porte la ligne : la liste principale d'abord (un compte sans type n'en a pas d'autre), sinon la plus ancienne liste d'un
        // type qui la porte (ADR 0036, LST-008 : le même ordre que diminuer un produit). Une seule lecture ; sans liste en cours ou sans ligne, rien n'est écrit
        $shoppingList = $this->shoppingListRepository->findOneActiveHolding($command->ownerId, $ingredientSlug);
        if (!$shoppingList instanceof ShoppingList) {
            return new RemovedItem(found: false);
        }
        // Le registre de la liste décide, pas l'ingrédient d'aujourd'hui (décision 9) : une entrée pour ce slug (une partie d'ingrédient n'a jamais de ligne,
        // ADR 0038 décision 33) se diminue avant toute ligne
        if ($shoppingList->partShareOf($ingredientSlug) !== null) {
            return $this->removePart($shoppingList, $ingredientSlug, $command);
        }
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
            listName: $shoppingList->getListTypeId() === null ? null : $this->listName->ofTyped($shoppingList->getName(), $shoppingList->getListTypeId()),
        );
    }

    /**
     * Retire `$command->quantity` d'une PARTIE d'ingrédient, dans l'unité d'achat de la partie (celle de l'entrée du registre ; l'unité dite est convertie
     * quand elle diffère, comme pour une ligne). La partie entière quitte le registre quand on retire autant ou plus qu'elle n'a ; la ligne du parent
     * garde sa quantité directe (rien de ce que les œufs entiers ont posé n'est retiré).
     */
    private function removePart(ShoppingList $shoppingList, string $partSlug, RemoveSpokenIngredientCommand $command): RemovedItem
    {
        $kept = $shoppingList->partShareOf($partSlug)?->quantity;
        if ($kept === null) {
            return new RemovedItem(found: false);
        }
        $quantity = (float) $command->quantity;
        $substituted = false;
        if ($command->unitSlug !== null) {
            $partUnitSlug = $this->ingredientRepository->findOneBySlug($partSlug)->getDefaultPurchaseUnit()->getSlug();
            [, $quantity, $substituted] = $this->lineQuantity->inLineUnit($partSlug, $command->quantity, $command->unitSlug, $partUnitSlug, $partUnitSlug);
        }

        $share = $shoppingList->subtractPart($partSlug, $quantity);
        if ($share === null) {
            return new RemovedItem(found: false);
        }
        $this->shoppingListRepository->save($shoppingList);

        $parent = $this->ingredientRepository->findOneBySlug($share->parentSlug);
        $row = $shoppingList->findRow($parent->getSlug());
        $unitSlug = $row?->getUnitSlug() ?? $parent->getDefaultPurchaseUnit()->getSlug();

        return new RemovedItem(
            found: true,
            remaining: $row?->getQuantity() ?? 0.0,
            removed: min($quantity, $kept),
            unitSlug: $unitSlug,
            unitName: $this->unitRepository->findOneBySlug($unitSlug)->getName(),
            unitSubstituted: $substituted,
            listName: $shoppingList->getListTypeId() === null ? null : $this->listName->ofTyped($shoppingList->getName(), $shoppingList->getListTypeId()),
            foldedInto: $parent->getName(),
        );
    }
}
