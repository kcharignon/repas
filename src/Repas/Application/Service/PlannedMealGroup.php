<?php

namespace Repas\Repas\Application\Service;

use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\ShoppingList;

/**
 * Le groupe de repas d'une planification (ADR 0036 décision 9) : une recette qui remplit plusieurs listes a un repas dans chacune, et les repas
 * d'une même planification partagent `meal.planned_group_id`. Changer le nombre de personnes ou retirer la recette agit sur TOUT le groupe,
 * en une transaction ; si une des listes du groupe est envoyée au drive, tout est refusé (`409 SHOPPING_LIST_SENT`) et rien n'est écrit.
 *
 * Ce service ne fait que retrouver le groupe et juger les garde-fous communs à ces deux écritures, avant que l'appelant ne modifie quoi que ce soit :
 *
 * - un repas sans groupe (tous ceux d'avant les types, et ceux dont la recette n'a rempli qu'une liste) agit seul, sans aucune lecture de plus ;
 * - une liste TERMINÉE du groupe est de l'histoire, jamais modifiée (elle est ignorée) ; une liste en pause l'est (c'est ce que devient la liste
 *   d'un type supprimé) ;
 * - `If-Match` (ADR 0016) ne vaut que pour la liste principale, quand elle est dans le groupe : le client ne lit que celle-là.
 */
readonly class PlannedMealGroup
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
    ) {
    }

    /**
     * Le repas de départ d'abord, puis ceux des autres listes du groupe (une seule lecture, et seulement si le repas a un groupe).
     *
     * @return non-empty-list<GroupedMeal>
     */
    public function of(ShoppingList $shoppingList, Meal $meal): array
    {
        $members = [new GroupedMeal($shoppingList, $meal)];

        $groupId = $meal->getPlannedGroupId();
        if ($groupId === null) {
            return $members;
        }

        foreach ($this->shoppingListRepository->findOtherListsOfPlannedGroup($shoppingList->getOwnerId(), $groupId, $shoppingList->getId()) as $other) {
            if ($other->isCompleted()) {
                continue;
            }
            foreach ($other->getMeals() as $otherMeal) {
                if ($otherMeal->getPlannedGroupId() === $groupId) {
                    $members[] = new GroupedMeal($other, $otherMeal);
                }
            }
        }

        return $members;
    }

    /**
     * `If-Match` ne vaut que pour la liste principale (ADR 0036 décision 12) : celle d'un type est ignorée, et rien n'est jugé quand la liste
     * principale n'est pas dans le groupe.
     *
     * @param list<GroupedMeal> $members
     *
     * @throws ShoppingListException la liste principale a changé depuis sa lecture
     */
    public function assertVersion(array $members, ?int $expectedVersion): void
    {
        foreach ($members as $member) {
            if ($member->shoppingList->getListTypeId() === null) {
                $member->shoppingList->assertVersion($expectedVersion);
            }
        }
    }

    /**
     * Une liste du groupe est-elle envoyée au drive ? La commande vocale le demande avant d'écrire, pour le dire (« Ta liste est déjà partie au drive. »)
     * au lieu de laisser `assertEditable` refuser en `409`.
     *
     * @param list<GroupedMeal> $members
     */
    public function hasSentList(array $members): bool
    {
        foreach ($members as $member) {
            if ($member->shoppingList->isSent()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Aucune liste du groupe n'est envoyée au drive : sinon tout est refusé (les repas sont modifiés sans passer par un mutateur de l'agrégat, la
     * garde de la liste envoyée est explicite, ADR 0023).
     *
     * @param list<GroupedMeal> $members
     *
     * @throws ShoppingListException une liste du groupe est envoyée au drive
     */
    public function assertEditable(array $members): void
    {
        foreach ($members as $member) {
            $member->shoppingList->assertEditable();
        }
    }
}
