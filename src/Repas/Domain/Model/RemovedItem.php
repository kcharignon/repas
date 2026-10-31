<?php

namespace Repas\Repas\Domain\Model;

/**
 * Ce qu'un retrait d'article de la liste de courses a fait (ADR 0029 décision 12) : de quoi en rendre compte, rien de plus.
 */
final readonly class RemovedItem
{
    /**
     * @param bool    $found           une ligne de cet ingrédient existait dans une liste en cours (faux : plus de liste en cours, ou pas de ligne ; rien n'a été écrit)
     * @param float   $remaining       la quantité qui reste dans la ligne, dans son unité ; 0 : la ligne a disparu
     * @param float   $removed         la quantité retirée, dans l'unité de la ligne (convertie, le cas échéant ; jamais plus que ce que la ligne contenait)
     * @param string  $unitSlug        l'unité de la ligne
     * @param string  $unitName        le nom de cette unité, tel que le catalogue l'écrit (« paquet »)
     * @param bool    $unitSubstituted une unité a été dite mais n'est pas celle de la ligne : l'ingrédient ne la connaît pas, ou la quantité dite a été
     *                                 convertie (ou retirée telle quelle si aucune conversion n'existe)
     * @param ?string $listName        le nom de la liste d'un TYPE dont la ligne a été retirée (ADR 0036, LST-008) ; `null` : la liste principale
     */
    public function __construct(
        public bool $found,
        public float $remaining = 0.0,
        public float $removed = 0.0,
        public string $unitSlug = 'unite',
        public string $unitName = 'unité',
        public bool $unitSubstituted = false,
        public ?string $listName = null,
    ) {
    }

    /** La ligne a disparu de la liste. */
    public function lineDeleted(): bool
    {
        return $this->found && $this->remaining <= 0.0;
    }
}
