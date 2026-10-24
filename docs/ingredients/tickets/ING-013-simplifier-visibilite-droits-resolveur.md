# ING-013 — Simplifier : visibilité, droits, résolveur, copie de recette, fusion, doublons, conversions

- **Dépôt** : back
- **Jalon** : I6 Plus d'ingrédient commun
- **Taille** : L
- **Effort recommandé pour l'agent** : **xhigh** (règles d'accès à tous les ingrédients, `OwnerVoter`, toutes les écritures qui reçoivent un slug)
- **Dépend de** : **la passation exécutée et contrôlée** (`sans-commun.md` § 4, étape 12) ; R1 en production
- **Statut** : fait (PR #247) — **ne se fusionne qu'après l'étape 12 de la passation (faite le 2026-10-21)** ; R2 avec ING-014
- **Sortie** : **R2**, avec ING-014, **un autre jour que la passation**
- **Lire d'abord** : [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) amendement du 2026-10-20 (décisions 1, 2, 8 ; tableau « Ce qui devient obsolète » ; D3, D4, D7) ; **[`sans-commun.md`](../sans-commun.md) §§ 7, 9, 11** ; [`analyse.md`](../analyse.md) §§ 3, 4 (l'inventaire d'origine) ; ING-002, ING-004, ING-005, ING-006 et leurs sections « Réalisation » (ce qu'on simplifie) ; ADR 0007 §§ 11 à 13 ; skills `/repas-dev`, `/repas-drive-back`.

## Contexte

Une fois les 261 anciens communs passés au propriétaire et tout compte actif amorcé, **aucun ingrédient n'est plus partagé**. Le code qui traite « sans créateur » à part est mort ou nuisible (le propriétaire ne verrait plus rien si la visibilité restait celle d'aujourd'hui, avec une branche administrateur en plus). Ce ticket livre le **comportement** de « tous les utilisateurs ont leurs ingrédients et basta » ; ING-014 retire le reste du code mort et pose les garde-fous.

## Portée

1. **Visibilité (une seule règle)** : `IngredientVisibility::visibleBy` devient « `creator` = l'utilisateur ». Appliquée telle quelle aux mêmes endroits qu'ING-002 : `IngredientPostgreSQLLoader::listByDepartment`, `::listAvailable`, `::search`, `IngredientPostgreSQLRepository::findByDepartmentAndOwner`, `::findByOwner`, `::findPurchaseUnitSlugUsableBy`, `IngredientMatchCandidatesPostgreSQLRepository::visibleTo`. `::get` (la fiche) : `404` quand le créateur n'est pas le lecteur, **sans exception administrateur** (D3).
2. **`canEdit`** : `creator` = le lecteur, donc toujours vrai pour ce qui est visible. Le champ **reste** dans le contrat (compatibilité des clients en cache, le front ne s'en sert plus pour décider, ING-015) ; son retrait est hors chantier. Les paramètres `$viewerIsAdmin` des requêtes, handlers, du port `IngredientLoader` et des controllers disparaissent.
3. **Droits** : `OwnerVoter` ne laisse plus passer l'administrateur pour `INGREDIENT_OWNER` (l'administrateur modifie, supprime, fusionne les **siens**, comme tout le monde) ; son passe-droit reste pour les autres attributs. `Recipe::assertIngredientIsAvailable` : l'ingrédient est à l'auteur, sinon `404` (plus de branche « sans créateur »).
4. **Résolveur d'écriture** : `IngredientSlugResolver` ne fait plus que résoudre les alias valides (`resolve`) : plus d'adoption à la volée, plus de notion d'« adoptant » (`AliasLookup::$adopter`, `IngredientRepository::findCommonSlugs`, `SOURCE_FIRST_USE`). Mêmes appelants qu'ING-002 ; `IngredientSlugWritesAreResolvedTest` inchangé dans son principe. La lecture d'alias reste une requête ; la lecture des communs et l'adoption disparaissent : les budgets `WriteBudget` **baissent** (`make sql-budget-update`, à annoncer).
5. **Copie de recette** : l'étape 0 (provenance) prend la clé d'alias `adopted_from` de l'ingrédient source, **sinon son slug**, et la résout par les alias du copieur ; plus de branche « commun », plus d'adoption à la volée ; si le copieur n'a rien pour cette clé, le rapprochement par le nom, puis la copie. **Toute copie retient son origine** (`adopted_from` = l'origine de l'original, sinon son slug : D7) : elle n'est donc plus comptée comme une création ; `RecipesOrIngredientsCreatedEvent` ne porte plus d'ingrédient créé par une copie de recette.
6. **Fusion et doublons** : retirer `MergeBlock::COMMON_INGREDIENT`, `IngredientException::cannotMergeCommon` (`CANNOT_MERGE_COMMON_INGREDIENT`), la branche de `IngredientMergePlanner`, de `IngredientMergePostgreSQLLoader`, de `MergeIngredientsHandler` (toujours `assertOwnedBy`), de `DuplicateIngredientFinder` ; l'administrateur fusionne et voit des doublons parmi **ses** ingrédients (le catalogue de 261 ne donne aucune paire : test existant). `make openapi` : les codes retirés disparaissent de la documentation ; le front tolère déjà un `blockedBy` inconnu.
7. **Conversions (D4)** : `ConversionLoader::list` ne rend que les conversions **générales** et celles des ingrédients **du lecteur** (le champ `creatorEmail` reste, toujours le lecteur ou vide) ; `GET /api/v1/conversions/{id}` d'une conversion d'un autre : `404` ; `CreateConversionCommand` et `UpdateConversionCommand` gagnent l'identifiant de l'utilisateur et leurs handlers refusent (`404`) un ingrédient qui n'est pas à lui ; les routes restent `#[IsGranted('ROLE_ADMIN')]`.
8. **Tests** : `IngredientBuilder` et ses usages (77 fichiers) : un ingrédient de test naît **chez un utilisateur** (par défaut un utilisateur de test partagé par le scénario), plus jamais « sans créateur » ; les tests qui supposaient « un commun visible de tous » créent un ingrédient par compte ou passent par l'amorçage.

## Hors portée

Supprimer le code mort restant, les commandes et le script d'ING-003, les fixtures, le garde-fou et la migration-garde (ING-014) ; le front (ING-015) ; retirer `canEdit` ou `creatorId` du contrat ; un accès de support de l'administrateur aux ingrédients des autres.

## Critères d'acceptation

- Étant donné la base après la passation (jeu de test reproduisant la production : propriétaire avec ses 261, un compte migré, un compte neuf amorcé), quand chacun liste le rayon, cherche « tomate », choisit un ingrédient pour une recette : **chacun ne voit que les siens**, le propriétaire exactement les mêmes qu'avant ; aucun ingrédient n'apparaît deux fois.
- Le propriétaire modifie, supprime (si inutilisé), fusionne un de **ses** ingrédients ; `PUT`/`DELETE` sur l'ingrédient d'un autre : `403` (voter) ; `GET` de la fiche d'un autre : `404`.
- Un administrateur qui n'est pas le propriétaire se comporte comme un utilisateur ordinaire pour les ingrédients.
- Étant donné un ancien slug (`tomate`) reçu par un compte migré : l'écriture atterrit sur sa copie (alias) ; reçu par un compte qui n'a pas d'alias pour ce slug : `404` ou « ingrédient inconnu », **aucune** adoption, aucune ligne sur l'ingrédient d'un autre.
- Étant donné la copie de la recette du propriétaire par un compte amorcé : ses lignes se branchent sur les copies du copieur par l'alias (clé = le slug de l'ingrédient du propriétaire) ; un ingrédient que le copieur n'a pas est copié, avec son origine, sans être compté comme créé.
- Étant donné la liste des conversions de l'administrateur : générales + les siennes ; aucune de celles des autres comptes ; la création d'une conversion sur l'ingrédient d'un autre : `404`.
- Les budgets d'écriture des use cases qui lisaient les alias et les communs baissent ; aucun ne monte ; `make sql-budget-update` est dans le commit.
- `IngredientVisibilityTest` : une seule définition de la visibilité ; tous les chargeurs et dépôts la partagent (comparés).

## Tests exigés

Tout ce qui précède sur base réelle (`OutputAdapter`) ; `OwnerVoterTest` (administrateur et propriétaire, ingrédient d'un autre et le sien) ; handlers en mémoire (résolveur, copie de recette : provenance par alias, ingrédient absent, retenue de l'origine, événement de statistiques) ; controllers (clés JSON inchangées, codes) ; parcours avec les vrais bus (recette → liste → choix du drive pour un compte amorcé ; copie d'une recette du propriétaire) ; `MergePreviewMatchesTheMergeTest` et la suite de la fusion ; `IngredientReferencesAreAllRepointedTest` ; `make test quality openapi-check`.

## Definition of Done

`repas-dev` § 4 ; `CLAUDE.md` et skill `repas-dev` mis à jour dans le même commit (plus de « un utilisateur sans alias garde les communs partagés », plus d'adoptant) ; roadmap cochée ; PR « À relire » : D3, D4, D7 et leurs effets visibles (l'administrateur ne modifie plus les ingrédients des autres ; la statistique « ingrédients créés » baisse pour les copies de recette).

## Pièges

- **Ne pas fusionner avant l'étape 12** : sur la base d'avant la passation, ce code rend le propriétaire aveugle (ING-014 pose une migration-garde qui l'empêche de démarrer ; ne pas la contourner).
- Ne pas casser le **retour arrière du code** : aucune migration dans ce ticket.
- Le résolveur garde son rôle **d'anciens slugs** (onglets, brouillons du drive, fusion) : ne pas l'enlever avec l'adoption.
- Le drive ne change pas : `IngredientAliasResolver` (port `Shared`) continue de résoudre, rien d'autre.
- `OwnerVoter` : ne pas retirer le passe-droit des autres attributs (recettes, listes) ; seul `INGREDIENT_OWNER` change.
- La statistique : `UpdateUserStatisticsCommand` compte déjà « créés = sans origine » : cohérent avec D7.
- **Fiche lue par un ancien slug** (onglet ouvert avant la migration d'ING-003, adopté depuis) : `GET /ingredients/{ancien slug}` répond `404` après ce ticket, alors qu'ING-002 la laissait lisible en lecture seule. C'est acceptable (écran périmé, le rayon se recharge) ; si l'on veut le tolérer, résoudre le slug par les alias en lecture, dans un ticket à part.

## Réalisation (PR back #247)

Livré comme décrit ci-dessus, avec les écarts listés plus bas. **Rien n'a été exécuté en production**, aucun schéma ni aucune donnée ne change, aucune migration. **Le contrat JSON ne change pas** : `docs/api/openapi.json` ne diffère que par des descriptions (aucune clé, aucun type, aucune valeur d'énumération retirés ; `make openapi-check` vert).

**Retiré**

- `IngredientVisibility::visibleBy` n'est plus que « `creator` = moi » (plus de `isCommon` DQL, de `slugsAdoptedBy`, de `NOT IN`). Appliquée par le Loader (rayon, recherche, disponibles), les dépôts et `IngredientMatchCandidatesPostgreSQLRepository`.
- `$viewerIsAdmin` : port `IngredientLoader`, `GetIngredientQuery`, `ListIngredientsByDepartmentQuery`, `SearchIngredientsQuery`, leurs handlers et les trois controllers ; `AuthenticatedUser::isAdmin()` (plus personne ne l'appelle). `canEdit` est écrit `true` (le champ reste). `isBase` / `canSetBase` : propriétaire du catalogue, lu une fois.
- `OwnerVoter` : plus de passe-droit `ROLE_ADMIN` pour `INGREDIENT_OWNER` seulement (recettes, listes, repas et lignes : inchangé).
- `IngredientSlugResolver` : `resolve(userId, slugs)` seulement, ne dépend plus que des alias ; plus d'adoption à la volée ni d'adoptant. `IngredientRepository::findCommonSlugs`, `IngredientAliasRepository::lookup`, `AliasLookup` (modèle supprimé).
- `Ingredient::isCommon`, `IngredientCandidate::isCommon` (et son argument `creatorId`), `MergeParty::isCommon`, `MergeBlock::COMMON_INGREDIENT`, `IngredientException::cannotMergeCommon` / `CANNOT_MERGE_COMMON_INGREDIENT`, et les branches de `IngredientMergePlanner`, `IngredientMergePostgreSQLLoader`, `MergeIngredientsHandler`, `DuplicateIngredientFinder`.
- `Recipe::assertIngredientIsAvailable` : l'ingrédient est à l'auteur, sinon `404`.
- Copie de recette : `CopyRecipeHandler` n'a plus de branche « commun » ni d'adoption à la volée. Étape 0 : clé d'alias = `adopted_from` de l'ingrédient source, sinon son slug, résolue par les alias du copieur ; sinon l'ingrédient du copieur de même nom ; sinon une copie. **Toute copie retient son origine** (`adopted_from` = celle de l'original, sinon son slug, `Ingredient::copyFromOriginal`, D7) et `RecipesOrIngredientsCreatedEvent` ne porte plus aucun ingrédient pour une copie de recette (jamais comptée comme une création).

**Conversions (D4)** : `ConversionLoader::list(viewerId)` et `::get(id, viewerId)` ne rendent que les conversions générales et celles des ingrédients du lecteur (`GET /conversions/{id}` d'un autre : `404`). `CreateConversionCommand` et `UpdateConversionCommand` portent `userId` (le jeton) ; leurs handlers refusent avec `404` un ingrédient qui n'est pas à lui, et `UpdateConversionHandler` refuse aussi une conversion dont l'ingrédient est à un autre. Les routes restent `ROLE_ADMIN`. `CreateIngredientWithConversionEvent` gagne `userId` (3e argument), parce que `CreateConversionEventListener` dispatche une `CreateConversionCommand` imbriquée qui en a besoin : sans cela, créer un ingrédient avec une conversion donnait `500`.

**Ce qui change pour un administrateur qui n'est pas le propriétaire (D3)** : plus aucun passe-droit sur les ingrédients. `GET /ingredients/{slug}` d'un autre : `404` ; `PUT` et `DELETE` : `403` (voter) ; fusion, doublons, « Pas le même » : les siens seulement ; écran des conversions : générales + celles de ses ingrédients. Le propriétaire du catalogue est dans le même cas pour les ingrédients des autres.

**Gardé exprès pour ING-014** (le ticket le dit, ou les commandes d'exploitation de la migration en dépendent) : `IngredientVisibility::isCommonSql` / `isCatalogSql` / `isBaseCatalogSql` (le « ou vide » de l'amorçage, de la passation et de `adopt-commons`) ; les commandes `adopt-commons`, `seed`, `hand-over-catalog` et leurs tests ; `Command\AdoptCommonIngredient\*`, `Ingredient::adopt`, `Ingredient::create(?string)`, `getCreatorId(): ?string`, `IngredientException::notCommon`, `IngredientAliasRepository::isAdopter` (l'amorçage s'en sert pour « déjà amorcé ») ; la garde `getCreatorId() !== null` de `RemoveIngredientHandler` ; `OwnershipLoader::ingredientCreatorId(): ?string` ; les fixtures.

**Compatibilité (ADR 0017 règle 6)** : le code lit la base d'après la passation (aucun créateur vide). Il ne fonctionne **pas** sur une base d'avant : le propriétaire ne verrait plus les anciens communs. C'est pourquoi ce ticket ne se fusionne qu'après la passation, et que la migration-garde d'ING-014 doit refuser le démarrage tant qu'un ingrédient sans créateur existe. Ce que le code écrit est lu par le code précédent : aucune forme de donnée nouvelle (alias, `adopted_from`, conversions inchangés).

**Écarts avec le ticket**

- **Le contrat garde `COMMON_INGREDIENT`** : le ticket dit que les codes retirés disparaissent de la documentation ; la consigne de ne casser aucun client en cache l'emporte. `blockedBy` garde la valeur `COMMON_INGREDIENT` dans l'énumération OpenAPI et le `422` garde la mention du code, avec « n'est plus renvoyé » dans la description ; le code, lui, ne la produit plus.
- **Budgets : trois scénarios montent d'une ou deux requêtes** (le ticket exigeait qu'aucun ne monte). `copier-recette-ingredient-personnel` 19 → 20 et `copier-recette-ingredient-rapproche` 15 → 16 : l'étape 0 lit désormais **toujours** les alias du copieur (la clé est le slug de la source quand elle n'a pas d'`adopted_from`, comme le ticket le prescrit), alors qu'avant elle ne les lisait que pour un commun ou une adoption. `copier-recette` est redéfini : le copieur est maintenant un compte amorcé (copies et alias des cinq ingrédients), 17 → **19** ; l'ancien cas « le copieur n'a rien » devient `copier-recette-sans-ingredients` (**40**, tout est créé). Les scénarios `ajouter-ingredient-liste-adoptant` (25) et `copier-recette-adoptant` (22) sont retirés avec l'adoption à la volée. Les autres budgets ne bougent pas (mesurés) : la lecture d'alias reste une requête (`lookup` devient `resolve`). Aucune économie qui compense n'a été trouvée sans s'écarter de la règle de provenance du ticket ; à valider par le relecteur.
- **Les fixtures de développement** (`IngredientFixture`, `ConversionFixture`, `RecipeFixture`, `Demo*Fixture`) créent encore des ingrédients sans créateur, que la visibilité ne montre plus à personne : ING-014 les réécrit ; en attendant, `make fixtures-load-dev` ne donne plus d'ingrédient visible.
- `HandOverCatalogNonRegressionTest` ne compare plus « avant / pendant / après la passation » (le code d'après ne marche que sur la base d'après) : il prouve l'après (chaque compte voit et fait tout avec ses ingrédients seulement) et l'amorçage d'un compte neuf.
- `IngredientBuilder` : un ingrédient de test naît chez un utilisateur de test (`DEFAULT_CREATOR_ID`), appliqué à la construction seulement pour ne pas changer son slug ; `withoutCreator()` ne sert plus qu'aux tests des commandes d'exploitation.

**Preuves** : `IngredientVisibilityTest` (une seule définition, tous les chargeurs et dépôts comparés), `IngredientPostgreSQLLoaderTest`, `ConversionPostgreSQLLoaderTest`, `OwnerVoterTest` (D3), `IngredientVisibilityApiFlowTest` (vrais bus, JWT : compte neuf, compte avec copies, propriétaire, **autre administrateur**), `RecipeCopyApiFlowTest` (copie, planification, envoi au drive), `ConversionAdminApiFlowTest` (D4 de bout en bout), `CopyRecipeHandlerTest`, `IngredientSlugResolverTest`, `HandOverCatalogNonRegressionTest` ; les tests du drive (`tests/Drive`) n'ont pas été modifiés et passent : le plan, l'estimation et les choix sont inchangés. Suite complète (3987 tests), PHPStan, Deptrac (7 configurations), `api-doc-validate`, `api-doc-coverage` et `openapi-check` verts.

**Points d'attention**

- **ING-014** : la migration-garde ; la colonne `creator` `NOT NULL` ; le reste du code mort listé ci-dessus ; `NoSharedIngredientTest` ; les fixtures ; les bandeaux de la documentation. `Ingredient::getCreatorId()` pourra devenir `string`.
- **ING-015 / front** : `canEdit` est toujours vrai (le champ reste) ; `creatorId` n'est plus jamais vide ; `blockedBy: COMMON_INGREDIENT` n'est plus renvoyé ; l'écran des conversions de l'administrateur est plus court (générales + les siennes) ; la fiche d'un ancien slug répond `404` (le rayon se recharge).

**Non vérifié** : le comportement sur une base d'avant la passation (voulu : refusé par ING-014) ; le retour arrière du code précédent après ce ticket n'a pas été rejoué (il relit ce que ce code écrit, aucune forme de donnée n'a changé).
