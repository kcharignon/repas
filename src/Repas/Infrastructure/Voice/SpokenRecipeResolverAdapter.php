<?php

namespace Repas\Repas\Infrastructure\Voice;

use Repas\Repas\Application\Command\RememberSpokenRecipePhrase\RememberSpokenRecipePhraseCommand;
use Repas\Repas\Application\Loader\SpokenRecipeCandidates;
use Repas\Repas\Application\Loader\SpokenRecipePhraseMemory;
use Repas\Repas\Domain\Model\Confidence;
use Repas\Repas\Domain\Model\RankedIngredient;
use Repas\Repas\Domain\Service\SpokenIngredientRanker;
use Repas\Shared\Application\Dto\RecipeSuggestion;
use Repas\Shared\Application\Dto\ResolvedRecipe;
use Repas\Shared\Application\Dto\SpokenRecipeResolution;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\SpokenRecipeResolver;

/**
 * Adaptateur du port `SpokenRecipeResolver` (ADR 0029 décision 13, VOX-305) : charge les recettes de l'utilisateur, puis laisse le MÊME classeur de
 * noms que les produits (`SpokenIngredientRanker`) choisir (`rank`) ou, à défaut, proposer (`suggest`) — avec les mêmes candidats, lus une seule
 * fois. La mémoire de phrase des recettes (une table à part de celle des produits) est lue AVANT le classement et écrite par une commande sur le bus
 * transactionnel (`remember`). Les deux recherches ne se mélangent jamais.
 *
 * Rien de sûr : les trois recettes les PLUS PROCHES, même lointaines (`closest()`, sans plancher : mieux vaut trois propositions éloignées qu'un silence, 2026-10-26), ou, si l'utilisateur n'a
 * aucune recette, un résultat qui le dit (`hasRecipes` faux).
 *
 * Une recette planifiée à tort modifie beaucoup de lignes de la liste : on ne la devine pas. Un choix sûr ou probable est rendu tel quel ; un choix
 * incertain (`UNSURE` : deux noms qui se touchent) est rendu en propositions (les ex æquo, ou à défaut le premier seul quand ils s'appellent pareil :
 * deux recettes de même nom ne se départagent pas par la voix, la première, dans l'ordre stable des identifiants, est prise).
 */
final readonly class SpokenRecipeResolverAdapter implements SpokenRecipeResolver
{
    private SpokenIngredientRanker $ranker;

    public function __construct(
        private SpokenRecipeCandidates $candidates,
        private SpokenRecipePhraseMemory $memory,
        private CommandBusInterface $commandBus,
    ) {
        $this->ranker = new SpokenIngredientRanker();
    }

    public function resolveOrSuggest(string $userId, string $phrase): SpokenRecipeResolution
    {
        // Une seule lecture des recettes de l'utilisateur, pour choisir comme pour proposer
        $candidates = $this->candidates->forUser($userId);
        if ($candidates === []) {
            return SpokenRecipeResolution::unknown(hasRecipes: false);
        }

        $key = SpokenIngredientRanker::phraseKey($phrase);
        $remembered = $key === '' ? null : $this->memory->rememberedId($userId, $key);

        $ranked = $this->ranker->rank($phrase, $candidates, rememberedSlug: $remembered);
        if ($ranked === null) {
            // Les trois recettes les plus proches, même lointaines : jamais un silence (il n'y a pas de recette à créer à la voix)
            return SpokenRecipeResolution::unknown(array_map(self::suggestion(...), $this->ranker->closest($phrase, $candidates)));
        }

        if ($ranked->confidence === Confidence::UNSURE) {
            $ties = $this->ranker->ties($phrase, $candidates, $remembered);
            if ($ties !== []) {
                return SpokenRecipeResolution::unknown(array_map(self::suggestion(...), $ties));
            }
        }

        return SpokenRecipeResolution::found(new ResolvedRecipe($ranked->slug, $ranked->name));
    }

    private static function suggestion(RankedIngredient $ranked): RecipeSuggestion
    {
        return new RecipeSuggestion($ranked->slug, $ranked->name);
    }

    public function remember(string $userId, string $phrase, string $recipeId): void
    {
        $this->commandBus->dispatch(new RememberSpokenRecipePhraseCommand($userId, $phrase, $recipeId));
    }
}
