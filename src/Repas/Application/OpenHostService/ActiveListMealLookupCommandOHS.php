<?php

namespace Repas\Repas\Application\OpenHostService;

use Repas\Repas\Domain\Interface\ActiveShoppingListContents;
use Repas\Repas\Domain\Interface\SpokenRecipePhraseChoiceRepository;
use Repas\Repas\Domain\Model\ActiveListMealMatch;
use Repas\Repas\Domain\Service\ActiveListLookup;

/**
 * Open Host Service d'écriture du contexte `Repas` (ADR 0035) : quel repas de la liste de courses ACTIVE de l'utilisateur une phrase désigne
 * (ADR 0029 décision 12, VOX-306), pour un appelant qui en tire une écriture dans sa transaction (retirer un repas). Rien n'est écrit ici et
 * aucune liste n'est créée. Une liste envoyée au drive n'est ni modifiée ni consultée (`ActiveListMealMatch::sentList()`).
 */
final readonly class ActiveListMealLookupCommandOHS
{
    public function __construct(
        private ActiveShoppingListContents $contents,
        private SpokenRecipePhraseChoiceRepository $memory,
        private ActiveListLookup $lookup,
    ) {
    }

    /**
     * Le repas (la recette planifiée) de la liste active de l'utilisateur que la phrase désigne : « enlève le repas hachis parmentier ». Seuls les
     * repas PLANIFIÉS sont cherchés (jamais les recettes de l'utilisateur qui ne sont pas dans la liste), avec le même classeur de noms que les
     * produits et la mémoire de phrase des recettes. Aucun repas sûr : les repas planifiés qui ressemblent à la phrase (trois au plus), jamais
     * devinés. Rien n'est écrit, aucune liste n'est créée.
     *
     * @param string $phrase la phrase reconnue (« hachis parmentier »), sans le mot « repas » ni « recette »
     */
    public function findMeal(string $userId, string $phrase): ActiveListMealMatch
    {
        $planned = $this->contents->meals($userId);
        if ($planned === null) {
            return $this->contents->hasSentList($userId) ? ActiveListMealMatch::sentList() : ActiveListMealMatch::noList();
        }

        return $this->lookup->meal($phrase, $planned, fn(string $key): ?string => $this->memory->rememberedId($userId, $key));
    }
}
