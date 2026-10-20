<?php

namespace Repas\Repas\Application\Query\ListMergeCandidates;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListMergeCandidatesQuery implements QueryInterface
{
    /**
     * @param string $userId l'utilisateur du jeton
     * @param string $slug l'ingrédient qu'on veut fusionner (il serait absorbé)
     * @param ?string $search ne garde que les ingrédients dont le nom contient ce texte (sans tenir compte de la casse ni des accents)
     */
    public function __construct(
        public string $userId,
        public string $slug,
        public ?string $search = null,
    ) {
    }
}
