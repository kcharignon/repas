<?php

namespace Repas\Repas\Infrastructure\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Domain\Interface\ListRoutingRepository;
use Repas\Repas\Domain\Service\ListDestinationResolver;
use Repas\Repas\Infrastructure\Visibility\IngredientVisibility;

/**
 * Lecture SQL de la destination des produits (ADR 0036) : UNE requête quel que soit le nombre de produits. Elle joint le choix du produit
 * et le type de son rayon aux types de l'utilisateur, pour que seuls les types qui existent comptent ; la décision est celle du service
 * de domaine `ListDestinationResolver`, jamais réécrite ici.
 */
final readonly class ListRoutingPostgreSQLRepository implements ListRoutingRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ListDestinationResolver $resolver,
    ) {
    }

    public function destinationsFor(string $ownerId, array $slugs): array
    {
        $slugs = array_values(array_unique($slugs));
        if ($slugs === []) {
            return [];
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT i.slug, i.list_destination, own.id AS own_type_id, dept.id AS department_type_id'
            . ' FROM ingredient i'
            . ' LEFT JOIN shopping_list_type own ON own.id = i.list_destination AND own.owner_id = :owner'
            . ' LEFT JOIN department_list_type dlt ON dlt.owner_id = :owner AND dlt.department_slug = i.department'
            . ' LEFT JOIN shopping_list_type dept ON dept.id = dlt.list_type_id AND dept.owner_id = :owner'
            . ' WHERE ' . IngredientVisibility::ownedSql('i', 'owner') . ' AND i.slug IN (:slugs)',
            ['owner' => $ownerId, 'slugs' => $slugs],
            ['slugs' => ArrayParameterType::STRING],
        );

        // Un produit inconnu ou à un autre n'est pas dans les lignes : la liste principale
        $destinations = array_fill_keys($slugs, null);
        foreach ($rows as $row) {
            $destinations[$row['slug']] = $this->resolver->resolve(
                $row['list_destination'],
                $row['department_type_id'],
                array_values(array_filter([$row['own_type_id'], $row['department_type_id']])),
            );
        }

        return $destinations;
    }
}
