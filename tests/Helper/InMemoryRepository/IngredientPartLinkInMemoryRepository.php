<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Interface\IngredientPartLinkRepository;
use Throwable;

/**
 * Le port de la liaison des sous-ingrédients (ADR 0038, SUB-002) en double idiot : il enregistre les comptes pour lesquels la liaison a été
 * demandée et rend le nombre de liens programmé (ou lève l'erreur programmée). La règle de liaison elle-même est prouvée sur la vraie base
 * (`tests/Repas/OutputAdapter/IngredientPartLinkTest`) : un handler ne fait que l'appeler, au bon moment.
 */
final class IngredientPartLinkInMemoryRepository implements IngredientPartLinkRepository
{
    /** @var list<string> les comptes pour lesquels la liaison a été demandée, dans l'ordre */
    public array $linkedUsers = [];
    /** Le nombre de liens que chaque appel dit avoir posés. */
    public int $links = 0;
    /** Quand elle est posée, la liaison échoue avec cette erreur (la transaction du bus annule alors la copie ou l'amorçage). */
    public ?Throwable $failure = null;
    /** Appelé à chaque liaison, avant qu'elle ne rende : de quoi lire ce qui s'est passé AVANT elle (l'ordre des écritures). */
    public ?\Closure $onLink = null;

    public function linkCopies(string $userId): int
    {
        $this->linkedUsers[] = $userId;
        if ($this->onLink !== null) {
            ($this->onLink)($userId);
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->links;
    }
}
