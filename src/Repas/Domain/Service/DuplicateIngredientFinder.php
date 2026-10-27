<?php

namespace Repas\Repas\Domain\Service;


use Repas\Repas\Domain\Model\DuplicatePair;
use Repas\Repas\Domain\Model\IngredientCandidate;
use Repas\Repas\Domain\Model\IngredientDistinctPair;

/**
 * Les paires d'ingrédients d'UN utilisateur que l'application propose de fusionner (« Doublons possibles », ADR 0028 décision h,
 * `docs/ingredients/fusion.md` § 8, ING-006). Pur : ni base ni horloge, les ingrédients lui sont donnés.
 *
 * Une paire est proposée quand, ensemble :
 *  1. les deux ingrédients sont à l'utilisateur (l'appelant ne lui donne que les siens, `IngredientMatchCandidates`) ;
 *  2. ils sont dans le MÊME rayon ;
 *  3. `IngredientNameMatcher` les juge ressemblants, avec ses garde-fous (même nombre de mots, chaque mot identique ou à une faute près) :
 *     `EXACT` (même nom une fois normalisé), `TYPO` (0,97 au moins) ou `SUGGESTION` (de 0,90 à 0,97) ;
 *  4. ils ne sont pas la copie de deux ingrédients d'origine DIFFÉRENTE (`adopted_from` : le propriétaire du catalogue les a voulus
 *     distincts) ; deux copies de la même origine, ou un ingrédient créé et une copie, sont proposés ;
 *  5. l'utilisateur n'a pas dit « Pas le même » pour cette paire, dans un sens ou dans l'autre : jamais reproposée.
 *
 * Le résultat est trié du plus probable au moins probable (score décroissant, puis slugs : deux appels donnent la même liste) et borné.
 * Les comparaisons se font par rayon et par nombre de mots (le garde-fou refuse deux noms de longueurs différentes) : jamais sur tous
 * les couples d'une liste. Le travail est borné aussi : un groupe n'examine que ses `MAX_CANDIDATES_PER_GROUP` premiers ingrédients dans
 * l'ordre des slugs (les couples d'un groupe croissent comme le carré de sa taille), les mêmes à chaque appel.
 */
final readonly class DuplicateIngredientFinder
{
    /** Nombre maximal de paires rendues : au-delà, l'écran serait une corvée, et les suivantes se retrouvent après avoir traité les premières. */
    public const int LIMIT = 50;

    /**
     * Nombre maximal d'ingrédients comparés entre eux dans un même rayon et pour un même nombre de mots, pris dans l'ordre des slugs : au plus
     * 124 750 couples par groupe. Un compte ordinaire n'en approche pas (quelques dizaines par groupe) ; les suivants ne sont pas examinés, et
     * se retrouvent après avoir fusionné ou renommé les premiers.
     */
    public const int MAX_CANDIDATES_PER_GROUP = 500;

    /**
     * Nombre maximal de comparaisons de deux ingrédients pour tout le compte, groupes pris dans l'ordre du rayon puis du nombre de mots : 250 000
     * comparaisons coûtent environ une seconde, même quand tous les noms se valent. Un compte ordinaire en fait quelques milliers.
     */
    public const int MAX_COMPARISONS = 250_000;

    /**
     * @param int $maxCandidatesPerGroup `MAX_CANDIDATES_PER_GROUP` en production ; plus petit dans les tests de la borne
     * @param int $maxComparisons `MAX_COMPARISONS` en production ; plus petit dans les tests de la borne
     */
    public function __construct(
        private IngredientNameMatcher $matcher,
        private int $maxCandidatesPerGroup = self::MAX_CANDIDATES_PER_GROUP,
        private int $maxComparisons = self::MAX_COMPARISONS,
    ) {
    }

    /**
     * @param iterable<IngredientCandidate> $candidates les ingrédients de l'utilisateur
     * @param array<string, true> $distinct les paires écartées, par `IngredientDistinctPair::key()` (ou `keyOf()`)
     * @return list<DuplicatePair>
     */
    public function find(iterable $candidates, array $distinct = [], int $limit = self::LIMIT): array
    {
        // Rayon, puis nombre de mots : seuls ces ingrédients peuvent se ressembler
        $groups = [];
        foreach ($candidates as $candidate) {
            if ($candidate->tokens === []) {
                continue;
            }
            $groups[$candidate->departmentSlug][count($candidate->tokens)][] = $candidate;
        }

        // Les groupes dans un ordre qui ne dépend pas de celui des candidats : si le budget de comparaisons s'épuise, c'est toujours au même endroit
        ksort($groups);
        $pairs = [];
        $compared = 0;
        foreach ($groups as $byWordCount) {
            ksort($byWordCount);
            foreach ($byWordCount as $group) {
                $group = $this->firstOf($group);
                $count = count($group);
                for ($i = 0; $i < $count - 1; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        if (++$compared > $this->maxComparisons) {
                            break 4;
                        }
                        $pair = $this->pairOf($group[$i], $group[$j], $distinct);
                        if ($pair !== null) {
                            $pairs[] = $pair;
                        }
                    }
                }
            }
        }

        usort($pairs, static fn(DuplicatePair $a, DuplicatePair $b): int => [$b->similarity->score, $a->first->slug, $a->second->slug]
            <=> [$a->similarity->score, $b->first->slug, $b->second->slug]);

        return array_slice($pairs, 0, max(0, $limit));
    }

    /**
     * Les `MAX_CANDIDATES_PER_GROUP` premiers du groupe dans l'ordre des slugs (par octets, comme le reste du tri) : le choix ne dépend pas de
     * l'ordre dans lequel l'appelant donne les ingrédients.
     *
     * @param list<IngredientCandidate> $group
     * @return list<IngredientCandidate>
     */
    private function firstOf(array $group): array
    {
        usort($group, static fn(IngredientCandidate $a, IngredientCandidate $b): int => strcmp($a->slug, $b->slug));

        return array_slice($group, 0, $this->maxCandidatesPerGroup);
    }

    /**
     * @param array<string, true> $distinct
     */
    private function pairOf(IngredientCandidate $one, IngredientCandidate $other, array $distinct): ?DuplicatePair
    {
        // Deux copies d'origines différentes : voulues distinctes par le propriétaire du catalogue
        if ($one->adoptedFrom !== null && $other->adoptedFrom !== null && $one->adoptedFrom !== $other->adoptedFrom) {
            return null;
        }
        if (isset($distinct[IngredientDistinctPair::keyOf($one->slug, $other->slug)])) {
            return null;
        }
        $similarity = $this->matcher->compare($one, $other);
        if ($similarity === null) {
            return null;
        }

        [$first, $second] = IngredientDistinctPair::ordered($one->slug, $other->slug)[0] === $one->slug ? [$one, $other] : [$other, $one];

        return new DuplicatePair($first, $second, $similarity);
    }
}
