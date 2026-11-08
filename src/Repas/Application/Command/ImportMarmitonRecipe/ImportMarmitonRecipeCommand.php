<?php

namespace Repas\Repas\Application\Command\ImportMarmitonRecipe;


/**
 * Importer la recette Marmiton dont la personne a collé le lien (ADR 0037 décision 9). `url` est le texte reçu, jamais appelé tel quel :
 * `MarmitonUrl::parse` en tire l'identifiant, et c'est lui seul qui construit l'adresse lue.
 */
readonly class ImportMarmitonRecipeCommand
{
    public function __construct(
        public string $url,
        public string $userId,
        /** L'identifiant de la copie, si elle est créée (sinon la copie existante est rendue). */
        public string $newRecipeId,
    ) {
    }
}
