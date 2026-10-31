<?php

namespace Repas\Repas\Application\OpenHostService;

use Repas\Repas\Application\Loader\ActiveShoppingListLines;
use Repas\Repas\Application\Loader\SpokenIngredientCandidates;
use Repas\Repas\Application\Loader\SpokenPhraseMemory;
use Repas\Repas\Domain\Service\ActiveListLookup;

/**
 * Open Host Service de lecture du contexte `Repas` (ADR 0035) : ce que la liste de courses ACTIVE de l'utilisateur contient pour une phrase
 * (ADR 0029 décision 12, VOX-304), pour un appelant qui répond sans rien écrire (« est-ce qu'il y a des croquettes dans la liste ? »). Rien n'est
 * écrit et aucune liste n'est créée. La recherche est celle de `ActiveListLineLookupCommandOHS` (le même `ActiveListLookup`), sur les Loaders.
 */
final readonly class ActiveListLineLookupQueryOHS
{
    public function __construct(
        private ActiveShoppingListLines $lines,
        private SpokenIngredientCandidates $candidates,
        private SpokenPhraseMemory $memory,
        private ActiveListLookup $lookup,
    ) {
    }

    /**
     * @param string $phrase la phrase reconnue (« des croquettes », « du pain de mie »), telle quelle
     */
    public function findLine(string $userId, string $phrase): ActiveListLineMatchDTO
    {
        $lines = $this->lines->forUser($userId);
        if ($lines === null) {
            return new ActiveListLineMatchDTO(false, [], null, $this->lines->hasSentList($userId), []);
        }

        $match = $this->lookup->line(
            $phrase,
            $lines,
            $this->candidates->forUser($userId),
            fn(string $key): ?string => $this->memory->rememberedSlug($userId, $key),
        );

        return new ActiveListLineMatchDTO(
            $match->hasList,
            array_map(static fn($line): ActiveListLineDTO => new ActiveListLineDTO($line->slug, $line->name, $line->departmentSlug, $line->departmentName, $line->quantity, $line->unitSlug, $line->unitName, $line->listName), $match->lines),
            $match->absentName,
            $match->listSent,
            array_map(static fn($line): ActiveListLineDTO => new ActiveListLineDTO($line->slug, $line->name, $line->departmentSlug, $line->departmentName, $line->quantity, $line->unitSlug, $line->unitName, $line->listName), $match->suggestions),
        );
    }
}
