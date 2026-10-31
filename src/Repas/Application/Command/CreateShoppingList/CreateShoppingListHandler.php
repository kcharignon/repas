<?php

namespace Repas\Repas\Application\Command\CreateShoppingList;

use Psr\Clock\ClockInterface;
use Repas\Repas\Domain\Event\NewShoppingListCreatedEvent;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListTypeRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CreateShoppingListHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
        private ShoppingListTypeRepository $listTypeRepository,
    ) {
    }

    /**
     * @return ShoppingList la liste créée (l'ouverture à la demande, `ActiveListForType`, s'en sert sans la relire)
     *
     * @throws ShoppingListException le propriétaire garde déjà `ShoppingList::MAX_PER_OWNER` listes
     * @throws ShoppingListTypeException le type demandé n'est pas à l'utilisateur (inconnu ou à un autre : `404`)
     */
    public function __invoke(CreateShoppingListCommand $query): ShoppingList
    {
        // Le type d'abord (il n'est jamais lu pour la liste principale) : un type qui n'est pas à l'utilisateur ne laisse rien d'écrit
        $listType = null;
        if ($query->listTypeId !== null) {
            $listType = $this->listTypeRepository->findOneById($query->listTypeId);
            $listType->assertOwnedBy($query->ownerId);
        }

        // Le plafond ensuite : rien n'est mis en pause ni créé au-delà
        if ($this->shoppingListRepository->ownerHasAtLeast($query->ownerId, ShoppingList::MAX_PER_OWNER)) {
            throw ShoppingListException::limitReached();
        }

        // Met en pause la liste en cours DU MÊME TYPE si elle existe (ADR 0036 décision 6) : les autres types et la liste principale ne bougent pas
        $activateShoppingList = $this->shoppingListRepository->findOneActiveByOwnerAndType($query->ownerId, $listType?->getId());
        if ($activateShoppingList instanceof ShoppingList) {
            $activateShoppingList->pause();
            $this->shoppingListRepository->save($activateShoppingList);
        }

        // Création d'une nouvelle liste (active), nommée comme son type quand elle en a un
        $shoppingList = ShoppingList::create(
            id: $query->shoppingListId,
            ownerId: $query->ownerId,
            createdAt: $this->clock->now(),
            listTypeId: $listType?->getId(),
            name: $listType?->getName(),
        );

        $this->shoppingListRepository->save($shoppingList);

        // Cet event est écouté par un eventListener qu'il supprime les listes terminées
        $this->eventDispatcher->dispatch(new NewShoppingListCreatedEvent($shoppingList->getId(), $query->ownerId));

        return $shoppingList;
    }
}
