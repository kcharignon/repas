# Fusionner deux ingrédients

> **Amendement du 2026-10-20 : plus d'ingrédient commun** ([`sans-commun.md`](sans-commun.md)). Après la passation, la règle 2 (« jamais un commun », `CANNOT_MERGE_COMMON_INGREDIENT`, `blockedBy: COMMON_INGREDIENT`) n'a plus d'objet et l'administrateur fusionne **ses** ingrédients comme tout le monde (ING-013). Un front qui tolère un `blockedBy` inconnu n'a rien à changer.

> **Amendement du 2026-10-21 : l'aperçu annonce le TOTAL après la fusion, et `A` RÉCUPÈRE les conversions de `B`** (retour de l'utilisateur sur ses données réelles, fusion de deux « Melon » utilisés chacun dans une recette : « le Melon restant sera utilisé dans DEUX recettes, le Melon supprimé sera remplacé par celui qu'on garde, et s'il y a des conversions qui manquent sur celui qu'on garde, on les récupère de celui qui disparaît »). Deux changements : (1) `merge-preview` gagne `resultRecipeCount`, `resultListCount` (le total d'après, l'union sans doublon) et `recoveredConversionCount` ([§ 7](#7-lapi)) ; (2) les conversions propres de `B` que `A` n'a pas sont copiées sur `A`, **`UNIT_MISSING` ne reste que pour une vraie impossibilité** (une unité sans chemin de conversion même après la récupération) : règles 5 et 7, [§ 3](#3-ce-qui-est-modifié-table-par-table) étape 0b, [§ 5](#5-les-unités-laperçu-les-vérifie-avant-le-geste), textes de [§ 9.3](#93-la-confirmation-commune-feuille-du-bas). ADR 0028, [amendement du 2026-10-21](../adr/0028-ingredients-propres-a-chaque-utilisateur.md#amendement-du-2026-10-21--la-fusion-récupère-les-conversions-de-labsorbé-et-annonce-le-total).

Spécification du back (tickets [ING-005](tickets/ING-005-fusion-back.md) et [ING-006](tickets/ING-006-doublons-possibles.md)) et de l'interface ([ING-007](tickets/ING-007-front-fusion-et-doublons.md)). Décisions : [ADR 0028](../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décisions g et h. Inventaire des endroits à repointer : [`analyse.md`](analyse.md). Le rapprochement qui sert aussi aux propositions : [`rapprochement.md`](rapprochement.md).

## 1. Le geste

Un utilisateur a deux ingrédients qui sont en fait le même (« Tomate » et « Tomates », une copie faite par erreur). Il en **garde un** (`A`) et fait **disparaître l'autre** (`B`, « absorbé ») : toutes ses recettes, ses listes de courses et ses choix du drive qui utilisaient `B` utilisent désormais `A`, et `B` est supprimé.

Mots de l'interface : **ingrédient**, **recette**, **liste de courses** (le mot « article » est réservé à la ligne d'une liste, [principes produit](../drive/principes-produit.md) § 5), **Fusionner**.

## 2. Règles métier

| # | Règle |
|---|---|
| 1 | **Même utilisateur seulement.** Les deux ingrédients ont `creator = l'utilisateur connecté`. Aucun autre cas. |
| 2 | **Jamais un commun** (`creator` vide) : `422 CANNOT_MERGE_COMMON_INGREDIENT`. Depuis ING-012 un administrateur crée des ingrédients à lui comme tout le monde, et la fusion le concerne pour ceux-là ; il n'a encore aucun ingrédient personnel tant que la passation des anciens communs n'a pas eu lieu. |
| 3 | **Jamais l'ingrédient d'un autre** : `404`, comme un ingrédient inconnu (on ne révèle pas son existence ; même règle que `findPurchaseUnitSlugUsableBy`). **L'exception administrateur de `OwnerVoter` ne s'applique pas** : le contrôle `creator = utilisateur du jeton` est fait par le cas d'usage lui-même, `ROLE_ADMIN` ou non. |
| 4 | `A` et `B` doivent être **différents** : `422 CANNOT_MERGE_INTO_ITSELF`. |
| 5 | **`A` garde tout** : nom, image, rayon, unités par défaut, unités compatibles, conversions propres, `adopted_from`. `B` ne lègue que ce qui manque à `A` : **ses conversions propres** dont les deux unités ne sont pas déjà reliées chez `A`, et ses unités compatibles utilisées par les lignes de recette déplacées (§ 3 étape 0b). **En cas de conflit (même paire d'unités, coefficients différents) celle de `A` gagne** : on n'écrase jamais ce que `A` sait. |
| 6 | **Le rayon peut différer** : le rayon de `A` l'emporte ; la confirmation le dit. |
| 7 | **Les unités doivent suivre**, une fois les conversions de `B` récupérées (§ 5), sinon **la fusion s'arrête et rien ne bouge** : `422 MERGE_UNIT_MISSING`. C'est une vraie impossibilité : ni `A` ni `B` ne savent convertir cette unité. |
| 8 | **Les listes envoyées au drive (`SENT`) bloquent la fusion** : si `A` ou `B` figure dans une liste `SENT`, `409 INGREDIENT_IN_SENT_LIST`. Raison : une liste envoyée est immuable et sert de plan à un envoi peut-être en cours ([ADR 0023](../adr/0023-liste-envoyee-au-drive.md)) ; la migration de données est l'unique exception, justifiée ([`migration.md`](migration.md) § 4). L'utilisateur rouvre (`reopen`) ou termine la liste, puis fusionne. |
| 9 | **Choix du drive** : pour chaque magasin (enseigne + magasin) où les deux ont un choix, **celui de `A` gagne** et celui de `B` est supprimé avec ses produits mémorisés ; là où seul `B` en a un, il passe sur `A`. Rien n'est mélangé. |
| 10 | **Versions** : chaque recette et chaque liste modifiée monte de 1 ([ADR 0016](../adr/0016-controle-de-version-etag-if-match.md)) ; un client qui avait lu l'ancienne version reçoit `412` à sa prochaine écriture versionnée. |
| 11 | **Une transaction** (`command.bus`) : tout ou rien. |
| 12 | **Idempotente** (§ 6). |
| 13 | **Statistiques** : supprimer un ingrédient *créé* par l'utilisateur décrémente son compteur ; supprimer une copie d'adoption (`adopted_from` renseigné) ne le touche pas. |
| 14 | **Les alias suivent** : tout alias qui désignait `B` désigne `A` ; un alias `B -> A` (type `merge`) est ajouté ; les écritures qui recevraient encore l'ancien slug (onglet ouvert, brouillon d'envoi sur le téléphone) retombent sur `A`. |
| 15 | **Pas de propagation aux autres** : les copies de recettes faites par d'autres utilisateurs ont leurs propres ingrédients ; une ligne d'un autre utilisateur qui référencerait `B` (donnée héritée) interrompt la fusion : `409 INGREDIENT_USED_BY_ANOTHER_USER`. |

## 3. Ce qui est modifié, table par table

Dans l'ordre, en une transaction, avec un nombre de requêtes **constant** (indépendant du nombre de recettes et de listes ; budget SQL du scénario `fusionner-ingredients` figé par le cliquet, ADR 0010 règle 12) :

| Étape | Table | Opération |
|---|---|---|
| 0 | `ingredient` | `SELECT … FOR UPDATE` des deux lignes, ordre alphabétique des slugs (deux fusions concurrentes se sérialisent ; la seconde trouve `B` supprimé et se reconnaît comme un rejeu, § 6) |
| 0b | `conversion`, `ingredient` | **récupération** (avant tout repointage, **une seule requête** `INSERT … SELECT` quel que soit le nombre de conversions, `IngredientMergeRepository::recoverConversions`) : chaque conversion propre de `B` dont les deux unités ne sont pas encore reliées dans le graphe de `A` (ses conversions propres, les conversions communes, et celles déjà récupérées) est copiée sur `A` (nouvel identifiant, unités et coefficient recopiés par la base) ; puis les unités compatibles de `B` que les lignes de recette déplacées utilisent et que `A` n'avait pas s'ajoutent aux siennes (`UPDATE ingredient`, une requête, seulement s'il y en a). Rien n'est écrit si la fusion est refusée |
| 1 | `recipe`, `recipe_row` | versions de toutes les recettes contenant `B` : `+1` ; `UPDATE recipe_row SET ingredient = A WHERE ingredient = B` (la **recette** garde ses deux lignes si elle contenait `A` et `B` : pas de cumul, l'auteur les a écrites) |
| 2 | `shopping_list`, `shopping_list_row` | pour chaque liste contenant `B` : si elle contient aussi `A`, la ligne `A` reçoit `qté(A) + qté(B) × f` et `checked = checked(A) ET checked(B)` (**décochée si l'une des deux l'est** : mieux vaut racheter que oublier), la ligne `B` est supprimée ; sinon la ligne `B` devient une ligne `A` : `quantité × f`, unité d'achat de `A`, `checked` inchangé. `f` = facteur de conversion de l'unité de la ligne de `B` vers l'unité où elle ira : celle de la ligne `A` de la même liste (la première, par identifiant), à défaut l'unité d'achat de `A` (1 si elles sont égales). Versions `+1`, une seule fois par liste même si elle a une ligne ET un ajout à la main de `B`. |
| 3 | `shopping_list_ingredient` | même règle, clé `(liste, ingrédient, unité)`, toujours dans l'unité d'achat de `A` (c'est la clé que `AddIngredientToShoppingList` utilise), quantité `ceil(qté × f)` avec la tolérance de 1e-9 du plan d'envoi, jamais moins de 1 (ce sont des compteurs de pas entiers : un pas ne disparaît pas) |
| 4 | `drive_choice`, `drive_choice_product` | règle 9, par le port `Shared` `DriveIngredientReferences::merge` (le contexte `Drive` garde ses tables, une requête, toutes enseignes et tous magasins) : suppression des produits puis des choix de `B` là où `A` en a un ; `UPDATE drive_choice SET ingredient_slug = A WHERE … = B` pour le reste (identifiant, date de mise à jour, recherche mémorisée et produits ne changent pas). Les contenances que la personne a données (`pack_size_*`, DRV-652) suivent le choix ; si l'unité d'achat de `B` diffère de celle de `A`, elles deviennent inertes (le calcul n'en tient compte que si leur unité est exactement celle de la ligne) |
| 5 | `ingredient_alias` | alias qui désignent `B` -> `A` (un alias d'adoption reste `adoption` : le commun reste masqué derrière `A` ; sa `source` devient `merge`, ce qui le sort d'un `--undo` de la migration et lui épargne l'exigence « la copie vient de ce commun » de `--check`) ; l'alias dont la clé est le slug de `A` est effacé ; ajout `B -> A` (`kind = merge`, `source = merge`) |
| 6 | `ingredient_distinct` | suppression des lignes qui nomment `B` (`IngredientDistinctRepository::deleteByIngredientSlug`, ING-006) |
| 7 | `conversion` | suppression des conversions propres de `B` (celles qui ont été copiées à l'étape 0b restent, ce sont déjà des conversions de `A`) ; `A` garde les siennes (`ConversionRepository::deleteByIngredient`) |
| 8 | `ingredient` | suppression de `B` |
| 9 | événement | `IngredientRemovedEvent` si `B` n'est pas une copie d'adoption (statistiques) |

**Les conversions de `B` : ce que `A` récupère (amendement du 2026-10-21).** Avant l'amendement, les conversions de `B` n'étaient pas reportées (ADR 0028 décision g, option G3 écartée : « écriture silencieuse sur `A` ») et une unité qui manquait à `A` arrêtait la fusion. L'utilisateur a demandé le contraire, sur ses données réelles : ce qui manque à l'ingrédient gardé, on le prend à celui qui disparaît. La règle, pour chaque conversion propre de `B` dans l'ordre (unité de départ, unité d'arrivée, identifiant) :

1. si ses deux unités sont **déjà reliées** dans le graphe de `A` (une conversion propre de `A`, une conversion commune, un chemin de plusieurs étapes, ou une conversion de `B` déjà copiée juste avant), **elle n'est pas copiée** : à l'identique il n'y a rien à ajouter, avec un autre coefficient **celle de `A` gagne** (on n'écrase jamais, et le chemin de `A` ne change pas) ;
2. sinon elle est **copiée sur `A`** (elle relie deux unités que `A` ne savait pas relier) ; le graphe de `A` s'en trouve complété pour la suite. Les conversions copiées ne relient que des unités jusque-là séparées : aucun chemin déjà connu de `A` ne change.

Un coefficient nul ou négatif n'est pas une conversion utilisable : il est ignoré. La copie ne passe pas par l'utilisateur : c'est la fusion, qu'il a confirmée, qui l'écrit ; l'aperçu dit combien (`recoveredConversionCount`). Les conversions copiées sont rattachées à `A` : elles disparaissent avec lui et avec le compte (`deleteByIngredientCreator`, aucune colonne utilisateur de plus).

## 4. Les lignes de recette : une règle, pas de conversion de quantité

Une ligne de recette garde sa **quantité et son unité** (« 3 tranches », « 200 g ») : la recette ne change pas. Elle doit seulement rester **planifiable** : son unité doit pouvoir se convertir dans l'unité d'achat de `A` par le graphe de conversion de `A` (`ConversionService`, [`analyse.md`](analyse.md) § 7).

## 5. Les unités : l'aperçu les vérifie avant le geste

**D'abord `A` récupère les conversions de `B` qui lui manquent** (§ 3 étape 0b), **puis** les unités sont vérifiées dans le graphe ainsi complété. Soit `U` l'ensemble des **unités des lignes de recette de `B`** (dans les recettes de l'utilisateur) et `P(A)` l'unité d'achat de `A`. Pour chaque `u` de `U`, il faut un chemin de `u` vers `P(A)` dans le graphe de conversion de `A` **complété par les conversions récupérées** (ses conversions propres, les communes et celles de `B` copiées ; `ConversionGraph`, le parcours en largeur de `ConversionService`). Pour les listes, chaque changement d'unité d'une quantité (§ 3 étapes 2 et 3 : l'unité de la ligne de `B` vers l'unité de la ligne `A` de la même liste, à défaut vers `P(A)`) doit avoir un chemin aussi, et son coefficient est `f` (une unité qui ne change pas n'a pas de facteur). Les unités de départ qui échouent **même après la récupération** forment `missingUnits` (triées, sans doublon) : c'est alors une vraie impossibilité, ni `A` ni `B` ne savent convertir cette unité. Un seul service de domaine, `IngredientMergePlanner`, rend ce verdict (et décide quelles conversions sont récupérées, § 3) pour l'aperçu et pour l'écriture.

- `missingUnits` vide : la fusion est possible (avec, le cas échéant, `recoveredConversionCount` conversions copiées sur `A`). Les quantités des **listes** sont ré-exprimées dans l'unité d'achat de `A` quand elles diffèrent (le même volume de nourriture), celles des **recettes** ne bougent pas (« Tes quantités ne changent pas » reste vrai dans la confirmation pour les recettes ; pour les listes on le dit autrement quand `f ≠ 1`, § 9).
- `missingUnits` non vide : `GET …/merge-preview` répond `canMerge: false`, `blockedBy: UNIT_MISSING`, la liste et `recoveredConversionCount: 0` (rien ne serait écrit) ; `POST …/merge` répond `422 MERGE_UNIT_MISSING` **sans rien écrire** (aucune conversion copiée non plus).

## 6. Idempotence

- `POST /api/v1/ingredients/{B}/merge {into: A}` rejoué après un succès : `B` n'existe plus, mais l'alias `B -> A` existe : **`204`** sans rien faire (le client qui a perdu la réponse peut renvoyer). Si l'alias désigne un autre ingrédient (`B` a été fusionné ailleurs) : `404`.
- Un ingrédient qui reprend plus tard le slug de `B` (même nom recréé) efface l'alias correspondant à sa création, pour que l'alias ne le détourne pas.

## 7. L'API

Toutes les routes : `#[IsGranted('ROLE_USER')]`, utilisateur pris dans le jeton (`#[CurrentUser] AuthenticatedUser`), jamais dans le corps. Une route = un controller = une commande ou une requête (ADR 0003, 0007 §11 à 13). Noms de DTO et de routes à confirmer à la relecture du ticket contre l'ADR 0007.

| Route | Rôle |
|---|---|
| `GET /api/v1/ingredients/{slug}/merge-candidates?q=` | les ingrédients de l'utilisateur (les siens, copies d'adoption comprises, **jamais un commun**) avec lesquels `{slug}` (l'ingrédient qui serait absorbé) peut être fusionné, **même rayon d'abord** (classés par `IngredientNameMatcher::similar`, ceux qui ressemblent le plus en tête, puis par nom), puis les autres rayons ; `q` filtre par nom (sans casse ni accent, 100 caractères au plus) ; 30 au plus ; chaque entrée : `slug`, `name`, `departmentSlug`, `departmentName`, `purchaseUnitName`, `recipeCount`, `listCount`, `sameDepartment`, `veryClose` (booléen d'après le nom seul : niveau exact ou faute de frappe de `rapprochement.md`, quel que soit le rayon). `404` si `{slug}` est inconnu ou à un autre utilisateur, `422 CANNOT_MERGE_COMMON_INGREDIENT` s'il est commun |
| `GET /api/v1/ingredients/{slug}/merge-preview?into={slug}` | l'aperçu de « fusionner `{slug}` dans `into` » (`into` est obligatoire, `422` sinon) : voir ci-dessous. `200` toujours si les deux ingrédients sont ceux de l'utilisateur (ou communs : `blockedBy` le dit) ; les erreurs de règle sont dans le corps (`blockedBy`) pour que le front affiche la bonne phrase sans une erreur ; `404` pour un ingrédient inconnu ou à un autre utilisateur |
| `POST /api/v1/ingredients/{slug}/merge` corps `{"into": "<slug>"}` | la fusion ; `204` |
| `GET /api/v1/ingredients/duplicates` | les doublons possibles (§ 8) ; `{member, totalItems}` |
| `POST /api/v1/ingredients/{slug}/distinct-from` corps `{"other": "<slug>"}` | « Pas le même » : mémorise la paire ; `204` |

**Aperçu** (`merge-preview`) :

```json
{
  "keep":     {"slug": "…", "name": "Tomate",  "departmentSlug": "fruit-et-legume", "departmentName": "Fruit & légume", "purchaseUnitName": "kilo"},
  "absorbed": {"slug": "…", "name": "Tomates", "departmentSlug": "fruit-et-legume", "departmentName": "Fruit & légume", "purchaseUnitName": "kilo"},
  "recipeCount": 7,
  "listCount": 2,
  "driveChoiceCount": 1,
  "departmentChanges": false,
  "listQuantitiesConverted": false,
  "missingUnits": [],
  "blockedBy": null,
  "canMerge": true,
  "resultRecipeCount": 19,
  "resultListCount": 5,
  "recoveredConversionCount": 1
}
```

`missingUnits` contient par exemple `[{"slug": "tranche", "name": "tranche"}]` quand `blockedBy` vaut `UNIT_MISSING` ; elle est renseignée même quand un refus plus haut dans l'ordre la masque. `blockedBy` : `null`, ou le **premier** cas dans cet ordre : `SAME_INGREDIENT`, `COMMON_INGREDIENT`, `USED_BY_ANOTHER_USER` (règle 15, ajouté au cahier d'origine : l'aperçu dit exactement ce que l'écriture ferait), `IN_SENT_LIST`, `UNIT_MISSING`. L'écriture lève l'erreur de ce même cas (tableau ci-dessous). Un front doit tolérer une valeur de `blockedBy` qu'il ne connaît pas (refus générique). `driveChoiceCount` = nombre de choix qui passent sur `A` (pas ceux qui sont supprimés). `recipeCount` et `listCount` = les recettes et les listes **de l'utilisateur** qui utilisent `B` (une liste compte une fois, qu'elle ait une ligne, un ajout à la main ou les deux) : **ce qui se déplace**, pas un total.

**Les trois clés ajoutées le 2026-10-21** (toujours écrites, entiers, jamais `null` ; ajouts seulement, aucune clé existante ne change) :

| Clé | Sens |
|---|---|
| `resultRecipeCount` | le nombre de recettes **distinctes** de l'utilisateur qui utiliseront `A` **après** la fusion : l'**union** des recettes qui utilisent déjà `A` et de celles qui utilisent `B` (une recette qui utilisait déjà les deux compte une fois). C'est le total à annoncer (« « Melon » sera utilisé dans 2 recettes ») ; `recipeCount` ne dit que ce qui se déplace (1). Même valeur que le nombre de recettes de l'utilisateur qui référencent `A` dans la base d'après (`MergePreviewMatchesTheMergeTest`) |
| `resultListCount` | idem pour les listes de courses de l'utilisateur (tous statuts ; une ligne, un ajout à la main ou les deux : une liste compte une fois) |
| `recoveredConversionCount` | le nombre de conversions propres de `B` que `A` récupérerait (§ 3 étape 0b). **`0` quand la fusion est refusée** (`canMerge` faux : rien ne serait écrit) |

Dans les deux refus où rien n'est lu (`SAME_INGREDIENT`, `COMMON_INGREDIENT`), les cinq nombres valent `0`. Dans les autres refus (`USED_BY_ANOTHER_USER`, `IN_SENT_LIST`, `UNIT_MISSING`), `recipeCount`, `listCount`, `resultRecipeCount` et `resultListCount` décrivent la fusion qui aurait lieu si le refus était levé, comme `missingUnits` ; seul `recoveredConversionCount` retombe à `0`.

**Codes d'erreur de `POST …/merge`** (message = code, comme `PROTECTED_ACCOUNT`) :

| Statut | Code | Quand |
|---|---|---|
| `404` | (ingrédient inconnu) | `B` ou `A` inexistant, ou à un autre utilisateur ; ou rejeu avec un alias vers un autre ingrédient |
| `422` | `CANNOT_MERGE_INTO_ITSELF` | `A = B` |
| `422` | `CANNOT_MERGE_COMMON_INGREDIENT` | l'un des deux est un commun |
| `422` | `MERGE_UNIT_MISSING` | § 5 |
| `409` | `INGREDIENT_IN_SENT_LIST` | règle 8 |
| `409` | `INGREDIENT_USED_BY_ANOTHER_USER` | règle 15 (recette, liste ou choix du drive d'un autre utilisateur sur `B`) |
| `409` | `INGREDIENT_MERGE_CONFLICT` | une écriture concurrente a posé une référence sur `B` pendant la fusion (ou en a laissé une) : rien n'a été écrit, la fusion se rejoue |
| `204` | | succès, ou rejeu idempotent |

`#[OA\…]` complet, `make openapi`, relire le diff de `docs/api/openapi.json` ([`repas-dev`](../../.claude/skills/repas-dev/SKILL.md) § 3.2). Pas d'`If-Match` : un ingrédient n'a pas de version ; la concurrence est réglée par le verrou des deux lignes et les versions des recettes et listes touchées.

## 8. Les doublons possibles (écran A)

`GET /api/v1/ingredients/duplicates` : les paires d'ingrédients **du même utilisateur, du même rayon**, que l'application pense être le même ingrédient (ticket [ING-006](tickets/ING-006-doublons-possibles.md), livré). Même moteur que le rapprochement (`IngredientNameMatcher`, garde-fou compris : même nombre de mots, chaque mot identique ou à une faute près), avec un seuil plus bas ([`rapprochement.md`](rapprochement.md) § 8). Une paire est proposée quand **toutes** ces conditions sont réunies :

1. **Les deux ingrédients sont à l'utilisateur** : ceux dont il est le créateur, copies d'adoption comprises. **Jamais un commun, jamais l'ingrédient d'un autre.** L'administrateur n'a aucun ingrédient personnel : sa liste est vide (`200`, pas une erreur).
2. **Même rayon.**
3. **Les noms se ressemblent** : niveau `EXACT` (même nom normalisé `N2` : « Tomate » et « Tomates », un « Oignon » en double après une migration), `TYPO` (à une faute près, Jaro-Winkler d'au moins 0,97) ou `SUGGESTION` (de **0,90** à 0,97). Le **nom précisé** (« Poivre » / « Poivre noir », « Tomate » / « Tomate cerise ») n'est **pas** proposé : le garde-fou refuse deux noms de longueurs différentes. Ce choix (décision du propriétaire pour ING-006) retire la catégorie la plus bruyante et la moins souvent juste ; l'utilisateur fusionne ces cas-là, s'il le veut, depuis la fiche de l'ingrédient (entrée B, `merge-candidates`).
4. **Pas deux adoptions de deux communs différents** (`adopted_from` différents) : l'administrateur les a voulus distincts. Deux adoptions du **même** commun (« pate » et « pate-2 »), ou une adoption et un ingrédient créé par l'utilisateur, sont proposées.
5. **L'utilisateur n'a pas dit « Pas le même »** pour cette paire (table `ingredient_distinct`), **dans un sens ou dans l'autre, y compris après un renommage** (la mémoire porte sur les slugs, qui ne changent pas).

**Le résultat** est trié du plus probable au moins probable (score décroissant, puis slugs des deux ingrédients : deux appels donnent la même liste), borné à **50** paires (`DuplicateIngredientFinder::LIMIT`). `totalItems` est le nombre de paires rendues (50 au plus).

**Une proposition porte de quoi afficher sa carte sans second appel** : `first` et `second` (les deux ingrédients, rangés par ordre des slugs, jamais par préférence : `slug`, `name`, `departmentSlug`, `departmentName`, `purchaseUnitName`, `recipeCount`, `listCount`), `score` (de 0,90 à 1), `level` (`EXACT`, `TYPO`, `SUGGESTION`) et `suggestedKeep` (le slug de la ligne « À garder » préselectionnée). Les nombres sont ceux d'`IngredientMergeUsageReader::counts()` (recettes et listes **de l'utilisateur** qui utilisent l'ingrédient, 0 s'il n'en a aucune). `GET …/merge-preview` reste l'appel qui dit exactement ce que la fusion ferait.

**L'ingrédient à garder par défaut** (`DuplicatePair::suggestedKeep`) : la première règle qui départage parmi (1) **le plus de recettes**, (2) le plus de listes de courses, (3) le **nom le plus court** (« Tomate » avant « Tomates »), (4) l'ordre alphabétique du nom normalisé, (5) l'ordre des slugs. Il n'y a pas d'ancienneté (un ingrédient n'a pas de date de création) ; la règle est déterministe.

**Où mémoriser « Pas le même »** : table `ingredient_distinct (user_id, first_slug, second_slug, created_at)`, clé primaire `(user_id, first_slug, second_slug)`, slugs rangés dans l'ordre des **octets** (`COLLATE "C"` : `strcmp` côté PHP, contrainte `CHECK (first_slug < second_slug)` côté base ; une paire = une ligne, `(a, b)` et `(b, a)` sont la même), aucune clé étrangère. Écrite par `POST /api/v1/ingredients/{slug}/distinct-from` `{other}` (`204`, **idempotent**, `404` si l'un des deux n'est pas un ingrédient de l'utilisateur, commun ou inconnu compris, sans exception administrateur ; `422 CANNOT_BE_DISTINCT_FROM_ITSELF`), effacée avec le compte (`PurgeUserDataHandler`, `UserOwnedDataCoverageTest`), nettoyée quand l'un des deux ingrédients est supprimé (`RemoveIngredientHandler`) ou fusionné (`MergeIngredientsHandler`, [§ 3](#3-ce-qui-est-modifié-table-par-table) étape 6). **Aucune route ne la défait** (hors portée). Pas de colonne sur `ingredient` (une paire a deux côtés), pas dans `user.statistics` (domaine différent). C'est une préférence privée : aucune lecture ni écriture d'administrateur.

**Coût** : trois lectures SQL, quel que soit le nombre d'ingrédients (les ingrédients de l'utilisateur, ses « Pas le même », les nombres des seules paires rendues) ; la comparaison est faite en mémoire, rayon par rayon puis par nombre de mots, après un refus rapide par l'inventaire des lettres. Mesuré sur 106 ingrédients personnels et 261 adoptés (367) : environ 55 ms dans l'environnement de test (Xdebug chargé), dont une trentaine pour normaliser les noms.

**Volume** : sur les données de production, deux paires « même nom » et aucune « faute » sur trois comptes réunis ([`rapprochement.md`](rapprochement.md) § 7.3) ; les 22 « noms précisés » ne sont plus proposés. L'écran n'est donc pas une corvée.

## 9. L'interface

Décision du propriétaire (maquette Claude Design à quatre pistes, lien dans l'ADR 0028) : **A « Doublons repérés »** comme entrée proactive, **plus B « Depuis la fiche »** comme entrée manuelle ; les pistes C (sélection multiple) et D (côte à côte) sont écartées, mais l'aperçu « Après la fusion » de D est repris dans la **confirmation commune**. Les textes sont écrits au tutoiement (DRV-627), en phrases de 15 mots au plus, avec le vocabulaire du glossaire ; ceux de la maquette sont repris tels quels, sauf trois ajustements notés « écart ».

### 9.1 Entrée A : l'écran « Doublons possibles »

- **Accès** : sur la page **Rayons**, sous la recherche, une ligne **« 3 doublons possibles »** (lien vers l'écran), **affichée seulement s'il y en a** (`totalItems > 0`) ; route `/ingredients/doublons`.
- **Écart** : le lien de retour s'appelle **« ‹ Rayons »** (le titre de la page d'où l'on vient, règle du glossaire « un mot pour une chose ») ; la maquette disait « ‹ Mes ingrédients ».
- **Titre** : « Doublons possibles ». **Phrase** : « Ces ingrédients se ressemblent dans le même rayon. Fusionne-les pour que tes recettes retombent sur un seul. »
- **Une carte par paire**, groupées sous le **nom du rayon** (« Fruit & légume »). La première carte est ouverte : deux lignes, chacune avec le nom et « 12 recettes · 3 listes » (jamais de compteur à zéro : « 7 recettes · 2 listes » ou « 2 listes »), celle **« À garder »** (pastille, coche) est **celle qu'on touche** : toucher l'autre ligne **bascule** « À garder » et la phrase d'effet. Phrase d'effet, mise à jour : « 7 recettes et 2 listes passent sur « Tomate ». » Deux boutons : **« Fusionner »** (principal) et **« Pas le même »**.
- Les cartes suivantes sont **repliées** : « Emmental râpé = Fromage râpé » et un bouton **« Voir et fusionner »** qui l'ouvre. Une seule carte ouverte à la fois.
- **« Fusionner »** ouvre la **confirmation commune** (§ 9.3). **« Pas le même »** retire la carte (appel `distinct-from`), sans confirmation (se défait en ne l'écartant pas : la paire ne revient plus, mais rien n'est détruit).
- **État vide** : « Aucun doublon pour le moment. » sans autre bouton. **Erreur de chargement** : trois phrases ([principes](../drive/principes-produit.md) § 4) : « Les doublons ne se sont pas affichés. Rien n'a changé. » [Réessayer].

### 9.2 Entrée B : depuis la fiche d'un ingrédient

- **La fiche est l'écran existant** « Modifier l'ingrédient » (`ingredient-edit`, `IngredientEditorView.vue`), pas un nouvel écran : on y ajoute, pour un ingrédient à soi (jamais un commun), une ligne **« Utilisé dans 7 recettes · 2 listes »** et, en bas, un bouton secondaire **« Fusionner avec un autre ingrédient »**. (La maquette montre une fiche en lecture ; ajouter un écran coûterait une touche de plus.)
- Le bouton ouvre une **feuille** « **Fusionner « Tomates » avec…** » + « « Tomates » disparaît : ses recettes et ses listes passent sur l'ingrédient choisi. », avec un **champ de recherche** (pré-rempli du début du nom : « tomate ») et deux groupes : **« Dans le même rayon · Fruit & légume »** d'abord (chaque ligne : nom, « 12 recettes · 3 listes · kilo », pastille **« Très proche »** sur la meilleure quand `veryClose`), puis **« Autres rayons »** (ligne : nom, « Surgelé · 1 recette »). Source : `merge-candidates`.
- Toucher une ligne ouvre la **confirmation commune** avec **cette ligne comme ingrédient à garder**.

### 9.3 La confirmation commune (feuille du bas)

**Titre** : « **Fusionner « Tomates » dans « Tomate » ?** » (le premier nom est l'absorbé, le second l'ingrédient gardé). Jusqu'à cinq lignes d'effets, issues de `merge-preview` (amendement du 2026-10-21 : la première dit le **total** d'après, la deuxième ce qui est **remplacé**, la troisième les conversions récupérées) :

1. (coche) le TOTAL, de `resultRecipeCount` et `resultListCount` : « **« Tomate » sera utilisé dans 19 recettes et 5 listes de courses.** » (singulier à 1 : « 1 recette », « 1 liste de courses » ; un nombre à zéro ne s'écrit pas : « … dans 19 recettes. » s'il n'y a pas de liste.) **Ce n'est pas `recipeCount`** : deux « Melon » utilisés chacun dans une recette disent « « Melon » sera utilisé dans 2 recettes », pas « 1 recette ».
2. (coche) ce qui est remplacé, de `recipeCount` et `listCount` : « **« Tomates » est remplacé par « Tomate » dans 7 recettes et 2 listes de courses.** » (mêmes règles de singulier et de zéro ; « « Tomates » n'est utilisé nulle part. » s'il n'y a aucune des deux, et alors la ligne 1 disparaît.)
3. (coche, seulement si `recoveredConversionCount > 0`) « **1 conversion ajoutée à « Tomate ».** » (« 2 conversions ajoutées à « Tomate ». » au pluriel) : ce que `A` apprend de `B` (§ 3 étape 0b).
4. (coche) « **Tes quantités ne changent pas.** » Variantes : si les listes sont converties (`listQuantitiesConverted`) : « Tes quantités en liste sont converties en « kilo ». » ; si les rayons diffèrent (`departmentChanges`) : « « Tomates » passe du rayon Surgelé au rayon Fruit & légume. » (une seule de ces trois phrases, par priorité : rayon, puis conversion, puis « Tes quantités ne changent pas ».)
5. (point d'attention) « **« Tomates » est supprimé. Tu ne pourras pas le retrouver.** »

Boutons : **« Fusionner »** (principal) et **« Annuler »**.

**Cas « une unité manque »** (`canMerge: false`, `blockedBy: UNIT_MISSING`) — **seulement une vraie impossibilité** depuis l'amendement du 2026-10-21 : une unité que ni `A` ni `B` ne savent convertir. Une unité que seul `B` sait convertir **ne bloque plus** : `canMerge` est vrai et la ligne 3 le dit. Le titre devient « **« Tomate » ne connaît pas toutes les unités.** », la ligne 1 disparaît, et le bloc dit : « La quantité en « tranche » ne peut pas être convertie en « kilo ». Rien n'a changé. » avec le bouton **« Modifier « Tomate » »** (vers `ingredient-edit` de l'ingrédient gardé, pour qu'il ajoute l'unité) et **« Annuler »** ; **pas** de bouton « Fusionner ». (La maquette présente ce cas comme un encadré « Si une unité manque » toujours visible ; on ne l'affiche que lorsqu'il arrive : pas d'avertissement dans le parcours, règle 10.)

**Cas « liste envoyée »** (`blockedBy: IN_SENT_LIST`) : « Une liste envoyée au drive utilise cet ingrédient. Rouvre-la ou termine-la, puis fusionne. » [Compris]. **Cas « commun »** : le bouton « Fusionner avec un autre ingrédient » n'est pas proposé (jamais atteignable).

**Après « Fusionner »** : la feuille se ferme, l'écran d'origine se rafraîchit (A : la carte disparaît ; B : retour à la page du rayon de l'ingrédient gardé, `invalidateQueries` sur les ingrédients, recettes, listes et choix). Un message court et non bloquant : « **Fusionné dans « Tomate ».** ». **Erreur** : « La fusion n'a pas eu lieu. Rien n'a changé. » [Réessayer] (le texte exact par code : tableau du § 7 ; jamais un code à l'écran).

### 9.4 Touches du parcours heureux

| Parcours | Avant (aujourd'hui) | Après |
|---|---|---|
| Fusionner depuis les propositions (A) | impossible | **3** : « 3 doublons possibles », « Fusionner », « Fusionner » (confirmation) |
| Fusionner depuis la fiche (B) | impossible | **4** : « Modifier », « Fusionner avec un autre ingrédient », l'ingrédient, « Fusionner » (+ la saisie facultative) |
| Copier une recette | 1 touche | **1** : aucun écran ajouté |

### 9.5 Contrôles produit (skill `repas-po`)

Tenir à 360 px sans défilement horizontal ; un seul bouton principal par écran ; test de glossaire (`voice-and-glossary.spec.ts`) étendu aux nouveaux textes (« vous », impératifs en « -ez » et mots interdits) ; les captures sont **lues** (Chromium) avant fusion ; pas d'avertissement hors de la confirmation (la phrase « Tu ne pourras pas le retrouver. » dit ce qui se passe, ce n'est pas une mise en garde de plus).

## 10. Sécurité et purge

- Voir § 2 règles 1 à 4 et 15 : le cas d'usage vérifie lui-même que **les deux ingrédients sont à l'utilisateur du jeton**, sans exception pour `ROLE_ADMIN`. Test : un administrateur ne peut pas fusionner les ingrédients d'un autre (404) ni un commun (422).
- Un utilisateur désactivé ne peut rien (le pare-feu le refuse déjà).
- `ingredient_alias` et `ingredient_distinct` portent `user_id` : ajoutées à `UserOwnedDataCoverageTest::ERASED_WITH_THE_USER` et à `PurgeUserDataHandler` (`supprimer-compte` : une requête de plus pour chacune des deux tables, budget 22 -> 23 pour la seconde, ING-006).
- Pas de limiteur de débit nouveau : une fusion est bornée à l'utilisateur et à une transaction.

## 11. Tests exigés

Domaine (sans base) : règles 1 à 5 et 13, `MergeIngredientsHandler` avec les `InMemoryRepository` (alias, fusion de lignes de liste, `checked`, conversion de quantité `f`, `ceil` des compteurs, choix du drive A gagne / B passe sur A, `SpyEventDispatcher` pour les statistiques) ; réconciliation d'unités en table (compatible, unité manquante, unité d'achat différente) ; **base réelle** : le dépôt de fusion (`OutputAdapter`) avec des données de tous les types, y compris la liste `SENT` (refus), la recette qui contient les deux, la donnée héritée d'un autre utilisateur (refus), l'alias et le rejeu idempotent, les versions montées ; **budget** `fusionner-ingredients`, `supprimer-compte` ; controller (`MockCommandBus`/`MockQueryBus`, clés JSON de l'aperçu, codes) ; parcours avec les vrais bus ; **une fusion ne change aucun choix du drive d'un autre utilisateur** et un plan relu après fusion donne les produits de `A` ([`analyse.md`](analyse.md) § 11) ; front : Vitest des feuilles (états : normal, unité manquante, liste envoyée, erreur), parcours Playwright A et B.

**Amendement du 2026-10-21** (totaux et conversions récupérées), ajouté aux tests ci-dessus : `IngredientMergePlannerTest` (conversion récupérée qui lève le refus, unité réellement impossible, paire déjà jointe à l'identique ou par un chemin, conflit de coefficient où `A` gagne, deux conversions qui joignent la même unité, ordre de lecture sans effet, coefficient inutilisable, unités compatibles ajoutées) ; `MergeIngredientsHandlerTest` (le cas de deux « Melon », conversion copiée avant le repointage, quantité de la recette convertie ensuite, refus sans rien copier, perte d'une conversion en cours de copie, rejeu) ; `IngredientMergeRepositoryTest` (union des totaux, conversions propres de `B` seulement, copie en une requête au coefficient identique au bit près, refus d'une conversion qui n'est pas celle de `B`) ; **`MergePreviewMatchesTheMergeTest`** (les totaux annoncés sont ceux de la base d'après, le nombre de conversions annoncé est celui qui a été copiée, rien n'est annoncé quand la fusion est refusée) ; `MergeFingerprintTest` (empreinte des conversions : celles de `B` disparaissent, la copie est la seule nouveauté, la commune et celles des autres n'ont pas bougé d'un octet) ; `MergeDriveEquivalenceTest` (plan et estimation inchangés quand `A` récupère une conversion) ; `IngredientMergeApiFlowTest` (parcours avec JWT : deux « Melon », conversion récupérée puis recette planifiée en kilo, unité réellement impossible) ; budget SQL : `fusionner-ingredients` inchangé (22), `fusionner-ingredients-avec-recuperation` (24, soit une requête de copie et une mise à jour des unités compatibles de plus, indépendantes du volume et du nombre de conversions : `MergeIngredientsBudgetTest`) ; `MergeRecoveredConversionsArePurgedTest` (la suppression du compte efface les conversions copiées).
