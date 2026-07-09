# CLAUDE.md

Guide d'architecture pour ce projet (Symfony 7.2 / PHP 8.4). L'objectif: rester cohérent avec le style DDD / Hexagonal / CQRS / Repository / SOLID déjà en place dans `src/`. Ne pas réinventer, suivre les patterns existants ci-dessous.

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
    Query/         # (prévu pour les lectures via bus, actuellement peu utilisé, cf. plus bas)
  Infrastructure/   # adapters concrets (framework, DB, HTTP)
    Entity/         # entités Doctrine (mapping ORM, séparées du Domain\Model)
    Repository/     # implémentations *PostgreSQLRepository des ports Domain\Interface
    Http/Controller # {Action}ViewController (un controller = une route = une action)
    Http/Form       # Symfony FormType liés aux Command
    Http/View       # templates Twig
    EventListener/  # écoute les Domain\Event
    DataFixture/    # fixtures Doctrine
```

**Règle de dépendance** : `Domain` ne dépend de rien (ni Application, ni Infrastructure, ni Symfony/Doctrine). `Application` dépend de `Domain` uniquement (via les interfaces). `Infrastructure` dépend de `Domain` et `Application` pour les implémenter/adapter, jamais l'inverse.

## CQRS

- **Écritures (Command)** : passent par un bus. `CommandBusInterface::dispatch()` (`src/Shared/Application/Interface/CommandBusInterface.php`) envoie le `{UseCase}Command` (DTO `readonly`, propriétés publiques, pas de logique) au `{UseCase}Handler` marqué `#[AsMessageHandler]` (Symfony Messenger). Le controller ne fait qu'assembler la Command et appeler `dispatch()` — jamais de logique métier dans un controller.
- **Lectures (Query)** : actuellement les controllers de lecture (`Get*ViewController`) appellent directement le `Domain\Interface\{X}Repository` injecté — pas de bus. `QueryBusInterface::ask()` existe (`src/Shared/Application/Interface/QueryBusInterface.php`) mais le dossier `Application/Query` est encore vide : si une lecture devient complexe (agrégation, plusieurs repositories), créer un `{UseCase}Query` + `{UseCase}QueryHandler` sur le même modèle que les Command plutôt que d'alourdir le controller.

## Domain Model

- Classes `final` (sauf besoin d'héritage explicite), propriétés `private`, jamais d'ORM dedans.
- Implémentent `Repas\Shared\Domain\Model\ModelInterface` + `use ModelTrait` : `getId()`, `toArray()` (sérialisation générique via réflexion + snake_case), `isEqual()`.
- Construction :
  - `create(...)` — factory pour une nouvelle instance (règles métier de création, ex. génération de slug).
  - `load(array $datas)` — reconstruction depuis la persistance (utilisé par les repositories).
  - Méthodes `update(...)` / `set*()` fluent (`return $this;`) pour les mutations.
- Comparaison par identité via `isEqual()`, jamais `===` sur les objets métier.
- Collections typées via `Repas\Shared\Domain\Tool\Tab` (wrapper de collection typé), pas de tableaux PHP nus pour des listes de modèles.

## Repository pattern

- Le port est défini dans `Domain/Interface/{X}Repository.php` — c'est ce type que Application et les controllers injectent, jamais l'implémentation concrète.
- L'implémentation Doctrine vit dans `Infrastructure/Repository/{X}PostgreSQLRepository.php`, hérite de `PostgreSQLRepository` (gère `EntityManager`/`ObjectRepository`), convertit `Entity` (Doctrine) <-> `Model` (Domain) via `Entity::fromModel()` / `{X}Model::load([...])`.
- Utiliser `ModelCache` (`Shared/Infrastructure/Repository/ModelCache.php`) pour éviter les hydratations répétées dans une requête.
- Pour les tests : implémentation `InMemory{X}Repository` dans `tests/Helper/InMemoryRepository/`, même interface, aucune dépendance Doctrine — permet de tester Application/Domain sans DB.
- Ne jamais faire de requête Doctrine (QueryBuilder, DQL) en dehors de `Infrastructure/Repository`.

## SOLID — application concrète dans ce repo

- **SRP** : un handler = un cas d'usage = un fichier. Un controller = une route.
- **OCP/LSP** : nouveaux cas d'usage = nouvelle classe Command/Handler, pas de branchement conditionnel dans un handler existant. Toute implémentation de repository doit respecter le contrat de l'interface (mêmes exceptions déclarées, ex. `@throws IngredientException`).
- **ISP** : une interface de repository par agrégat (`IngredientRepository`, `RecipeRepository`, ...), pas de "GodRepository".
- **DIP** : Application/Domain dépendent des interfaces (`Domain/Interface/*`), jamais des classes `*PostgreSQLRepository` directement. L'injection se fait via le constructeur, propriétés `private readonly`.

## Exceptions

- Une classe `{Aggregate}Exception extends DomainException` par agrégat, avec des factory methods statiques nommées par cas (`notFound()`, `cannotBeRemove...()`, etc.) plutôt que `new XException("...")` dispersé dans le code.

## Conventions de nommage

- Use case : dossier `Application/Command/{VerbNoun}/` contenant `{VerbNoun}Command.php` + `{VerbNoun}Handler.php`.
- Controller HTTP : suffixe `ViewController`, attribut `#[Route]` sur `__invoke()`.
- Repository infra : suffixe technologie, ex. `PostgreSQLRepository`.
- Événements de domaine : suffixe `Event`, dispatchés via `EventDispatcherInterface` depuis un Handler, consommés par un `EventListener` en Infrastructure.

## Tests

- `tests/Helper/Builder/` : builders pour construire des modèles de test rapidement (pattern Test Data Builder).
- `tests/Helper/InMemoryRepository/` : doubles des repositories pour tester Application sans DB.
- Tester les Handler avec les InMemoryRepository + `SpyEventDispatcher`, pas de mock manuel des interfaces.

## Quand tu ajoutes une fonctionnalité

1. Modéliser dans `Domain/Model` (+ `Domain/Exception` si nouveaux cas d'erreur).
2. Définir/étendre le port dans `Domain/Interface`.
3. Écrire le cas d'usage dans `Application/Command/{UseCase}/` (Command DTO + Handler `#[AsMessageHandler]`).
4. Implémenter le port dans `Infrastructure/Repository` (+ `Infrastructure/Entity` si nouvelle table Doctrine).
5. Exposer via `Infrastructure/Http/Controller` qui appelle uniquement le `CommandBusInterface` (écriture) ou le repository (lecture simple).
6. Ajouter l'InMemoryRepository de test + Builder si nouvel agrégat.

Ne pas mettre de logique métier dans les controllers, les entités Doctrine ou les templates Twig — tout va dans `Domain`/`Application`.
