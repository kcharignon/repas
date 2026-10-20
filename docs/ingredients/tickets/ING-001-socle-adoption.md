# ING-001 — Socle : l'adoption d'un commun par un utilisateur

- **Dépôt** : back
- **Jalon** : I1 Socle
- **Taille** : M
- **Effort recommandé pour l'agent** : **xhigh** (schéma, droits sur les données, suppression de compte)
- **Dépend de** : —
- **Statut** : fait (PR back #233)
- **Lire d'abord** : [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) (décisions a à e) ; [`analyse.md`](../analyse.md) §§ 2, 3, 7, 9, 10 ; [`migration.md`](../migration.md) §§ 3 et 5 ; skills `/repas-dev` et `/repas-drive-back` (migrations, budgets, purge) ; ADR 0013 (un modèle est une copie), 0017 règle 6.

## Contexte

Tout le chantier repose sur une opération : **adopter un commun pour un utilisateur** = lui en créer une copie à lui (avec ses conversions) et noter la correspondance. Ce ticket livre cette opération et ses structures de données, **sans rien changer au comportement visible** : aucun alias n'existe en production tant que la migration (ING-003) n'a pas tourné, donc rien n'est masqué ni repointé.

## Portée

1. **Migration Doctrine additive** (`migrations/Version…`, ADR 0017 règle 6, compatible avec la version précédente du code) :
   - `ALTER TABLE ingredient ADD adopted_from VARCHAR(255) DEFAULT NULL` (le commun dont cet ingrédient est l'adoption) ;
   - `CREATE TABLE ingredient_alias (user_id VARCHAR(36) NOT NULL, alias_slug VARCHAR(255) NOT NULL, ingredient_slug VARCHAR(255) NOT NULL, kind VARCHAR(16) NOT NULL, source VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (user_id, alias_slug))` + index `(user_id, ingredient_slug)` ; `kind` vaut `adoption` ou `merge` ; `source` : `first-use`, `copy`, `migration-AAAA-MM-JJ` ou `merge`. Aucune clé étrangère (ADR 0013, 0021). `down` documenté « sur décision humaine, efface les alias ».
   - Entité Doctrine `IngredientAlias` (`Repas\Repas\Infrastructure\Entity`), colonne `adoptedFrom` sur l'entité `Ingredient`.
2. **Domaine** :
   - `Ingredient::adopt(Ingredient $common, string $userId, string $slug): self` (usine : mêmes nom, `sluggedName`, image, rayon, unités, unités compatibles ; `creatorId = userId` ; `adoptedFrom = $common->getSlug()`), `getAdoptedFrom()`, `isAdoption()`. `Ingredient::load` reçoit `adopted_from`. `ModelTrait::toArray()` reste interdit en sortie HTTP.
   - Modèle `IngredientAlias` (`create`, `load`, identifiants seulement, `kind` en enum `AliasKind`).
   - Service de domaine `IngredientSlugPolicy::forAdoption(string $sluggedName, string $userId, callable|array $taken): string` : `sluggedName . userId`, puis `sluggedName . '-2' . userId`, `-3`… jusqu'au premier libre ([`migration.md`](../migration.md) § 5). Pur, testé en table.
   - Port `Domain/Interface/IngredientAliasRepository` : `find(userId, aliasSlug)`, `resolve(userId, list<string> slugs): array<string,string>` (une requête), `isAdopter(userId): bool`, `save(IngredientAlias)`, `deleteByUser(userId)`, `deleteByAliasSlug(userId, aliasSlug)`. Implémentation `IngredientAliasPostgreSQLRepository` (SQL direct, `INSERT … ON CONFLICT (user_id, alias_slug) DO NOTHING` pour l'adoption concurrente) et `IngredientAliasInMemoryRepository` (tests).
3. **Cas d'usage `AdoptCommonIngredient`** (`Application/Command/AdoptCommonIngredient/`, Command + Handler `#[AsMessageHandler]`, **retourne le slug de l'ingrédient de l'utilisateur**) : pour un utilisateur et un slug de commun, dans l'ordre : l'alias existe -> le renvoyer ; le slug n'est pas un commun (créateur non vide) -> `IngredientException` ; sinon copier l'ingrédient (slug de `IngredientSlugPolicy`, vérifié par `IngredientRepository::exists`), copier ses conversions propres (nouveaux identifiants par `UuidGenerator`, `ConversionRepository::findByIngredient`), écrire l'alias (`source` fourni par l'appelant). **Aucun événement** (`IngredientCreatedEvent` et `RecipesOrIngredientsCreatedEvent` ne sont pas émis : une adoption n'est pas une création).
4. **Statistiques** ([`analyse.md`](../analyse.md) § 9) : `RemoveIngredientHandler` n'émet pas `IngredientRemovedEvent` pour un ingrédient qui est une adoption ; `UpdateUserStatisticsCommand` (console) ne compte que les ingrédients dont `adopted_from` est vide ; `CreateIngredientHandler` **efface l'alias de l'utilisateur dont la clé est le slug qu'il crée** (un ingrédient recréé ne doit pas être détourné par un ancien alias de fusion).
5. **Suppression d'un ingrédient** : `RemoveIngredientHandler` supprime aussi les alias qui désignent l'ingrédient supprimé (le commun redevient visible pour l'utilisateur ; documenté, [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) conséquences).
6. **Suppression de compte** (ADR 0021 décision 10) : `PurgeUserDataHandler` appelle `IngredientAliasRepository::deleteByUser` ; `UserOwnedDataCoverageTest::ERASED_WITH_THE_USER` gagne `IngredientAlias::class => ['userId']` ; budget `supprimer-compte` +1.
7. **Au passage** : `IngredientPayload::$name` plafonné à **200** caractères (nom 255 + identifiant 36 dépasserait la colonne du slug, [`analyse.md`](../analyse.md) § 2) -> `make openapi`.

## Hors portée

Masquer les communs adoptés dans les lectures et résoudre les alias dans les écritures (ING-002) ; adopter tout le catalogue d'un coup (ING-003) ; le rapprochement (ING-004) ; la fusion (ING-005, qui écrit des alias `merge`). Aucun appel du cas d'usage depuis un controller dans ce ticket.

## Critères d'acceptation

- Étant donné un commun `tomate` avec deux conversions propres et l'utilisateur `U`, quand `AdoptCommonIngredient(U, 'tomate', source)` s'exécute, alors l'ingrédient `tomate<U>` existe avec `creator = U`, `adopted_from = tomate`, mêmes nom, image, rayon, unités par défaut et unités compatibles ; ses conversions sont copiées (même nombre, mêmes unités et coefficients, **nouveaux identifiants**) ; l'alias `(U, tomate) -> tomate<U>` est écrit ; aucun événement n'est dispatché ; `user.statistics.ingredients` ne change pas.
- Rejouée, l'adoption ne crée ni doublon d'ingrédient, ni de conversion, ni d'alias, et renvoie le même slug.
- Étant donné que `U` possède déjà `tomate<U>` (un ingrédient personnel de même nom), quand il adopte `tomate`, alors la copie prend le slug `tomate-2<U>` et **l'ingrédient existant n'est pas modifié**.
- Étant donné un slug d'ingrédient personnel, l'adoption échoue (`IngredientException`).
- Étant donné la suppression d'une copie par son propriétaire, l'alias disparaît et `IngredientRemovedEvent` n'est pas émis ; pour un ingrédient créé par l'utilisateur, il l'est.
- Étant donné la suppression du compte, ses alias n'existent plus (`UserDataPurgeRepositoryTest`, `AccountAdministrationFlowTest`).
- Créer un ingrédient dont le slug est la clé d'un alias de l'utilisateur supprime cet alias.
- La migration est **additive** : sur le schéma précédent, `doctrine:migrations:migrate` puis `doctrine:schema:validate` passent ; l'ancien code (image précédente, `deploy-smoke`) démarre sur le nouveau schéma.

## Tests exigés

Domaine : `IngredientSlugPolicyTest` (table : libre, pris, pris deux fois), `Ingredient::adopt`. Handler (en mémoire, `SpyEventDispatcher`) : tous les critères ci-dessus. Base réelle (`OutputAdapter`) : `IngredientAliasRepositoryTest` (`resolve` en une requête, `ON CONFLICT`), `IngredientRepositoryTest` (`adopted_from` aller-retour), `ConversionRepositoryTest` (copie). `UserOwnedDataCoverageTest`. Budgets `WriteBudget` : nouveau scénario `adopter-ingredient` ; `supprimer-compte`, `supprimer-ingredient`, `creer-ingredient` montent d'une requête chacun au plus (à dire dans la PR). `make test quality` ; `make deploy-smoke` si le schéma change l'entrypoint (non).

## Definition of Done

Celle du skill `repas-dev` § 4, plus : `make openapi` relu (plafond du nom), `docs/migration-roadmap.md` n'est pas concerné, `docs/ingredients/roadmap.md` coché.

## Pièges

- **Un modèle est une copie** (ADR 0013 règle 7) : charger le commun une fois, ne pas le recharger pour copier ses conversions ; `IngredientPostgreSQLRepository::save` écrase un ingrédient existant de même slug (`updateEntity` `:138`) : `exists()` d'abord, toujours.
- `ConversionRepository::findByIngredient` renvoie des modèles avec leur ingrédient chargé : pour 100 conversions c'est lourd ; ce cas d'usage ne copie qu'**un** ingrédient (la copie en masse est ING-003, en SQL).
- Le slug d'adoption doit être **identique** à ce que `Ingredient::create` produirait (`slugged_name . userId`), pour que les ingrédients adoptés et créés se ressemblent (aucune règle de slug nouvelle).
- Le slug `tomate-2<U>` coïncide avec celui d'un ingrédient que l'utilisateur appellerait « Tomate 2 » : `exists()` avant toute création, dans les deux sens, suffit (409), ne pas inventer un séparateur de plus.
- Ne pas confondre `adopted_from` (fait immuable : cet ingrédient est né d'un commun) et l'alias (association actuelle : pour cet utilisateur, ce commun est cet ingrédient ; elle suit les fusions).

## Réalisation (PR back #233)

Livré tel que décrit ci-dessus, avec les écarts et ajouts suivants, tous décidés pour tenir l'intention du ticket.

**Forme exacte du schéma** (`migrations/Version20261020200000.php`, additive, rejouée en `up`, `down`, `up` sur une base vide, `schema:validate` vert) :

- `ingredient.adopted_from VARCHAR(255) DEFAULT NULL` ;
- `ingredient_alias (user_id VARCHAR(36), alias_slug VARCHAR(255), ingredient_slug VARCHAR(255), kind VARCHAR(16), source VARCHAR(64), created_at TIMESTAMP(0) WITHOUT TIME ZONE)`, tous `NOT NULL`, clé primaire `(user_id, alias_slug)`, index `idx_ingredient_alias_ingredient (user_id, ingredient_slug)`, aucune clé étrangère.

**Contrats** :

- Port `Repas\Repas\Domain\Interface\IngredientAliasRepository` : `find(userId, aliasSlug): ?IngredientAlias`, `resolve(userId, list<string> slugs): array<alias_slug, ingredient_slug>` (une requête, un slug sans alias est absent), `isAdopter(userId): bool`, **`save(IngredientAlias): bool`** (`INSERT … ON CONFLICT (user_id, alias_slug) DO NOTHING` ; `true` si la ligne a été écrite, `false` si l'utilisateur en avait déjà une, qui n'est jamais remplacée), `deleteByUser`, `deleteByAliasSlug(userId, aliasSlug)`, **`deleteByIngredientSlug(userId, ingredientSlug)`** (ajouté : sert à la suppression d'un ingrédient).
- `AdoptCommonIngredientCommand(userId, commonSlug, source)` -> `AdoptCommonIngredientHandler` renvoie le slug de l'ingrédient de l'utilisateur. Aucun événement.
- `Ingredient::adopt($common, $userId, $slug)`, `getAdoptedFrom()`, `isAdoption()` ; `AliasKind` (`adoption`, `merge`) ; `IngredientAlias::create(...)` refuse une source vide ou de plus de 64 caractères et un alias qui se désigne lui-même (`IngredientAliasException`, 422) ; `IngredientException::notCommon($slug)` (422).
- `IngredientSlugPolicy::forAdoption(string $name, string $userId, array|callable $taken): string`.

**Écarts avec le ticket** :

1. **Le slug se calcule sur le nom, pas sur `slugged_name`** : `slugify(nom . U)`, puis `slugify(nom . '-2' . U)`, `-3`… C'est ce que `Ingredient::create()` produit réellement, et c'est `slugged_name . U` pour tout nom qui finit par une lettre ou un chiffre. Les deux formules divergent pour un nom qui finit par un symbole : « couche (taille 4) » donne `couche-taille-4-<U>` à la création et `couche-taille-4<U>` en collant `slugged_name` (quatre communs du jeu de référence `Data/ingredient.json`). Suivre la formule du ticket aurait fait manquer la collision avec un ingrédient personnel de même nom pour ces quatre-là. Le premier paramètre s'appelle donc `$name`. `IngredientSlugPolicyTest` rejoue la politique contre `Ingredient::create()` sur sept noms.
2. **L'alias est écrit avant la copie** (le ticket dit « copier, puis écrire l'alias »). C'est l'alias qui arbitre l'adoption simultanée du même commun : l'`INSERT … ON CONFLICT DO NOTHING` de la seconde transaction attend la fin de la première (vérifié sur PostgreSQL 16 avec deux sessions), ne trouve plus rien à écrire, et la seconde rend l'ingrédient de la gagnante au lieu de créer une deuxième copie. Écrit après la copie, le conflit aurait éclaté sur la clé de l'ingrédient (`500`) avant d'atteindre l'alias. Si la transaction échoue plus loin, l'alias s'annule avec le reste. Deux adoptions simultanées de deux communs *différents* par le même utilisateur n'ont pas la même clé d'alias et ne s'arbitrent pas ainsi ; elles ne se heurtent que si leurs noms visent le même slug (« Tomate » et « Tomate 2 » quand l'utilisateur a déjà son « Tomate » : tous deux visent `tomate-2<U>`), et l'une échoue alors sur la clé de l'ingrédient (une requête en erreur, rien de corrompu, à rejouer).
3. `CreateIngredientHandler` n'efface l'alias que pour **la création d'un ingrédient personnel** : un administrateur crée un commun, il n'a jamais d'alias, la requête serait gaspillée.
4. `UpdateUserStatisticsCommand` (console) n'avait aucun test : `UpdateUserStatisticsCommandTest` est ajouté.
5. `IngredientPayload::$name` plafonné à **200** (`Length` + `maxLength` OpenAPI) ; le contrat `docs/api/openapi.json` ne change que sur ce champ. Le champ du front doit suivre quand il aura l'occasion (un nom de 201 à 255 caractères est désormais refusé en `422`).

**Budgets SQL** (`tests/Repas/WriteBudget`) : nouveau scénario `adopter-ingredient` = **16** requêtes (le beurre, deux conversions propres ; une requête de plus par slug déjà pris) ; `creer-ingredient` 9 -> **10**, `supprimer-ingredient` 10 -> **11**, `supprimer-compte` 21 -> **22** (une requête d'alias chacun, sans lecture préalable).

**Compatibilité avec le code précédent (ADR 0017 règle 6)**, vérifiée en rejouant le code de `main` d'avant ce ticket sur une base migrée qui contenait des adoptions : `doctrine:migrations:migrate --all-or-nothing` répond « Already at the latest version » (la migration est « exécutée et indisponible », sans effet) ; lire, renommer, créer, supprimer un ingrédient et supprimer un compte fonctionnent ; l'ancien code ne lit ni n'efface `adopted_from` (un renommage par l'ancien code la laisse intacte) ; seules dégradations : les alias ne sont pas supprimés avec leur ingrédient ni avec le compte (orphelins, requête de nettoyage dans ING-010). `doctrine:schema:validate` lancé avec l'ancien code signale la différence de schéma, ce qui ne concerne que la CI, pas le démarrage.

**Points d'attention pour ING-002** : `resolve()` ne vérifie pas que l'ingrédient désigné existe (un alias orphelin, laissé par l'ancien code après un retour arrière, désigne un slug absent : la visibilité d'ING-002 et son résolveur doivent s'en méfier, par exemple en ne masquant un commun que si la copie existe) ; `AdoptCommonIngredient` rend le slug de l'alias sans relire l'ingrédient (il répond même si le commun a été supprimé depuis) ; la source passée (`first-use`, `copy`) est celle du premier passage, elle n'est pas réécrite au rejeu ; les administrateurs ne sont pas exclus par le cas d'usage (« les administrateurs n'adoptent jamais » est à tenir par l'appelant).

**Points d'attention pour ING-003** : appeler `IngredientSlugPolicy` (pas de formule en SQL), puis insérer en lot ; la copie en masse n'utilise pas `AdoptCommonIngredient` (une adoption coûte 16 requêtes, ~260 communs par utilisateur) mais doit produire les mêmes lignes : même `adopted_from`, mêmes conversions copiées (propres seulement), même alias (`kind = adoption`, `source` du lancement), ni événement ni statistique ; l'ordre « alias puis copie » n'a pas de sens en lot (la transaction par utilisateur et le verrou consultatif de la migration arbitrent).
