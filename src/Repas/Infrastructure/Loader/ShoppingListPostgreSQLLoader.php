<?php

namespace Repas\Repas\Infrastructure\Loader;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use IntlDateFormatter;
use Repas\Repas\Application\Loader\ShoppingListLoader;
use Repas\Repas\Application\Query\ListShoppingLists\ShoppingListDTO;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Infrastructure\Entity\ShoppingList;

final readonly class ShoppingListPostgreSQLLoader implements ShoppingListLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function listByOwner(string $ownerId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('l.id AS id', 'l.name AS name', 'l.status AS status', 'l.createdAt AS createdAt')
            ->from(ShoppingList::class, 'l')
            ->where('l.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // Motif repris tel quel du template d'origine : « YYYY » est l'année ISO de la semaine (voir roadmap, « Hors périmètre »).
        $defaultNameFormatter = new IntlDateFormatter('fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, IntlDateFormatter::GREGORIAN, 'dd MMMM YYYY');

        return array_map(
            static function (array $row) use ($defaultNameFormatter): ShoppingListDTO {
                /** @var DateTimeImmutable $createdAt */
                $createdAt = $row['createdAt'];
                /** @var ShoppingListStatus $status */
                $status = $row['status'];

                return new ShoppingListDTO(
                    id: $row['id'],
                    displayName: $row['name'] ?? $defaultNameFormatter->format($createdAt),
                    status: $status->value,
                    isActive: $status === ShoppingListStatus::ACTIVE,
                    isPaused: $status === ShoppingListStatus::PAUSED,
                    isCompleted: $status === ShoppingListStatus::COMPLETED,
                    createdAt: $createdAt->format(DATE_ATOM),
                );
            },
            $rows,
        );
    }
}
