<?php

namespace Repas\Shared\Application\Interface;

use Repas\Shared\Application\Dto\CreatedIngredient;
use Repas\Shared\Application\Dto\SpokenChoice;

/**
 * Port par lequel le contexte `Voice` crée un ingrédient à l'utilisateur qui l'a nommé sans qu'il existe (ADR 0029 décision 11, VOX-303), et
 * lit les rayons et les unités qu'il peut citer. Défini ici, implémenté dans `Repas\Infrastructure\Voice` (ADR 0013 règle 8 : `Voice`
 * n'importe pas `Repas`).
 *
 * L'ingrédient créé a UNE seule unité, pour l'achat comme pour la cuisine (aucun coefficient à demander à voix haute) ; il est à
 * l'utilisateur, comme tout ingrédient (ADR 0028).
 */
interface SpokenIngredientCreator
{
    /**
     * Les rayons du catalogue commun, triés par slug.
     *
     * @return list<SpokenChoice>
     */
    public function departments(): array;

    /**
     * Les unités du catalogue commun, triées par slug.
     *
     * @return list<SpokenChoice>
     */
    public function units(): array;

    /**
     * @throws \Repas\Shared\Domain\Exception\DomainException le rayon ou l'unité n'existe pas, ou l'utilisateur a déjà cet ingrédient (rien n'est écrit)
     */
    public function create(string $userId, string $name, string $departmentSlug, string $unitSlug): CreatedIngredient;
}
