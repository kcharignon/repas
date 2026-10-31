<?php

namespace Repas\Repas\Domain\Model;

/**
 * Une ligne d'une liste de courses EN COURS de l'utilisateur, telle que `ActiveListLookup` la cherche (ADR 0029 décision 12, VOX-304) : l'ingrédient
 * (slug, nom, rayon), la quantité de la ligne et son unité, et la liste qui la porte (la liste principale, ou celle d'un type : ADR 0036, LST-008).
 * Une valeur lue, jamais un agrégat. Un même ingrédient peut avoir une ligne dans plusieurs listes en cours.
 */
final readonly class ActiveListLine
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $departmentSlug,
        public string $departmentName,
        /** La quantité de la ligne, dans son unité. */
        public float $quantity,
        public string $unitSlug,
        /** Le nom de l'unité, tel que le catalogue l'écrit (« paquet »). */
        public string $unitName,
        /**
         * Le nom de la liste en cours qui porte la ligne quand ce n'est PAS la liste principale (le nom que cette liste porte, à défaut celui de son
         * type) ; `null` : la liste principale. Un compte sans type n'a que des lignes `null`.
         */
        public ?string $listName = null,
    ) {
    }

    /** La ligne est dans la liste principale. */
    public function isInMainList(): bool
    {
        return $this->listName === null;
    }
}
