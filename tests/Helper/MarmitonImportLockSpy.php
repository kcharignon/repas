<?php

namespace Repas\Tests\Helper;


use Closure;
use Repas\Repas\Domain\Interface\MarmitonImportLock;

/**
 * Verrou d'import Marmiton qui enregistre ses prises. `whileWaiting` rejoue ce qui se passe PENDANT l'attente d'un vrai verrou : l'autre
 * transaction, qui tenait le verrou, a terminé (elle a créé la neutre, peut-être la copie de la personne) quand le verrou est enfin obtenu. Un
 * cas d'usage qui regarde AVANT de prendre le verrou ne voit pas ce travail ; un autre, qui regarde après, le voit.
 */
class MarmitonImportLockSpy implements MarmitonImportLock
{
    /** @var list<string> les identifiants dont le verrou a été pris, dans l'ordre */
    public array $acquired = [];

    /**
     * @param (Closure(string): void)|null $whileWaiting appelé à chaque prise, avec l'identifiant, juste avant de rendre la main
     */
    public function __construct(private readonly ?Closure $whileWaiting = null)
    {
    }

    public function acquire(string $marmitonId): void
    {
        $this->acquired[] = $marmitonId;
        if ($this->whileWaiting !== null) {
            ($this->whileWaiting)($marmitonId);
        }
    }
}
