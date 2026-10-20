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
 *  1. les deux ingrédients sont à l'utilisateur (un commun, `creatorId` vide, n'est jamais comparé : il n'est pas à lui) ;
 *  2. ils sont dans le MÊME rayon ;
 *  3. `IngredientNameMatcher` les juge ressemblants, avec ses garde-fous (même nombre de mots, chaque mot identique ou à une faute près) :
 *     `EXACT` (même nom une fois normalisé), `TYPO` (0,97 au moins) ou `SUGGESTION` (de 0,90 à 0,97) ;
 *  4. ils ne sont pas l'adoption de deux communs DIFFÉRENTS (l'administrateur les a voulus distincts) ; deux adoptions du même commun,
 *     ou un ingrédient créé et une adoption, sont proposés ;
 *  5. l'utilisateur n'a pas dit « Pas le même » pour cette paire, dans un sens ou dans l'autre : jamais reproposée.
 *
 * Le résultat est trié du plus probable au moins probable (score décroissant, puis slugs : deux appels donnent la même liste) et borné.
 * Les comparaisons se font par rayon et par nombre de mots (le garde-fou refuse deux noms de longueurs différentes) : jamais sur tous
 * les couples d'une liste.
 */
final readonly class DuplicateIngredientFinder
{
    /** Nombre maximal de paires rendues : au-delà, l'écran serait une corvée, et les suivantes se retrouvent après avoir traité les premières. */
    public const int LIMIT = 50;

    public function __construct(
        private IngredientNameMatcher $matcher,
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
            if ($candidate->isCommon() || $candidate->tokens === []) {
                continue;
            }
            $groups[$candidate->departmentSlug][count($candidate->tokens)][] = $candidate;
        }

        $pairs = [];
        foreach ($groups as $byWordCount) {
            foreach ($byWordCount as $group) {
                $count = count($group);
                for ($i = 0; $i < $count - 1; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
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
     * @param array<string, true> $distinct
     */
    private function pairOf(IngredientCandidate $one, IngredientCandidate $other, array $distinct): ?DuplicatePair
    {
        // Deux adoptions de communs différents : voulus distincts par l'administrateur
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
