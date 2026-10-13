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
            ->orderBy('d.slug', 'ASC')
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
        $departments = [new DepartmentDTOBuilder()->withSlug('boulangerie')->build()];
        $loader = new DepartmentInMemoryLoader($departments);
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

Étendre `Repas\Tests\Helper\DatabaseTestCase`. Chaque test tourne dans une **transaction annulée à la fin** (DAMA) : rien ne fuit d'un test à l'autre, pas besoin de nettoyer.

**Le test crée lui-même ses données** (builders + repository, puis `flush`/`clear` si besoin) et n'asserte que sur elles. Il ne s'appuie **jamais** sur les fixtures globales, encore chargées par `DatabaseTestCase` tant que la dette « fixtures globales » n'est pas soldée (roadmap) : pas de `assertCount(N, …)` sur un total, pas de `assertNotEmpty` comme seule preuve.

```php
class DepartmentPostgreSQLLoaderTest extends DatabaseTestCase
{
    public function testListIsOrderedBySlug(): void
    {
        // Given
        $repository = static::getContainer()->get(DepartmentRepository::class);
        $repository->save(new DepartmentBuilder()->withSlug('zzz-outils')->withName('Zzz Outils')->build());
        $repository->save(new DepartmentBuilder()->withSlug('aaa-outils')->withName('Aaa Outils')->build());
        $loader = static::getContainer()->get(DepartmentLoader::class);

        // When
        $result = $loader->list();

        // Then
        $slugs = array_map(static fn (DepartmentDTO $d): string => $d->slug, $result);
        $this->assertContainsOnlyInstancesOf(DepartmentDTO::class, $result);
        $this->assertContains('aaa-outils', $slugs);
        $this->assertContains('zzz-outils', $slugs);
        $this->assertLessThan(array_search('zzz-outils', $slugs), array_search('aaa-outils', $slugs));
    }
}
```

Piège : le tri se prouve par la position relative de deux enregistrements créés par le test (`aaa-…` avant `zzz-…`), jamais en comparant toute la liste à `sort()` PHP (la collation PostgreSQL ignore parfois la casse et les tirets). Compléter : cas vide (possible seulement une fois les fixtures globales retirées), filtres, `NotFound`. Si le Loader remplace un N+1, assert le nombre de requêtes.

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

Vérifier : (a) la query construite (`MockQueryBus::getLastAskedQuery()`), (b) le statut, (c) **les clés JSON** de chaque DTO, (d) sans token → 401. Modèle complet : `tests/Repas/InputAdapter/ListRecipeTypesApiControllerTest.php` (API) et `GetAllRecipeTypeViewControllerTest.php` / `AdminGetAllRecipeTypeViewControllerTest.php` (Twig). Le test étend `Repas\Tests\Helper\QueryBusWebTestCase`, qui remplace le QueryBus par un `MockQueryBus` (`$this->queryBus`, avant la première requête du client : le kernel redémarre entre deux requêtes), fournit le client (`$this->client`), un vrai utilisateur annulé en fin de test (`$this->savedUser(admin: true)`), la connexion Twig (`$this->client->loginUser($user)`), le jeton JWT sans passer par `/api/login_check` (`server: $this->bearerFor($user)`) et `$this->jsonBody()`.

Double : `tests/Helper/MockQueryBus.php` (existe) : `willReturn(XQuery::class, $resultat)`, `getLastAskedQuery()`, `countAskedQueries()` ; lève une `LogicException` si une query sans résultat programmé est posée. Un `MockCommandBus` du même modèle est à créer à la première slice d'écriture API.

**Controller de formulaire qui ré-affiche une liste** (ex. `AdminCreateUnitViewController`, `AdminUpdateUnitViewController`) : il demande la même query que la page de liste. Test : `tests/Repas/InputAdapter/AdminCreateUnitViewControllerTest.php`. Une soumission valide se simule par `$this->client->request('POST', $url, ['nom_du_formulaire' => ['champ' => …, '_token' => 'csrf-token']], server: ['HTTP_ORIGIN' => 'http://localhost'])` (CSRF stateless de Symfony 7.2 : la valeur est le littéral `csrf-token` et l'origine doit correspondre à l'hôte). Le `CommandBus` reste le vrai (donnée écrite en base de test, annulée en fin de test) ; le `QueryBus` reste le `MockQueryBus`. Vérifier l'écriture via le repository et le rendu via la query doublée. Le nom du formulaire est le préfixe de bloc de son type (`CreateUnitType` donne `create_unit`).
