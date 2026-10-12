# 0010 — Stratégie de tests de la lecture et de l'API

- **Statut** : Accepted
- **Date** : 2026-10-12
- **Liens** : `CLAUDE.md` (« Tests »), `presentation-archi/presentation.md` §13–§19

## Contexte

`tests/` contient : `Helper/Builder/` (12 builders), `Helper/InMemoryRepository/` (9 repos en mémoire, `SpyEventDispatcher`), `Repas/Application/` et `User/Application/` (un test par `CommandHandler`, sans kernel), `Repas/OutputAdapter/` (tests de repositories et de `RecipeLoader` sur la vraie base via `Helper/DatabaseTestCase`), `Repas/InputAdapter/` (quasi vide). Pas de test de controller de lecture ni de template.

## Décision

Chaque couche a son type de test :

| Quoi | Où | Comment |
|---|---|---|
| `CommandHandler` | `tests/Repas/Application/…` | Inchangé : InMemory repos + builders + `SpyEventDispatcher`, pas de kernel. |
| `QueryHandler` | `tests/Repas/Application/Query/…` | Unitaire, sans kernel. Fake du Loader. Vérifie que la query est transmise au Loader avec les bons arguments et que le DTO est renvoyé tel quel. |
| `Loader` | `tests/Repas/OutputAdapter/…` | Base réelle (`DatabaseTestCase`), fixtures via les `Repository`/builders puis `flush()` + `clear()`, assertion sur les DTO. Couvre filtres, ordre, cas vide, `NotFound`. Pour un Loader qui remplace un N+1, un test compte les requêtes. |
| Controller API / Twig | `tests/Repas/InputAdapter/…` | `WebTestCase`, `QueryBus`/`CommandBus` remplacés par des doubles (`MockQueryBus`, `MockCommandBus`). Vérifie la query/commande construite, le statut, et pour l'API les **clés JSON** du DTO. |

Règles :

1. Fakes de Loader écrits à la main dans `tests/Helper/Loader/Fake{X}Loader.php` (un par interface). Ils enregistrent leurs arguments et renvoient un DTO issu d'un builder de DTO (`tests/Helper/Builder/DTO/`).
2. Pas de `createMock()` sur les interfaces du projet.
3. Structure `// Given` / `// When` / `// Then` dans chaque test.
4. Un nouveau controller (Api) ou `QueryHandler` sans test est refusé par PHPStan (règle custom, [0011](0011-garde-fous-deptrac-phpstan.md)).
5. Les tests `Application` ne touchent jamais la base.
6. Horloge et ids : `FrozenClock` et `ControlledUuidGenerator` (`tests/Helper/`).

## Conséquences

- Positives : la couche la plus testée (Application) est la plus rapide ; un test rouge localise la couche en cause (métier, mapping SQL, contrat HTTP).
- Coûts : une fake de Loader par interface ; les tests de Loader dépendent de la base de test.
- Garde-fou : couverture 80 % déjà exigée en CI ; PHPStan pour la présence des tests.
