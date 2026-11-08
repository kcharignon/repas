<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Exception\MarmitonImportException;
use Repas\Repas\Domain\Model\MarmitonRecipePage;
use Repas\Repas\Domain\Model\MarmitonUrl;

/**
 * Lit la page d'une recette Marmiton et rend ses faits utiles (ADR 0037 décisions 12 et 13, MAR-001). L'adresse appelée est reconstruite
 * par `MarmitonUrl` : l'appelant ne choisit jamais ce qui est appelé. Un appel par lecture, jamais d'exploration ni de rafraîchissement ;
 * rien n'est écrit en base. L'adaptateur HTTP est dans l'infrastructure (`Infrastructure\Marmiton`).
 */
interface MarmitonPageReader
{
    /**
     * @throws MarmitonImportException `recipeNotFound` (404 ou 410), `recipeUnreadable` (page sans recette lisible), `unavailable` (délai,
     *                                 réseau, TLS, refus ou panne de Marmiton, redirection hors de Marmiton, réponse trop grosse ou d'un autre type)
     */
    public function read(MarmitonUrl $url): MarmitonRecipePage;
}
