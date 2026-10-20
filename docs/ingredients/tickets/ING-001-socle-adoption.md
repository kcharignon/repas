# ING-001 — Socle : l'adoption d'un commun par un utilisateur

- **Dépôt** : back
- **Jalon** : I1 Socle
- **Taille** : M
- **Effort recommandé pour l'agent** : **xhigh** (schéma, droits sur les données, suppression de compte)
- **Dépend de** : —
- **Statut** : à faire
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
