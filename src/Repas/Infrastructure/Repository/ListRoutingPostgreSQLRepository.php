<?php

namespace Repas\Repas\Infrastructure\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Domain\Interface\ListRoutingRepository;
use Repas\Repas\Domain\Model\ProductPlacement;
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

        // Un produit inconnu ou à un autre n'a pas de placement : la liste principale
        $destinations = array_fill_keys($slugs, null);
        foreach ($this->placementsFor($ownerId, $slugs) as $slug => $placement) {
            $destinations[$slug] = $placement->listTypeId;
        }

        return $destinations;
    }

    public function placementsFor(string $ownerId, array $slugs): array
    {
        $slugs = array_values(array_unique($slugs));
        if ($slugs === []) {
            return [];
        }

        // Une PARTIE d'ingrédient (ADR 0038 décision 10) se pose dans la liste de son parent, sur la ligne de son parent : le choix de liste, le
        // rayon et l'unité d'achat sont ceux du parent (`p`, un ingrédient du même utilisateur), dans la même requête. Sans parent à lui, une
        // partie se pose comme un ingrédient ordinaire. Un ingrédient ordinaire n'a pas de `p` : tout est lu sur lui-même, comme avant
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT i.slug, p.slug AS part_of, CASE WHEN p.slug IS NULL THEN i.list_destination ELSE p.list_destination END AS list_destination,'
            . ' COALESCE(p.default_purchase_unit, i.default_purchase_unit) AS default_purchase_unit, own.id AS own_type_id, dept.id AS department_type_id'
            . ' FROM ingredient i'
            . ' LEFT JOIN ingredient p ON p.slug = i.parent_slug AND ' . IngredientVisibility::ownedSql('p', 'owner')
            . ' LEFT JOIN shopping_list_type own ON own.id = (CASE WHEN p.slug IS NULL THEN i.list_destination ELSE p.list_destination END) AND own.owner_id = :owner'
            . ' LEFT JOIN department_list_type dlt ON dlt.owner_id = :owner AND dlt.department_slug = COALESCE(p.department, i.department)'
            . ' LEFT JOIN shopping_list_type dept ON dept.id = dlt.list_type_id AND dept.owner_id = :owner'
            . ' WHERE ' . IngredientVisibility::ownedSql('i', 'owner') . ' AND i.slug IN (:slugs)',
            ['owner' => $ownerId, 'slugs' => $slugs],
            ['slugs' => ArrayParameterType::STRING],
        );

        $placements = [];
        foreach ($rows as $row) {
            $placements[$row['slug']] = new ProductPlacement(
                $this->resolver->resolve(
                    $row['list_destination'],
                    $row['department_type_id'],
                    array_values(array_filter([$row['own_type_id'], $row['department_type_id']])),
                ),
                $row['default_purchase_unit'],
                $row['part_of'],
            );
        }

        return $placements;
    }
}
