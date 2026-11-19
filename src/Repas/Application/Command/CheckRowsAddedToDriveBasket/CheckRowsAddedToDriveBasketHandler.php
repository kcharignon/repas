<?php

namespace Repas\Repas\Application\Command\CheckRowsAddedToDriveBasket;

use Psr\Clock\ClockInterface;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Écrit ce que le passage au drive a fait des lignes (DRV-745, ADR 0023 décision 3 amendée, ADR 0041) : la SEULE écriture qui coche une ligne d'une liste
 * envoyée, réservée à son propriétaire, et qui garde l'issue de chaque ligne (au panier, refusée par le drive, indisponible…). Elle ne passe pas par
 * l'agrégat (`ShoppingList` reste verrouillé) : une seule instruction SQL, jamais de décochage, aucune version de liste bougée (le front rouvre avec
 * le `If-Match` du plan), aucun `LineTickedEvent` (la dernière ligne cochée ne termine PAS la liste : « Terminer la liste » reste un geste explicite).
 */
#[AsMessageHandler]
readonly class CheckRowsAddedToDriveBasketHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private ShoppingListRowRepository $shoppingListRowRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Idempotent : un slug absent de la liste est ignoré, une ligne déjà cochée le reste et garde son origine.
     *
     * @throws ShoppingListException liste inconnue (404), à un autre utilisateur (403), ou pas envoyée au drive (409, rouverte ou terminée entre-temps)
     */
    public function __invoke(CheckRowsAddedToDriveBasketCommand $command): void
    {
        $ownerId = $this->shoppingListRepository->ownerIdOf($command->shoppingListId)
            ?? throw ShoppingListException::shoppingListNotFound($command->shoppingListId);
        // Un administrateur passe le voter du controller : le propriétaire seul coche, on le juge ici
        if ($ownerId !== $command->userId) {
            throw ShoppingListException::forbidden($command->shoppingListId);
        }
        if (!$this->shoppingListRepository->isSent($command->shoppingListId)) {
            throw ShoppingListException::notSent($command->shoppingListId);
        }

        $this->shoppingListRowRepository->recordDrivePassage($command->shoppingListId, $command->attempts, $this->clock->now());
    }
}
