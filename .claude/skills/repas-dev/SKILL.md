---
name: repas-dev
description: Développer ou migrer une fonctionnalité du projet Repas (Symfony 7.2 / PHP 8.4, hexagonal + DDD + CQRS). À utiliser pour ajouter ou migrer un use case de lecture (Query, QueryHandler, Loader, DTO), un endpoint API `/api/v1` (JWT), un use case d'écriture (Command), ou basculer un ViewController Twig vers le QueryBus. Charge les ADR et la roadmap avant d'écrire du code.
---

# repas-dev — développer et migrer Repas selon les ADR

## 0. Avant toute chose

1. Lire `docs/adr/README.md` (index) puis les ADR concernés par la tâche : lecture = 0003/0004/0005, buses = 0006, API = 0007/0008, migration = 0009, tests = 0010, outils = 0011.
2. Lire `docs/migration-roadmap.md` : trouver la slice, vérifier qu'elle n'est pas déjà faite, repérer les dépendances (ex. `EnsureActiveShoppingList` avant `GetShoppingList`).
3. Lire `CLAUDE.md` pour la structure des dossiers. En cas de désaccord entre `CLAUDE.md` et un ADR, **l'ADR gagne** ; signaler l'écart.
4. Si la tâche impose une décision d'architecture absente des ADR : écrire un nouvel ADR (numéro suivant, format `docs/adr/0000-template.md`) dans le même commit.

## 1. Règles en bref

- Dépendances : `Infrastructure → Application → Domain`. `Shared` n'importe jamais `Repas` ni `User` (ADR 0001).
- Écriture : `Controller → CommandBusInterface::dispatch(Command) → Handler → Domain → Repository`. Lecture : `Controller → QueryBusInterface::ask(Query) → QueryHandler → Loader → DTO` (ADR 0003).
- `QueryHandler` n'appelle **jamais** un `Repository`. `CommandHandler` n'appelle **jamais** un `Loader`. Un `QueryHandler` ne modifie pas l'état.
- Un Loader retourne des **DTO**, jamais un modèle du domaine (ADR 0004). Il lit les entités Doctrine/SQL directement, jointures manuelles sur ids.
- Un DTO est `final readonly`, props publiques, sans logique ; tout calcul d'affichage (flags `canEdit`, quantité en liste, nom par défaut, statuts, regroupements) est précalculé par le Loader (ADR 0005). **Jamais de modèle de domaine ni de `current_user` dans un template.**
- `ModelTrait::toArray()` n'est jamais une sortie HTTP.
- Domaine : `create()` pour créer, `load()` réservé à l'infra et aux builders de test ; ids et dates injectés par le handler (`UuidGenerator`, `Clock`) (ADR 0002).
- Controllers : un controller = une route = une action, aucune logique métier, injecter les **interfaces** des buses. Twig : `{Action}ViewController` ; API : `{UseCase}ApiController` dans `Http/Api/{UseCase}/`.
- API : `/api/v1`, stateless JWT, `{member,totalItems}` en lecture de liste, `204`/`201 + Location` en écriture, erreurs `{error:{message}}`, `#[OA\…]` obligatoire (ADR 0007/0008).
- Les routes Twig/AJAX existantes restent valides (coexistence, ADR 0009). Pas de changement de comportement visible dans une slice de lecture.

## 2. Chemins et noms

| Élément | Chemin | Nom |
|---|---|---|
| Query | `src/Repas/Application/Query/{UseCase}/` | `{UseCase}Query` (`final readonly`, `implements QueryInterface`) |
| QueryHandler | idem | `{UseCase}QueryHandler`, `#[AsMessageHandler(bus: 'query.bus')]`, `__invoke(Query)` |
| DTO | idem | `{X}DTO` (`final readonly`) |
| Port Loader | `src/Repas/Application/Loader/` | `{X}Loader` (interface) |
| Impl. Loader | `src/Repas/Infrastructure/Loader/` | `{X}PostgreSQLLoader implements {X}Loader` |
| Controller API | `src/Repas/Infrastructure/Http/Api/{UseCase}/` | `{UseCase}ApiController`, + `{UseCase}RequestQuery` / `{UseCase}Payload` |
| Controller Twig | `src/Repas/Infrastructure/Http/Controller/` | `{Action}ViewController` (existant) |
| Command | `src/Repas/Application/Command/{UseCase}/` | `{UseCase}Command` + `{UseCase}Handler` (existant) |
| Exception | `Domain/Exception/{Aggregate}Exception` | factories statiques (`notFound()`) |
| Double de Loader (test) | `tests/Helper/InMemoryLoader/` | `{X}InMemoryLoader` (renvoie des DTO programmés, enregistre ses appels) |
| Builder de DTO (test) | `tests/Helper/Builder/DTO/` | `{X}DTOBuilder` |

Même chose pour le contexte `User` (`src/User/…`), avec deux nuances : `Application/` y est à plat pour les commandes (pas de sous-dossier `Command/`), mais les lectures vont dans `Application/Query/{UseCase}/` et `Application/Loader/` comme dans `Repas` (les règles Deptrac et PHPStan sont par namespace) ; les tests sont dans `tests/User/{Application,InputAdapter,OutputAdapter}/`. Exemple complet : `GetCurrentUser`. Verbes : `Get{X}` pour un élément, `List{X}` pour une collection.

## 3. Recettes

### 3.1 Nouveau use case de lecture
Détail et squelettes : `references/read-slice-template.md`. Ordre :
1. DTO(s) dans `Application/Query/{UseCase}/`.
2. Interface `Application/Loader/{X}Loader`.
3. `{UseCase}Query` + `{UseCase}QueryHandler` (le handler délègue au Loader, rien d'autre).
4. `Infrastructure/Loader/{X}PostgreSQLLoader` (DQL/DBAL → DTO). Autowiring suffit s'il n'y a qu'une implémentation.
5. Tests : handler + `{X}InMemoryLoader` (unitaire : bons paramètres transmis au Loader, résultat renvoyé tel quel, **jamais de base**) ; Loader sur base réelle (`DatabaseTestCase`, dossier `tests/Repas/OutputAdapter/`).
   `QueryConventionTest` contrôle tout seul l'attribut `bus: 'query.bus'` et la présence du handler : ne pas le dupliquer dans le test du handler.
6. `make test deptrac phpstan`.

### 3.2 Migrer un ViewController Twig vers le QueryBus
Détail : `references/migrate-view-controller.md`. Résumé :
1. Lister ce que le template lit (variables, appels de méthodes de domaine, `current_user`).
2. Mettre ces besoins dans le DTO (3.1), logique d'affichage incluse.
3. Le controller n'injecte plus que `QueryBusInterface` : `$dto = $this->queryBus->ask(new XQuery(...))`.
4. Adapter le template : propriétés du DTO, plus aucun appel de domaine.
5. Vérifier le nombre de requêtes avant/après (web profiler) et que le HTML est équivalent.
6. Test visuel dans Chromium (`references/visual-check.md`) : captures lues, liens et actions essayés.
7. Cocher la slice dans `docs/migration-roadmap.md`.

### 3.3 Nouvel endpoint API
1. La query existe (3.1) — sinon la créer d'abord.
2. `Infrastructure/Http/Api/{UseCase}/{UseCase}ApiController.php` : `#[Route('/api/v1/...', methods: [...])]`, `#[OA\…]`, appelle `QueryBusInterface::ask()` ou `CommandBusInterface::dispatch()`, renvoie `JsonResponse`/`Response`. Entrées par `#[MapQueryString(validationFailedStatusCode: 422)]` / `#[MapRequestPayload]`.
3. Liste → `{member: [...], totalItems: N}`. Écriture → `204` ou `201` + `Location`.
4. Test `tests/Repas/InputAdapter/` : `MockQueryBus`/`MockCommandBus`, query construite, statut, clés JSON.
5. `make api-doc-validate api-doc-coverage`, relire la doc générée (`/api/doc`).
6. Vérifier le niveau d'accès (`#[IsGranted]` / voter propriétaire) : `ROLE_USER` est un plancher, pas une autorisation.

### 3.4 Nouveau use case d'écriture
1. Modèle (`Domain/Model`, `create()`/méthode métier) + exception si nouveaux cas d'erreur.
2. Port/extension du Repository (`Domain/Interface`) + implémentation `Infrastructure/Repository`.
3. `Application/Command/{UseCase}/{UseCase}Command.php` (DTO `readonly`) + `{UseCase}Handler.php` (`#[AsMessageHandler]`, repositories + `UuidGenerator` + `Clock` + `EventDispatcherInterface` si événement).
4. Controller (Twig ou API) qui ne fait que `CommandBusInterface::dispatch()`.
5. Test : handler avec `{X}InMemoryRepository` + `SpyEventDispatcher` + builders (aucune base, aucun kernel ; la persistance se teste dans `OutputAdapter`, pas à travers le handler).

## 4. Definition of Done

- [ ] `make test` vert
- [ ] `make deptrac` vert (la baseline ne grossit pas)
- [ ] `make phpstan` vert (aucune nouvelle entrée de baseline)
- [ ] `make api-doc-validate` et `make api-doc-coverage` verts si un endpoint API est touché
- [ ] Le controller migré n'injecte plus de `*Repository`; les templates concernés n'appellent plus de méthode de domaine
- [ ] Tests : `QueryHandler` (unitaire, `InMemoryLoader`, paramètres transmis), Loader (base réelle), controller API (clés JSON)
- [ ] Test visuel dans Chromium de chaque page Twig touchée (connexion réelle, captures lues, liens et actions essayés, aucune erreur console/HTTP) : `references/visual-check.md`
- [ ] `docs/migration-roadmap.md` mis à jour (case cochée, bugs vus en passant notés dans « Hors périmètre »)
- [ ] ADR ajouté ou amendé si une convention a changé (et `CLAUDE.md` + ce skill dans le même commit)

## 5. Interdits

- `Repository` dans un `QueryHandler`; `Loader` dans un `CommandHandler`.
- Tester un handler (Command ou Query) avec la base, le kernel ou une classe d'`Infrastructure` : la persistance se teste dans `OutputAdapter`, jamais par transitivité à travers un handler (ADR 0010, vérifié par Deptrac).
- Un `InMemoryLoader` qui rejoue filtre, tri ou jointure : c'est un double idiot.
- Modèle du domaine (ou `toArray()`) dans un DTO, une réponse HTTP ou un template migré.
- `load()` dans un handler pour créer une entité ; `new \DateTimeImmutable()` / `Uuid::v4()` dans le Domain.
- Logique métier dans un controller, une entité Doctrine ou un template.
- Requête Doctrine (QueryBuilder, DQL) hors `Infrastructure/Repository` et `Infrastructure/Loader`.
- Lecture avec effet de bord (créer si absent) : scinder en commande + query.
- Injecter `CommandBus`/`QueryBus` concrets au lieu des interfaces.
- `git push`, `--no-verify`, commit rouge, `git add -A` sans relire `git status`.

## 6. Commits

Un commit par unité logique (ex. : DTO+Query+Handler+Loader+tests de la slice, puis bascule du controller Twig, puis controller API). Message court en français au style du dépôt (`feat:`, `refacto:`, `chore:`, `docs:`, `fix:`), sans `Co-Authored-By`. Ne jamais pousser sans demande explicite.
