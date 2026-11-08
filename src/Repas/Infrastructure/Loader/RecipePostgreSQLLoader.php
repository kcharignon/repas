<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Repas\Repas\Application\Loader\RecipeLoader;
use Repas\Repas\Application\Query\GetRecipe\RecipeComponentDTO;
use Repas\Repas\Application\Query\GetRecipe\RecipeDTO;
use Repas\Repas\Application\Query\GetRecipe\RecipeRowDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipeDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipesDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipeTypes\OtherPeopleRecipeTypeDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipeTypes\OtherPeopleRecipeTypesDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeTypeRecipesDTO;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;
use Repas\Repas\Application\Query\ListRecipeUsages\RecipeUsagesDTO;
use Repas\Repas\Application\Query\ListUsableRecipes\ListUsableRecipesQuery;
use Repas\Repas\Application\Query\ListUsableRecipes\UsableRecipeDTO;
use Repas\Repas\Application\Query\ListUsableRecipes\UsableRecipesDTO;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\Recipe as DomainRecipe;
use Repas\Repas\Infrastructure\Conversion\IngredientConversionGraphs;
use Repas\Repas\Infrastructure\Entity\Department;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\Recipe;
use Repas\Repas\Infrastructure\Entity\RecipeComponent;
use Repas\Repas\Infrastructure\Entity\RecipeRow;
use Repas\Repas\Infrastructure\Entity\RecipeType;
use Repas\Repas\Infrastructure\Entity\Unit;
use Repas\Shared\Domain\Tool\SearchRelevance;
use Repas\Shared\Domain\Tool\StringTool;

final readonly class RecipePostgreSQLLoader implements RecipeLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Les lignes sont dans l'ordre de la recette (colonne `position`, écrite dans l'ordre de la saisie à chaque enregistrement).
     *
     * Chaque ligne dit si son unité se convertit en l'unité d'achat de son ingrédient (`convertible`) : les conversions propres des ingrédients
     * de la recette et les universelles sont lues en UNE requête de plus (le nombre de requêtes ne dépend pas du nombre de lignes) et passées
     * au graphe pur de `ConversionService`, qui donne la même réponse que la planification. La recette d'un autre utilisateur se lit de la même
     * façon, sur les ingrédients de son auteur : on n'en apprend rien de plus que ce booléen et l'unité d'achat, une donnée publique.
     *
     * Les composants (ADR 0040 décision 20) : sans composant, ces trois requêtes et rien de plus ; avec, UNE de plus (les arêtes jointes à la recette
     * utilisée), les lignes de toutes les recettes dans l'instruction qui lit déjà les lignes et le graphe de tous les ingrédients dans la même lecture.
     * `convertible` d'un composant : toutes les lignes de la recette utilisée se convertissent.
     *
     * Une recette secrète n'est lue que par son auteur (ADR 0033) : la condition est dans la requête même, avant toute lecture des lignes. Pour
     * tout autre, administrateur compris, la requête ne rend rien et la réponse est celle d'une recette inconnue (`404`, jamais `403` : on ne
     * révèle pas qu'elle existe).
     */
    public function get(string $id, string $userId, bool $isAdministrator = false): RecipeDTO
    {
        $recipe = $this->entityManager->createQueryBuilder()
            ->select('r.id AS id', 'r.name AS name', 'r.serving AS serving', 't.slug AS typeSlug', 't.name AS typeName', 'r.version AS version', 'r.secret AS secret', 'r.favorite AS favorite', 'r.authorId AS authorId', 'r.sourceUrl AS sourceUrl', 'r.componentCount AS componentCount', 'o.id AS originalId', 'k.id AS copyId')
            ->from(Recipe::class, 'r')
            ->innerJoin(RecipeType::class, 't', Join::WITH, 't.slug = r.typeSlug')
            // L'original, dans la même instruction : seulement s'il existe encore et que le lecteur le voit (jamais l'identifiant d'une recette supprimée ou secrète)
            ->leftJoin(Recipe::class, 'o', Join::WITH, 'o.id = r.originalId AND (o.secret = false OR o.authorId = :userId)')
            // La copie du lecteur quand la recette n'a pas de propriétaire : au plus UNE ligne jointe (la plus ancienne), dans la même instruction
            ->leftJoin(Recipe::class, 'k', Join::WITH, $this->firstCopyOfTheReader('r') . ' AND r.authorId = :withoutOwner')
            ->where('r.id = :id')
            ->andWhere('(r.secret = false OR r.authorId = :userId)')
            ->setParameter('id', $id)
            ->setParameter('userId', $userId)
            ->setParameter('withoutOwner', DomainRecipe::MARMITON_OWNER_ID)
            ->getQuery()
            ->getOneOrNullResult();

        if ($recipe === null) {
            throw RecipeException::notFound($id);
        }

        // Les composants (ADR 0040 décision 20) : sans composant (`component_count`, lu avec la recette), pas une requête de plus. Avec, UNE requête
        // pour toutes les arêtes jointes à la recette utilisée, quel que soit leur nombre. Une recette utilisée supprimée (aucune clé étrangère) ou
        // que le lecteur ne voit pas (secrète d'un autre) est ignorée, jamais une erreur (décision 21)
        $components = (int) $recipe['componentCount'] > 0 ? $this->components($id, $userId) : [];

        // Les lignes de la recette ET celles de ses recettes utilisées dans la même instruction (`IN`), rangées par recette ensuite ; le graphe des
        // conversions se lit sur l'union des ingrédients, toujours en une lecture
        $rowsByRecipe = $this->rowsOf([$id, ...array_column($components, 'recipeId')]);
        $slugs = [];
        foreach ($rowsByRecipe as $rows) {
            array_push($slugs, ...array_column($rows, 'ingredientSlug'));
        }

        // Le même graphe que la planification et que le rapport de santé des conversions (`repas:conversions:check`)
        $graphs = new IngredientConversionGraphs($this->entityManager->getConnection())->forIngredients(array_values(array_unique($slugs)));
        $toRowDto = static fn(array $row): RecipeRowDTO => new RecipeRowDTO(
            $row['ingredientSlug'], $row['ingredientName'], $row['ingredientImage'], (float) $row['quantity'], $row['unitSlug'], $row['unitSymbol'],
            $row['unitName'], $row['purchaseUnitSlug'], $row['purchaseUnitName'],
            $graphs[$row['ingredientSlug']]->connects($row['unitSlug'], $row['purchaseUnitSlug']),
            $row['departmentSlug'], $row['departmentName'], $row['partOfName'],
        );

        return new RecipeDTO(
            id: $recipe['id'],
            name: $recipe['name'],
            serving: (int) $recipe['serving'],
            typeSlug: $recipe['typeSlug'],
            typeName: $recipe['typeName'],
            secret: (bool) $recipe['secret'],
            // Le choix de l'auteur : la recette ordinaire d'un autre n'est jamais « favorite » pour qui la lit
            favorite: (bool) $recipe['favorite'] && $recipe['authorId'] === $userId,
            rows: array_map($toRowDto, $rowsByRecipe[$id] ?? []),
            components: array_map(
                static function (array $component) use ($rowsByRecipe, $toRowDto): RecipeComponentDTO {
                    $rows = array_map($toRowDto, $rowsByRecipe[$component['recipeId']] ?? []);

                    return new RecipeComponentDTO(
                        $component['recipeId'], $component['name'], (int) $component['serving'], (float) $component['multiplier'], (bool) $component['secret'],
                        // Toutes ses lignes : une seule qui ne se convertit pas empêche de planifier la recette qui l'utilise
                        !in_array(false, array_map(static fn(RecipeRowDTO $row): bool => $row->convertible, $rows), true),
                        $rows,
                    );
                },
                $components,
            ),
            version: (int) $recipe['version'],
            sourceUrl: $recipe['sourceUrl'],
            originalId: $recipe['originalId'],
            // La sienne ; pour un administrateur, toute recette qu'il lit (le droit `RECIPE_OWNER` : une recette sans propriétaire n'est modifiée que par lui)
            canEdit: $isAdministrator || $recipe['authorId'] === $userId,
            ownerless: $recipe['authorId'] === DomainRecipe::MARMITON_OWNER_ID,
            // Pour une recette ordinaire la jointure ne trouve rien : toujours `null`
            copyId: $recipe['copyId'],
        );
    }

    /**
     * Les recettes que la recette lue utilise, dans l'ordre de leurs positions : UNE requête (l'arête jointe à la recette utilisée), jamais une par
     * composant. La jointure est interne : une arête dont la recette utilisée n'existe plus, ou que le lecteur ne voit pas (secrète d'un autre -
     * une recette ordinaire n'en a pas, mais l'état d'une base ancienne ne doit jamais la montrer), n'est pas lue.
     *
     * @return list<array{recipeId: string, name: string, serving: int|string, multiplier: float|string, secret: bool}>
     */
    private function components(string $recipeId, string $userId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('rc.componentId AS recipeId', 'c.name AS name', 'c.serving AS serving', 'rc.multiplier AS multiplier', 'c.secret AS secret')
            ->from(RecipeComponent::class, 'rc')
            ->innerJoin(Recipe::class, 'c', Join::WITH, 'c.id = rc.componentId AND (c.secret = false OR c.authorId = :userId)')
            ->where('rc.recipeId = :id')
            ->setParameter('id', $recipeId)
            ->setParameter('userId', $userId)
            ->orderBy('rc.position', 'ASC')
            ->addOrderBy('rc.id', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Les lignes de ces recettes en UNE requête, rangées par recette et, pour chacune, dans l'ordre de la recette (colonne `position`, écrite
     * dans l'ordre de la saisie à chaque enregistrement).
     *
     * @param list<string> $recipeIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function rowsOf(array $recipeIds): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'rr.recipeId AS recipeId', 'i.slug AS ingredientSlug', 'i.name AS ingredientName', 'i.image AS ingredientImage',
                'rr.quantity AS quantity', 'u.slug AS unitSlug', 'u.symbol AS unitSymbol', 'u.name AS unitName',
                'pu.slug AS purchaseUnitSlug', 'pu.name AS purchaseUnitName',
                'd.slug AS departmentSlug', 'd.name AS departmentName', 'p.name AS partOfName',
            )
            ->from(RecipeRow::class, 'rr')
            ->innerJoin(Ingredient::class, 'i', Join::WITH, 'i.slug = rr.ingredientSlug')
            // Le parent de l'ingrédient quand c'est une partie (ADR 0038) : dans la même instruction, aucune requête de plus
            ->leftJoin(Ingredient::class, 'p', Join::WITH, 'p.slug = i.parentSlug AND p.creatorId = i.creatorId')
            ->innerJoin(Department::class, 'd', Join::WITH, 'd.slug = i.departmentSlug')
            ->innerJoin(Unit::class, 'u', Join::WITH, 'u.slug = rr.unitSlug')
            ->innerJoin(Unit::class, 'pu', Join::WITH, 'pu.slug = i.defaultPurchaseUnitSlug')
            ->where('rr.recipeId IN (:ids)')
            ->setParameter('ids', $recipeIds)
            ->orderBy('rr.position', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $byRecipe = [];
        foreach ($rows as $row) {
            $byRecipe[$row['recipeId']][] = $row;
        }

        return $byRecipe;
    }

    public function listByAuthorAndType(string $authorId, string $typeSlug): RecipeTypeRecipesDTO
    {
        $type = $this->entityManager->createQueryBuilder()
            ->select('t.slug AS slug', 't.name AS name', 't.image AS image', 't.sequence AS sequence')
            ->from(RecipeType::class, 't')
            ->where('t.slug = :slug')
            ->setParameter('slug', $typeSlug)
            ->getQuery()
            ->getOneOrNullResult();

        if ($type === null) {
            throw RecipeException::typeNotFound($typeSlug);
        }

        $recipes = $this->entityManager->createQueryBuilder()
            ->select('r.id AS id', 'r.name AS name', 'r.secret AS secret')
            ->from(Recipe::class, 'r')
            ->where('r.authorId = :authorId')
            ->andWhere('r.typeSlug = :typeSlug')
            ->setParameter('authorId', $authorId)
            ->setParameter('typeSlug', $typeSlug)
            ->orderBy('r.slug', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return new RecipeTypeRecipesDTO(
            new RecipeTypeDTO($type['slug'], $type['name'], $type['image'], (int) $type['sequence']),
            array_map(static fn(array $recipe): RecipeSummaryDTO => new RecipeSummaryDTO($recipe['id'], $recipe['name'], (bool) $recipe['secret']), $recipes),
        );
    }

    public function listFavoritesOfAuthor(string $authorId): array
    {
        $recipes = $this->entityManager->createQueryBuilder()
            ->select('r.id AS id', 'r.name AS name', 'r.secret AS secret')
            ->from(Recipe::class, 'r')
            ->where('r.authorId = :authorId')
            ->andWhere('r.favorite = true')
            ->setParameter('authorId', $authorId)
            ->orderBy('r.slug', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $recipe): RecipeSummaryDTO => new RecipeSummaryDTO($recipe['id'], $recipe['name'], (bool) $recipe['secret']), $recipes);
    }

    /**
     * Une page de `$limit` recettes au plus. Le même ordre à chaque appel (deux recettes de même nom ne changent pas de page).
     *
     * Sans recherche : par catégorie (colonne `sequence` du type, l'ordre de `ListRecipeTypes`), puis par slug (le nom sans accent ni majuscule),
     * puis par identifiant, en SQL. Le nombre total n'est compté que si la page ne dit pas tout : une première page qui n'est pas pleine contient
     * déjà toutes les recettes, le cas ordinaire coûte une seule requête.
     *
     * Avec une recherche : comme `IngredientPostgreSQLLoader::search()` (ADR 0019), le score de Jaro-Winkler n'existe pas en SQL ; une seule requête
     * lit l'identifiant, le nom et la catégorie de TOUTES les recettes des autres, et la pertinence est calculée en PHP.
     *
     * Avec `$typeSlug`, la page, le total ET la recherche se restreignent à cette catégorie, dans la même requête de base ; une catégorie inconnue
     * ou sans recette à copier rend une page vide, jamais une erreur.
     */
    public function listOfOtherPeople(string $userId, int $limit, int $offset, ?string $search = null, bool $isAdministrator = false, ?string $typeSlug = null): OtherPeopleRecipesDTO
    {
        if ($search !== null) {
            return $this->searchOfOtherPeople($userId, $limit, $offset, $search, $isAdministrator, $typeSlug);
        }

        $recipes = $this->withAdministratorCopy($this->otherPeopleQuery($userId, $isAdministrator, $typeSlug), $isAdministrator)
            ->select('r.id AS id', 'r.name AS name', 't.name AS typeName', 't.slug AS typeSlug', 'r.marmitonId AS marmitonId', ...$this->copyIdSelect($isAdministrator))
            ->orderBy('t.sequence', 'ASC')
            ->addOrderBy('r.slug', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $total = $offset === 0 && count($recipes) < $limit
            ? count($recipes)
            : (int) $this->otherPeopleQuery($userId, $isAdministrator, $typeSlug)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        return new OtherPeopleRecipesDTO(array_map(self::toDto(...), $recipes), $total);
    }

    /**
     * UNE requête (GROUP BY) sur la requête commune des recettes des autres : le compte d'une catégorie est celui de sa page, par construction.
     * Un type sans recette à copier n'a pas de ligne.
     */
    public function listOtherPeopleTypes(string $userId, bool $isAdministrator = false): OtherPeopleRecipeTypesDTO
    {
        $rows = $this->otherPeopleQuery($userId, $isAdministrator)
            ->select('t.slug AS typeSlug', 't.name AS typeName', 'COUNT(r.id) AS recipeCount')
            ->groupBy('t.slug', 't.name', 't.sequence')
            ->orderBy('t.sequence', 'ASC')
            ->addOrderBy('t.slug', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $types = array_map(static fn(array $row): OtherPeopleRecipeTypeDTO => new OtherPeopleRecipeTypeDTO($row['typeSlug'], $row['typeName'], (int) $row['recipeCount']), $rows);

        return new OtherPeopleRecipeTypesDTO($types, count($types));
    }

    /**
     * Les recettes qu'un éditeur propose comme recette utilisée (ADR 0040 décision 22) : une lecture de l'auteur et de l'usage de la recette écrite
     * (seulement avec `$forRecipeId`), puis UNE requête de recettes (le nombre n'en dépend pas).
     *
     * Les recettes secrètes de l'auteur ne sont proposées que si `$secret` ET que l'utilisateur EST l'auteur : la condition est dans la requête, jamais
     * un passe-droit (l'administrateur qui modifie la recette ordinaire d'un autre ne voit que des recettes ordinaires). Sans `$search`, 200 au plus
     * en SQL, le total n'est compté que si la liste est pleine ; avec, comme `searchOfOtherPeople()`, le score de Jaro-Winkler n'existe pas en SQL : la
     * requête lit toutes les recettes proposables de l'auteur (au plus `Recipe::MAX_PER_AUTHOR_WITH_COPIES`) et la pertinence est calculée en PHP.
     */
    public function listUsable(string $userId, ?string $forRecipeId, bool $secret, ?string $search): UsableRecipesDTO
    {
        $authorId = $userId;
        $canHaveComponents = true;
        if ($forRecipeId !== null) {
            // L'auteur de la recette écrite et « une autre recette l'utilise-t-elle ? », en une lecture
            $recipe = $this->entityManager->getConnection()->fetchAssociative(
                'SELECT r.author AS author, EXISTS (SELECT 1 FROM recipe_component rc WHERE rc.component = r.id) AS used FROM recipe r WHERE r.id = :id',
                ['id' => $forRecipeId],
            );
            if ($recipe === false) {
                throw RecipeException::notFound($forRecipeId);
            }
            $authorId = $recipe['author'];
            // Une recette sans propriétaire n'est jamais composée (ADR 0040 décision 7) ; une recette utilisée ne peut pas avoir de composants (profondeur 1)
            $canHaveComponents = !(bool) $recipe['used'] && $authorId !== DomainRecipe::MARMITON_OWNER_ID;
            if ($authorId === DomainRecipe::MARMITON_OWNER_ID) {
                return new UsableRecipesDTO([], 0, false);
            }
        }

        $query = fn(): QueryBuilder => $this->usableQuery($authorId, $userId, $forRecipeId, $secret);
        if ($search !== null) {
            return $this->searchUsable($query(), $search, $canHaveComponents);
        }

        $rows = $query()
            ->select('r.id AS id', 'r.name AS name', 'r.serving AS serving', 't.slug AS typeSlug', 't.name AS typeName', 'r.secret AS secret')
            ->orderBy('r.slug', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setMaxResults(ListUsableRecipesQuery::MAX_ITEMS)
            ->getQuery()
            ->getArrayResult();

        // Le total n'est compté que si la liste est pleine : sinon elle contient déjà toutes les recettes proposables
        $total = count($rows) < ListUsableRecipesQuery::MAX_ITEMS
            ? count($rows)
            : (int) $query()->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        return new UsableRecipesDTO(array_map(self::toUsable(...), $rows), $total, $canHaveComponents);
    }

    /**
     * Les recettes de l'auteur qu'une autre peut utiliser (sans select : l'appelant choisit la liste ou le compte) : sans composants, ni la recette
     * écrite, ordinaires ; les secrètes de l'auteur seulement pour lui-même et si `$secret`.
     */
    private function usableQuery(string $authorId, string $userId, ?string $forRecipeId, bool $secret): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->from(Recipe::class, 'r')
            ->innerJoin(RecipeType::class, 't', Join::WITH, 't.slug = r.typeSlug')
            ->where('r.authorId = :author')
            ->andWhere('r.authorId != :withoutOwner')
            ->andWhere('r.componentCount = 0')
            ->setParameter('author', $authorId)
            ->setParameter('withoutOwner', DomainRecipe::MARMITON_OWNER_ID);

        if ($secret && $authorId === $userId) {
            // Les siennes : ordinaires et secrètes
        } else {
            $query->andWhere('r.secret = false');
        }
        if ($forRecipeId !== null) {
            $query->andWhere('r.id != :forRecipeId')->setParameter('forRecipeId', $forRecipeId);
        }

        return $query;
    }

    /**
     * @param array{id: string, name: string, serving: int|string, typeSlug: string, typeName: string, secret: bool} $recipe
     */
    private static function toUsable(array $recipe): UsableRecipeDTO
    {
        return new UsableRecipeDTO($recipe['id'], $recipe['name'], (int) $recipe['serving'], $recipe['typeSlug'], $recipe['typeName'], (bool) $recipe['secret']);
    }

    /**
     * Du plus pertinent au moins pertinent ; à pertinence égale par nom (slug) puis par identifiant. Un texte sans lettre ni chiffre ne trouve rien.
     */
    private function searchUsable(QueryBuilder $query, string $search, bool $canHaveComponents): UsableRecipesDTO
    {
        $needle = StringTool::slugify($search);
        if ($needle === '') {
            return new UsableRecipesDTO([], 0, $canHaveComponents);
        }

        $rows = $query
            ->select('r.id AS id', 'r.name AS name', 'r.slug AS slug', 'r.serving AS serving', 't.slug AS typeSlug', 't.name AS typeName', 'r.secret AS secret')
            ->getQuery()
            ->getArrayResult();

        $scored = [];
        foreach ($rows as $row) {
            $score = SearchRelevance::score($needle, (string) $row['slug']);
            if ($score >= SearchRelevance::MINIMUM) {
                $scored[] = ['score' => $score, 'row' => $row];
            }
        }
        usort($scored, static fn(array $a, array $b): int => [$b['score'], $a['row']['slug'], $a['row']['id']] <=> [$a['score'], $b['row']['slug'], $b['row']['id']]);

        return new UsableRecipesDTO(
            array_map(static fn(array $match): UsableRecipeDTO => self::toUsable($match['row']), array_slice($scored, 0, ListUsableRecipesQuery::MAX_ITEMS)),
            count($scored),
            $canHaveComponents,
        );
    }

    /**
     * Les recettes de l'auteur qui utilisent celle-ci : UNE requête (l'arête jointe à la recette composée, sur l'index des recettes utilisées). Une arête
     * dont la recette composée n'existe plus n'y est pas (jointure interne) ; la recette secrète d'un autre non plus (jamais de passe-droit).
     */
    public function listUsages(string $recipeId, string $userId): RecipeUsagesDTO
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('p.id AS id', 'p.name AS name', 'p.secret AS secret')
            ->from(RecipeComponent::class, 'rc')
            ->innerJoin(Recipe::class, 'p', Join::WITH, 'p.id = rc.recipeId')
            ->where('rc.componentId = :id')
            // De l'auteur de la recette : une arête d'un autre auteur (que les écritures refusent) ne serait pas un usage de cette recette
            ->andWhere(sprintf('p.authorId = (SELECT c.authorId FROM %s c WHERE c.id = :id)', Recipe::class))
            ->andWhere('(p.secret = false OR p.authorId = :userId)')
            ->setParameter('id', $recipeId)
            ->setParameter('userId', $userId)
            ->orderBy('p.slug', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return new RecipeUsagesDTO(
            array_map(static fn(array $recipe): RecipeSummaryDTO => new RecipeSummaryDTO($recipe['id'], $recipe['name'], (bool) $recipe['secret']), $rows),
            count($rows),
        );
    }

    /**
     * Toutes catégories confondues (ou celle de `$typeSlug`), du plus pertinent au moins pertinent ; à pertinence égale par slug puis par identifiant. Un texte sans lettre
     * ni chiffre ne trouve rien. Limite connue : toute recherche lit les noms de toutes les recettes des autres (ADR 0019, amendement).
     */
    private function searchOfOtherPeople(string $userId, int $limit, int $offset, string $search, bool $isAdministrator, ?string $typeSlug): OtherPeopleRecipesDTO
    {
        $needle = StringTool::slugify($search);
        if ($needle === '') {
            return new OtherPeopleRecipesDTO([], 0);
        }

        $rows = $this->withAdministratorCopy($this->otherPeopleQuery($userId, $isAdministrator, $typeSlug), $isAdministrator)
            ->select('r.id AS id', 'r.name AS name', 'r.slug AS slug', 't.name AS typeName', 't.slug AS typeSlug', 'r.marmitonId AS marmitonId', ...$this->copyIdSelect($isAdministrator))
            ->getQuery()
            ->getArrayResult();

        $scored = [];
        foreach ($rows as $row) {
            $score = SearchRelevance::score($needle, (string) $row['slug']);
            if ($score >= SearchRelevance::MINIMUM) {
                $scored[] = ['score' => $score, 'row' => $row];
            }
        }

        // Ex æquo : par nom puis par identifiant, pour que deux appels (et deux pages) donnent le même ordre
        usort($scored, static fn(array $a, array $b): int => [$b['score'], $a['row']['slug'], $a['row']['id']] <=> [$a['score'], $b['row']['slug'], $b['row']['id']]);

        return new OtherPeopleRecipesDTO(
            array_map(static fn(array $match): OtherPeopleRecipeDTO => self::toDto($match['row']), array_slice($scored, $offset, $limit)),
            count($scored),
        );
    }

    /**
     * @param array{id: string, name: string, typeName: string, typeSlug: string, marmitonId: string|null, copyId?: string|null} $recipe
     */
    private static function toDto(array $recipe): OtherPeopleRecipeDTO
    {
        // Une recette Marmiton neutre porte le tag de la page (lu dans la même instruction que la page) ; aucune autre recette. Une copie déjà
        // faite n'est vue que d'un administrateur, pour une recette sans propriétaire : `alreadyCopied` n'est que « il y a une copie »
        $copyId = $recipe['copyId'] ?? null;

        return new OtherPeopleRecipeDTO($recipe['id'], $recipe['name'], $recipe['typeName'], $recipe['typeSlug'], $recipe['marmitonId'] !== null, $copyId !== null, $copyId);
    }

    /**
     * Ce que la page lit en plus pour un administrateur, dans la même instruction : l'identifiant de sa copie de la recette (`copyId`). Rien pour
     * tout autre utilisateur (sa liste ne contient que ce qu'il n'a pas copié).
     *
     * @return list<string>
     */
    private function copyIdSelect(bool $isAdministrator): array
    {
        return $isAdministrator ? ['k.id AS copyId'] : [];
    }

    /**
     * Joint la copie de l'administrateur : AU PLUS UNE ligne par recette (la plus ancienne), la jointure ne multiplie donc ni la page ni le coût.
     * Ajoutée aux seules requêtes de la page, jamais au total (qu'elle ne change pas).
     */
    private function withAdministratorCopy(QueryBuilder $query, bool $isAdministrator): QueryBuilder
    {
        return $isAdministrator ? $query->leftJoin(Recipe::class, 'k', Join::WITH, $this->firstCopyOfTheReader('r')) : $query;
    }

    /**
     * Condition de jointure : `c` est une copie de `$original` faite par le lecteur (`:userId`), la PREMIÈRE s'il en a plusieurs (un réimport
     * après la suppression de la neutre en laisse deux). Une recette n'a pas de date de création : l'ordre déterministe est celui de
     * l'identifiant. Le `NOT EXISTS` ne se joue que pour les copies déjà trouvées (une ou deux), jamais par recette de la page.
     */
    private function firstCopyOfTheReader(string $original): string
    {
        return sprintf(
            'k.authorId = :userId AND k.originalId = %1$s.id AND NOT EXISTS (SELECT f.id FROM %2$s f WHERE f.authorId = :userId AND f.originalId = %1$s.id AND f.id < k.id)',
            $original,
            Recipe::class,
        );
    }

    /**
     * Les recettes originales des autres que l'utilisateur n'a pas encore copiées (sans select : l'appelant choisit la page ou le compte).
     *
     * Jamais une recette secrète (ADR 0033) : le filtre est ICI, dans la seule requête dont partent la page, le total et la recherche, pour
     * qu'aucun chemin ne puisse l'oublier (`secret = false`, jamais « secret ou auteur » : ce sont les recettes des AUTRES).
     *
     * Pour un administrateur, une recette SANS PROPRIÉTAIRE (auteur `Recipe::MARMITON_OWNER_ID`) y reste même copiée : il la modifie et la supprime,
     * il doit pouvoir la trouver. Une seule exception, dans la même instruction ; toute autre recette suit la règle commune.
     *
     * `$typeSlug` restreint à une catégorie (l'écran des catégories) : un filtre de plus, la règle de visibilité ne change pas.
     */
    private function otherPeopleQuery(string $userId, bool $isAdministrator = false, ?string $typeSlug = null): QueryBuilder
    {
        $notCopied = sprintf('NOT EXISTS (SELECT c.id FROM %s c WHERE c.authorId = :userId AND c.originalId = r.id)', Recipe::class);
        $query = $this->entityManager->createQueryBuilder()
            ->from(Recipe::class, 'r')
            ->innerJoin(RecipeType::class, 't', Join::WITH, 't.slug = r.typeSlug')
            ->where('r.authorId != :userId')
            ->andWhere('r.secret = false')
            ->andWhere('r.originalId IS NULL')
            ->setParameter('userId', $userId);

        if ($typeSlug !== null) {
            $query->andWhere('r.typeSlug = :typeSlug')->setParameter('typeSlug', $typeSlug);
        }

        if ($isAdministrator) {
            return $query->andWhere("(r.authorId = :withoutOwner OR $notCopied)")->setParameter('withoutOwner', DomainRecipe::MARMITON_OWNER_ID);
        }

        return $query->andWhere($notCopied);
    }
}
