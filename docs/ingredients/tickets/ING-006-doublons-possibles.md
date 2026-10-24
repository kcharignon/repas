# ING-006 — Doublons possibles : la liste proposée et « Pas le même »

- **Dépôt** : back
- **Jalon** : I4 Fusion
- **Taille** : M
- **Effort recommandé pour l'agent** : **high**
- **Dépend de** : ING-004 (`IngredientNameMatcher::similar`), ING-005 (fusion, alias, nettoyage de paires)
- **Statut** : fait (PR #239)
- **Lire d'abord** : [`fusion.md`](../fusion.md) § 8 ; [`rapprochement.md`](../rapprochement.md) §§ 7.3 et 8 ; [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décision h ; skill `/repas-dev` (lecture : Query, QueryHandler, Loader, DTO ; écriture) ; ADR 0021 (purge).

## Contexte

L'écran « Doublons possibles » (piste A de la maquette) propose à l'utilisateur les paires d'ingrédients qui se ressemblent dans son rayon ; « Fusionner » ouvre la confirmation d'ING-005, « Pas le même » écarte la paire **pour toujours**. Ce ticket livre les deux routes et la mémoire des paires écartées.

## Portée

1. **Migration additive** : `CREATE TABLE ingredient_distinct (user_id VARCHAR(36) NOT NULL, first_slug VARCHAR(255) NOT NULL, second_slug VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (user_id, first_slug, second_slug))`, slugs rangés par ordre alphabétique (`first_slug < second_slug`, contrainte `CHECK`). Entité `IngredientDistinct`.
2. **Écriture** `MarkIngredientsDistinct` (`Command` + `Handler`, port `IngredientDistinctRepository`) : `POST /api/v1/ingredients/{slug}/distinct-from` corps `{"other": "<slug>"}`, `204`, idempotent (`ON CONFLICT DO NOTHING`), refuse (`404`) un ingrédient qui n'est pas à l'utilisateur, `422` si les deux sont identiques. Nettoyage : `RemoveIngredientHandler` et `MergeIngredients` (ING-005) suppriment les lignes qui nomment l'ingrédient supprimé.
3. **Lecture** `ListDuplicateIngredients` (Query + QueryHandler + `IngredientDuplicateLoader` + DTO) : `GET /api/v1/ingredients/duplicates` -> `{member, totalItems}` selon `fusion.md` § 8 : règles 1 à 5 (même nom normalisé ; à une faute près à 0,90 ; nom précisé, 10 au plus ; jamais deux adoptions de communs différents ; jamais une paire écartée), même rayon, ingrédients de l'utilisateur seulement ; par proposition : `reason`, rayon (slug, nom), les deux ingrédients (`slug`, `name`, `recipeCount`, `listCount`), `suggestedKeep`. Le Loader utilise le **matcher de domaine** d'ING-004 (`similar`) sur les ingrédients lus en SQL ; classement : `SAME_NAME`, `TYPO`, `QUALIFIED`, puis par rayon et par nom ; résultat identique à chaque appel.
4. **Suppression de compte** : `PurgeUserDataHandler` supprime les lignes de l'utilisateur ; `UserOwnedDataCoverageTest` gagne `IngredientDistinct::class => ['userId']` ; budget `supprimer-compte` +1.
5. `#[OA\…]`, `make openapi`.

## Hors portée

L'écran (ING-007) ; un seuil réglable ; « annuler » un « Pas le même » (backlog) ; une notification.

## Critères d'acceptation

- Étant donné `U` avec « Tomate » et « Tomates » (même rayon), alors la liste contient une proposition `SAME_NAME`, `suggestedKeep` = le plus utilisé, avec les comptes de recettes et de listes ; avec « Tomate » et « Tomate cerise » : `QUALIFIED` seulement si l'un est personnel ; « Poivre » et « Poivre noir » (deux ingrédients créés par `U`) : `QUALIFIED`.
- Deux adoptions de communs **différents** (« tomate » et « tomate cerise » adoptés) ne sont jamais proposées ; deux adoptions du **même** commun le sont (`SAME_NAME`).
- Deux ingrédients de rayons différents ne sont jamais proposés.
- Après `POST …/distinct-from`, la paire n'apparaît plus, ni dans l'ordre inverse ; elle réapparaît **jamais** après un renommage ; après suppression de l'un des deux, la ligne `ingredient_distinct` disparaît.
- Les propositions d'un utilisateur ne montrent jamais l'ingrédient d'un autre.
- 10 propositions `QUALIFIED` au plus.
- Compte supprimé : plus de ligne `ingredient_distinct` (test de base et parcours `AccountAdministrationFlowTest`).
- Aucun appel ne modifie d'ingrédient : seule la mémoire des paires écrit.

## Tests exigés

`MarkIngredientsDistinctHandlerTest` ; `IngredientDistinctRepositoryTest` (ordre alphabétique, idempotence) ; **`IngredientDuplicateLoaderTest` sur base réelle** avec des jeux qui couvrent chaque règle (dont l'exclusion des adoptions distinctes, des paires écartées, des rayons différents, du plafond de 10) ; handler de la query avec `InMemoryLoader` ; controllers ; `UserOwnedDataCoverageTest` ; budget. Une mesure de volume : le Loader reste à une requête de lecture (+ une de comptes) quel que soit le nombre d'ingrédients (260 adoptés + quelques personnels).

## Definition of Done

`repas-dev` § 4.

## Pièges

- La **comparaison est quadratique** par rayon : 38 ingrédients dans le plus grand rayon du catalogue, quelques centaines au pire par utilisateur ; garder la boucle par rayon (jamais sur tous les couples), et mesurer.
- Le Loader a besoin de `adopted_from` pour la règle 4 : lire la colonne, pas l'alias.
- Ne pas proposer une paire dont l'un des deux est un commun partagé (non adopté) : seuls les ingrédients **de l'utilisateur** sont comparés.
- « Pas le même » est une préférence privée de l'utilisateur : jamais de chemin d'accès administrateur à la lire.

## Réalisation

Livré avec les écarts suivants, **tous décidés par le propriétaire pour ING-006** (ils gagnent sur le texte du ticket ci-dessus, qui garde la trace du cadrage initial). **Un seul changement de schéma, additif** : la table `ingredient_distinct` (migration `Version20261021100000`).

**Les routes et les contrats** (`docs/api/openapi.json` fait foi, ajouts seulement ; clés toujours écrites) :

- `GET /api/v1/ingredients/duplicates` : `{member, totalItems}`. Chaque membre : `first` et `second` (`slug`, `name`, `departmentSlug`, `departmentName`, `purchaseUnitName`, `recipeCount`, `listCount`), `score` (nombre de 0,90 à 1), `level` (`EXACT`, `TYPO`, `SUGGESTION`), `suggestedKeep` (le slug de `first` ou de `second`). Trié du plus probable au moins probable, 50 au plus, même ordre à chaque appel. Vide (`200`) sans ingrédient personnel (l'administrateur). La route précède `GET /ingredients/{slug}` (`priority: 10`).
- `POST /api/v1/ingredients/{slug}/distinct-from`, corps `{"other": "<slug>"}` : `204`, idempotent (rejouée, dans un sens ou dans l'autre) ; `404` si l'un des deux est inconnu, commun ou à un autre utilisateur (administrateur compris) ; `422 CANNOT_BE_DISTINCT_FROM_ITSELF` ; `422` corps invalide.

**Écarts avec le ticket** :

1. **Pas de catégorie « nom précisé » (`QUALIFIED`) ni de `reason`.** Le garde-fou du matcher (même nombre de mots) reste entier : « Poivre » / « Poivre noir » ne sont pas proposés (ils se fusionnent depuis la fiche, `merge-candidates`). La proposition porte `level` (`EXACT`, `TYPO`, `SUGGESTION`) et `score` à la place de `reason`, et les niveaux `SUGGESTION` (de 0,90 à 0,97) sont proposés. Pas de plafond de 10 à part : le plafond est de 50 paires pour l'ensemble.
2. **`suggestedKeep`** : le plus de recettes, puis de listes, puis le nom le plus court, puis l'ordre alphabétique du nom normalisé, puis des slugs ; voir `DuplicatePair::suggestedKeep` et `fusion.md` § 8. Pas d'ancienneté (un ingrédient n'a pas de date de création). Les deux côtés sont `first` et `second` dans l'ordre des slugs (stable d'un appel à l'autre, indépendant de la préférence) : le front bascule « À garder » sans recalcul.
3. **`MatchLevel`/`NameSimilarity` et `IngredientNameMatcher::compare()`** (ajout, ING-004 n'avait que `similar` : le ticket l'attendait) : même verdict que `similar`, mais avec le **score**, sur deux candidats dont les mots sont déjà calculés, et indépendant de l'ordre des arguments (le garde-fou apparie les mots dans l'ordre du premier : on compare toujours le plus petit slug au second). Un refus rapide ajouté au garde-fou (`mayBeTheSameLetters` : longueur et inventaire des lettres) ne change **aucun verdict** (le jeu de vérité terrain, 1 421 cas, donne les mêmes chiffres) : il divise par trois à quatre le coût de la comparaison de masse (3 270 couples : environ 105 ms avant, 30 ms après).
4. **Trois lectures, pas deux** : les ingrédients de l'utilisateur (avec rayon, unité, `adopted_from`), ses « Pas le même », puis les nombres de recettes et de listes des seuls ingrédients des paires rendues (`IngredientMergeUsageReader::counts()`, partagé avec la fusion). Seulement une lecture si le compte a moins de deux ingrédients.
5. **Nettoyage** : `MergeIngredientsHandler` (étape 6 de `fusion.md` § 3) et `RemoveIngredientHandler` effacent les paires qui nomment l'ingrédient disparu ; `PurgeUserDataHandler` celles du compte. Budgets : `fusionner-ingredients` 21 -> 22, `supprimer-ingredient` 11 -> 12, `supprimer-compte` 22 -> 23, **chacun d'une requête** (`DELETE ingredient_distinct`) ; nouveau scénario `pas-le-meme` = 4 (deux ingrédients lus avec rayon et unité, une insertion).
6. La contrainte `CHECK (first_slug COLLATE "C" < second_slug COLLATE "C")` n'est que dans la migration (Doctrine ne décrit pas les contraintes `CHECK`, le schéma de test en est dépourvu) : éprouvée à la main sur la migration, et l'ordre est imposé par le modèle (`IngredientDistinctPair::create`).

**Preuves** : `DuplicateIngredientFinderTest` (règles une à une, vérité terrain : tous les pluriels, accents, casses, ponctuations proposés ; 432 fautes de frappe sur 481 ; aucun des 40 « qualificatifs » ; 6 des 26 « frères trompeurs » proposés à 0,90, aucun automatique ; les 256 communs du catalogue, vus comme les ingrédients d'un utilisateur, ne donnent **aucune** paire) ; `IngredientDuplicateLoaderTest` sur base réelle (chaque règle, un commun jamais proposé, un autre utilisateur jamais montré, « Pas le même » jamais reproposé dans l'autre ordre ni après un renommage, plafond de 50, ex æquo, jeu de 106 personnels + 261 adoptés dont 7 au slug suffixé : exactement les 9 paires plantées, **3 requêtes**, environ 55 ms) ; `DuplicateIngredientsApiFlowTest` (vrais bus et JWT : lecture, écartement, rejeu, renommage, fusion, suppression, administrateur, autre utilisateur) ; `AccountAdministrationFlowTest` et `UserDataPurgeRepositoryTest` (purge du compte).

**Compatibilité avec le code précédent (ADR 0017 règle 6)** : la table est nouvelle et le code d'avant l'ignore ; le retour arrière du code ne défait pas le schéma. Si le code précédent supprime un ingrédient ou un compte, des lignes d'`ingredient_distinct` peuvent rester (inoffensives : un slug qui revient plus tard est un ingrédient du même nom et du même propriétaire, dont la paire écartée reste écartée ; le compte supprimé n'a plus d'utilisateur).

**Points d'attention pour ING-007 (front)** :

- Grouper les cartes par `first.departmentSlug` (le même pour les deux côtés) ; la phrase d'effet vient de `recipeCount`/`listCount` du côté absorbé (celui qui n'est pas gardé) ; « Fusionner » appelle `merge-preview` avec `{slug}` = l'absorbé et `into` = le gardé, puis `merge`. « Pas le même » appelle `distinct-from` (n'importe quel côté dans l'URL).
- Tolérer un `level` inconnu (traiter comme `SUGGESTION`). `score` est un nombre JSON (`1` pour 1,0).
- `totalItems` est le nombre de paires rendues, **50 au plus** : pour la ligne « 3 doublons possibles », afficher « 50 doublons possibles » ou « Plus de 49 » à ce plafond, et la liste se renouvelle après avoir traité les premières.
- Après une fusion ou un « Pas le même », relire la liste : les nombres d'usage ont changé.
- Les « noms précisés » ne sont plus proposés : la fiche d'ingrédient (entrée B) reste le seul chemin pour fusionner « Poivre » avec « Poivre noir ».
