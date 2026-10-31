---
name: repas-dev
description: Développer une fonctionnalité du projet Repas (Symfony 8.1 / PHP 8.5, hexagonal + DDD + CQRS). À utiliser pour ajouter un use case de lecture (Query, QueryHandler, Loader, DTO), un endpoint API `/api/v1` (JWT) ou un use case d'écriture (Command). Charge les ADR et la roadmap avant d'écrire du code.
---

# repas-dev — développer Repas selon les ADR

## 0. Avant toute chose

1. Lire `docs/adr/README.md` (index) puis les ADR concernés par la tâche : lecture = 0003/0004/0005, buses = 0006, API = 0007/0008, migration = 0009, tests = 0010, outils = 0011.
2. Lire `docs/migration-roadmap.md` : trouver la slice, vérifier qu'elle n'est pas déjà faite, repérer les dépendances (ex. `EnsureActiveShoppingList` avant `GetShoppingList`).
3. Lire `CLAUDE.md` pour la structure des dossiers. En cas de désaccord entre `CLAUDE.md` et un ADR, **l'ADR gagne** ; signaler l'écart.
4. Si la tâche impose une décision d'architecture absente des ADR : écrire un nouvel ADR (numéro suivant, format `docs/adr/0000-template.md`) dans le même commit.

## 1. Règles en bref

- Dépendances : `Infrastructure → Application → Domain`. `Shared` n'importe jamais un contexte (ADR 0001) ; un contexte n'importe jamais un autre contexte : il lui parle par l'Open Host Service du producteur et sa propre couche anticorruption (ADR 0035, § 3.4).
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
- Emails sortants (ADR 0020 et son amendement du 2026-10-28 pour l'inscription, 0026 pour le mot de passe oublié) : un port `Domain/Interface/…Notifier`, un adaptateur `Infrastructure/Mailer/` (Symfony Mailer, sans Twig ; le HTML et le texte viennent de `Shared\Infrastructure\Mailer\BrandedEmailLayout`, ADR 0031 : le notifier ne fait que dire un `BrandedEmailContent`, jamais de HTML à la main), envoi direct dans la requête (pas de worker) ; `MAILER_DSN` / `MAILER_FROM` / `FRONT_URL`, Mailpit en dev (http://localhost:8025), `null://null` en test. Tutoiement et phrases de 15 mots au plus (DRV-627), vérifiés par le test du notifier ; un lien envoyé par email est un jeton haché à usage unique, un modèle par finalité (`EmailVerificationToken`, `PasswordResetToken`, ce dernier aussi pour le lien de bienvenue de l'inscription, 24 h), et la route publique qui le demande répond `204` sans rien révéler du compte, **la même réponse pour tout état de l'adresse** (inscription : `RegisterNewUser` ne reçoit que l'adresse, le mot de passe se choisit par le lien, `User::NO_PASSWORD`) ; chaque cas qui écrit à l'adresse fait un envoi, pour égaliser le temps de réponse.
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
6. `make test deptrac phpstan` (`make test-fast` pendant le travail : même suite en parallèle, environ cinq fois plus vite ; `make test` reste la référence en local avant de pousser ; la CI joue `make test-ci`, la même suite en parallèle avec couverture).

### 3.2 Nouvel endpoint API
1. La query existe (3.1) — sinon la créer d'abord.
2. `Infrastructure/Http/Api/{UseCase}/{UseCase}ApiController.php` : `#[Route('/api/v1/...', methods: [...])]`, `#[OA\…]`, appelle `QueryBusInterface::ask()` ou `CommandBusInterface::dispatch()`, renvoie `JsonResponse`/`Response`. Entrées par `#[MapQueryString(validationFailedStatusCode: 422)]` / `#[MapRequestPayload]`.
3. Liste → `{member: [...], totalItems: N}`. Écriture → `204` ou `201` + `Location`.
4. Test `tests/Repas/InputAdapter/` : `MockQueryBus`/`MockCommandBus`, query construite, statut, clés JSON.
   Écriture : `$this->mockCommandBus()` (une requête par test), 401, 403 propriétaire, 404 inconnu, 422 corps invalide, `Location` en 201 ; plus un parcours avec les vrais bus par ressource (`ShoppingListWriteApiFlowTest`). Conventions : ADR 0007 §11 à 13 (action en `POST …/{action}`, alias `active`, identité par le JWT, jamais par le corps).
5. `make api-doc-validate api-doc-coverage`, relire la doc générée (`/api/doc.json`), puis `make openapi` et relire le diff de `docs/api/openapi.json` (le contrat versionné, ADR 0007 §15).
6. Vérifier le niveau d'accès (`#[IsGranted]` / voter propriétaire) : `ROLE_USER` est un plancher, pas une autorisation. Une route publique (ADR 0007 §14) est une exception : chemin exact et méthode dans `access_control`, dite et justifiée dans l'ADR.

### 3.3 Nouveau use case d'écriture
0. Il écrit ou copie le **nom d'une recette ou d'un ingrédient** ? Passer le nom par `StringTool::capitalizeFirst` dans le handler (première lettre en majuscule, casse de la suite conservée, en silence : ADR 0030) ; `NamesStartWithACapitalTest` échoue sinon. Les builders et les données de test utilisent des noms déjà en majuscule.
1. Modèle (`Domain/Model`, `create()`/méthode métier) + exception si nouveaux cas d'erreur.
2. Port/extension du Repository (`Domain/Interface`) + implémentation `Infrastructure/Repository`.
3. `Application/Command/{UseCase}/{UseCase}Command.php` (DTO `readonly`) + `{UseCase}Handler.php` (`#[AsMessageHandler]`, repositories + `UuidGenerator` + `Clock` + `EventDispatcherInterface` si événement).
4. Controller API qui ne fait que `CommandBusInterface::dispatch()`.
5. Test : handler avec `{X}InMemoryRepository` + `SpyEventDispatcher` + builders (aucune base, aucun kernel ; la persistance se teste dans `OutputAdapter`, pas à travers le handler).

### 3.4 Un contexte a besoin d'un autre (Open Host Service + ACL, ADR 0035)
Pas de port dans `Shared`, pas d'import direct, pas de requête sur la table d'un autre contexte. Le modèle complet est le pilote `Voice` → `User` : `docs/contextes/roadmap.md` (§ « Le modèle à suivre » et « Migrer un port, pas à pas »).
1. **Producteur** : `Application/OpenHostService/{Capacité}CommandOHS` (le consommateur écrit : repositories, services de domaine, ses commandes par le bus ; rend un Model du producteur, `void` ou une valeur simple) ou `{Capacité}QueryOHS` (le consommateur lit : loaders, query bus ; rend un `…DTO` publié sous `OpenHostService/`, ou une valeur simple). `final readonly`, aucune règle métier, jamais Loader dans un CommandOHS, jamais Repository dans un QueryOHS.
2. **Consommateur** : `Application/Port/{Besoin}CommandPort|QueryPort` (interface, ses types, sa langue) et `Infrastructure/Acl/{Besoin}CommandAdapter|QueryAdapter` (le seul à importer l'autre contexte ; il appelle l'OHS du même genre et transpose). Un handler d'écriture n'injecte que des `CommandPort`, un handler de lecture (ou un Loader) que des `QueryPort`.
3. **Tests** : le test du handler appelant construit le vrai adapter et le vrai OHS sur les `InMemoryRepository` / `InMemoryLoader` du producteur (l'OHS est testé par transitivité, jamais la base) ; sinon un test unitaire de l'OHS avec les mêmes doubles ; la transposition de l'adapter dans `tests/{Consommateur}/Acl/`.
4. **Garde-fous** : `deptrac.bounded-context.yaml`, `OpenHostServiceConventionTest`, `ContextsDoNotShareTablesTest`, `SharedHoldsNoContextBusinessTest` (cliquet des anciens ports).

## 4. Definition of Done

- [ ] `make test` vert
- [ ] `make deptrac` vert (plus de baseline : aucune dépendance entre contextes ; entre deux contextes : Open Host Service du producteur, port et ACL du consommateur, ADR 0035 ; seuls les événements de domaine circulent par `Shared`)
- [ ] `make audit` vert si `composer.json` ou `composer.lock` change (ADR 0014)
- [ ] `make phpstan` vert (aucune nouvelle entrée de baseline)
- [ ] `make api-doc-validate`, `make api-doc-coverage` et `make openapi-check` verts si un endpoint API est touché (`make openapi` régénère `docs/api/openapi.json`, diff relu)
- [ ] Use case d'écriture touché ou ajouté : son scénario existe dans `tests/Repas/WriteBudget/scenarios.php`, son budget SQL ne monte pas ; s'il baisse, `make sql-budget-update` et `budgets.php` est dans le commit (ADR 0010 règle 12)
- [ ] Aucun controller n'injecte de `*Repository` (Deptrac `no-repository-in-http`)
- [ ] Nouvelle table ou colonne qui désigne un utilisateur (`owner`, `author`, `creator`, `user`) : elle est effacée à la suppression du compte (`DeleteUserHandler` côté `User`, `PurgeUserDataHandler` côté `Repas`, `PurgeUserDriveDataHandler` côté `Drive`, `PurgeUserVoiceDataHandler` côté `Voice`, ADR 0021) et ajoutée à `UserOwnedDataCoverageTest`
- [ ] Tests : `QueryHandler` (unitaire, `InMemoryLoader`, paramètres transmis), Loader (base réelle), controller API (clés JSON)
- [ ] `docs/migration-roadmap.md` mis à jour (case cochée, bugs vus en passant notés dans « Hors périmètre »)
- [ ] Quantité, coefficient ou nombre de personnes reçu par l'API : borné par `QuantityBounds` (ou la plage du nombre de personnes, un entier JSON : propriété `mixed` + `Assert\Type('integer')`) dans le corps de la requête ET dans le modèle de domaine, qui est la garde finale ; une nouvelle création par compte (ou par ingrédient, pour une conversion propre) vérifie son quota par un test d'existence (`…HasAtLeast`), sauf l'amorçage ; la copie de recette a son propre plafond (`Recipe::MAX_PER_AUTHOR_WITH_COPIES`)
- [ ] Écriture qui reçoit le slug d'un ingrédient (commande, ligne de recette, intention du drive) : elle le traduit par les alias valides de l'utilisateur (`IngredientSlugResolver::resolve`, ou `IngredientAliasResolver` côté `Drive`) et elle est déclarée dans `IngredientSlugWritesAreResolvedTest` (ADR 0028) ; une requête qui liste ou choisit des ingrédients pour un utilisateur applique `IngredientVisibility::visibleBy` (« créateur = moi »)
- [ ] Écriture qui crée, renomme ou copie le nom d'une recette ou d'un ingrédient : le nom passe par `StringTool::capitalizeFirst` dans le handler (ADR 0030, `NamesStartWithACapitalTest`), un test du handler envoie le nom en minuscules et vérifie le nom enregistré ; toute migration de données qui touche ces noms le fait en PHP (`mb_*`), jamais avec `upper()` SQL
- [ ] Une lecture, une liste ou une copie qui montre la recette d'une personne à une autre applique la règle de la recette secrète (ADR 0033) : `secret = false` dans la requête (`RecipePostgreSQLLoader::otherPeopleQuery` pour les listes et la recherche), `Recipe::isVisibleTo` ou la condition du chargeur pour une lecture ou une copie par identifiant ; réponse `404` au message d'une recette inconnue (jamais `403`), aucun passe-droit `ROLE_ADMIN` pour lire, copier, modifier ni supprimer une secrète d'un autre ; un repas ou une liste qui joint `recipe` ne rend ni nom ni type d'une secrète qui n'est pas au propriétaire de la liste ; un test sur la vraie base le prouve (liste, total, recherche, lecture, copie, administrateur) ; le champ `secret` d'un corps est strictement booléen et facultatif (absent : inchangé)
- [ ] Supprimer un type de recette ou changer le type des recettes en masse (ADR 0034) : les recettes passent d'abord dans un type existant et différent (jamais de recette sans type), version +1 dans la même instruction SQL, une transaction ; le type « Favoris » du front n'est pas un type en base, et le drapeau `recipe.favorite` est le choix de l'auteur seul (`Recipe::changeFavorite`, comme `secret`)
- [ ] Il n'y a plus d'ingrédient commun (ADR 0028, amendement) : dans du nouveau code, ni `creator IS NULL` ni `getCreatorId() === null`, ni adoption à la volée, ni notion d'adoptant, ni passe-droit `ROLE_ADMIN` sur un ingrédient (l'administrateur est un utilisateur comme les autres pour les ingrédients ; il garde son rôle pour les comptes, les unités, les rayons, les types de recette et les conversions générales ou de ses ingrédients). Un test d'ingrédient le crée **chez un utilisateur** (`IngredientBuilder` : propriétaire par défaut `DEFAULT_CREATOR_ID`, `withCreator()` pour un autre ; `withoutCreator()` est réservé aux tests des commandes d'exploitation de la migration)
- [ ] Écriture qui crée ou modifie le nom ou le rayon d'un ingrédient : l'unicité est celle du couple (propriétaire, nom normalisé, rayon) (`IngredientRepository::creatorHasNameInDepartment`, `409 INGREDIENT_NAME_ALREADY_USED`, ADR 0028 amendement du 2026-10-30) ; le même nom dans un autre rayon est permis, donc le slug d'une création est le premier libre (`IngredientSlugPolicy::firstFreeSlug`), jamais `slugify(nom + identifiant)` supposé libre ; toute résolution ou lecture par nom rend les deux ingrédients ou départage par le rayon, jamais un choix silencieux
- [ ] Un DTO qui montre un nom d'ingrédient dit aussi son rayon (`departmentSlug`, `departmentName`) : deux ingrédients du même compte peuvent porter le même nom dans deux rayons ; l'icône d'un rayon (`DepartmentIcon`, `department.icon`) ne se répète jamais sur chaque ligne, le front la tire de la liste des rayons (ADR 0028, amendement du 2026-10-30)
- [ ] Un gestionnaire qui rend un compte `ACTIF` (ou en crée un actif) dispatche `Shared\Domain\Event\UserActivatedEvent` après avoir enregistré le compte, dans la transaction du bus : c'est ce qui lui donne ses ingrédients (ING-008) ; `UserActivationDispatchesEventTest` échoue sinon. Tout `INSERT` SQL d'ingrédient nomme `is_base` (`FALSE` pour une copie)
- [ ] Ingrédient copié ou créé par un nouveau chemin : il naît avec `is_base` à faux, jamais la valeur de la source (ADR 0028, amendement) ; pour savoir qui est le propriétaire du catalogue, port `Repas\Application\Port\CatalogOwnerCommandPort` ou `CatalogOwnerQueryPort` (le compte protégé, vu de `User` par son Open Host Service), jamais `ROLE_ADMIN` ni `isAdmin()` ; une réponse qui montre le marqueur le calcule pour le seul propriétaire (`isBase`, `canSetBase`)
- [ ] Requête SQL qui filtre `ingredient` d'après les lignes d'un compte (recettes, listes, choix du drive) : une sous-requête NON corrélée (`slug IN (SELECT … UNION …)`) ou une jointure, jamais un `EXISTS` ou une colonne scalaire corrélés par ingrédient. Le planificateur multiplie leur prix par le nombre d'ingrédients, franchit `jit_above_cost` et PostgreSQL compile la requête (JIT : 0,5 à 1 s pour 2 ms de travail, mesuré en ING-009 dans `SeedingSourceReader`, déjà noté dans `IngredientVisibility`) ; un test de volume le voit (`HandOverCatalogPerformanceTest`)
- [ ] Commande vocale (contexte `Voice`, ADR 0029) : tout ce qu'Alexa dit vit dans `Voice\Domain\Model\VoiceReplies` (jamais un texte écrit dans un controller ou un handler ; `VoiceRepliesTest` impose tutoiement, deux phrases — trois pour la seule question « de quoi tu parles ? » —, 15 mots, aucun jargon) ; le webhook répond `200` et une réponse vocale à toute requête authentique, `400` sans détail sinon ; le corps de la requête (la voix transcrite), le jeton et le message d'une exception ne sont JAMAIS journalisés ; une nouvelle intention s'ajoute à `AlexaIntent` ET à `docs/voice/skill/generate-model.mjs`, qui produit `interaction-model.fr-FR.json` (`make voice-model`, jamais à la main ; `DocsVoiceSkillTest`) ; l'état d'un dialogue lu dans la requête (`session.attributes`) est une entrée non fiable : ses slugs ne sont jamais crus, il n'est jamais journalisé
- [ ] Nouveau limiteur de débit (`config/packages/rate_limiter.yaml`) : il déclare `lock_factory: Repas\Shared\Infrastructure\RateLimiter\BoundedLockFactory`, pour que ses compteurs se lisent et s'écrivent sous verrou (`EveryLimiterIsLockedTest`) ; une comparaison de mot de passe saisi passe par `PasswordAttemptLimiter::attempt`
- [ ] ADR ajouté ou amendé si une convention a changé (et `CLAUDE.md` + ce skill dans le même commit)

## 5. Interdits

- `Repository` dans un `QueryHandler`; `Loader` dans un `CommandHandler`.
- Tester un handler (Command ou Query) avec la base, le kernel ou une classe d'`Infrastructure` : la persistance se teste dans `OutputAdapter`, jamais par transitivité à travers un handler (ADR 0010, vérifié par Deptrac).
- Un `InMemoryLoader` qui rejoue filtre, tri ou jointure : c'est un double idiot.
- Modèle du domaine (ou `toArray()`) dans un DTO, une réponse HTTP ou un template migré.
- `load()` dans un handler pour créer une entité ; `new \DateTimeImmutable()` / `Uuid::v4()` dans le Domain.
- Logique métier dans un controller, une entité Doctrine ou un template.
- Requête Doctrine (QueryBuilder, DQL) hors `Infrastructure/Repository` et `Infrastructure/Loader`.
- Un port, un DTO ou une classe d'un contexte dans `Shared` ; un import d'un autre contexte hors de `Infrastructure/Acl` ; une requête SQL ou DQL qui nomme la table d'un autre contexte ; un `QueryPort` dans un handler d'écriture, un `CommandPort` dans un handler de lecture (ADR 0035).
- Lecture avec effet de bord (créer si absent) : scinder en commande + query.
- Injecter `CommandBus`/`QueryBus` concrets au lieu des interfaces.
- Merger une PR dont la CI n'est pas verte ou sans description, pousser sur `main`, force-push, `--no-verify`, commit rouge, `git add -A` sans relire `git status`.

## 6. Commits

Un commit par unité logique (ex. : DTO+Query+Handler+Loader+tests de la slice, puis controller API). Message court en français au style du dépôt (`feat:`, `refacto:`, `chore:`, `docs:`, `fix:`), sans `Co-Authored-By`.

Fin de slice : `git push -u origin <branche>` puis `gh pr create --base main` sans attendre de demande. Titre court (`refactor(chore): cqrs phase N …` pour une phase de la roadmap). Description concise, du même ton que les messages de commit, qui suit `.github/pull_request_template.md` : **Quoi** (3 à 5 puces), **Vérifié** (`make test`, `make quality`, `make deploy-smoke` si `deploy/` change, mesure utile), **À relire** (décisions, risques, bugs notés hors périmètre). Une PR sans description n'est pas acceptable. Pas de pied de page d'attribution. Donner le lien de la PR. Une fois la CI verte, Claude fusionne la PR (`gh pr merge --squash --delete-branch`, autorisé le 2026-10-16) ; jamais de push sur `main` ni de force-push ; l'approbation du déploiement reste à l'humain.
