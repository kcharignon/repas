<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Repas\Repas\Application\Loader\IngredientLoader;
use Repas\Repas\Application\Query\GetIngredient\IngredientDTO;
use Repas\Repas\Application\Query\ListAvailableIngredients\IngredientOptionDTO;
use Repas\Repas\Application\Query\ListCompatibleUnits\CompatibleUnitDTO;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\DepartmentIngredientsDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\IngredientRowDTO;
use Repas\Repas\Application\Query\SearchIngredients\IngredientSearchResultDTO;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Infrastructure\Entity\Conversion;
use Repas\Repas\Infrastructure\Entity\Department;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\Unit;
use Repas\Shared\Domain\Tool\SearchRelevance;
use Repas\Shared\Domain\Tool\StringTool;

final readonly class IngredientPostgreSQLLoader implements IngredientLoader
{
    /** Nombre maximal de résultats d'une recherche : au-delà, la saisie est à préciser */
    private const int SEARCH_LIMIT = 30;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function listByDepartment(string $departmentSlug, string $viewerId, bool $viewerIsAdmin): DepartmentIngredientsDTO
    {
        $department = $this->entityManager->createQueryBuilder()
            ->select('d.slug AS slug', 'd.name AS name', 'd.image AS image')
            ->from(Department::class, 'd')
            ->where('d.slug = :slug')
            ->setParameter('slug', $departmentSlug)
            ->getQuery()
            ->getOneOrNullResult();

        if ($department === null) {
            throw DepartmentException::notFound();
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('i.slug AS slug', 'i.name AS name', 'i.creatorId AS creatorId', 'u.symbol AS purchaseUnitSymbol')
            ->from(Ingredient::class, 'i')
            ->innerJoin(Unit::class, 'u', Join::WITH, 'u.slug = i.defaultPurchaseUnitSlug')
            ->where('i.departmentSlug = :department')
            ->andWhere('i.creatorId = :viewer OR i.creatorId IS NULL')
            ->setParameter('department', $departmentSlug)
            ->setParameter('viewer', $viewerId)
            ->orderBy('i.sluggedName', 'ASC')
            ->addOrderBy('i.slug', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return new DepartmentIngredientsDTO(
            new DepartmentDTO($department['slug'], $department['name'], $department['image']),
            array_map(
                fn(array $row): IngredientRowDTO => new IngredientRowDTO(
                    $row['slug'], $row['name'], $row['purchaseUnitSymbol'], $this->canEdit($row['creatorId'], $viewerId, $viewerIsAdmin),
                ),
                $rows,
            ),
        );
    }

    public function get(string $slug, string $viewerId, bool $viewerIsAdmin): IngredientDTO
    {
        $ingredient = $this->entityManager->createQueryBuilder()
            ->select(
                'i.slug AS slug', 'i.name AS name', 'i.image AS image', 'i.creatorId AS creatorId',
                'd.slug AS departmentSlug', 'd.name AS departmentName',
                'cu.slug AS cookingUnitSlug', 'cu.name AS cookingUnitName',
                'pu.slug AS purchaseUnitSlug', 'pu.name AS purchaseUnitName', 'pu.symbol AS purchaseUnitSymbol',
            )
            ->from(Ingredient::class, 'i')
            ->innerJoin(Department::class, 'd', Join::WITH, 'd.slug = i.departmentSlug')
            ->innerJoin(Unit::class, 'cu', Join::WITH, 'cu.slug = i.defaultCookingUnitSlug')
            ->innerJoin(Unit::class, 'pu', Join::WITH, 'pu.slug = i.defaultPurchaseUnitSlug')
            ->where('i.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        // Un ingrédient créé par un autre utilisateur n'existe pas pour un utilisateur qui n'est pas administrateur
        if ($ingredient === null || (!$viewerIsAdmin && $ingredient['creatorId'] !== null && $ingredient['creatorId'] !== $viewerId)) {
            throw IngredientException::notFound($slug);
        }

        $hasSameUnit = $ingredient['cookingUnitSlug'] === $ingredient['purchaseUnitSlug'];

        return new IngredientDTO(
            slug: $ingredient['slug'],
            name: $ingredient['name'],
            image: $ingredient['image'],
            departmentSlug: $ingredient['departmentSlug'],
            departmentName: $ingredient['departmentName'],
            cookingUnitSlug: $ingredient['cookingUnitSlug'],
            cookingUnitName: $ingredient['cookingUnitName'],
            purchaseUnitSlug: $ingredient['purchaseUnitSlug'],
            purchaseUnitName: $ingredient['purchaseUnitName'],
            purchaseUnitSymbol: $ingredient['purchaseUnitSymbol'],
            hasSameUnit: $hasSameUnit,
            coefficient: $hasSameUnit ? null : $this->findCoefficient($slug, $ingredient['purchaseUnitSlug'], $ingredient['cookingUnitSlug']),
            creatorId: $ingredient['creatorId'],
            canEdit: $this->canEdit($ingredient['creatorId'], $viewerId, $viewerIsAdmin),
        );
    }

    public function listCompatibleUnits(string $ingredientSlug): array
    {
        $ingredient = $this->entityManager->createQueryBuilder()
            ->select('i.compatibleUnitSlugs AS compatibleUnitSlugs')
            ->from(Ingredient::class, 'i')
            ->where('i.slug = :slug')
            ->setParameter('slug', $ingredientSlug)
            ->getQuery()
            ->getOneOrNullResult();

        if ($ingredient === null) {
            throw IngredientException::notFound($ingredientSlug);
        }

        $slugs = $ingredient['compatibleUnitSlugs'] ?? [];
        if ($slugs === []) {
            return [];
        }

        $names = $this->entityManager->createQueryBuilder()
            ->select('u.slug AS slug', 'u.name AS name')
            ->from(Unit::class, 'u')
            ->where('u.slug IN (:slugs)')
            ->setParameter('slugs', $slugs)
            ->getQuery()
            ->getArrayResult();
        $nameBySlug = array_column($names, 'name', 'slug');

        // Ordre d'enregistrement de l'ingrédient, comme l'ancien modèle de domaine
        $units = [];
        foreach ($slugs as $slug) {
            if (isset($nameBySlug[$slug])) {
                $units[] = new CompatibleUnitDTO($slug, StringTool::upperCaseFirst($nameBySlug[$slug]));
            }
        }

        return $units;
    }

    public function listAvailable(string $userId, ?string $search = null): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('i.slug AS slug', 'i.name AS name')
            ->from(Ingredient::class, 'i')
            ->where('i.creatorId = :user OR i.creatorId IS NULL')
            ->setParameter('user', $userId)
            ->orderBy('i.sluggedName', 'ASC')
            ->addOrderBy('i.slug', 'ASC');

        // `slugged_name` est le nom sans accent ni majuscule, séparé par des tirets : on cherche le texte dans la même forme
        $needle = $search === null ? '' : StringTool::slugify($search);
        if ($needle !== '') {
            $query->andWhere('i.sluggedName LIKE :search')->setParameter('search', '%' . $needle . '%');
        }

        $rows = $query->getQuery()->getArrayResult();

        return array_map(static fn(array $row): IngredientOptionDTO => new IngredientOptionDTO($row['slug'], $row['name']), $rows);
    }

    public function search(string $search, string $viewerId, bool $viewerIsAdmin): array
    {
        // `slugged_name` est le nom sans accent ni majuscule, séparé par des tirets : on cherche le texte dans la même forme
        $needle = StringTool::slugify($search);
        if ($needle === '') {
            return [];
        }

        // Le score est calculé en PHP (Jaro-Winkler n'existe pas en SQL) sur tous les ingrédients visibles : quelques centaines
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'i.slug AS slug', 'i.name AS name', 'i.sluggedName AS sluggedName', 'i.creatorId AS creatorId',
                'u.symbol AS purchaseUnitSymbol',
                'd.slug AS departmentSlug', 'd.name AS departmentName', 'd.image AS departmentImage',
            )
            ->from(Ingredient::class, 'i')
            ->innerJoin(Unit::class, 'u', Join::WITH, 'u.slug = i.defaultPurchaseUnitSlug')
            ->innerJoin(Department::class, 'd', Join::WITH, 'd.slug = i.departmentSlug')
            ->where('i.creatorId = :viewer OR i.creatorId IS NULL')
            ->setParameter('viewer', $viewerId)
            ->getQuery()
            ->getArrayResult();

        $scored = [];
        foreach ($rows as $row) {
            $score = SearchRelevance::score($needle, $row['sluggedName']);
            if ($score >= SearchRelevance::MINIMUM) {
                $scored[] = ['score' => $score, 'row' => $row];
            }
        }

        // Ex æquo : ordre alphabétique, pour que deux appels donnent le même résultat
        usort($scored, static fn(array $a, array $b): int => [$b['score'], $a['row']['sluggedName'], $a['row']['slug']] <=> [$a['score'], $b['row']['sluggedName'], $b['row']['slug']]);

        return array_map(
            fn(array $match): IngredientSearchResultDTO => new IngredientSearchResultDTO(
                slug: $match['row']['slug'],
                name: $match['row']['name'],
                purchaseUnitSymbol: $match['row']['purchaseUnitSymbol'],
                canEdit: $this->canEdit($match['row']['creatorId'], $viewerId, $viewerIsAdmin),
                departmentSlug: $match['row']['departmentSlug'],
                departmentName: $match['row']['departmentName'],
                departmentImage: $match['row']['departmentImage'],
            ),
            array_slice($scored, 0, self::SEARCH_LIMIT),
        );
    }

    private function canEdit(?string $creatorId, string $viewerId, bool $viewerIsAdmin): bool
    {
        return $creatorId === $viewerId || ($viewerIsAdmin && $creatorId === null);
    }

    /**
     * Conversion de l'unité d'achat vers l'unité des recettes : celle de l'ingrédient, à défaut la conversion commune.
     */
    private function findCoefficient(string $ingredientSlug, string $purchaseUnitSlug, string $cookingUnitSlug): ?float
    {
        $coefficient = $this->entityManager->createQueryBuilder()
            ->select('c.coefficient AS coefficient')
            ->from(Conversion::class, 'c')
            ->where('c.ingredientSlug = :ingredient OR c.ingredientSlug IS NULL')
            ->andWhere('c.startUnitSlug = :start')
            ->andWhere('c.endUnitSlug = :end')
            ->setParameter('ingredient', $ingredientSlug)
            ->setParameter('start', $purchaseUnitSlug)
            ->setParameter('end', $cookingUnitSlug)
            ->addSelect('CASE WHEN c.ingredientSlug IS NULL THEN 1 ELSE 0 END AS HIDDEN commonLast')
            ->orderBy('commonLast', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $coefficient === null ? null : (float) $coefficient['coefficient'];
    }
}
