<?php

namespace Repas\Repas\Domain\Service\Marmiton;


use Repas\Repas\Domain\Model\IngredientCandidate;
use Repas\Repas\Domain\Service\IngredientNameMatcher;

/**
 * Retrouve, parmi des ingrédients, celui qui porte le nom d'une ligne de recette Marmiton (ADR 0037 décision 6). Pur : ni base ni réseau.
 *
 * Une ligne de Marmiton (« 250 g de farine ») ne dit PAS le rayon de son ingrédient, que `IngredientNameMatcher::match()` exige : on le cherche
 * donc dans chaque rayon qui a des candidats, avec les règles, les seuils et les garde-fous du rapprochement de la copie d'une recette (même
 * nom normalisé, ou une faute de frappe à 0,97, même nombre de mots). Au MOINDRE DOUTE on ne branche pas : si deux rayons ont chacun un candidat
 * (« tomate » en fruit et légume ET en surgelé), le nom ne désigne personne et l'appelant crée (« un doublon se voit et se fusionne, un faux
 * rapprochement ne se voit pas »).
 */
final readonly class MarmitonIngredientMatcher
{
    public function __construct(
        private IngredientNameMatcher $nameMatcher = new IngredientNameMatcher(),
    ) {
    }

    /**
     * Le candidat sûr pour ce nom, ou `null` : aucun, ou plus d'un rayon concerné.
     *
     * @param iterable<IngredientCandidate> $candidates
     */
    public function match(string $name, iterable $candidates): ?IngredientCandidate
    {
        /** @var array<string, list<IngredientCandidate>> $byDepartment */
        $byDepartment = [];
        foreach ($candidates as $candidate) {
            $byDepartment[$candidate->departmentSlug][] = $candidate;
        }

        $matched = [];
        foreach ($byDepartment as $departmentSlug => $inDepartment) {
            $match = $this->nameMatcher->match($name, (string) $departmentSlug, $inDepartment);
            if ($match !== null) {
                $matched[] = $match->candidate;
            }
        }

        return count($matched) === 1 ? $matched[0] : null;
    }
}
