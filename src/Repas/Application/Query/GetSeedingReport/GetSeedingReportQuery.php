<?php

namespace Repas\Repas\Application\Query\GetSeedingReport;

use Repas\Shared\Application\Interface\QueryInterface;

/**
 * Le rapport « à blanc » de l'amorçage des ingrédients des comptes (ING-008, `docs/ingredients/sans-commun.md` §§ 2 et 5) : qui serait servi,
 * ce qui serait copié et repointé, la requête de santé, les empreintes. Lecture seule, aucune donnée personnelle dans le résultat affichable.
 */
final readonly class GetSeedingReportQuery implements QueryInterface
{
    /**
     * @param list<string> $only se limite à ces comptes (vide : le périmètre par défaut, les comptes actifs non amorcés) ; chacun est un
     *        identifiant complet ou la référence courte du rapport, de n'importe quel statut
     * @param list<string> $except exclut ces comptes, mêmes formes
     * @param bool $complete retient aussi un compte DÉJÀ amorcé (il recevra ce que le catalogue a de plus) : seulement avec `$only`
     */
    public function __construct(
        public array $only = [],
        public array $except = [],
        public bool $complete = false,
    ) {
    }
}
