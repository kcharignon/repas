<?php

namespace Repas\Voice\Domain\Model;

/**
 * Ce que la liste de courses a fait d'un article ajouté (ADR 0029 décision 5) : de quoi choisir la phrase de
 * réponse, rien de plus.
 */
final readonly class AddedItem
{
    /**
     * @param bool   $listCreated       l'utilisateur n'avait aucune liste active : une nouvelle a été créée pour recevoir l'article
     * @param bool   $previousListSent  la liste créée remplace une liste envoyée au drive (jamais vrai quand aucune liste n'a été créée) ;
     *                                  la liste envoyée n'est pas modifiée (ADR 0023)
     * @param float  $quantity          la quantité TOTALE de la ligne après l'ajout, dans l'unité de la ligne
     * @param string $unitSlug          l'unité de la ligne (celle de la première ligne de cet ingrédient dans la liste)
     * @param string $unitName          le nom de cette unité, tel que le catalogue l'écrit (« paquet »)
     * @param bool   $lineExisted       l'ingrédient avait déjà une ligne dans la liste active : la quantité s'y est ajoutée
     * @param bool   $unitSubstituted   une unité a été dite mais n'est pas celle de la ligne : l'ingrédient ne la connaît pas, ou la ligne en avait
     *                                  déjà une autre (la quantité dite est alors convertie, ou ajoutée telle quelle si aucune conversion n'existe)
     * @param float  $addedQuantity     la quantité ajoutée à la ligne, dans l'unité de la ligne (convertie, le cas échéant)
     */
    public function __construct(
        public bool $listCreated,
        public bool $previousListSent,
        public float $quantity = 1.0,
        public string $unitSlug = 'unite',
        public string $unitName = 'unité',
        public bool $lineExisted = false,
        public bool $unitSubstituted = false,
        public float $addedQuantity = 1.0,
    ) {
    }
}
