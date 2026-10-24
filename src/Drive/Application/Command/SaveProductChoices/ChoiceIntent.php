<?php

namespace Repas\Drive\Application\Command\SaveProductChoices;


/**
 * Une intention de l'utilisateur sur un ingrédient. Cohérence exigée (sinon `DRIVE_CHOICE_INVALID`) : `ADD_PRODUCT` a un produit
 * (avec libellé) et un placement ; `REJECT_PRODUCT` un produit (avec libellé) sans placement ; `HAVE_AT_HOME` ni produit ni
 * placement ; `REFRESH_PRICE` un produit avec un prix, sans placement ; `NOTE_SEARCH` (DRV-653) ni produit ni placement mais une
 * recherche à mémoriser (un texte, jamais `null`). Une recherche mémorisée (`searchQuery`, DRV-650) n'est permise que sur
 * `ADD_PRODUCT` (le texte qui a fait trouver ce produit) et `NOTE_SEARCH` (où elle est obligatoire). `null` (pas de changement) garde celle qui est
 * mémorisée ; `SearchQueryChange::clear()` l'efface ; `SearchQueryChange::set()` la remplace.
 */
final readonly class ChoiceIntent
{
    public function __construct(
        public string $ingredientSlug,
        public ChoiceAction $action,
        public ?Placement $placement = null,
        public ?ProductInput $product = null,
        public ?SearchQueryChange $searchQuery = null,
    ) {
    }
}
