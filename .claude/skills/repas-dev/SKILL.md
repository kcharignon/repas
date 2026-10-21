---
name: repas-dev
description: Développer une fonctionnalité du projet Repas (Symfony 8.1 / PHP 8.4, hexagonal + DDD + CQRS). À utiliser pour ajouter un use case de lecture (Query, QueryHandler, Loader, DTO), un endpoint API `/api/v1` (JWT) ou un use case d'écriture (Command). Charge les ADR et la roadmap avant d'écrire du code.
---

# repas-dev — développer Repas selon les ADR

## 0. Avant toute chose

1. Lire `docs/adr/README.md` (index) puis les ADR concernés par la tâche : lecture = 0003/0004/0005, buses = 0006, API = 0007/0008, migration = 0009, tests = 0010, outils = 0011.
2. Lire `docs/migration-roadmap.md` : trouver la slice, vérifier qu'elle n'est pas déjà faite, repérer les dépendances (ex. `EnsureActiveShoppingList` avant `GetShoppingList`).
3. Lire `CLAUDE.md` pour la structure des dossiers. En cas de désaccord entre `CLAUDE.md` et un ADR, **l'ADR gagne** ; signaler l'écart.
4. Si la tâche impose une décision d'architecture absente des ADR : écrire un nouvel ADR (numéro suivant, format `docs/adr/0000-template.md`) dans le même commit.

## 1. Règles en bref

- Dépendances : `Infrastructure → Application → Domain`. `Shared` n'importe jamais `Repas`, `User` ni `Drive` (ADR 0001) ; un contexte n'importe jamais un autre contexte.
- Écriture : `Controller → CommandBusInterface::dispatch(Command) → Handler → Domain → Repository`. Lecture : `Controller → QueryBusInterface::ask(Query) → QueryHandler → Loader → DTO` (ADR 0003).
- `QueryHandler` n'appelle **jamais** un `Repository`. `CommandHandler` n'appelle **jamais** un `Loader`. Un `QueryHandler` ne modifie pas l'état.
- Un Loader retourne des **DTO**, jamais un modèle du domaine (ADR 0004). Il lit les entités Doctrine/SQL directement, jointures manuelles sur ids.
- Un DTO est `final readonly`, props publiques, sans logique ; tout calcul d'affichage (flags `canEdit`, quantité en liste, nom par défaut, statuts, regroupements) est précalculé par le Loader (ADR 0005). **Jamais de modèle de domaine ni de `current_user` dans un template.**
- `ModelTrait::toArray()` n'est jamais une sortie HTTP.
- Un agrégat référence les autres agrégats par identifiant, jamais par l'objet (`Meal::recipeId`, `ShoppingListRow::ingredientSlug`) ; une question d'existence se pose par une méthode de port booléenne (ADR 0013).
- Un modèle est une copie : un use case charge un agrégat une seule fois et passe l'instance, un listener recharge depuis la base, jamais d'enregistrement d'un modèle chargé avant un événement qui peut modifier le même agrégat (dernier enregistrement gagnant, ADR 0013 règle 7).
- Recette et liste de courses portent une `version` (ADR 0016) : `ETag` sur les `GET`, `If-Match` facultatif sur les écritures (`EntityVersion::expected($request)` dans le controller, `?int $expectedVersion = null` dans la commande, `$agregat->assertVersion($command->expectedVersion)` dans le handler juste après le chargement ; `#[OA\Parameter(ref: '#/components/parameters/IfMatch')]` et la réponse `412` dans la doc). Chaque enregistrement d'un agrégat existant coûte une requête de plus (budget SQL).
- Domaine : `create()` pour créer, `load()` réservé à l'infra et aux builders de test ; ids et dates injectés par le handler (`UuidGenerator`, `Clock`) (ADR 0002).
- Controllers : un controller = une route = une action, aucune logique métier, injecter les **interfaces** des buses. API : `{UseCase}ApiController` dans `Http/Api/{UseCase}/`. L'utilisateur connecté s'obtient par `#[CurrentUser] AuthenticatedUser $user` (`Shared\Domain\Interface`, accesseurs `getId()` et `getDefaultServing()`) puis `$user->getId()` : jamais `User\Domain\Model\User` dans le contexte `Repas` (ADR 0001 §8).
- API : `/api/v1`, stateless JWT, `{member,totalItems}` en lecture de liste, `204`/`201 + Location` en écriture, erreurs `{error:{message}}`, `#[OA\…]` obligatoire (ADR 0007/0008).
- Emails sortants (ADR 0020, 0026 pour le mot de passe oublié) : un port `Domain/Interface/…Notifier`, un adaptateur `Infrastructure/Mailer/` (Symfony Mailer, texte et HTML en PHP, sans Twig), envoi direct dans la requête (pas de worker) ; `MAILER_DSN` / `MAILER_FROM` / `FRONT_URL`, Mailpit en dev (http://localhost:8025), `null://null` en test. Tutoiement et phrases de 15 mots au plus (DRV-627), vérifiés par le test du notifier ; un lien envoyé par email est un jeton haché à usage unique, un modèle par finalité (`EmailVerificationToken`, `PasswordResetToken`), et la route publique qui le demande répond `204` sans rien révéler du compte.
- Le back ne sert que l'API (ADR 0018) : pas de page, de session ni de formulaire. Un changement de contrat se relit dans `docs/api/openapi.json` (`make openapi`, `make openapi-check`).

## 2. Chemins et noms

| Élément | Chemin | Nom |
|---|---|---|
| Query | `src/Repas/Application/Query/{UseCase}/` | `{UseCase}Query` (`final readonly`, `implements QueryInterface`) |
| QueryHandler | idem | `{UseCase}QueryHandler`, `#[AsMessageHandler(bus: 'query.bus')]`, `__invoke(Query)` |
| DTO | idem | `{X}DTO` (`final readonly`) |
| Port Loader | `src/Repas/Application/Loader/` | `{X}Loader` (interface) |
| Impl. Loader | `src/Repas/Infrastructure/Loader/` | `{X}PostgreSQLLoader implements {X}Loader` |
| Controller API | `src/Repas/Infrastructure/Http/Api/{UseCase}/` | `{UseCase}ApiController`, + `{UseCase}RequestQuery` / `{UseCase}Payload` |
| Command | `src/Repas/Application/Command/{UseCase}/` | `{UseCase}Command` + `{UseCase}Handler` (existant) |
| Exception | `Domain/Exception/{Aggregate}Exception` | factories statiques (`notFound()`) |
| Double de Loader (test) | `tests/Helper/InMemoryLoader/` | `{X}InMemoryLoader` (renvoie des DTO programmés, enregistre ses appels) |
| Builder de DTO (test) | `tests/Helper/Builder/DTO/` | `{X}DTOBuilder` |

Même chose pour le contexte `User` (`src/User/…`), avec deux nuances : `Application/` y est à plat pour les commandes (pas de sous-dossier `Command/`), mais les lectures vont dans `Application/Query/{UseCase}/` et `Application/Loader/` comme dans `Repas` (les règles Deptrac et PHPStan sont par namespace) ; les tests sont dans `tests/User/{Application,InputAdapter,OutputAdapter}/`. Exemple complet : `GetCurrentUser`. Verbes : `Get{X}` pour un élément, `List{X}` pour une collection, `Is{X}` / `Has{X}` pour un prédicat booléen (le handler renvoie un `bool`).

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

### 3.2 Nouvel endpoint API
1. La query existe (3.1) — sinon la créer d'abord.
2. `Infrastructure/Http/Api/{UseCase}/{UseCase}ApiController.php` : `#[Route('/api/v1/...', methods: [...])]`, `#[OA\…]`, appelle `QueryBusInterface::ask()` ou `CommandBusInterface::dispatch()`, renvoie `JsonResponse`/`Response`. Entrées par `#[MapQueryString(validationFailedStatusCode: 422)]` / `#[MapRequestPayload]`.
3. Liste → `{member: [...], totalItems: N}`. Écriture → `204` ou `201` + `Location`.
4. Test `tests/Repas/InputAdapter/` : `MockQueryBus`/`MockCommandBus`, query construite, statut, clés JSON.
   Écriture : `$this->mockCommandBus()` (une requête par test), 401, 403 propriétaire, 404 inconnu, 422 corps invalide, `Location` en 201 ; plus un parcours avec les vrais bus par ressource (`ShoppingListWriteApiFlowTest`). Conventions : ADR 0007 §11 à 13 (action en `POST …/{action}`, alias `active`, identité par le JWT, jamais par le corps).
5. `make api-doc-validate api-doc-coverage`, relire la doc générée (`/api/doc.json`), puis `make openapi` et relire le diff de `docs/api/openapi.json` (le contrat versionné, ADR 0007 §15).
6. Vérifier le niveau d'accès (`#[IsGranted]` / voter propriétaire) : `ROLE_USER` est un plancher, pas une autorisation. Une route publique (ADR 0007 §14) est une exception : chemin exact et méthode dans `access_control`, dite et justifiée dans l'ADR.

### 3.3 Nouveau use case d'écriture
1. Modèle (`Domain/Model`, `create()`/méthode métier) + exception si nouveaux cas d'erreur.
2. Port/extension du Repository (`Domain/Interface`) + implémentation `Infrastructure/Repository`.
3. `Application/Command/{UseCase}/{UseCase}Command.php` (DTO `readonly`) + `{UseCase}Handler.php` (`#[AsMessageHandler]`, repositories + `UuidGenerator` + `Clock` + `EventDispatcherInterface` si événement).
4. Controller API qui ne fait que `CommandBusInterface::dispatch()`.
5. Test : handler avec `{X}InMemoryRepository` + `SpyEventDispatcher` + builders (aucune base, aucun kernel ; la persistance se teste dans `OutputAdapter`, pas à travers le handler).

## 4. Definition of Done

- [ ] `make test` vert
- [ ] `make deptrac` vert (plus de baseline : aucune dépendance entre contextes, ce qui circule passe par `Shared`)
- [ ] `make audit` vert si `composer.json` ou `composer.lock` change (ADR 0014)
- [ ] `make phpstan` vert (aucune nouvelle entrée de baseline)
- [ ] `make api-doc-validate`, `make api-doc-coverage` et `make openapi-check` verts si un endpoint API est touché (`make openapi` régénère `docs/api/openapi.json`, diff relu)
- [ ] Use case d'écriture touché ou ajouté : son scénario existe dans `tests/Repas/WriteBudget/scenarios.php`, son budget SQL ne monte pas ; s'il baisse, `make sql-budget-update` et `budgets.php` est dans le commit (ADR 0010 règle 12)
- [ ] Aucun controller n'injecte de `*Repository` (Deptrac `no-repository-in-http`)
- [ ] Nouvelle table ou colonne qui désigne un utilisateur (`owner`, `author`, `creator`, `user`) : elle est effacée à la suppression du compte (`DeleteUserHandler` côté `User`, `PurgeUserDataHandler` côté `Repas`, `PurgeUserDriveDataHandler` côté `Drive`, ADR 0021) et ajoutée à `UserOwnedDataCoverageTest`
- [ ] Tests : `QueryHandler` (unitaire, `InMemoryLoader`, paramètres transmis), Loader (base réelle), controller API (clés JSON)
- [ ] `docs/migration-roadmap.md` mis à jour (case cochée, bugs vus en passant notés dans « Hors périmètre »)
- [ ] Écriture qui reçoit le slug d'un ingrédient (commande, ligne de recette, intention du drive) : elle le traduit par les alias valides de l'utilisateur (`IngredientSlugResolver::resolve`, ou `IngredientAliasResolver` côté `Drive`) et elle est déclarée dans `IngredientSlugWritesAreResolvedTest` (ADR 0028) ; une requête qui liste ou choisit des ingrédients pour un utilisateur applique `IngredientVisibility::visibleBy` (« créateur = moi »)
- [ ] Il n'y a plus d'ingrédient commun (ADR 0028, amendement) : dans du nouveau code, ni `creator IS NULL` ni `getCreatorId() === null`, ni adoption à la volée, ni notion d'adoptant, ni passe-droit `ROLE_ADMIN` sur un ingrédient (l'administrateur est un utilisateur comme les autres pour les ingrédients ; il garde son rôle pour les comptes, les unités, les rayons, les types de recette et les conversions générales ou de ses ingrédients). Un test d'ingrédient le crée **chez un utilisateur** (`IngredientBuilder` : propriétaire par défaut `DEFAULT_CREATOR_ID`, `withCreator()` pour un autre ; `withoutCreator()` est réservé aux tests des commandes d'exploitation de la migration)
- [ ] Un gestionnaire qui rend un compte `ACTIF` (ou en crée un actif) dispatche `Shared\Domain\Event\UserActivatedEvent` après avoir enregistré le compte, dans la transaction du bus : c'est ce qui lui donne ses ingrédients (ING-008) ; `UserActivationDispatchesEventTest` échoue sinon. Tout `INSERT` SQL d'ingrédient nomme `is_base` (`FALSE` pour une copie)
- [ ] Ingrédient copié ou créé par un nouveau chemin : il naît avec `is_base` à faux, jamais la valeur de la source (ADR 0028, amendement) ; pour savoir qui est le propriétaire du catalogue, port `Shared` `IngredientCatalogOwner` (le compte protégé), jamais `ROLE_ADMIN` ni `isAdmin()` ; une réponse qui montre le marqueur le calcule pour le seul propriétaire (`isBase`, `canSetBase`)
- [ ] Requête SQL qui filtre `ingredient` d'après les lignes d'un compte (recettes, listes, choix du drive) : une sous-requête NON corrélée (`slug IN (SELECT … UNION …)`) ou une jointure, jamais un `EXISTS` ou une colonne scalaire corrélés par ingrédient. Le planificateur multiplie leur prix par le nombre d'ingrédients, franchit `jit_above_cost` et PostgreSQL compile la requête (JIT : 0,5 à 1 s pour 2 ms de travail, mesuré en ING-009 dans `SeedingSourceReader`, déjà noté dans `IngredientVisibility`) ; un test de volume le voit (`HandOverCatalogPerformanceTest`)
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
- Merger une PR dont la CI n'est pas verte ou sans description, pousser sur `main`, force-push, `--no-verify`, commit rouge, `git add -A` sans relire `git status`.

## 6. Commits

Un commit par unité logique (ex. : DTO+Query+Handler+Loader+tests de la slice, puis controller API). Message court en français au style du dépôt (`feat:`, `refacto:`, `chore:`, `docs:`, `fix:`), sans `Co-Authored-By`.

Fin de slice : `git push -u origin <branche>` puis `gh pr create --base main` sans attendre de demande. Titre court (`refactor(chore): cqrs phase N …` pour une phase de la roadmap). Description concise, du même ton que les messages de commit, qui suit `.github/pull_request_template.md` : **Quoi** (3 à 5 puces), **Vérifié** (`make test`, `make quality`, `make deploy-smoke` si `deploy/` change, mesure utile), **À relire** (décisions, risques, bugs notés hors périmètre). Une PR sans description n'est pas acceptable. Pas de pied de page d'attribution. Donner le lien de la PR. Une fois la CI verte, Claude fusionne la PR (`gh pr merge --squash --delete-branch`, autorisé le 2026-10-16) ; jamais de push sur `main` ni de force-push ; l'approbation du déploiement reste à l'humain.
