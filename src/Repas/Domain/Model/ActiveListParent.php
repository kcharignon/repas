<?php

namespace Repas\Repas\Domain\Model;

/**
 * La ligne du PARENT dans laquelle une partie d'ingrédient est pliée (ADR 0038 décision 6) : son nom, son total dans la liste (« 5 œufs » : les œufs
 * entiers et le plus grand équivalent de ses parties) et l'unité de cette ligne. Une partie (« Jaune d'œuf ») n'est jamais une ligne de liste, mais la
 * voix doit pouvoir la retrouver (ADR 0038 décision 33) : `ActiveListLine::$foldedInto` dit alors où elle compte.
 */
final readonly class ActiveListParent
{
    public function __construct(
        public string $name,
        /** La quantité de la ligne du parent, dans son unité. */
        public float $quantity,
        public string $unitSlug,
        /** Le nom de l'unité de la ligne du parent, tel que le catalogue l'écrit. */
        public string $unitName,
    ) {
    }
}
