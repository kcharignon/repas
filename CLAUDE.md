# CLAUDE.md

Guide d'architecture pour ce projet (Symfony 8.1 / PHP 8.4). L'objectif: rester cohérent avec le style DDD / Hexagonal / CQRS / Repository / SOLID déjà en place dans `src/`. Ne pas réinventer, suivre les patterns existants ci-dessous.

## Source de vérité : ADR, roadmap, skill

- **Décisions d'architecture** : `docs/adr/README.md` (index). À lire avant de développer. En cas de désaccord avec ce fichier, l'ADR gagne.
- **Migration en cours** : les lectures passent petit à petit de « controller → repository » à « controller → QueryBus → QueryHandler → Loader → DTO », avec en parallèle de nouveaux endpoints API JSON stateless (JWT). Suivi dans `docs/migration-roadmap.md` (à mettre à jour à chaque slice).
- **Skill développeur** : `/repas-dev` (`.claude/skills/repas-dev/`) — recettes pas-à-pas (nouvelle lecture, migration d'un ViewController, endpoint API, écriture), nommage, Definition of Done.
- Changer une convention = mettre à jour l'ADR, ce fichier, le skill et la roadmap dans le même commit.

## Bounded contexts

Le code est organisé en contextes métier sous `src/` :

- `Repas` — recettes, ingrédients, listes de courses, unités, conversions.
- `User` — inscription, authentification, préférences.
- `Shared` — briques transverses (bus, modèle de base, outils).

Chaque contexte suit la même structure en 3 couches (architecture hexagonale) :

```
src/{Context}/
  Domain/          # coeur métier, zéro dépendance framework/infra
    Model/         # entités/value objects métier (PHP pur)
    Interface/     # ports (interfaces de repository, services)
    Service/       # services de domaine (logique multi-agrégats)
    Event/         # événements de domaine
    Exception/     # exceptions métier (héritent de Shared\Domain\Exception\DomainException)
  Application/     # orchestration des cas d'usage
    Command/{UseCase}/{UseCase}Command.php + {UseCase}Handler.php
    Query/{UseCase}/{UseCase}Query.php + {UseCase}QueryHandler.php + {X}DTO.php
    Loader/        # ports de lecture : interfaces {X}Loader (retournent des DTO)
  Infrastructure/   # adapters concrets (framework, DB, HTTP)
    Entity/         # entités Doctrine (mapping ORM, séparées du Domain\Model)
    Repository/     # implémentations *PostgreSQLRepository des ports Domain\Interface (écriture, agrégats)
    Loader/         # implémentations {X}PostgreSQLLoader des ports Application\Loader (lecture, DTO)
    Http/Controller # {Action}ViewController Twig (un controller = une route = une action)
    Http/Api/{UseCase}/ # {UseCase}ApiController JSON `/api/v1` (+ RequestQuery / Payload)
    Http/Form       # Symfony FormType liés aux Command
    Http/View       # templates Twig
    EventListener/  # écoute les Domain\Event
    DataFixture/    # fixtures Doctrine
```

**Règle de dépendance** : `Domain` ne dépend de rien (ni Application, ni Infrastructure, ni Symfony/Doctrine). `Application` dépend de `Domain` uniquement (via les interfaces). `Infrastructure` dépend de `Domain` et `Application` pour les implémenter/adapter, jamais l'inverse.

## CQRS

- **Écritures (Command)** : passent par un bus. `CommandBusInterface::dispatch()` (`src/Shared/Application/Interface/CommandBusInterface.php`) envoie le `{UseCase}Command` (DTO `readonly`, propriétés publiques, pas de logique) au `{UseCase}Handler` marqué `#[AsMessageHandler]` (Symfony Messenger). Le controller ne fait qu'assembler la Command et appeler `dispatch()` — jamais de logique métier dans un controller.
- **Lectures (Query)** : passent par `QueryBusInterface::ask()` (`src/Shared/Application/Interface/QueryBusInterface.php`). Le `{UseCase}Query` (`final readonly`, `implements QueryInterface`) est traité par un `{UseCase}QueryHandler` qui délègue à un port `Application/Loader/{X}Loader` et renvoie un `{X}DTO` (`final readonly`). Le controller (Twig ou API) ne manipule que ce DTO — jamais un modèle de domaine, jamais un repository (ADR 0003, 0004, 0005).
- **Règles fermes** : un `QueryHandler` n'appelle jamais un `Repository` ; un `CommandHandler` n'appelle jamais un `Loader` ; un `QueryHandler` ne modifie pas l'état (une lecture qui crée si absent = une Command puis une Query).
- **État de la migration** : plus aucun controller, API, formulaire ni voter ne lit via un `Domain\Interface\{X}Repository` (phase 7) ; Deptrac (`deptrac.no-repository-in-http.yaml`) l'interdit dans `Infrastructure\Http`. Toute nouvelle lecture suit la recette du skill `/repas-dev` et coche la slice dans `docs/migration-roadmap.md`. Un prédicat booléen se nomme `Is{X}` / `Has{X}` (ex. `IsEmailAlreadyUsed`) et renvoie un `bool`.
- **Droits d'accès** : `OwnerVoter` (contexte `User`) interroge le port `Shared\Application\Interface\OwnershipLoader` (ids de propriétaire uniquement), implémenté dans `Repas\Infrastructure\Loader`.
- **Buses** : `command.bus` et `query.bus` distincts (ADR 0006). `command.bus` est transactionnel (`doctrine_transaction`) : une commande est atomique, un handler qui échoue en cours de route n'écrit rien. Les controllers injectent toujours `CommandBusInterface` / `QueryBusInterface`, jamais les classes concrètes.

## API JSON (`/api/v1`)

- Controllers dans `Infrastructure/Http/Api/{UseCase}/`, suffixe `ApiController`, qui n'appellent que le `QueryBusInterface` (GET) ou le `CommandBusInterface` (écritures). Sortie de lecture = le DTO de la query ; liste = `{member, totalItems}` ; écriture = `204` ou `201` + `Location` ; erreurs = `{error:{message}}` (ADR 0007).
- Stateless, authentifiés par **JWT** (ADR 0008). Seule exception : l'inscription `POST /api/v1/users` est publique (`access_control`, ADR 0007 §14). Les routes Twig/AJAX existantes restent en session et ne changent pas : les deux coexistent.
- Documentation OpenAPI (`#[OA\…]`) obligatoire sur chaque controller API.
- Le contrat complet est versionné dans `docs/api/openapi.json` : après tout changement d'API, `make openapi` puis relire le diff (`make openapi-check` échoue sinon, ADR 0007 §15).
- Endpoints d'écriture : un use case = une commande = un controller ; l'utilisateur vient du JWT (`#[CurrentUser] AuthenticatedUser`), jamais du corps ; transitions d'état en `POST …/{action}` ; codes de domaine 404 / 409 / 422, `403` réservé à l'autorisation (ADR 0007 §11 à 13).

## Domain Model

- Classes `final` (sauf besoin d'héritage explicite), propriétés `private`, jamais d'ORM dedans.
- Implémentent `Repas\Shared\Domain\Model\ModelInterface` + `use ModelTrait` : `getId()`, `toArray()` (sérialisation générique via réflexion + snake_case, **jamais une sortie HTTP** : elle exposerait les champs privés), `isEqual()`.
- Construction :
  - `create(...)` — factory pour une nouvelle instance (règles métier de création, ex. génération de slug).
  - `load(array $datas)` — reconstruction depuis la persistance (utilisé par les repositories).
  - Méthodes `update(...)` / `set*()` fluent (`return $this;`) pour les mutations.
- Comparaison par identité via `isEqual()`, jamais `===` sur les objets métier.
- **Un agrégat référence les autres agrégats par identifiant** (slug, id), jamais par l'objet : `ShoppingListRow` porte `ingredientSlug` / `unitSlug`, `Meal` porte `recipeId` (ADR 0013). Un use case qui a besoin de la `Recipe` d'un repas la charge par son port ; une question d'existence ou d'état d'un ensemble est une méthode de port booléenne, pas une hydratation d'agrégats.
- **Un modèle est une copie** : chaque `find` en construit un neuf, et `save()` recopie tout le modèle sur l'entité (dernier enregistrement gagnant). Un use case charge donc un agrégat une seule fois et passe l'instance ; un listener recharge depuis la base ; on n'enregistre pas un modèle chargé avant un événement qui peut modifier le même agrégat (statistiques d'un `User`) ; `isEqual()`, jamais `===` (ADR 0013 règle 7).
- Collections typées via `Repas\Shared\Domain\Tool\Tab` (wrapper de collection typé), pas de tableaux PHP nus pour des listes de modèles.

## Repository pattern

- Le port est défini dans `Domain/Interface/{X}Repository.php` — c'est ce type que les `CommandHandler` injectent, jamais l'implémentation concrète. Un controller n'injecte plus de repository (écriture : `CommandBusInterface` ; lecture : `QueryBusInterface`).
- L'implémentation Doctrine vit dans `Infrastructure/Repository/{X}PostgreSQLRepository.php`, hérite de `PostgreSQLRepository` (gère `EntityManager`/`ObjectRepository`), convertit `Entity` (Doctrine) <-> `Model` (Domain) via `Entity::fromModel()` / `{X}Model::load([...])`.
- Pas de cache de modèles : l'identity map de Doctrine sert les `find()` par clé primaire sans requête. Pour charger N entités d'un coup (références d'une recette, unités compatibles), `PostgreSQLRepository::preload()` les lit en une requête ; les `find()` suivants ne coûtent rien. `ModelCache` a été retiré en phase 8 de la roadmap.
- Pour les tests : implémentation `{X}InMemoryRepository` dans `tests/Helper/InMemoryRepository/`, même interface, aucune dépendance Doctrine — permet de tester Application/Domain sans DB.
- Un Repository sert à **charger pour modifier** un agrégat ; pour **afficher**, utiliser un Loader (ADR 0004).
- Ne jamais faire de requête Doctrine (QueryBuilder, DQL) en dehors de `Infrastructure/Repository` et `Infrastructure/Loader`.

## SOLID — application concrète dans ce repo

- **SRP** : un handler = un cas d'usage = un fichier. Un controller = une route.
- **OCP/LSP** : nouveaux cas d'usage = nouvelle classe Command/Handler, pas de branchement conditionnel dans un handler existant. Toute implémentation de repository doit respecter le contrat de l'interface (mêmes exceptions déclarées, ex. `@throws IngredientException`).
- **ISP** : une interface de repository par agrégat (`IngredientRepository`, `RecipeRepository`, ...), pas de "GodRepository".
- **DIP** : Application/Domain dépendent des interfaces (`Domain/Interface/*`), jamais des classes `*PostgreSQLRepository` directement. L'injection se fait via le constructeur, propriétés `private readonly`.

## Exceptions

- Une classe `{Aggregate}Exception extends DomainException` par agrégat, avec des factory methods statiques nommées par cas (`notFound()`, `cannotBeRemove...()`, etc.) plutôt que `new XException("...")` dispersé dans le code.

## Conventions de nommage

- Use case d'écriture : dossier `Application/Command/{VerbNoun}/` contenant `{VerbNoun}Command.php` + `{VerbNoun}Handler.php`.
- Use case de lecture : dossier `Application/Query/{Get|List}{Noun}/` contenant `{Get|List}{Noun}Query.php`, `…QueryHandler.php` (`#[AsMessageHandler(bus: 'query.bus')]`) et les `{X}DTO.php`. `Get` = un élément, `List` = une collection, `Is`/`Has` = un prédicat booléen.
- Controller HTTP Twig : suffixe `ViewController` ; API : suffixe `ApiController` ; attribut `#[Route]` sur `__invoke()`.
- Utilisateur connecté dans un controller du contexte `Repas` : `#[CurrentUser] AuthenticatedUser $user` (`Shared\Domain\Interface`, accesseurs `getId()` et `getDefaultServing()`), aussi dans les formulaires, jamais `User\Domain\Model\User` (ADR 0001 §8).
- Repository infra : suffixe technologie, ex. `PostgreSQLRepository`. Loader infra : `{X}PostgreSQLLoader implements {X}Loader`.
- Événements de domaine : suffixe `Event`, dispatchés via `EventDispatcherInterface` depuis un Handler, consommés par un `EventListener` en Infrastructure.

## Dépendances (ADR 0014)

- Dependabot propose chaque lundi les correctifs et mineures (composer, GitHub Actions, Docker), regroupés `symfony` / `doctrine` / `quality` ; les montées **majeures** se font à la main, une PR par chantier (`make outdated` les liste). Une PR de dépendances ne se merge que verte (`make test`, `make quality`), sans notice ni dépréciation : on corrige, on ne masque pas.
- `symfony/*` reste contraint à une seule mineure (`8.1.*`, `extra.symfony.require` assorti ; une mineure 8.x n'est maintenue que 8 mois : fin de maintenance de la 8.1 en 01/2027, la prochaine LTS est la 8.4 en 11/2027). `make audit` (`composer audit --locked`, aussi en CI) doit être vert dès qu'on touche `composer.json` ou `composer.lock`.
- Image Docker : FrankenPHP/PHP épinglés par tag complet, paquets `apt` jamais épinglés.

## Tests

- `tests/Helper/Builder/` : builders pour construire des modèles de test rapidement (pattern Test Data Builder).
- `tests/Helper/InMemoryRepository/` et `tests/Helper/InMemoryLoader/` : doubles en mémoire des repositories (`{X}InMemoryRepository`) et des loaders (`{X}InMemoryLoader`), pour tester Application sans DB.
- **Un handler (Command ou Query) se teste en unitaire, jamais avec la base ni le kernel** (ADR 0010, vérifié par Deptrac). La persistance se teste uniquement dans `tests/*/OutputAdapter/` (repositories et loaders), jamais par transitivité à travers un handler.
- **Tests de base** : `DatabaseTestCase` crée le schéma (vide) une seule fois, puis chaque test tourne dans une transaction annulée (DAMA) ; suite complète ~3 s. Un test de base **crée ses propres données** (builders + repositories ; `tests/Helper/DataPersister` enregistre un modèle et les agrégats qu'il référence) et n'asserte que sur elles : aucune fixture globale. Les `DataFixture` ne servent qu'au développement.
- `CommandHandler` : `{X}InMemoryRepository` + `SpyEventDispatcher`, pas de mock manuel des interfaces.
- `QueryHandler` : un `{X}InMemoryLoader` (double idiot : renvoie des DTO programmés, enregistre ses appels). Le test asserte que le handler transmet les bons paramètres au Loader et renvoie son résultat tel quel. Filtres, tri, jointures et calculs du DTO se prouvent dans le test du Loader réel (`tests/Repas/OutputAdapter/`, `DatabaseTestCase`). Controllers API : doubles de bus (`tests/Repas/InputAdapter/`), en vérifiant la query construite, le statut et les **clés JSON** du DTO (ADR 0010).
- Buses : `QueryBus` / `CommandBus` se testent en unitaire avec `MockMessageBus` ; `BusWiringTest` prouve le câblage réel avec les handlers factices de `tests/Helper/Bus/` ; `QueryConventionTest` impose `#[AsMessageHandler(bus: 'query.bus')]` et un handler par query (ADR 0006). Les controllers se testent avec `MockQueryBus` (`MockCommandBus` enregistre les commandes reçues sans les exécuter).
- **Budget SQL des écritures** : `tests/Repas/WriteBudget` mesure chaque use case d'écriture de bout en bout (requêtes SQL) et fige le résultat dans `budgets.php`, en cliquet (ADR 0010 règle 12). Un use case plus gourmand fait échouer `make test` ; un use case allégé impose `make sql-budget-update`.
- Structure `// Given` / `// When` / `// Then`.

## Quand tu ajoutes une fonctionnalité

Utiliser le skill `/repas-dev` (recettes détaillées). En bref :

**Écriture**
1. Modéliser dans `Domain/Model` (+ `Domain/Exception` si nouveaux cas d'erreur).
2. Définir/étendre le port dans `Domain/Interface`.
3. Écrire le cas d'usage dans `Application/Command/{UseCase}/` (Command DTO + Handler `#[AsMessageHandler]`).
4. Implémenter le port dans `Infrastructure/Repository` (+ `Infrastructure/Entity` si nouvelle table Doctrine).
5. Exposer via un controller qui n'appelle que le `CommandBusInterface`.
6. Ajouter l'InMemoryRepository de test + Builder si nouvel agrégat.

**Lecture**
1. DTO + interface `Application/Loader/{X}Loader` + `{UseCase}Query` + `{UseCase}QueryHandler`.
2. Implémenter `Infrastructure/Loader/{X}PostgreSQLLoader` (DQL/DBAL → DTO, pas d'hydratation de modèle de domaine).
3. Exposer via un controller Twig et/ou API qui n'appelle que le `QueryBusInterface`.
4. Tests : handler + InMemoryLoader (unitaire, jamais de base), Loader sur base réelle, controller API.

**Avant de committer** : `make test` puis `make quality` (agrège `deptrac`, `phpstan`, `api-doc-validate`, `api-doc-coverage`, `openapi-check`). `make audit` si `composer.*` change. Toute slice qui touche une page Twig passe aussi un test visuel dans Chromium (captures lues, voir `.claude/skills/repas-dev/references/visual-check.md`). Les clés JWT de dev s'initialisent avec `make jwt-keys`. La baseline PHPStan (`phpstan-baseline.neon`) ne doit que diminuer ; Deptrac n'a plus de baseline : aucune dépendance entre contextes n'est tolérée (ce qui doit circuler passe par `Shared`, ADR 0013 règle 8). Un commit par unité logique, message court en français, sans `Co-Authored-By`. En fin de slice, pousser la branche de travail (`git push -u origin <branche>`) et ouvrir la PR vers `main` avec `gh pr create` sans attendre de demande : titre court (`refactor(chore): cqrs phase N …`), description concise qui suit `.github/pull_request_template.md` (Quoi / Vérifié / À relire), sans pied de page d'attribution. Jamais de merge, de push sur `main` ni de force-push : le merge est fait par l'humain.

Ne pas mettre de logique métier dans les controllers, les entités Doctrine ou les templates Twig — tout va dans `Domain`/`Application` ; les calculs d'affichage d'une lecture sont précalculés dans le DTO par le Loader.
