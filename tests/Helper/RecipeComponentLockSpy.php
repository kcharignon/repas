<?php

namespace Repas\Tests\Helper;


use Closure;
use Repas\Repas\Domain\Interface\RecipeComponentLock;

/**
 * Verrou des recettes composées qui enregistre ses prises. `whileWaiting` rejoue ce qui se passe PENDANT l'attente d'un vrai verrou : l'autre
 * transaction, qui le tenait, a terminé quand il est enfin obtenu. Un cas d'usage qui lit AVANT de le prendre ne voit pas ce travail ; un autre, qui
 * lit après, le voit.
 */
class RecipeComponentLockSpy implements RecipeComponentLock
{
    /** @var list<string> les auteurs dont le verrou a été pris, dans l'ordre */
    public array $acquired = [];

    /**
     * @param (Closure(string): void)|null $whileWaiting appelé à chaque prise, avec l'auteur, juste avant de rendre la main
     */
    public function __construct(private readonly ?Closure $whileWaiting = null)
    {
    }

    public function acquire(string $authorId): void
    {
        $this->acquired[] = $authorId;
        if ($this->whileWaiting !== null) {
            ($this->whileWaiting)($authorId);
        }
    }
}
