<?php

namespace Repas\Repas\Application\OpenHostService;

use Repas\Repas\Domain\Interface\UnitRepository;

/**
 * Open Host Service d'écriture du contexte `Repas` (ADR 0035) : quelles unités existent, pour un appelant qui écrit une quantité dans une
 * unité (DRV-652) et ne la mémorise que si la liste peut réellement l'employer. Des slugs seulement, jamais un agrégat.
 */
final readonly class UnitCommandOHS
{
    public function __construct(
        private UnitRepository $units,
    ) {
    }

    /**
     * Une seule requête quel que soit le nombre de slugs.
     *
     * @param list<string> $slugs
     *
     * @return list<string> ceux de ces slugs qui désignent une unité ; un slug inconnu est absent du résultat
     */
    public function existingSlugs(array $slugs): array
    {
        return $this->units->existingSlugs($slugs);
    }
}
