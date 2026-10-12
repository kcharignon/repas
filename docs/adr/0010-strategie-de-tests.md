# 0010 — Stratégie de tests de la lecture et de l'API

- **Statut** : Accepted
- **Date** : 2026-10-12
- **Liens** : `CLAUDE.md` (« Tests »), `presentation-archi/presentation.md` §13–§19

## Contexte

`tests/` contient : `Helper/Builder/` (12 builders), `Helper/InMemoryRepository/` (9 repos en mémoire, `SpyEventDispatcher`), `Repas/Application/` et `User/Application/` (un test par `CommandHandler`, sans kernel), `Repas/OutputAdapter/` (tests de repositories et de `RecipeLoader` sur la vraie base via `Helper/DatabaseTestCase`), `Repas/InputAdapter/` (quasi vide). Pas de test de controller de lecture ni de template.

## Principe

**Chaque couche est testée une seule fois, isolée de ses voisines.** La persistance (mapping Doctrine, SQL, jointures, tri, filtres) ne se teste que là où elle vit : les tests de `Repository` et de `Loader`, sur la vraie base. Elle n'est **jamais** testée par transitivité à travers un handler.

Conséquence : un test de handler, `CommandHandler` comme `QueryHandler`, est un test **unitaire**. Il n'utilise ni kernel, ni base, ni Doctrine, ni aucune classe d'`Infrastructure`. Ses collaborateurs sont des doubles en mémoire qui implémentent les mêmes interfaces que la production :

- côté écriture, un `{X}InMemoryRepository` ;
- côté lecture, un `{X}InMemoryLoader`.

## Décision

Chaque couche a son type de test :

| Quoi | Où | Comment | Ce que le test prouve |
|---|---|---|---|
| `CommandHandler` | `tests/Repas/Application/…` | Unitaire. `{X}InMemoryRepository` + builders + `SpyEventDispatcher`. Aucun kernel, aucune base. | La logique d'orchestration : le bon agrégat est chargé, modifié, sauvegardé, les bons événements sont émis, les exceptions de domaine remontent. |
| `QueryHandler` | `tests/Repas/Application/Query/…` | Unitaire. `{X}InMemoryLoader`. Aucun kernel, aucune base. | Le handler **transmet les bons paramètres au Loader** (déduits de la query) et **renvoie le résultat du Loader tel quel**. Rien d'autre. |
| `Repository` | `tests/Repas/OutputAdapter/…` | Base réelle (`DatabaseTestCase`). | Le mapping `Entity` ↔ `Model` et les requêtes d'écriture/relecture d'agrégats. |
| `Loader` | `tests/Repas/OutputAdapter/…` | Base réelle (`DatabaseTestCase`), fixtures via les `Repository`/builders puis `flush()` + `clear()`, assertion sur les DTO. | Filtres, ordre, jointures, calculs précalculés du DTO, cas vide, `NotFound`. Pour un Loader qui remplace un N+1, un test compte les requêtes. |
| Controller API / Twig | `tests/Repas/InputAdapter/…` | `WebTestCase`, `QueryBus`/`CommandBus` remplacés par des doubles (`MockQueryBus`, `MockCommandBus`). | La query/commande construite, le statut, et pour l'API les **clés JSON** du DTO. |

Règles :

1. **Un handler n'est jamais testé avec la base.** Un test de `tests/*/Application/**` n'étend ni `DatabaseTestCase`, ni `KernelTestCase`, ni `WebTestCase`, et ne référence ni Doctrine ni une classe d'`Infrastructure`. Vérifié par Deptrac ([0011](0011-garde-fous-deptrac-phpstan.md), `deptrac.no-database-in-handler-tests.yaml`).
2. **Un `{X}InMemoryLoader` est un double idiot** : `tests/Helper/InMemoryLoader/{X}InMemoryLoader.php`, un par interface de Loader. Il renvoie les DTO qu'on lui a programmés (construits avec un builder de DTO, `tests/Helper/Builder/DTO/`) et **enregistre les arguments reçus**. Il ne réimplémente ni filtre, ni tri, ni jointure : sinon le test du handler vérifierait la logique du double, pas celle du code, et donnerait une fausse confiance. Cette logique est prouvée par le test du Loader réel.
3. Un test de `QueryHandler` asserte donc (a) les arguments reçus par le Loader, (b) l'identité du résultat renvoyé. Il ne teste pas le contenu des DTO.
4. Le `{X}InMemoryRepository` est lui un vrai double fonctionnel (il stocke et retrouve des agrégats) parce que le handler d'écriture en dépend pour sa logique ; sa fidélité au vrai repository est garantie par le test `OutputAdapter` du repository.
5. Pas de `createMock()` sur les interfaces du projet.
6. Structure `// Given` / `// When` / `// Then` dans chaque test.
7. Un nouveau controller (Api) ou `QueryHandler` sans test est refusé par PHPStan (règle custom, [0011](0011-garde-fous-deptrac-phpstan.md)).
8. Horloge et ids : `FrozenClock` et `ControlledUuidGenerator` (`tests/Helper/`).
9. **Couverture en cliquet.** La CI exige un seuil de couverture (`COVERAGE_THRESHOLD` dans `.github/workflows/ci.yml`, 75 % au 2026-10-12) qui ne baisse jamais et se remonte vers 80 % au fil de la migration. La couche Twig legacy (`Http/Controller` et `Http/Form` des deux contextes), sans test fonctionnel et vouée à être remplacée, est exclue de la mesure dans `phpunit.dist.xml` ; les controllers API (`Http/Api`) restent mesurés. Le seuil de 80 % initial n'était déjà plus tenu avant la migration (57 %).

## Conséquences

- Positives : la couche la plus testée (Application) est la plus rapide ; un test rouge localise la couche en cause (métier, mapping SQL, contrat HTTP) ; un changement de schéma ne fait pas tomber les tests de handlers.
- Coûts : un `InMemoryLoader` par interface de Loader ; les tests de Loader et de Repository dépendent de la base de test.
- Garde-fou : Deptrac pour l'isolation des tests de handlers ; seuil de couverture en cliquet en CI (règle 9) ; PHPStan pour la présence des tests.
