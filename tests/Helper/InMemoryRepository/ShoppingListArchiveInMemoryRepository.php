<?php

namespace Repas\Tests\Helper\InMemoryRepository;

use DateTimeImmutable;
use Repas\Repas\Domain\Interface\ShoppingListArchiveRepository;
use Repas\Repas\Domain\Model\ShoppingListArchiveCause;

/**
 * Double idiot du port des archives (ADR 0041) : il note les copies demandées et les comptes dont on a effacé les archives. Le contenu d'une archive se
 * prouve sur la vraie base (`tests/Repas/OutputAdapter/ShoppingListArchiveRepositoryTest`), jamais ici.
 */
class ShoppingListArchiveInMemoryRepository implements ShoppingListArchiveRepository
{
    /** @var list<array{archiveId: string, shoppingListId: string, cause: ShoppingListArchiveCause, at: DateTimeImmutable}> */
    public array $archived = [];

    /** @var list<string> */
    public array $ownersErased = [];

    public function archive(string $archiveId, string $shoppingListId, ShoppingListArchiveCause $cause, DateTimeImmutable $at): void
    {
        $this->archived[] = ['archiveId' => $archiveId, 'shoppingListId' => $shoppingListId, 'cause' => $cause, 'at' => $at];
    }

    public function deleteByOwner(string $ownerId): void
    {
        $this->ownersErased[] = $ownerId;
    }
}
