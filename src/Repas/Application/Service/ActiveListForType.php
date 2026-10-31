<?php

namespace Repas\Repas\Application\Service;

use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListCommand;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListHandler;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Domain\Interface\UuidGenerator;

/**
 * Ouverture à la demande (ADR 0036 décision 7) : la liste en cours d'un type, ou, si le type n'en a pas, une liste neuve de ce type nommée
 * comme lui. Les ajouts de produits et de recettes (LST-005) passent par ici pour chaque liste qu'ils visent.
 *
 * - Un type sans liste en cours ouvre sa liste sans toucher aux autres listes (aucune n'est mise en pause : ce type n'en a pas en cours,
 *   `CreateShoppingListHandler` ne met en pause que celle du même type). Une liste `SENT` n'est jamais modifiée : elle n'est pas en cours,
 *   le type ouvre donc une liste neuve.
 * - La liste principale garde le comportement d'aujourd'hui : sans liste en cours elle n'est PAS ouverte en silence, l'appelant reçoit
 *   `ShoppingListException::activeShoppingListNotFound` (c'est l'appelant qui décide de la créer, comme la commande vocale).
 * - Le type d'un autre utilisateur ou inconnu est `404` (`LIST_TYPE_NOT_FOUND`), rien n'est écrit ; le plafond de listes s'applique à
 *   l'ouverture comme à toute création, et les listes terminées sont nettoyées comme à toute création.
 *
 * Coût : une lecture quand la liste existe ; sinon celle de la création (`CreateShoppingListHandler`).
 */
readonly class ActiveListForType
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private CreateShoppingListHandler $createShoppingListHandler,
        private UuidGenerator $uuidGenerator,
    ) {
    }

    /**
     * @param ?string $listTypeId le type visé, `null` pour la liste principale
     *
     * @throws ShoppingListException la liste principale n'a pas de liste en cours, ou le plafond de listes est atteint
     * @throws ShoppingListTypeException le type n'est pas à l'utilisateur
     */
    public function forType(string $ownerId, ?string $listTypeId): OpenedList
    {
        $active = $this->shoppingListRepository->findOneActiveByOwnerAndType($ownerId, $listTypeId);
        if ($active instanceof ShoppingList) {
            return new OpenedList($active, false);
        }

        if ($listTypeId === null) {
            throw ShoppingListException::activeShoppingListNotFound();
        }

        $opened = ($this->createShoppingListHandler)(new CreateShoppingListCommand($this->uuidGenerator->generate(), $ownerId, $listTypeId));

        return new OpenedList($opened, true);
    }
}
