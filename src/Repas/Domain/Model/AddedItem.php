<?php

namespace Repas\Repas\Domain\Model;

/**
 * Ce qu'un ajout d'article à la liste de courses a fait (ADR 0029 décision 5) : de quoi en rendre compte, rien de plus.
 */
final readonly class AddedItem
{
    /**
     * @param bool    $listCreated       l'utilisateur n'avait aucune liste active : une nouvelle a été créée pour recevoir l'article (la liste principale ;
     *                                   une liste de type ouverte à la demande n'est pas dite « créée », elle est nommée par `$listName`)
     * @param bool    $previousListSent  la liste créée remplace une liste envoyée au drive (jamais vrai quand aucune liste n'a été créée) ;
     *                                   la liste envoyée n'est pas modifiée (ADR 0023)
     * @param float   $quantity          la quantité TOTALE de la ligne après l'ajout, dans l'unité de la ligne
     * @param string  $unitSlug          l'unité de la ligne (celle de la première ligne de cet ingrédient dans la liste)
     * @param string  $unitName          le nom de cette unité, tel que le catalogue l'écrit (« paquet »)
     * @param bool    $lineExisted       l'ingrédient avait déjà une ligne dans la liste active : la quantité s'y est ajoutée
     * @param bool    $unitSubstituted   une unité a été dite mais n'est pas celle de la ligne : l'ingrédient ne la connaît pas, ou la ligne en avait
     *                                   déjà une autre (la quantité dite est alors convertie, ou ajoutée telle quelle si aucune conversion n'existe)
     * @param float   $addedQuantity     la quantité ajoutée à la ligne, dans l'unité de la ligne (convertie, le cas échéant)
     * @param ?string $listName          le nom de la liste d'un TYPE qui a reçu l'article (ADR 0036 décision 3 : la destination du produit, LST-008) ;
     *                                   `null` : la liste principale, comme pour tout compte sans type
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
        public ?string $listName = null,
    ) {
    }
}
