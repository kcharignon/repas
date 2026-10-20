<?php

namespace Repas\Repas\Infrastructure\Loader;


use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Application\Loader\IngredientDuplicateLoader;
use Repas\Repas\Application\Query\ListDuplicateIngredients\DuplicateIngredientDTO;
use Repas\Repas\Application\Query\ListDuplicateIngredients\DuplicateIngredientSideDTO;
use Repas\Repas\Domain\Model\DuplicatePair;
use Repas\Repas\Domain\Model\IngredientCandidate;
use Repas\Repas\Domain\Model\IngredientDistinctPair;
use Repas\Repas\Domain\Service\DuplicateIngredientFinder;
use Repas\Repas\Infrastructure\Merge\IngredientMergeUsageReader;

/**
 * Les doublons possibles d'un utilisateur (ADR 0028 décision h, ING-006), en SQL : aucun modèle de domaine n'est hydraté. Trois lectures
 * au plus, quel que soit le nombre d'ingrédients : (1) tous les ingrédients PERSONNELS de l'utilisateur avec leur rayon, leur unité
 * d'achat et leur origine ; (2) ses paires « Pas le même » ; (3) le nombre de recettes et de listes des seuls ingrédients des paires
 * proposées (`IngredientMergeUsageReader::counts()`). La comparaison est faite en mémoire par le service de domaine
 * `DuplicateIngredientFinder`, rayon par rayon. Une lecture : rien n'est écrit.
 */
final readonly class IngredientDuplicatePostgreSQLLoader implements IngredientDuplicateLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IngredientMergeUsageReader $usageReader,
        private DuplicateIngredientFinder $finder,
    ) {
    }

    public function duplicates(string $userId): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT i.slug, i.name, i.adopted_from, i.department AS department_slug, d.name AS department_name,'
            . ' i.default_purchase_unit AS unit_slug, u.name AS unit_name'
            . ' FROM ingredient i JOIN department d ON d.slug = i.department JOIN unit u ON u.slug = i.default_purchase_unit'
            . ' WHERE i.creator = :user ORDER BY i.slug',
            ['user' => $userId],
        );
        // Une paire a besoin de deux ingrédients
        if (count($rows) < 2) {
            return [];
        }

        $candidates = [];
        $details = [];
        foreach ($rows as $row) {
            $candidates[] = new IngredientCandidate($row['slug'], $row['name'], $row['department_slug'], $row['unit_slug'], [], $userId, $row['adopted_from']);
            $details[$row['slug']] = $row;
        }

        $distinct = [];
        foreach ($this->connection()->fetchAllNumeric('SELECT first_slug, second_slug FROM ingredient_distinct WHERE user_id = :user', ['user' => $userId]) as [$first, $second]) {
            $distinct[IngredientDistinctPair::keyOf($first, $second)] = true;
        }

        $pairs = $this->finder->find($candidates, $distinct);
        if ($pairs === []) {
            return [];
        }

        $counts = $this->usageReader->counts($userId, $this->slugsOf($pairs));

        return array_map(function (DuplicatePair $pair) use ($details, $counts): DuplicateIngredientDTO {
            $first = $pair->first->slug;
            $second = $pair->second->slug;

            return new DuplicateIngredientDTO(
                first: $this->side($details[$first], $counts[$first] ?? []),
                second: $this->side($details[$second], $counts[$second] ?? []),
                score: round($pair->similarity->score, 4),
                level: strtoupper($pair->similarity->level->value),
                suggestedKeep: $pair->suggestedKeep(
                    $counts[$first]['recipes'] ?? 0,
                    $counts[$first]['lists'] ?? 0,
                    $counts[$second]['recipes'] ?? 0,
                    $counts[$second]['lists'] ?? 0,
                )->slug,
            );
        }, $pairs);
    }

    /**
     * @param list<DuplicatePair> $pairs
     * @return list<string>
     */
    private function slugsOf(array $pairs): array
    {
        $slugs = [];
        foreach ($pairs as $pair) {
            $slugs[$pair->first->slug] = true;
            $slugs[$pair->second->slug] = true;
        }

        return array_map('strval', array_keys($slugs));
    }

    /**
     * @param array{slug: string, name: string, department_slug: string, department_name: string, unit_name: string} $row
     * @param array{recipes?: int, lists?: int} $counts
     */
    private function side(array $row, array $counts): DuplicateIngredientSideDTO
    {
        return new DuplicateIngredientSideDTO(
            slug: $row['slug'],
            name: $row['name'],
            departmentSlug: $row['department_slug'],
            departmentName: $row['department_name'],
            purchaseUnitName: $row['unit_name'],
            recipeCount: $counts['recipes'] ?? 0,
            listCount: $counts['lists'] ?? 0,
        );
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
