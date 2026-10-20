# ING-006 — Doublons possibles : la liste proposée et « Pas le même »

- **Dépôt** : back
- **Jalon** : I4 Fusion
- **Taille** : M
- **Effort recommandé pour l'agent** : **high**
- **Dépend de** : ING-004 (`IngredientNameMatcher::similar`), ING-005 (fusion, alias, nettoyage de paires)
- **Statut** : à faire
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
