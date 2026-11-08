<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Repas\Repas\Application\Loader\RecipeLoader;
use Repas\Repas\Application\Query\GetRecipe\RecipeDTO;
use Repas\Repas\Application\Query\GetRecipe\RecipeRowDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipeDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipesDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeTypeRecipesDTO;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\Recipe as DomainRecipe;
use Repas\Repas\Infrastructure\Conversion\IngredientConversionGraphs;
use Repas\Repas\Infrastructure\Entity\Department;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\Recipe;
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
     * Une recette secrète n'est lue que par son auteur (ADR 0033) : la condition est dans la requête même, avant toute lecture des lignes. Pour
     * tout autre, administrateur compris, la requête ne rend rien et la réponse est celle d'une recette inconnue (`404`, jamais `403` : on ne
     * révèle pas qu'elle existe).
     */
    public function get(string $id, string $userId, bool $isAdministrator = false): RecipeDTO
    {
        $recipe = $this->entityManager->createQueryBuilder()
            ->select('r.id AS id', 'r.name AS name', 'r.serving AS serving', 't.slug AS typeSlug', 't.name AS typeName', 'r.version AS version', 'r.secret AS secret', 'r.favorite AS favorite', 'r.authorId AS authorId', 'r.sourceUrl AS sourceUrl', 'o.id AS originalId')
            ->from(Recipe::class, 'r')
            ->innerJoin(RecipeType::class, 't', Join::WITH, 't.slug = r.typeSlug')
            // L'original, dans la même instruction : seulement s'il existe encore et que le lecteur le voit (jamais l'identifiant d'une recette supprimée ou secrète)
            ->leftJoin(Recipe::class, 'o', Join::WITH, 'o.id = r.originalId AND (o.secret = false OR o.authorId = :userId)')
            ->where('r.id = :id')
            ->andWhere('(r.secret = false OR r.authorId = :userId)')
            ->setParameter('id', $id)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        if ($recipe === null) {
            throw RecipeException::notFound($id);
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'i.slug AS ingredientSlug', 'i.name AS ingredientName', 'i.image AS ingredientImage',
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
            ->where('rr.recipeId = :id')
            ->setParameter('id', $id)
            ->orderBy('rr.position', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // Le même graphe que la planification et que le rapport de santé des conversions (`repas:conversions:check`)
        $graphs = new IngredientConversionGraphs($this->entityManager->getConnection())->forIngredients(array_values(array_unique(array_column($rows, 'ingredientSlug'))));

        return new RecipeDTO(
            id: $recipe['id'],
            name: $recipe['name'],
            serving: (int) $recipe['serving'],
            typeSlug: $recipe['typeSlug'],
            typeName: $recipe['typeName'],
            secret: (bool) $recipe['secret'],
            // Le choix de l'auteur : la recette ordinaire d'un autre n'est jamais « favorite » pour qui la lit
            favorite: (bool) $recipe['favorite'] && $recipe['authorId'] === $userId,
            rows: array_map(
                static fn(array $row): RecipeRowDTO => new RecipeRowDTO(
                    $row['ingredientSlug'], $row['ingredientName'], $row['ingredientImage'], (float) $row['quantity'], $row['unitSlug'], $row['unitSymbol'],
                    $row['unitName'], $row['purchaseUnitSlug'], $row['purchaseUnitName'],
                    $graphs[$row['ingredientSlug']]->connects($row['unitSlug'], $row['purchaseUnitSlug']),
                    $row['departmentSlug'], $row['departmentName'], $row['partOfName'],
                ),
                $rows,
            ),
            version: (int) $recipe['version'],
            sourceUrl: $recipe['sourceUrl'],
            originalId: $recipe['originalId'],
            // La sienne ; pour un administrateur, toute recette qu'il lit (le droit `RECIPE_OWNER` : une recette sans propriétaire n'est modifiée que par lui)
            canEdit: $isAdministrator || $recipe['authorId'] === $userId,
        );
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
     */
    public function listOfOtherPeople(string $userId, int $limit, int $offset, ?string $search = null, bool $isAdministrator = false): OtherPeopleRecipesDTO
    {
        if ($search !== null) {
            return $this->searchOfOtherPeople($userId, $limit, $offset, $search, $isAdministrator);
        }

        $recipes = $this->otherPeopleQuery($userId, $isAdministrator)
            ->select('r.id AS id', 'r.name AS name', 't.name AS typeName', 't.slug AS typeSlug', 'r.marmitonId AS marmitonId', ...$this->alreadyCopiedSelect($isAdministrator))
            ->orderBy('t.sequence', 'ASC')
            ->addOrderBy('r.slug', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $total = $offset === 0 && count($recipes) < $limit
            ? count($recipes)
            : (int) $this->otherPeopleQuery($userId, $isAdministrator)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        return new OtherPeopleRecipesDTO(array_map(self::toDto(...), $recipes), $total);
    }

    /**
     * Toutes catégories confondues, du plus pertinent au moins pertinent ; à pertinence égale par slug puis par identifiant. Un texte sans lettre
     * ni chiffre ne trouve rien. Limite connue : toute recherche lit les noms de toutes les recettes des autres (ADR 0019, amendement).
     */
    private function searchOfOtherPeople(string $userId, int $limit, int $offset, string $search, bool $isAdministrator): OtherPeopleRecipesDTO
    {
        $needle = StringTool::slugify($search);
        if ($needle === '') {
            return new OtherPeopleRecipesDTO([], 0);
        }

        $rows = $this->otherPeopleQuery($userId, $isAdministrator)
            ->select('r.id AS id', 'r.name AS name', 'r.slug AS slug', 't.name AS typeName', 't.slug AS typeSlug', 'r.marmitonId AS marmitonId', ...$this->alreadyCopiedSelect($isAdministrator))
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
     * @param array{id: string, name: string, typeName: string, typeSlug: string, marmitonId: string|null, alreadyCopied?: bool|int|string|null} $recipe
     */
    private static function toDto(array $recipe): OtherPeopleRecipeDTO
    {
        // Une recette Marmiton neutre porte le tag de la page (lu dans la même instruction que la page) ; aucune autre recette. Une copie déjà
        // faite n'est vue que d'un administrateur, pour une recette sans propriétaire
        return new OtherPeopleRecipeDTO($recipe['id'], $recipe['name'], $recipe['typeName'], $recipe['typeSlug'], $recipe['marmitonId'] !== null, (bool) ($recipe['alreadyCopied'] ?? false));
    }

    /**
     * Ce que la page lit en plus pour un administrateur, dans la même instruction : a-t-il déjà une copie de la recette ? Rien pour tout autre
     * utilisateur (sa liste ne contient que ce qu'il n'a pas copié).
     *
     * @return list<string>
     */
    private function alreadyCopiedSelect(bool $isAdministrator): array
    {
        return $isAdministrator
            ? [sprintf('CASE WHEN EXISTS (SELECT m.id FROM %s m WHERE m.authorId = :userId AND m.originalId = r.id) THEN true ELSE false END AS alreadyCopied', Recipe::class)]
            : [];
    }

    /**
     * Les recettes originales des autres que l'utilisateur n'a pas encore copiées (sans select : l'appelant choisit la page ou le compte).
     *
     * Jamais une recette secrète (ADR 0033) : le filtre est ICI, dans la seule requête dont partent la page, le total et la recherche, pour
     * qu'aucun chemin ne puisse l'oublier (`secret = false`, jamais « secret ou auteur » : ce sont les recettes des AUTRES).
     *
     * Pour un administrateur, une recette SANS PROPRIÉTAIRE (auteur `Recipe::MARMITON_OWNER_ID`) y reste même copiée : il la modifie et la supprime,
     * il doit pouvoir la trouver. Une seule exception, dans la même instruction ; toute autre recette suit la règle commune.
     */
    private function otherPeopleQuery(string $userId, bool $isAdministrator = false): QueryBuilder
    {
        $notCopied = sprintf('NOT EXISTS (SELECT c.id FROM %s c WHERE c.authorId = :userId AND c.originalId = r.id)', Recipe::class);
        $query = $this->entityManager->createQueryBuilder()
            ->from(Recipe::class, 'r')
            ->innerJoin(RecipeType::class, 't', Join::WITH, 't.slug = r.typeSlug')
            ->where('r.authorId != :userId')
            ->andWhere('r.secret = false')
            ->andWhere('r.originalId IS NULL')
            ->setParameter('userId', $userId);

        if ($isAdministrator) {
            return $query->andWhere("(r.authorId = :withoutOwner OR $notCopied)")->setParameter('withoutOwner', DomainRecipe::MARMITON_OWNER_ID);
        }

        return $query->andWhere($notCopied);
    }
}
