<?php

namespace Repas\Voice\Application\Port;

use Repas\Voice\Domain\Model\CreatedIngredient;
use Repas\Voice\Domain\Model\DialogueOption;

/**
 * Ce que les écritures vocales demandent pour créer l'ingrédient qu'Alexa ne connaît pas (ADR 0029 décision 11, VOX-303) : les rayons et les
 * unités que l'utilisateur peut citer, et la création. Adapté depuis le contexte `Repas` (ADR 0035).
 *
 * L'ingrédient créé a UNE seule unité, pour l'achat comme pour la cuisine (aucun coefficient à demander à voix haute) ; il est à
 * l'utilisateur, comme tout ingrédient (ADR 0028).
 */
interface IngredientCreationCommandPort
{
    /**
     * Les rayons du catalogue, triés par slug.
     *
     * @return list<DialogueOption>
     */
    public function departments(): array;

    /**
     * Les unités du catalogue, triées par slug.
     *
     * @return list<DialogueOption>
     */
    public function units(): array;

    /**
     * @throws \Repas\Shared\Domain\Exception\DomainException le rayon ou l'unité n'existe pas, ou l'utilisateur a déjà cet ingrédient (rien n'est écrit)
     */
    public function create(string $userId, string $name, string $departmentSlug, string $unitSlug): CreatedIngredient;
}
