<?php

namespace Repas\Repas\Domain\Model;


/**
 * Ce qu'une seule lecture des alias d'un utilisateur répond à la résolution de slugs reçus en écriture (ADR 0028 décision 4) : les
 * alias valides pour les slugs demandés, et si l'utilisateur est adoptant (il a adopté au moins un commun). Une valeur, pas un
 * agrégat : identifiants et un booléen seulement (ADR 0013).
 */
final readonly class AliasLookup
{
    /**
     * @param array<string, string> $resolved slug de l'ingrédient de l'utilisateur par slug reçu ; un slug sans alias valide est absent
     */
    public function __construct(
        public array $resolved,
        public bool $adopter,
    ) {
    }
}
