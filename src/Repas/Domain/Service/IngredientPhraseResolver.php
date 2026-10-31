<?php

namespace Repas\Repas\Domain\Service;


use Repas\Repas\Domain\Model\Confidence;
use Repas\Repas\Domain\Model\IngredientPhraseResolution;
use Repas\Repas\Domain\Model\RankedIngredient;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Shared\Domain\Tool\StringTool;

/**
 * Trouve, parmi les ingrédients d'un utilisateur, celui que désigne une phrase prononcée (ADR 0029 décisions 3 et 11) : le classeur
 * `SpokenIngredientRanker` choisit (`rank`) ou, à défaut, propose (`suggest`), avec les MÊMES candidats, lus une seule fois par l'appelant.
 * Quand le classeur hésite entre des ingrédients qui se valent (même nom dans deux rayons, habitudes qui ne tranchent pas), il ne devine
 * pas : les ex æquo sont rendus pour que l'utilisateur choisisse. Pur : ni base ni horloge.
 */
final readonly class IngredientPhraseResolver
{
    private SpokenIngredientRanker $ranker;

    public function __construct()
    {
        $this->ranker = new SpokenIngredientRanker();
    }

    /**
     * @param list<SpokenCandidate> $candidates les ingrédients de l'utilisateur, avec leur habitude
     * @param string|null $rememberedSlug ce que l'utilisateur a déjà choisi pour cette phrase (la mémoire passe avant tout classement)
     */
    public function resolve(string $phrase, array $candidates, ?string $rememberedSlug): IngredientPhraseResolution
    {
        $ranked = $this->ranker->rank($phrase, $candidates, rememberedSlug: $rememberedSlug);
        if ($ranked !== null) {
            // Le classeur hésite entre des ingrédients qui se valent : on ne devine pas, on les rend pour que l'utilisateur départage (le rayon)
            $ties = $ranked->confidence === Confidence::UNSURE ? $this->ranker->ties($phrase, $candidates, $rememberedSlug) : [];

            return $ties !== [] ? IngredientPhraseResolution::tied($ties) : IngredientPhraseResolution::found($ranked);
        }

        return IngredientPhraseResolution::unknown($this->onePerName($this->ranker->suggest($phrase, $candidates, SpokenIngredientRanker::MAX_SUGGESTIONS * 2)));
    }

    /**
     * Un nom une seule fois dans les propositions (le mieux classé, habitudes comprises) : deux ingrédients de même nom dans deux rayons (ADR 0028,
     * amendement du 2026-10-30) se diraient à l'identique (« petits pois, petits pois ») sans que l'utilisateur sache les distinguer. Quand le
     * nom est dit tel quel, le classeur les départage par le rayon (`ties`) ; ici la phrase est approximative, on propose le nom une fois.
     *
     * @param list<RankedIngredient> $ranked
     *
     * @return list<RankedIngredient>
     */
    private function onePerName(array $ranked): array
    {
        $byName = [];
        foreach ($ranked as $item) {
            $byName[StringTool::slugify($item->name)] ??= $item;
        }

        return array_slice(array_values($byName), 0, SpokenIngredientRanker::MAX_SUGGESTIONS);
    }
}
