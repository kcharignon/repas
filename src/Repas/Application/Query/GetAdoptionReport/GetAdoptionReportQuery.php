<?php

namespace Repas\Repas\Application\Query\GetAdoptionReport;

use Repas\Shared\Application\Interface\QueryInterface;

/**
 * Le rapport « à blanc » de la migration d'adoption des communs (ING-003) : qui est concerné, ce qui serait copié et repointé, les
 * anomalies, les empreintes. Lecture seule, aucune donnée personnelle dans le résultat affichable.
 */
final readonly class GetAdoptionReportQuery implements QueryInterface
{
    /**
     * @param list<string> $only se limite à ces comptes (vide : tous) ; chacun est un identifiant complet ou la référence courte du rapport
     * @param list<string> $except exclut ces comptes, mêmes formes
     */
    public function __construct(
        public array $only = [],
        public array $except = [],
    ) {
    }
}
