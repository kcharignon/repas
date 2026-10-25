<?php

namespace Repas\Shared\Application\Interface;

/**
 * Ce que la commande vocale a fait pour ajouter un article à la liste de courses (ADR 0029 décision 5) : de quoi choisir la phrase de
 * réponse, rien de plus.
 */
final readonly class AddedItem
{
    /**
     * @param bool $listCreated       l'utilisateur n'avait aucune liste active : une nouvelle a été créée pour recevoir l'article
     * @param bool $previousListSent  la liste créée remplace une liste envoyée au drive (jamais vrai quand aucune liste n'a été créée) ;
     *                                la liste envoyée n'est pas modifiée (ADR 0023)
     */
    public function __construct(
        public bool $listCreated,
        public bool $previousListSent,
    ) {
    }
}
