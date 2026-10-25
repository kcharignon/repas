<?php

namespace Repas\Repas\Domain\Service;


use Repas\Repas\Domain\Model\Confidence;
use Repas\Repas\Domain\Model\RankedIngredient;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Shared\Domain\Tool\SearchRelevance;

/**
 * Choisit, parmi les ingrédients d'un utilisateur, le plus probable pour une phrase prononcée (« des tomates », « du pain de mie »),
 * d'après la pertinence du nom et les habitudes (ADR 0029 décision 3, `docs/voice/alexa.md` § 4). Pur : ni base ni horloge, les candidats
 * (et leur nombre d'usages sur douze mois) lui sont donnés.
 *
 * 1. Normalisation : `IngredientNameMatcher::tokens` (accents, casse et ponctuation retirés, pluriels repliés, mots vides retirés : ses
 *    `STOP_WORDS` couvrent déjà « du », « de la », « des », « un », « une »). Une phrase sans aucun mot qui compte : rien.
 * 2. Mémoire de phrase : si l'ingrédient retenu pour cette phrase est parmi les candidats, il l'emporte sur tout, `SURE`.
 * 3. Sinon, seuls les candidats d'une pertinence d'au moins `SearchRelevance::MINIMUM` restent (une habitude ne fait jamais passer un nom
 *    trop différent), notés `pertinence + HABIT_WEIGHT × n / (n + HABIT_SOFTNESS)`.
 * 4. Un nom identique à la phrase (une fois normalisés) passe avant tout nom qui n'est que proche : « tomate » donne *tomate* même si
 *    *tomates pelées* est acheté bien plus souvent, car le bonus d'habitude (jusqu'à 0,1) peut dépasser l'écart de pertinence (0,95 contre 1).
 *    L'habitude ne départage donc que des noms de même rang.
 * 5. Confiance : voir `confidenceOf()`. Ordre déterministe : score, puis nom, puis slug.
 *
 * Ce n'est pas `IngredientNameMatcher::compare` : lui dit « c'est le même ingrédient » avec certitude, en se trompant vers le doublon.
 * Ici l'utilisateur veut le plus probable, et l'erreur se répare d'un geste dans l'application.
 */
final readonly class SpokenIngredientRanker
{
    /** Poids maximal de l'habitude dans le score : assez pour départager des noms proches, trop peu pour faire passer un nom lointain. */
    public const float HABIT_WEIGHT = 0.1;

    /** Douceur de l'habitude `n / (n + HABIT_SOFTNESS)` : trois usages valent la moitié du poids, les suivants comptent de moins en moins. */
    public const int HABIT_SOFTNESS = 3;

    /** Écart de score à partir duquel le premier devance « nettement » le second (`PROBABLE` ; au-dessous, `UNSURE`). */
    public const float CLEAR_MARGIN = 0.03;

    /** Le score d'un ingrédient mémorisé pour la phrase : au-dessus de tout score calculé (au plus 0,999 + HABIT_WEIGHT). */
    public const float REMEMBERED_SCORE = 2.0;

    /** Tolérance des comparaisons de scores à virgule flottante (l'écart de 0,03 compte, même avec l'arrondi des flottants). */
    private const float EPSILON = 1e-9;

    /**
     * @param list<SpokenCandidate> $candidates les ingrédients de l'utilisateur
     * @param ?string $rememberedSlug l'ingrédient que l'utilisateur a déjà choisi pour cette phrase
     */
    public function rank(string $phrase, array $candidates, ?string $rememberedSlug = null): ?RankedIngredient
    {
        $search = self::normalize($phrase);
        if ($search === '' || $candidates === []) {
            return null;
        }

        if ($rememberedSlug !== null) {
            foreach ($candidates as $candidate) {
                if ($candidate->slug === $rememberedSlug) {
                    return self::ranked($candidate, self::REMEMBERED_SCORE, Confidence::SURE);
                }
            }
        }

        $scored = [];
        foreach ($candidates as $candidate) {
            $name = self::normalize($candidate->sluggedName);
            $relevance = SearchRelevance::score($search, $name);
            if ($relevance < SearchRelevance::MINIMUM) {
                continue;
            }
            $scored[] = [
                'candidate' => $candidate,
                'exact' => $name === $search,
                'score' => $relevance + self::habit($candidate->uses12m),
            ];
        }
        if ($scored === []) {
            return null;
        }

        // Les noms identiques à la phrase seuls, s'il y en a ; sinon tous les noms assez proches
        $exact = array_values(array_filter($scored, static fn(array $entry): bool => $entry['exact']));
        $pool = $exact !== [] ? $exact : $scored;

        // Le meilleur score d'abord ; à score égal le nom puis le slug : le résultat ne dépend pas de l'ordre des candidats
        usort($pool, static fn(array $first, array $second): int => [$second['score'], $first['candidate']->sluggedName, $first['candidate']->slug]
            <=> [$first['score'], $second['candidate']->sluggedName, $second['candidate']->slug]);

        $best = $pool[0];
        $confidence = self::confidenceOf($best['exact'], $best['score'], $pool[1]['score'] ?? null);

        return self::ranked($best['candidate'], $best['score'], $confidence);
    }

    /**
     * `SURE` : le seul nom identique à la phrase. `PROBABLE` : le premier devance le suivant d'au moins `CLEAR_MARGIN`, ou n'a pas de
     * suivant. `UNSURE` : le premier et le suivant se touchent (deux noms identiques ou deux noms proches d'habitudes voisines).
     */
    private static function confidenceOf(bool $exact, float $best, ?float $next): Confidence
    {
        if ($next === null) {
            return $exact ? Confidence::SURE : Confidence::PROBABLE;
        }

        return $best - $next >= self::CLEAR_MARGIN - self::EPSILON ? Confidence::PROBABLE : Confidence::UNSURE;
    }

    /** Le bonus d'habitude, de 0 (jamais utilisé) à `HABIT_WEIGHT` (très utilisé). */
    private static function habit(int $uses): float
    {
        $uses = max(0, $uses);

        return self::HABIT_WEIGHT * $uses / ($uses + self::HABIT_SOFTNESS);
    }

    /** Le nom ou la phrase normalisés (mots joints par « - ») ; vide si aucun mot ne compte. */
    private static function normalize(string $text): string
    {
        $words = array_filter(
            IngredientNameMatcher::tokens($text),
            static fn(string $word): bool => !in_array($word, IngredientNameMatcher::STOP_WORDS, true),
        );

        return implode('-', $words);
    }

    private static function ranked(SpokenCandidate $candidate, float $score, Confidence $confidence): RankedIngredient
    {
        return new RankedIngredient($candidate->slug, $candidate->name, $candidate->departmentName, $score, $confidence);
    }
}
