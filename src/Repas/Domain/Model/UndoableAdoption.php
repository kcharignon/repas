<?php

namespace Repas\Repas\Domain\Model;


/**
 * Une adoption écrite par un lancement de la migration, vue par son retour arrière (`--undo`) : le commun d'origine, la copie qui le
 * remplace pour l'utilisateur, et si la copie a été modifiée depuis (nom, image, rayon, unités, conversions : l'utilisateur y a touché,
 * on ne la défait pas). Des slugs, jamais d'agrégat.
 */
final readonly class UndoableAdoption
{
    public function __construct(
        public string $commonSlug,
        public string $copySlug,
        public bool $modified,
    ) {
    }
}
