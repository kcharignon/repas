<?php

namespace Repas\Repas\Application\Query\ListOtherPeopleRecipeTypes;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListOtherPeopleRecipeTypesQuery implements QueryInterface
{
    /**
     * @param bool $isAdministrator l'utilisateur est administrateur : les recettes sans propriétaire sont comptées même copiées
     */
    public function __construct(
        public string $userId,
        public bool $isAdministrator = false,
    ) {
    }
}
