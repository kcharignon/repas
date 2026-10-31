<?php

namespace Repas\Repas\Domain\Service;

use Repas\Repas\Domain\Model\Confidence;
use Repas\Repas\Domain\Model\RankedIngredient;
use Repas\Repas\Domain\Model\RecipeChoice;
use Repas\Repas\Domain\Model\RecipePhraseResolution;
use Repas\Repas\Domain\Model\SpokenCandidate;

/**
 * Trouve, parmi les recettes d'un utilisateur, celle que désigne une phrase (ADR 0029 décision 13, VOX-305) : le MÊME classeur de noms que les
 * produits (`SpokenIngredientRanker`) choisit (`rank`) ou, à défaut, propose (`closest`), avec les mêmes candidats, lus une seule fois par
 * l'appelant. Pur : ni base ni horloge.
 *
 * Une recette planifiée à tort modifie beaucoup de lignes de la liste : on ne la devine pas. Un choix sûr ou probable est rendu tel quel ; un
 * choix incertain (`UNSURE` : deux noms qui se touchent) est rendu en propositions (les ex æquo, ou à défaut le premier seul quand ils
 * s'appellent pareil : deux recettes de même nom ne se départagent pas par la voix, la première, dans l'ordre stable des identifiants, est prise).
 * Rien de sûr : les trois recettes les PLUS PROCHES, même lointaines (`closest()`, sans plancher : mieux vaut trois propositions éloignées qu'un
 * silence), ou, si l'utilisateur n'a aucune recette, un résultat qui le dit (`hasRecipes` faux).
 */
final readonly class RecipePhraseResolver
{
    private SpokenIngredientRanker $ranker;

    public function __construct()
    {
        $this->ranker = new SpokenIngredientRanker();
    }

    /**
     * @param list<SpokenCandidate> $candidates les recettes de l'utilisateur : `slug` porte l'IDENTIFIANT de la recette (le classeur rend ce qu'on lui donne)
     * @param string|null $rememberedId ce que l'utilisateur a déjà choisi pour cette phrase (la mémoire passe avant tout classement)
     */
    public function resolve(string $phrase, array $candidates, ?string $rememberedId): RecipePhraseResolution
    {
        if ($candidates === []) {
            return RecipePhraseResolution::unknown(hasRecipes: false);
        }

        $ranked = $this->ranker->rank($phrase, $candidates, rememberedSlug: $rememberedId);
        if ($ranked === null) {
            // Les trois recettes les plus proches, même lointaines : jamais un silence (il n'y a pas de recette à créer à la voix)
            return RecipePhraseResolution::unknown(array_map(self::choice(...), $this->ranker->closest($phrase, $candidates)));
        }

        if ($ranked->confidence === Confidence::UNSURE) {
            $ties = $this->ranker->ties($phrase, $candidates, $rememberedId);
            if ($ties !== []) {
                return RecipePhraseResolution::unknown(array_map(self::choice(...), $ties));
            }
        }

        return RecipePhraseResolution::found(self::choice($ranked));
    }

    private static function choice(RankedIngredient $ranked): RecipeChoice
    {
        return new RecipeChoice($ranked->slug, $ranked->name);
    }
}
