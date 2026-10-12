# Squelettes d'une slice de lecture

Exemple fil rouge : `ListDepartments`. Remplacer les noms. Namespaces racine : `Repas\Repas\…` (contexte Repas), `Repas\User\…`, `Repas\Shared\…`, tests `Repas\Tests\…`.

## 1. DTO — `src/Repas/Application/Query/ListDepartments/DepartmentDTO.php`

```php
<?php

namespace Repas\Repas\Application\Query\ListDepartments;

use OpenApi\Attributes as OA;

final readonly class DepartmentDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant (slug)', example: 'fruits-et-legumes')]
        public string $slug,
        #[OA\Property(example: 'Fruits & légumes')]
        public string $name,
        #[OA\Property(description: 'Chemin de l\'image')]
        public string $image,
    ) {
    }
}
```

Règles : `final readonly`, props publiques, dates en `string` `DATE_ATOM`, enums en `->value`, flags d'affichage précalculés (`canEdit`, `isActive`, `displayName`…), listes `@var list<XDTO>` + `#[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: XDTO::class)))]` (`Nelmio\ApiDocBundle\Attribute\Model`).

## 2. Port — `src/Repas/Application/Loader/DepartmentLoader.php`

```php
<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;

interface DepartmentLoader
{
    /**
     * @return list<DepartmentDTO>
     */
    public function list(): array;
}
```

Paramètres : types simples, value objects, contextes de pagination. Jamais l'objet `Query`. Ressource absente → exception de domaine `…NotFound` (jamais `RuntimeException`).

## 3. Query + Handler — `src/Repas/Application/Query/ListDepartments/`

```php
<?php

namespace Repas\Repas\Application\Query\ListDepartments;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListDepartmentsQuery implements QueryInterface
{
}
```

```php
<?php

namespace Repas\Repas\Application\Query\ListDepartments;

use Repas\Repas\Application\Loader\DepartmentLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListDepartmentsQueryHandler
{
    public function __construct(
        private DepartmentLoader $departmentLoader,
    ) {
    }

    /**
     * @return list<DepartmentDTO>
     */
    public function __invoke(ListDepartmentsQuery $query): array
    {
        return $this->departmentLoader->list();
    }
}
```

Le handler délègue au Loader et ne fait rien d'autre. Pas de `Repository`.

## 4. Implémentation — `src/Repas/Infrastructure/Loader/DepartmentPostgreSQLLoader.php`

```php
<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Application\Loader\DepartmentLoader;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Infrastructure\Entity\Department;

final readonly class DepartmentPostgreSQLLoader implements DepartmentLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function list(): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('d.slug AS slug', 'd.name AS name', 'd.image AS image')
            ->from(Department::class, 'd')
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): DepartmentDTO => new DepartmentDTO($row['slug'], $row['name'], $row['image']),
            $rows,
        );
    }
}
```

Jointure (entités sans relations) :
`->leftJoin(Ingredient::class, 'i', Join::WITH, 'i.departmentSlug = d.slug')`. Une requête par lecture (ou quelques-unes bornées), jamais de boucle qui requête. Projection lourde : DBAL (`$this->entityManager->getConnection()`).

Les entités Doctrine (`Repas\Repas\Infrastructure\Entity\*`) exposent leurs champs via des propriétés privées : sélectionner des colonnes scalaires (`d.slug AS slug`) plutôt que d'hydrater l'entité.

## 5. Test du handler — `tests/Repas/Application/Query/ListDepartments/ListDepartmentsQueryHandlerTest.php`

```php
<?php

namespace Repas\Tests\Repas\Application\Query\ListDepartments;

use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\ListDepartments\ListDepartmentsQuery;
use Repas\Repas\Application\Query\ListDepartments\ListDepartmentsQueryHandler;
use Repas\Tests\Helper\Builder\DTO\DepartmentDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\DepartmentInMemoryLoader;

class ListDepartmentsQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $departments = [DepartmentDTOBuilder::aDepartment()->build()];
        $loader = new DepartmentInMemoryLoader(departments: $departments);
        $handler = new ListDepartmentsQueryHandler($loader);

        // When
        $result = $handler(new ListDepartmentsQuery());

        // Then
        $this->assertSame($departments, $result);
    }
}
```

**Règle (ADR 0010) : un test de handler est unitaire. Jamais de `DatabaseTestCase`, de kernel, de Doctrine ni de classe d'`Infrastructure`** (Deptrac le refuse). Le `QueryHandler` ne prouve que deux choses : il transmet les **bons paramètres** au Loader, et il renvoie **le résultat du Loader tel quel**. Tri, filtre, jointure, calculs du DTO = test du Loader réel (section 6), jamais à travers le handler.

Quand la query porte des paramètres, asserter les arguments reçus par le Loader :

```php
public function testForwardsQueryParametersToTheLoader(): void
{
    // Given
    $loader = new RecipeInMemoryLoader();
    $handler = new ListRecipesByTypeQueryHandler($loader);

    // When
    $handler(new ListRecipesByTypeQuery(recipeTypeSlug: 'plat', authorId: 'user-1'));

    // Then
    $this->assertSame([['plat', 'user-1']], $loader->listByTypeCalls);
}
```

Double — `tests/Helper/InMemoryLoader/DepartmentInMemoryLoader.php` (miroir de `tests/Helper/InMemoryRepository/`, un par interface de Loader) : implémente l'interface, **renvoie les DTO programmés au constructeur** (construits via un `{X}DTOBuilder` dans `tests/Helper/Builder/DTO/`) et **enregistre ses appels** (`listByTypeCalls`, …). C'est un double idiot : il ne rejoue ni filtre, ni tri, ni jointure, sinon le test du handler vérifierait le double et pas le code.

Test d'un `CommandHandler` : même esprit, avec les `{X}InMemoryRepository` + `SpyEventDispatcher`. Le mapping `Entity` ↔ `Model` est prouvé par le test `OutputAdapter` du repository, pas par le handler.

## 6. Test du Loader (base réelle) — `tests/Repas/OutputAdapter/DepartmentPostgreSQLLoaderTest.php`

Étendre `Repas\Tests\Helper\DatabaseTestCase` (schéma recréé + fixtures chargées). Modèle : `tests/Repas/OutputAdapter/RecipeLoaderTest.php`.

```php
class DepartmentPostgreSQLLoaderTest extends DatabaseTestCase
{
    public function testList(): void
    {
        // Given
        $loader = static::getContainer()->get(DepartmentLoader::class);

        // When
        $result = $loader->list();

        // Then
        $this->assertContainsOnlyInstancesOf(DepartmentDTO::class, $result);
        $this->assertNotEmpty($result);
    }
}
```

Compléter : cas vide (si pertinent), tri, filtres, `NotFound`. Si le Loader remplace un N+1, assert le nombre de requêtes.

## 7. Controller Twig — bascule (existant)

```php
public function __construct(private readonly QueryBusInterface $queryBus) {}

#[Route(path: '/department', name: 'view_departments', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
public function __invoke(): Response
{
    $departments = $this->queryBus->ask(new ListDepartmentsQuery());

    return $this->render('@Repas/Department/departments.html.twig', ['departments' => $departments]);
}
```

Le template lit `department.slug`, `department.name`, `department.image` (propriétés du DTO).

## 8. Controller API — `src/Repas/Infrastructure/Http/Api/ListDepartments/ListDepartmentsApiController.php`

```php
<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListDepartments;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Application\Query\ListDepartments\ListDepartmentsQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListDepartmentsApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/departments', name: 'api_list_departments', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: 'Liste des rayons')]
    #[OA\Tag(name: 'Departments')]
    #[OA\Response(
        response: 200,
        description: 'Liste des rayons',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: DepartmentDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer'),
        ]),
    )]
    public function __invoke(): JsonResponse
    {
        $departments = $this->queryBus->ask(new ListDepartmentsQuery());

        return new JsonResponse(['member' => $departments, 'totalItems' => count($departments)]);
    }
}
```

Entrée en query string : `#[MapQueryString(validationFailedStatusCode: 422)] ListXRequestQuery $requestQuery`. Entrée en corps : `#[MapRequestPayload] XPayload $payload` (objet `final readonly` + `#[Assert\…]`, statut d'échec 422 par défaut).

## 9. Test du controller API — `tests/Repas/InputAdapter/ListDepartmentsApiControllerTest.php`

Vérifier : (a) la query construite (`MockQueryBus::getLastAskedQuery()`), (b) le statut, (c) **les clés JSON** de chaque DTO, (d) sans token → 401. Remplacer le bus : `static::getContainer()->set(QueryBusInterface::class, $mockQueryBus)`. Les doubles `MockQueryBus` / `MockCommandBus` sont à créer dans `tests/Helper/` à la première slice qui en a besoin (copier le principe de `Compliance/tests/Helper/MockQueryBus.php`) : enregistrent les messages, rendent une valeur préprogrammée par classe de query.
