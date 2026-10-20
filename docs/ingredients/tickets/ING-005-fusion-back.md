# ING-005 — Fusionner deux ingrédients : aperçu et écriture (back)

- **Dépôt** : back
- **Jalon** : I4 Fusion
- **Taille** : L
- **Effort recommandé pour l'agent** : **xhigh** (réécrit des références dans quatre tables et le contexte `Drive`, droits, listes envoyées)
- **Dépend de** : ING-001 (alias) ; ING-002 recommandé (la résolution des alias `merge`)
- **Statut** : fait (PR back #PR)
- **Lire d'abord** : **[`fusion.md`](../fusion.md) §§ 1 à 7 et 10, 11** ; [`analyse.md`](../analyse.md) §§ 4, 7, 11 ; [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décision g ; ADR 0016 (versions), 0023 (liste `SENT`), 0021 (purge) ; skills `/repas-dev`, `/repas-drive-back`.

## Contexte

L'utilisateur doit pouvoir fusionner deux de ses ingrédients (une copie faite par erreur, un doublon) : toutes ses recettes, listes et choix du drive retombent sur un seul. Ce ticket livre la logique et l'API ; l'écran est ING-007.

## Portée

1. **Cas d'usage `MergeIngredients`** (`Application/Command/MergeIngredients/`, `MergeIngredientsCommand(userId, absorbedSlug, keptSlug)` + `MergeIngredientsHandler`) : règles 1 à 15 de `fusion.md` § 2, étapes 0 à 9 de § 3, rejeu idempotent de § 6. Le handler vérifie lui-même `creator = userId` pour les deux ingrédients (**pas de passe-droit `ROLE_ADMIN`**). Un domaine : `Ingredient::assertOwnedBy(userId)`, `IngredientException::{cannotMergeIntoItself, cannotMergeCommon, mergeUnitMissing, inSentList, usedByAnotherUser}` (factories statiques nommées, messages = codes).
2. **Service de domaine `IngredientMergePlanner`** (pur) : à partir des unités utilisées par `B` et du graphe de conversion de `A` (`ConversionService`), calcule `missingUnits`, le facteur `f` de conversion des listes et le drapeau `listQuantitiesConverted` ([`fusion.md`](../fusion.md) § 5). Utilisé par l'aperçu et par l'écriture.
3. **Port d'écriture** `Domain/Interface/IngredientMergeRepository` (implémentation SQL en masse, nombre de requêtes **constant** ; verrou `SELECT … FOR UPDATE` des deux ingrédients) : `lockBoth`, `usageOf(slug)` (recettes, listes, choix, listes `SENT`), `hasRowsOfAnotherUser`, `repoint(...)` qui fait les étapes 1 à 8 de `fusion.md` § 3 (fusion des lignes de liste avec `checked = A ET B`, `ceil` des compteurs, choix du drive « A gagne »), versions `+1` par `UPDATE … RETURNING`.
4. **Lecture** `GetMergePreview` (Query + QueryHandler + `IngredientMergeLoader`, port `Application/Loader`) : l'aperçu JSON de `fusion.md` § 7 (compte des recettes, listes, choix, `departmentChanges`, `listQuantitiesConverted`, `missingUnits`, `blockedBy`, `canMerge`) ; `ListMergeCandidates` : les candidats de `fusion.md` § 7 (même rayon d'abord, classés par `IngredientNameMatcher::similar`, ING-004 ; **si ING-004 n'est pas livré, classement alphabétique** avec la même forme de réponse et un `veryClose` à `false`).
5. **Controllers** (`Infrastructure/Http/Api/{MergeIngredients,GetMergePreview,ListMergeCandidates}/`) : `POST /api/v1/ingredients/{slug}/merge` (`204`, `#[Payload] {into}`), `GET …/merge-preview?into=`, `GET …/merge-candidates?q=` ; `#[IsGranted('ROLE_USER')]` seulement (le contrôle de propriété est métier, [`fusion.md`](../fusion.md) § 10) ; `#[OA\…]` complets, codes de `fusion.md` § 7 ; `make openapi` et relecture du diff.
6. **Alias `merge`** : écriture de `B -> A`, repointage des alias qui désignaient `B`, rejeu idempotent (`204`), nettoyage à la création d'un ingrédient (déjà en ING-001).
7. **Statistiques** : `IngredientRemovedEvent` si `B` n'est pas une adoption. **Purge de compte** : rien de plus (les alias le sont déjà, ING-001).
8. **Contrat** : `IngredientDTO` de la fiche (`GetIngredient`) gagne `recipeCount` et `listCount` (pour la ligne « Utilisé dans 7 recettes · 2 listes » de la fiche, ING-007).

## Hors portée

Les doublons proposés et « Pas le même » (ING-006) ; l'écran (ING-007) ; reporter les conversions de `B` sur `A` ; fusionner des communs ou des ingrédients d'utilisateurs différents ; une limite de débit.

## Critères d'acceptation

(Chaque critère existe en test, base réelle pour les écritures.)

- **Cas nominal.** `A` « Tomate » (12 recettes, 3 listes), `B` « Tomates » (7 recettes, 2 listes), même rayon et unités : après la fusion, `B` n'existe plus, toutes les lignes de `recipe_row`, `shopping_list_row`, `shopping_list_ingredient` qui étaient sur `B` sont sur `A`, les versions des 7 recettes et des 2 listes montent de 1 et aucune autre, les quantités sont inchangées.
- **Recette qui contient les deux** : elle garde **deux lignes** sur `A`. **Liste qui contient les deux** : une ligne `A` avec la somme, `checked` seulement si les deux l'étaient ; une liste qui n'a que `B` : la ligne devient `A`, `checked` inchangé.
- **Unités d'achat différentes** : `B` en « kilo », `A` en « gramme » (conversion connue) -> quantités de liste converties, `listQuantitiesConverted: true`, compteurs en `ceil`.
- **Unité manquante** : une ligne de recette de `B` en « tranche » que `A` ne sait pas convertir -> `GET …/merge-preview` : `canMerge: false`, `missingUnits: [tranche]`, `POST` : `422 MERGE_UNIT_MISSING` et **aucune ligne modifiée** (comptage).
- **Liste `SENT`** contenant `A` ou `B` -> `409 INGREDIENT_IN_SENT_LIST`, rien modifié ; après `reopen`, la fusion passe.
- **Choix du drive.** `A` et `B` ont chacun un choix pour le même magasin : celui de `A` reste, celui de `B` et ses produits sont supprimés ; magasin où seul `B` a un choix : le choix passe sur `A` avec ses produits et leurs `pack_size_*` (DRV-652) ; si l'unité d'achat de `B` diffère de celle de `A`, la contenance reste en base mais le plan n'en tient pas compte (unité différente de celle de la ligne) ; un second magasin/enseigne est traité séparément. `GetDrivePlan` relu donne les produits de `A`.
- **Rayons différents** : le rayon de `A` l'emporte, `departmentChanges: true`.
- **Sécurité.** `B` à un autre utilisateur -> `404` ; `B` commun -> `422 CANNOT_MERGE_COMMON_INGREDIENT` ; `A = B` -> `422 CANNOT_MERGE_INTO_ITSELF` ; **un administrateur** qui tente les ingrédients d'un autre -> `404` ; une ligne héritée d'un autre utilisateur sur `B` -> `409 INGREDIENT_USED_BY_ANOTHER_USER`.
- **Idempotence.** Un second `POST` identique -> `204` sans rien changer ; `POST` vers un autre ingrédient que celui de l'alias -> `404`.
- **Alias.** Un alias d'adoption qui désignait `B` désigne `A` ; une requête qui envoie encore le slug `B` (recette, liste, choix du drive) atterrit sur `A` (via ING-002).
- **Statistiques.** Fusionner un ingrédient créé par l'utilisateur décrémente `statistics.ingredients` ; fusionner une copie d'adoption ne le fait pas.
- **Budget** `fusionner-ingredients` figé, **indépendant du volume** (même budget avec 2 ou 20 recettes, mesuré).
- **Concurrence.** Une écriture de recette qui a lu la version avant la fusion reçoit `409`/`412` au lieu de recréer une ligne sur `B` supprimé.

## Tests exigés

`IngredientMergePlannerTest` (table des unités) ; `MergeIngredientsHandlerTest` en mémoire (tous les critères) ; `IngredientMergeRepositoryTest` base réelle avec données de tous types (recette double, liste double, `SENT`, plusieurs magasins et enseignes, produits mémorisés, donnée d'un autre utilisateur, alias) ; `GetMergePreviewQueryHandlerTest`, `…LoaderTest` ; trois controllers (`MockCommandBus`/`MockQueryBus`, clés JSON, tous les statuts) ; `UserOwnedDataCoverageTest` inchangé ; `WriteBudget` ; parcours complet avec les vrais bus (créer, planifier, fusionner, relire recette et liste, `412` d'un ancien `If-Match`). `make test quality openapi-check`.

## Definition of Done

`repas-dev` § 4 ; le contrat `docs/api/openapi.json` régénéré et relu fait foi pour le front (ING-007).

## Pièges

- **Ne rien faire via `RemoveIngredient`** (il refuse un ingrédient utilisé et émet des événements, ADR 0021 décision 9) : suppression en masse dédiée.
- Une fusion de lignes de liste est une **écriture hors de `ShoppingList::assertEditable`** : les listes `SENT` sont exclues **avant** (règle 8), les autres statuts (pause, terminée) sont modifiables.
- Les compteurs `shopping_list_ingredient` sont des entiers de pas : `ceil` et non `round`, pour ne pas faire disparaître une unité.
- Les `UPDATE` en masse ne passent pas par Doctrine : `clear()` si le test charge un agrégat avant et après.
- Ne pas laisser un ingrédient « fantôme » : toute table qui référence un ingrédient doit être dans la liste de `fusion.md` § 3 ; si une nouvelle table apparaît (`grep ingredient` dans les entités), l'ajouter et un test.

## Réalisation (PR back #PR)

Livré comme décrit ci-dessus, avec les écarts et ajouts suivants, tous décidés pour tenir l'intention du ticket et de l'ADR 0028. **Aucune migration de schéma** (ni table, ni colonne : les alias `merge` et la source `merge` tiennent dans `ingredient_alias` d'ING-001) ; **rien n'a été exécuté en production**.

**Les routes et les contrats** (`docs/api/openapi.json` fait foi, ajouts seulement ; clés toujours écrites, `blockedBy` nullable explicite) :

- `POST /api/v1/ingredients/{slug}/merge`, corps `{"into": "<slug>"}` (obligatoire, 255 caractères au plus, `422` sinon), `204`. `{slug}` est l'ingrédient **absorbé**, `into` le **gardé**. Rejeu après un succès : `204`, rien ne change.
- `GET /api/v1/ingredients/{slug}/merge-preview?into=<slug>` (`into` obligatoire, `422` sinon) : `200` avec `keep`, `absorbed` (`slug`, `name`, `departmentSlug`, `departmentName`, `purchaseUnitName`), `recipeCount`, `listCount`, `driveChoiceCount`, `departmentChanges`, `listQuantitiesConverted`, `missingUnits` (`[{slug, name}]`), `blockedBy`, `canMerge`. `blockedBy` : `null`, ou le premier de `SAME_INGREDIENT`, `COMMON_INGREDIENT`, `USED_BY_ANOTHER_USER`, `IN_SENT_LIST`, `UNIT_MISSING`.
- `GET /api/v1/ingredients/{slug}/merge-candidates?q=` : `{member, totalItems}`, chaque membre `slug`, `name`, `departmentSlug`, `departmentName`, `purchaseUnitName`, `recipeCount`, `listCount`, `sameDepartment`, `veryClose` ; 30 au plus, même rayon d'abord.
- `GET /api/v1/ingredients/{slug}` (la fiche, `IngredientDTO`) gagne `recipeCount` et `listCount` : les recettes et les listes **du lecteur** qui utilisent l'ingrédient (0 s'il n'en a aucune, jamais `null`).
- Codes d'erreur de l'écriture (message = code, ADR 0007) : `404` ingrédient inconnu ou à un autre utilisateur (administrateur compris) ; `422` `CANNOT_MERGE_INTO_ITSELF`, `CANNOT_MERGE_COMMON_INGREDIENT`, `MERGE_UNIT_MISSING` ; `409` `INGREDIENT_IN_SENT_LIST`, `INGREDIENT_USED_BY_ANOTHER_USER`, `INGREDIENT_MERGE_CONFLICT`.

**Les classes** : `Ingredient::isCommon/isOwnedBy/assertOwnedBy` ; `IngredientException::{cannotMergeIntoItself, cannotMergeCommon, mergeUnitMissing, inSentList, usedByAnotherUser, mergeConflict}` ; l'enum `MergeBlock` (qui sait son exception) ; le service de domaine **`IngredientMergePlanner`** (pur : `MergeParty`, `ConversionGraph`, `IngredientMergeUsage` -> `IngredientMergePlan`) ; `ConversionGraph` (le parcours en largeur de `ConversionService`, extrait sans changer son comportement, `ConversionService::graphOf()`) ; le port `IngredientMergeRepository` (`lock`, `usageOf`, `repoint`) et `IngredientMergePostgreSQLRepository` ; `IngredientMergeUsageReader` (le SQL des nombres, partagé par l'écriture et l'aperçu, comme `AdoptionSnapshotReader`) ; `MergeIngredients` (Command + Handler) ; `GetMergePreview` et `ListMergeCandidates` (Query + QueryHandler + DTO) avec le port `IngredientMergeLoader` ; `IngredientAliasRepository::mergeInto` ; côté `Shared` : `DriveMergeImpact` et `DriveIngredientReferences::{mergeImpact, merge}`, implémentés par `Drive`.

**Écarts avec le ticket** :

1. **L'aperçu n'hydrate aucun modèle de domaine** (CLAUDE.md : un Loader ne renvoie pas de modèle). Le ticket demandait un `IngredientMergePlanner` « utilisé par l'aperçu et par l'écriture » : il travaille donc sur des **slugs et des booléens** (`MergeParty`, `ConversionGraph`), que l'écriture tire de ses modèles et que l'aperçu tire de SQL (mêmes nombres : `IngredientMergeUsageReader` ; même graphe : `conversion` propre ou commune). **`MergePreviewMatchesTheMergeTest`** prouve l'égalité sur 13 situations (dont chaque refus) avec les vrais bus : si l'aperçu autorise, la fusion réussit et les nombres annoncés sont ceux de la base d'après ; s'il refuse, la fusion lève l'erreur de ce cas et aucune table n'a changé.
2. **`IngredientMergeRepository` n'a que `lock`, `usageOf` et `repoint`** (le ticket nommait `lockBoth`, `hasRowsOfAnotherUser`) : « une ligne d'un autre » est un champ de `IngredientMergeUsage`, lu avec le reste.
3. **`USED_BY_ANOTHER_USER` est une valeur de `blockedBy`** (le cahier n'en listait que quatre), pour que l'aperçu dise exactement ce que l'écriture ferait ; elle compte aussi les **choix du drive** d'un autre utilisateur sur l'absorbé. **`INGREDIENT_MERGE_CONFLICT`** (`409`) est nouveau : une référence à l'absorbé apparue ou restée pendant l'écriture (écriture concurrente, unité apparue entre l'analyse et le repointage) annule la transaction.
4. **Les unités des listes sont traitées par couple (départ, arrivée)**, pas seulement `P(B) -> P(A)` : une ligne de `B` va dans l'unité de la ligne de `A` de la même liste (la première par identifiant) et, à défaut, dans l'unité d'achat de `A` ; les ajouts à la main vont dans l'unité d'achat de `A` (c'est la clé que `AddIngredientToShoppingList` utilise). Une ligne dont l'unité n'est plus l'unité d'achat (modifiée depuis) est donc bien ré-exprimée. Les compteurs sont arrondis au pas supérieur avec la tolérance de 1e-9 du plan d'envoi et ne descendent jamais sous 1.
5. **Les alias déplacés prennent la source `merge`.** Un alias d'adoption `C -> B` devient `C -> A` (il reste `adoption` : `C` reste masqué, l'utilisateur reste « adoptant ») ; sa source passe à `merge`, ce qui le sort d'un `--undo` de la migration (ING-003) et rend `--check` indulgent : l'exigence « la copie vient de ce commun » ne vaut plus pour lui (`IngredientAdoptionReportPostgreSQLLoader`, SQL de contrôle de `migration.md` § 13, **`AdoptionAfterMergeTest`** : la migration, déjà exécutée en production, reste juste après des fusions).
6. **`GET …/merge-candidates` d'un commun : `422 CANNOT_MERGE_COMMON_INGREDIENT`** ; `veryClose` se juge sur le nom seul, quel que soit le rayon.
7. **`ingredient_distinct` n'existe pas encore** (ING-006) : l'étape 6 de `fusion.md` § 3 et le nettoyage de `RemoveIngredientHandler` s'ajoutent avec elle.
8. **Pas de limite de débit** (`fusion.md` § 10 : aucune règle n'existe hors du drive).
9. Un administrateur crée des **communs** : il n'a aucun ingrédient personnel, la fusion ne le concerne donc pas (`422` s'il essaie les siens, `404` pour ceux d'un autre) ; `OwnerVoter` n'est pas utilisé sur ces routes.
10. `MockCommandBus::willThrow()` (aide de test) pour les codes d'erreur d'un controller d'écriture ; `MergeDataset` (aide de test) pour les jeux de données de la fusion.

**Preuve par empreinte** (`MergeFingerprintTest`, jeu de données riche, oracle calculé indépendamment du code) : avant/après, le contenu vu par **nom, rayon, quantité, unité, coché** de chaque recette et de chaque liste est celui que la règle annonce (« Tomates » s'appelle « Tomate », une ligne de liste par ingrédient, quantités sommées, cochée seulement si toutes l'étaient) ; les listes de tous les statuts gardent statut et date d'envoi ; les versions montent de 1 pour les seules recettes et listes concernées ; les produits mémorisés du drive (contenances, recherches) sont intacts ; les recettes, listes et choix d'un autre utilisateur ont la même empreinte. `MergeDriveEquivalenceTest` : le plan d'envoi et l'estimation d'une liste sont identiques à l'ingrédient près (slug, nom) avant et après ; quand le gardé a son choix c'est lui qui sert le plan ; sans choix la ligne reste « nouvelle ». `IngredientReferencesAreAllRepointedTest` : le slug de l'absorbé ne reste dans **aucune** colonne de la base, hors la clé de son alias. `IngredientMergeApiFlowTest` (vrais bus et JWT) : créer, planifier, fusionner, relire, retirer et replanifier un repas, `412` d'un ancien `If-Match`, un ancien slug dans une écriture de recette, de liste ou du drive, rejeu, liste `SENT` puis `reopen`.

**Budget SQL** : nouveau scénario **`fusionner-ingredients` = 21 requêtes**, mesuré **indépendant du volume** (2, 8 ou 40 recettes, 2, 6 ou 30 listes : `MergeIngredientsBudgetTest`) ; un essai à 2 000 recettes et 800 listes : 21 requêtes, 0,5 s. Aucun autre budget ne bouge (aucune table de plus, `supprimer-compte` inchangé).

**Compatibilité avec le code précédent (ADR 0017 règle 6)** : aucun changement de schéma. Ce que la fusion écrit (des lignes déplacées, des alias `merge`, un ingrédient et ses conversions supprimés) est lu tel quel par le code d'avant : il ignore les alias, résout les slugs déjà repointés et ne connaît pas l'ingrédient supprimé. Le retour arrière du code ne défait pas une fusion faite : l'ingrédient absorbé n'existe plus, ses références sont sur le gardé.

**Points d'attention** :

- **ING-006** : ajouter à `MergeIngredientsHandler` (et à `RemoveIngredientHandler`) le nettoyage des lignes de `ingredient_distinct` qui nomment l'absorbé ; la liste des doublons peut réutiliser `IngredientMergeUsageReader::counts()` (recettes et listes par ingrédient, en une requête pour tous) et le tri de `IngredientMergePostgreSQLLoader::candidates()` ; « Fusionner » d'une proposition appelle `merge-preview` puis `merge`.
- **ING-007** (front) : lire `docs/api/openapi.json`. Tolérer un `blockedBy` inconnu (refus générique) ; afficher « Utilisé dans N recettes · M listes » depuis `recipeCount`/`listCount` de la fiche ; un ingrédient commun n'a pas de bouton « Fusionner » (`GET …/merge-candidates` d'un commun répond `422`) ; après une fusion, invalider les ingrédients, recettes, listes et choix du drive (les versions des recettes et listes concernées ont monté : un `ETag` gardé répond `412`) ; un ancien slug de l'absorbé reste accepté par les écritures de recette, de liste et du drive (alias `merge`).
- Une **liste envoyée** bloque (`IN_SENT_LIST`) même si elle n'utilise que le **gardé** : le plan d'une liste envoyée changerait si le gardé recevait le choix de l'absorbé.
- **Non vérifié** : deux fusions réellement simultanées sur deux sessions PostgreSQL (la sérialisation repose sur `SELECT … FOR UPDATE` dans l'ordre alphabétique des slugs ; les tests tournent dans une transaction unique) ; une création de recette qui utiliserait l'absorbé exactement pendant la fusion peut laisser une référence que le filet de fin de `repoint()` ne voit pas si elle est écrite après lui (même fenêtre qu'aujourd'hui entre la suppression d'un ingrédient et la création d'une recette, quelques millisecondes pour un seul utilisateur).
