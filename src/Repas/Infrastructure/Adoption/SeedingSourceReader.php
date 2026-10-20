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
     * @param ?list<string> $assumedBaseSlugs seulement pour le rapport à blanc de la passation (ING-009), AVANT que le classement soit posé : les
     *        ingrédients sans créateur de cette liste sont lus comme « de base » ; `null` (l'écriture, toujours) : le marqueur de la colonne
     *
     * @return list<IngredientToSeed> triés par slug
     */
    public function read(string $userId, string $ownerId, array $driveReferencedSlugs, ?array $assumedBaseSlugs = null): array
    {
        $assumedBaseSlugs = $assumedBaseSlugs === [] ? null : $assumedBaseSlugs;
        // Les lignes du compte qui référencent l'ingrédient `c` : recettes dont il est l'auteur, listes dont il est le propriétaire, et (passés
        // en paramètre, le contexte `Drive` garde ses tables) les ingrédients de ses choix du drive. Une sous-requête NON corrélée, lue une fois :
        // trois `EXISTS` corrélés, eux, coûtent pour le planificateur leur prix MULTIPLIÉ par le nombre d'ingrédients, franchissent
        // `jit_above_cost` dès quelques centaines de lignes et déclenchent la compilation JIT (de l'ordre de 0,5 s pour une requête de 2 ms,
        // mesuré avec 1 800 ingrédients et 2 500 lignes de liste : l'activation d'un compte la lit deux fois)
        $referenced = 'c.slug IN (SELECT x.ingredient FROM recipe_row x JOIN recipe r ON r.id = x.recipe WHERE r.author = :u'
            . ' UNION SELECT x.ingredient FROM shopping_list_row x JOIN shopping_list l ON l.id = x.shopping_list WHERE l.owner = :u'
            . ' UNION SELECT x.ingredient FROM shopping_list_ingredient x JOIN shopping_list l ON l.id = x.shopping_list WHERE l.owner = :u)';
        $params = ['u' => $userId, 'owner' => $ownerId];
        $types = [];
        $assumed = $assumedBaseSlugs === null ? null : 'assumed';
        if ($assumed !== null) {
            $params[$assumed] = array_values($assumedBaseSlugs);
            $types[$assumed] = ArrayParameterType::STRING;
        }
        if ($driveReferencedSlugs !== []) {
            $referenced .= ' OR c.slug IN (:drive)';
            $params['drive'] = array_values(array_unique($driveReferencedSlugs));
            $types['drive'] = ArrayParameterType::STRING;
        }

        // La colonne `existing_copy` : un ingrédient du compte né de celui-là (`adopted_from`), sans alias, et strictement conforme à lui. Elle vient
        // d'une table dérivée, jointe une fois : les copies du compte (peu nombreuses, souvent aucune) comparées à leur origine, et non une sous-requête
        // corrélée qui relirait toute la table `ingredient` pour chaque candidat (de l'ordre de la seconde par lecture avec 800 ingrédients, qui croît
        // avec le nombre de comptes amorcés : l'activation d'un compte la lit deux fois)
        $rows = $this->connection()->fetchAllNumeric(
            'SELECT c.slug, c.name, ' . IngredientVisibility::isBaseCatalogSql('c', 'owner', $assumed) . ' AS is_base, existing.copy_slug AS existing_copy'
            . ' FROM ingredient c LEFT JOIN ('
            . '   SELECT o.adopted_from AS origin, min(o.slug) AS copy_slug FROM ingredient o JOIN ingredient c ON c.slug = o.adopted_from'
            . '   WHERE o.creator = :u AND NOT (' . IngredientAdoptionBulkPostgreSQLRepository::COPY_DIFFERS_FROM_COMMON . ') GROUP BY o.adopted_from'
            . ' ) existing ON existing.origin = c.slug'
            . ' WHERE (' . IngredientVisibility::isBaseCatalogSql('c', 'owner', $assumed)
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
