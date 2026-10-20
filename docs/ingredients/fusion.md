# Fusionner deux ingrédients

Spécification du back (tickets [ING-005](tickets/ING-005-fusion-back.md) et [ING-006](tickets/ING-006-doublons-possibles.md)) et de l'interface ([ING-007](tickets/ING-007-front-fusion-et-doublons.md)). Décisions : [ADR 0028](../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décisions g et h. Inventaire des endroits à repointer : [`analyse.md`](analyse.md). Le rapprochement qui sert aussi aux propositions : [`rapprochement.md`](rapprochement.md).

## 1. Le geste

Un utilisateur a deux ingrédients qui sont en fait le même (« Tomate » et « Tomates », une copie faite par erreur). Il en **garde un** (`A`) et fait **disparaître l'autre** (`B`, « absorbé ») : toutes ses recettes, ses listes de courses et ses choix du drive qui utilisaient `B` utilisent désormais `A`, et `B` est supprimé.

Mots de l'interface : **ingrédient**, **recette**, **liste de courses** (le mot « article » est réservé à la ligne d'une liste, [principes produit](../drive/principes-produit.md) § 5), **Fusionner**.

## 2. Règles métier

| # | Règle |
|---|---|
| 1 | **Même utilisateur seulement.** Les deux ingrédients ont `creator = l'utilisateur connecté`. Aucun autre cas. |
| 2 | **Jamais un commun** (`creator` vide) : `422 CANNOT_MERGE_COMMON_INGREDIENT`. Un administrateur n'a aucun ingrédient personnel (il crée des communs) : la fusion ne le concerne pas pour l'instant. |
| 3 | **Jamais l'ingrédient d'un autre** : `404`, comme un ingrédient inconnu (on ne révèle pas son existence ; même règle que `findPurchaseUnitSlugUsableBy`). **L'exception administrateur de `OwnerVoter` ne s'applique pas** : le contrôle `creator = utilisateur du jeton` est fait par le cas d'usage lui-même, `ROLE_ADMIN` ou non. |
| 4 | `A` et `B` doivent être **différents** : `422 CANNOT_MERGE_INTO_ITSELF`. |
| 5 | **`A` garde tout** : nom, image, rayon, unités par défaut, unités compatibles, conversions propres, `adopted_from`. `B` ne lègue rien (voir § 4 pour les conversions). |
| 6 | **Le rayon peut différer** : le rayon de `A` l'emporte ; la confirmation le dit. |
| 7 | **Les unités doivent suivre** (§ 5), sinon **la fusion s'arrête et rien ne bouge** : `422 MERGE_UNIT_MISSING`. |
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
| 0 | `ingredient` | `SELECT … FOR UPDATE` des deux lignes, ordre alphabétique des slugs (deux fusions concurrentes se sérialisent) |
| 1 | `recipe`, `recipe_row` | versions de toutes les recettes contenant `B` : `+1` ; `UPDATE recipe_row SET ingredient = A WHERE ingredient = B` (la **recette** garde ses deux lignes si elle contenait `A` et `B` : pas de cumul, l'auteur les a écrites) |
| 2 | `shopping_list`, `shopping_list_row` | pour chaque liste contenant `B` : si elle contient aussi `A`, la ligne `A` reçoit `qté(A) + qté(B) × f` et `checked = checked(A) ET checked(B)` (**décochée si l'une des deux l'est** : mieux vaut racheter que oublier), la ligne `B` est supprimée ; sinon la ligne `B` devient une ligne `A` : `quantité × f`, unité d'achat de `A`, `checked` inchangé. `f` = facteur de conversion de l'unité d'achat de `B` vers celle de `A` (1 si elles sont égales). Versions `+1`. |
| 3 | `shopping_list_ingredient` | même règle, clé `(liste, ingrédient, unité)`, quantité `ceil(qté × f)` (ce sont des compteurs de pas entiers) |
| 4 | `drive_choice`, `drive_choice_product` | règle 9 : suppression des produits puis des choix de `B` là où `A` en a un ; `UPDATE drive_choice SET ingredient_slug = A WHERE … = B` pour le reste |
| 5 | `ingredient_alias` | alias qui désignent `B` -> `A` ; ajout `B -> A` (`kind = merge`) |
| 6 | `ingredient_distinct` | suppression des lignes qui nomment `B` |
| 7 | `conversion` | suppression des conversions propres de `B` (**non reportées**, voir ci-dessous) ; `A` garde les siennes |
| 8 | `ingredient` | suppression de `B` |
| 9 | événement | `IngredientRemovedEvent` si `B` n'est pas une copie d'adoption (statistiques) |

**Pourquoi les conversions de `B` ne sont pas reportées sur `A`** : l'utilisateur ne peut pas créer de conversion lui-même (réservé aux administrateurs, [`analyse.md`](analyse.md) § 4) ; reporter celles de `B` serait une écriture silencieuse sur `A`. Si une unité manque, la fusion s'arrête et le dit (§ 5), l'utilisateur peut compléter `A` par l'éditeur d'ingrédient (le coefficient unité d'achat / unité de recette crée la conversion) puis recommencer. Reporter les conversions de `B` quand elles comblent le trou est une évolution possible (backlog), pas une promesse.

## 4. Les lignes de recette : une règle, pas de conversion de quantité

Une ligne de recette garde sa **quantité et son unité** (« 3 tranches », « 200 g ») : la recette ne change pas. Elle doit seulement rester **planifiable** : son unité doit pouvoir se convertir dans l'unité d'achat de `A` par le graphe de conversion de `A` (`ConversionService`, [`analyse.md`](analyse.md) § 7).

## 5. Les unités : l'aperçu les vérifie avant le geste

Soit `U` l'ensemble des **unités des lignes de recette de `B`** et `P(B)`, `P(A)` les unités d'achat. Pour chaque `u` de `U`, il faut `convertTo(A, 1, u, P(A))` possible. Pour les listes, il faut `convertTo(A, 1, P(B), P(A))` possible (c'est `f`). Les unités qui échouent forment `missingUnits`.

- `missingUnits` vide : la fusion est possible. Les quantités des **listes** sont ré-exprimées dans l'unité d'achat de `A` quand elles diffèrent (le même volume de nourriture), celles des **recettes** ne bougent pas (« Tes quantités ne changent pas » reste vrai dans la confirmation pour les recettes ; pour les listes on le dit autrement quand `f ≠ 1`, § 9).
- `missingUnits` non vide : `GET …/merge-preview` répond `canMerge: false`, `blockedBy: UNIT_MISSING` et la liste ; `POST …/merge` répond `422 MERGE_UNIT_MISSING` **sans rien écrire**.

## 6. Idempotence

- `POST /api/v1/ingredients/{B}/merge {into: A}` rejoué après un succès : `B` n'existe plus, mais l'alias `B -> A` existe : **`204`** sans rien faire (le client qui a perdu la réponse peut renvoyer). Si l'alias désigne un autre ingrédient (`B` a été fusionné ailleurs) : `404`.
- Un ingrédient qui reprend plus tard le slug de `B` (même nom recréé) efface l'alias correspondant à sa création, pour que l'alias ne le détourne pas.

## 7. L'API

Toutes les routes : `#[IsGranted('ROLE_USER')]`, utilisateur pris dans le jeton (`#[CurrentUser] AuthenticatedUser`), jamais dans le corps. Une route = un controller = une commande ou une requête (ADR 0003, 0007 §11 à 13). Noms de DTO et de routes à confirmer à la relecture du ticket contre l'ADR 0007.

| Route | Rôle |
|---|---|
| `GET /api/v1/ingredients/{slug}/merge-candidates?q=` | les ingrédients de l'utilisateur avec lesquels `{slug}` peut être fusionné, **même rayon d'abord** (classés par `IngredientNameMatcher`, ceux qui ressemblent le plus en tête), puis les autres rayons ; `q` filtre par nom (recherche de la feuille) ; 30 au plus ; chaque entrée : `slug`, `name`, `departmentSlug`, `departmentName`, `purchaseUnitName`, `recipeCount`, `listCount`, `sameDepartment`, `veryClose` (booléen : niveau exact ou faute de frappe de `rapprochement.md`) |
| `GET /api/v1/ingredients/{slug}/merge-preview?into={slug}` | l'aperçu de « fusionner `{slug}` dans `into` » : voir ci-dessous. `200` toujours si les deux ingrédients sont ceux de l'utilisateur ; les erreurs de règle sont dans le corps (`blockedBy`) pour que le front affiche la bonne phrase sans une erreur |
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
  "missingUnits": [{"slug": "tranche", "name": "tranche"}],
  "blockedBy": null,
  "canMerge": true
}
```

`blockedBy` : `null`, `UNIT_MISSING`, `IN_SENT_LIST`, `COMMON_INGREDIENT`, `SAME_INGREDIENT`. `driveChoiceCount` = nombre de choix qui passent sur `A` (pas ceux qui sont supprimés).

**Codes d'erreur de `POST …/merge`** (message = code, comme `PROTECTED_ACCOUNT`) :

| Statut | Code | Quand |
|---|---|---|
| `404` | (ingrédient inconnu) | `B` ou `A` inexistant, ou à un autre utilisateur ; ou rejeu avec un alias vers un autre ingrédient |
| `422` | `CANNOT_MERGE_INTO_ITSELF` | `A = B` |
| `422` | `CANNOT_MERGE_COMMON_INGREDIENT` | l'un des deux est un commun |
| `422` | `MERGE_UNIT_MISSING` | § 5 |
| `409` | `INGREDIENT_IN_SENT_LIST` | règle 8 |
| `409` | `INGREDIENT_USED_BY_ANOTHER_USER` | règle 15 |
| `204` | | succès, ou rejeu idempotent |

`#[OA\…]` complet, `make openapi`, relire le diff de `docs/api/openapi.json` ([`repas-dev`](../../.claude/skills/repas-dev/SKILL.md) § 3.2). Pas d'`If-Match` : un ingrédient n'a pas de version ; la concurrence est réglée par le verrou des deux lignes et les versions des recettes et listes touchées.

## 8. Les doublons possibles (écran A)

`GET /api/v1/ingredients/duplicates` : les paires d'ingrédients **du même utilisateur, du même rayon**, que l'application pense être le même ingrédient. Même moteur que le rapprochement, avec un seuil plus bas ([`rapprochement.md`](rapprochement.md) § 8) :

1. **Même nom normalisé** (niveau exact de `N2`) : « Tomate » et « Tomates », ou un « Oignon » en double après une migration. Toujours proposé.
2. **À une faute près** : `N2` + `G6` + Jaro-Winkler d'au moins **0,90**. Toujours proposé.
3. **Nom précisé** : les mots de l'un sont inclus dans ceux de l'autre (« Poivre » / « Poivre noir », « Crème fraîche » / « Crème fraîche épaisse »). Proposé en dernier, **10 au plus** (les plus courts d'abord) : sur les données de production, c'est la catégorie la plus bruyante et la moins souvent juste.
4. **Jamais** : une paire dont les **deux** ingrédients sont l'adoption de **deux communs différents** (`adopted_from` différents) ; l'administrateur les a voulus distincts. Une paire dont les deux sont l'adoption du **même** commun est toujours proposée (doublon certain).
5. **Jamais** : une paire que l'utilisateur a écartée par « Pas le même » (table `ingredient_distinct`).

**Où mémoriser « Pas le même »** : table `ingredient_distinct (user_id, first_slug, second_slug, created_at)`, clé primaire `(user_id, first_slug, second_slug)`, slugs rangés par ordre alphabétique (une paire = une ligne). Effacée avec le compte (`PurgeUserDataHandler`, `UserOwnedDataCoverageTest`), nettoyée quand l'un des deux ingrédients est supprimé ou fusionné ([§ 3](#3-ce-qui-est-modifié-table-par-table) étape 6). Pas de colonne sur `ingredient` (une paire a deux côtés), pas dans `user.statistics` (domaine différent).

Chaque proposition : `reason` (`SAME_NAME`, `TYPO`, `QUALIFIED`), le rayon, les deux ingrédients avec `recipeCount`, `listCount`, et `suggestedKeep` (celui des deux qui est le plus utilisé ; à égalité, le plus court ; puis l'ordre alphabétique) : c'est la ligne « À garder » préselectionnée.

**Volume** : sur les données de production, deux paires « même nom » et aucune « faute » sur trois comptes réunis, plus une vingtaine de « noms précisés » ([`rapprochement.md`](rapprochement.md) § 7.3). L'écran n'est donc pas une corvée.

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

**Titre** : « **Fusionner « Tomates » dans « Tomate » ?** » (le premier nom est l'absorbé, le second l'ingrédient gardé). Trois lignes d'effets, issues de `merge-preview` :

1. (coche) « **7 recettes et 2 listes de courses utiliseront « Tomate ».** » (« 7 recettes utiliseront « Tomate ». » s'il n'y a pas de liste ; « « Tomates » n'est utilisé nulle part. » s'il n'y en a aucune.)
2. (coche) « **Tes quantités ne changent pas.** » Variantes : si les listes sont converties (`listQuantitiesConverted`) : « Tes quantités en liste sont converties en « kilo ». » ; si les rayons diffèrent (`departmentChanges`) : « « Tomates » passe du rayon Surgelé au rayon Fruit & légume. » (une seule de ces trois phrases, par priorité : rayon, puis conversion, puis « Tes quantités ne changent pas ».)
3. (point d'attention) « **« Tomates » est supprimé. Tu ne pourras pas le retrouver.** »

Boutons : **« Fusionner »** (principal) et **« Annuler »**.

**Cas « une unité manque »** (`canMerge: false`, `blockedBy: UNIT_MISSING`) : le titre devient « **« Tomate » ne connaît pas toutes les unités.** », la ligne 1 disparaît, et le bloc dit : « La quantité en « tranche » ne peut pas être convertie en « kilo ». Rien n'a changé. » avec le bouton **« Modifier « Tomate » »** (vers `ingredient-edit` de l'ingrédient gardé, pour qu'il ajoute l'unité) et **« Annuler »** ; **pas** de bouton « Fusionner ». (La maquette présente ce cas comme un encadré « Si une unité manque » toujours visible ; on ne l'affiche que lorsqu'il arrive : pas d'avertissement dans le parcours, règle 10.)

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
- `ingredient_alias` et `ingredient_distinct` portent `user_id` : ajoutées à `UserOwnedDataCoverageTest::ERASED_WITH_THE_USER` et à `PurgeUserDataHandler` (`supprimer-compte` : budget qui monte de deux requêtes, à dire dans la PR).
- Pas de limiteur de débit nouveau : une fusion est bornée à l'utilisateur et à une transaction.

## 11. Tests exigés

Domaine (sans base) : règles 1 à 5 et 13, `MergeIngredientsHandler` avec les `InMemoryRepository` (alias, fusion de lignes de liste, `checked`, conversion de quantité `f`, `ceil` des compteurs, choix du drive A gagne / B passe sur A, `SpyEventDispatcher` pour les statistiques) ; réconciliation d'unités en table (compatible, unité manquante, unité d'achat différente) ; **base réelle** : le dépôt de fusion (`OutputAdapter`) avec des données de tous les types, y compris la liste `SENT` (refus), la recette qui contient les deux, la donnée héritée d'un autre utilisateur (refus), l'alias et le rejeu idempotent, les versions montées ; **budget** `fusionner-ingredients`, `supprimer-compte` ; controller (`MockCommandBus`/`MockQueryBus`, clés JSON de l'aperçu, codes) ; parcours avec les vrais bus ; **une fusion ne change aucun choix du drive d'un autre utilisateur** et un plan relu après fusion donne les produits de `A` ([`analyse.md`](analyse.md) § 11) ; front : Vitest des feuilles (états : normal, unité manquante, liste envoyée, erreur), parcours Playwright A et B.
