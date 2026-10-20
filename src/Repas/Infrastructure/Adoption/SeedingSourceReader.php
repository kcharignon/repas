<?php

namespace Repas\Repas\Infrastructure\Adoption;


use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Domain\Model\IngredientToSeed;
use Repas\Repas\Infrastructure\Repository\IngredientAdoptionBulkPostgreSQLRepository;
use Repas\Repas\Infrastructure\Visibility\IngredientVisibility;

/**
 * Lit, en LECTURE SEULE et en UNE requête, ce qu'un compte doit recevoir à son amorçage (ING-008, `docs/ingredients/sans-commun.md` § 2.2) :
 * les ingrédients du catalogue du propriétaire qui portent le marqueur « de base », plus ceux du catalogue que les lignes du compte
 * référencent, sauf ceux qu'il a déjà adoptés. Partagée par `IngredientAdoptionBulkPostgreSQLRepository` (qui écrit) et
 * `IngredientSeedingReportPostgreSQLLoader` (qui rapporte à blanc), pour qu'elles disent la même chose — comme `AdoptionSnapshotReader`.
 *
 * La règle « de base ET créateur propriétaire ou vide » n'est écrite qu'à un seul endroit, `IngredientVisibility::isBaseCatalogSql()`.
 */
final readonly class SeedingSourceReader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<string> $driveReferencedSlugs les slugs que `DriveIngredientReferences::referencedSlugs()` rend pour ce compte
     *
     * @return list<IngredientToSeed> triés par slug
     */
    public function read(string $userId, string $ownerId, array $driveReferencedSlugs): array
    {
        // Les lignes du compte qui référencent l'ingrédient `c` : recettes dont il est l'auteur, listes dont il est le propriétaire, et (passés
        // en paramètre, le contexte `Drive` garde ses tables) les ingrédients de ses choix du drive
        $referenced = 'EXISTS (SELECT 1 FROM recipe_row x JOIN recipe r ON r.id = x.recipe WHERE r.author = :u AND x.ingredient = c.slug)'
            . ' OR EXISTS (SELECT 1 FROM shopping_list_row x JOIN shopping_list l ON l.id = x.shopping_list WHERE l.owner = :u AND x.ingredient = c.slug)'
            . ' OR EXISTS (SELECT 1 FROM shopping_list_ingredient x JOIN shopping_list l ON l.id = x.shopping_list WHERE l.owner = :u AND x.ingredient = c.slug)';
        $params = ['u' => $userId, 'owner' => $ownerId];
        $types = [];
        if ($driveReferencedSlugs !== []) {
            $referenced .= ' OR c.slug IN (:drive)';
            $params['drive'] = array_values(array_unique($driveReferencedSlugs));
            $types['drive'] = ArrayParameterType::STRING;
        }

        // La colonne `existing_copy` : un ingrédient du compte né de celui-là (`adopted_from`), sans alias, et strictement conforme à lui
        $rows = $this->connection()->fetchAllNumeric(
            'SELECT c.slug, c.name, ' . IngredientVisibility::isBaseCatalogSql('c', 'owner') . ' AS is_base,'
            . ' (SELECT min(o.slug) FROM ingredient o WHERE o.creator = :u AND o.adopted_from = c.slug AND NOT (' . IngredientAdoptionBulkPostgreSQLRepository::COPY_DIFFERS_FROM_COMMON . ')) AS existing_copy'
            . ' FROM ingredient c WHERE (' . IngredientVisibility::isBaseCatalogSql('c', 'owner')
            . '   OR (' . IngredientVisibility::isCatalogSql('c', 'owner') . ' AND (' . $referenced . ')))'
            . ' AND NOT EXISTS (SELECT 1 FROM ingredient_alias a JOIN ingredient own ON own.slug = a.ingredient_slug AND own.creator = a.user_id'
            . "   WHERE a.user_id = :u AND a.alias_slug = c.slug AND a.kind = 'adoption')"
            . ' ORDER BY c.slug',
            $params,
            $types,
        );

        return array_map(static fn(array $row): IngredientToSeed => new IngredientToSeed($row[0], $row[1], (bool) $row[2], $row[3]), $rows);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
