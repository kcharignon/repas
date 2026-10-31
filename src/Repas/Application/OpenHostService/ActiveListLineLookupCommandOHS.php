<?php

namespace Repas\Repas\Application\OpenHostService;

use Repas\Repas\Domain\Interface\ActiveShoppingListContents;
use Repas\Repas\Domain\Interface\IngredientPhraseCandidates;
use Repas\Repas\Domain\Interface\SpokenPhraseChoiceRepository;
use Repas\Repas\Domain\Model\ActiveListLineMatch;
use Repas\Repas\Domain\Service\ActiveListLookup;

/**
 * Open Host Service d'écriture du contexte `Repas` (ADR 0035) : ce que la liste de courses ACTIVE de l'utilisateur contient pour une phrase
 * (ADR 0029 décision 12, VOX-304), pour un appelant qui en tire une écriture dans sa transaction (retirer un article). Rien n'est écrit ici et
 * aucune liste n'est créée. Seule la liste ACTIVE est lue : une liste envoyée au drive n'est ni modifiée ni consultée, sa seule présence se dit
 * (`ActiveListLineMatch::sentList()`).
 *
 * Les lectures sont celles de `ActiveListLookup` : les lignes de la liste, les ingrédients de l'utilisateur (seulement s'il y a une liste active)
 * puis, si la phrase a des mots qui comptent, sa mémoire de phrase. La même lecture existe pour un appelant qui lit (`ActiveListLineLookupQueryOHS`).
 */
final readonly class ActiveListLineLookupCommandOHS
{
    public function __construct(
        private ActiveShoppingListContents $contents,
        private IngredientPhraseCandidates $candidates,
        private SpokenPhraseChoiceRepository $memory,
        private ActiveListLookup $lookup,
    ) {
    }

    /**
     * L'ingrédient de l'utilisateur que la phrase désigne (même mémoire de phrase et même classeur que `IngredientPhraseCommandOHS`), cherché
     * parmi les lignes de sa liste active : quand plusieurs ingrédients se valent pour la phrase (même nom dans deux rayons), ceux qui sont
     * DANS la liste passent avant — un seul dans la liste, c'est lui ; plusieurs, ils sont rendus à départager. Jamais de proposition ni de
     * création : un produit inconnu de l'utilisateur est simplement « pas dans la liste ».
     *
     * @param string $phrase la phrase reconnue (« des croquettes », « du pain de mie »), telle quelle
     */
    public function findLine(string $userId, string $phrase): ActiveListLineMatch
    {
        $lines = $this->contents->lines($userId);
        if ($lines === null) {
            return $this->contents->hasSentList($userId) ? ActiveListLineMatch::sentList() : ActiveListLineMatch::noList();
        }

        return $this->lookup->line(
            $phrase,
            $lines,
            $this->candidates->forUser($userId),
            fn(string $key): ?string => $this->memory->rememberedSlug($userId, $key),
        );
    }
}
