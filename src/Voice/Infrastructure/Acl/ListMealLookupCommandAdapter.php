<?php

namespace Repas\Voice\Infrastructure\Acl;

use Repas\Repas\Application\OpenHostService\ActiveListMealLookupCommandOHS;
use Repas\Repas\Domain\Model\ActiveListMeal;
use Repas\Voice\Application\Port\ListMealLookupCommandPort;
use Repas\Voice\Domain\Model\ListedMeal;
use Repas\Voice\Domain\Model\SpokenMealLookupResult;

/**
 * Couche anticorruption (ADR 0035) : quel repas de la liste active de `Repas` une phrase désigne (un repas, des repas qui ressemblent, aucun, pas
 * de liste) devient ce que le dialogue vocal attend. Rien n'est décidé ici.
 */
final readonly class ListMealLookupCommandAdapter implements ListMealLookupCommandPort
{
    public function __construct(
        private ActiveListMealLookupCommandOHS $meals,
    ) {
    }

    public function find(string $userId, string $phrase): SpokenMealLookupResult
    {
        $match = $this->meals->findMeal($userId, $phrase);

        if (!$match->hasList) {
            return $match->listSent ? SpokenMealLookupResult::sentList() : SpokenMealLookupResult::noList();
        }

        return $match->meal !== null
            ? SpokenMealLookupResult::found(self::meal($match->meal))
            : SpokenMealLookupResult::notPlanned(array_map(self::meal(...), $match->suggestions));
    }

    private static function meal(ActiveListMeal $meal): ListedMeal
    {
        return new ListedMeal($meal->recipeId, $meal->name, $meal->servings);
    }
}
