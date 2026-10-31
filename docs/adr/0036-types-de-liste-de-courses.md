# 0036 — Types de liste de courses : une liste en cours par type

- **Statut** : Accepted (2026-10-31, demande du propriétaire, issue #380)
- **Date** : 2026-10-31
- **Liens** : [0013](0013-agregats-references-par-identifiant.md), [0016](0016-controle-de-version-etag-if-match.md), [0023](0023-liste-envoyee-au-drive.md) (liste `SENT`), [0028](0028-ingredients-propres-a-chaque-utilisateur.md) (données propres à chaque compte), [0029](0029-commande-vocale-alexa.md), [0035](0035-contextes-etanches-open-host-service.md), `docs/listes/roadmap.md`, issue #380, maquette Claude Design (5 écrans)

## Contexte

Un compte n'a aujourd'hui qu'**une liste de courses en cours** (`ShoppingListStatus::ACTIVE`) : créer, activer ou rouvrir une liste met l'autre en pause. Le propriétaire veut garder ce comportement par défaut et laisser ceux qui le souhaitent **séparer leurs courses par magasin** : un type « Boucherie » pour tout le rayon Viande, un type « Marché » pour les fruits et légumes, chacun avec sa liste en cours.

## Décision

### Vocabulaire

1. **Type de liste** : un nom que l'utilisateur crée, propre à son compte (comme ses ingrédients, ADR 0028). **Liste principale** : la liste sans type, qui existe pour tous, ne se supprime pas et reste exactement la liste d'aujourd'hui. **Produit à part** : un produit dont le type est choisi à la main. Jamais « catégorie », « destination » ou « routage » à l'écran.
2. **Zéro type = rien ne change.** Un compte sans type voit les mêmes écrans, routes et réponses qu'avant (les tests existants passent sans modification).

### Où va un produit

3. **Une seule fonction, un seul endroit** (service de domaine `ListDestinationResolver`, comme `IngredientVisibility::visibleBy`) : 1) le choix fait sur le produit (`ingredient.list_destination` : un type, ou la valeur réservée `main` = liste principale) ; 2) sinon le type de son rayon (`department_list_type`) ; 3) sinon la liste principale. Pour N produits, **une seule lecture SQL** (port `ListRoutingRepository`) : nombre de requêtes constant.
4. **Un rayon est dans un seul type à la fois** (clé `owner_id` + `department_slug`) ; rattacher un rayon déjà pris le déplace.
5. **Le choix se fait à l'ajout et se fige** : changer le type d'un rayon ou d'un produit ne déplace aucune ligne déjà dans une liste. Pour que retirer ou ajuster un repas ne dépende jamais de la destination du jour, **chaque repas d'une recette éclatée garde les produits qu'il a posés dans sa liste** (`meal.placed_ingredients`, une liste JSON de slugs d'ingrédients ; vide = toute la recette, comme tout repas d'avant les types ; LST-005). Une fusion de deux produits y remplace l'absorbé par le gardé.

### Des listes en cours, une par type

6. **Une seule liste `ACTIVE` par type, et une seule pour la liste principale.** Créer une liste, activer une liste en pause (`ActivatedShoppingList`) ou rouvrir une liste envoyée (`ReopenShoppingList`) ne met en pause que la liste en cours **du même type** (`shopping_list.list_type_id`, vide = principale). La règle est celle de l'application, sans contrainte unique en base (comme le reste du dépôt).
7. **Ouverture à la demande.** Un ajout vers un type sans liste en cours ouvre une liste de ce type, **nommée comme lui**, sans toucher aux autres. Un ajout ne modifie jamais une liste `SENT` : il ouvre une liste neuve du type. Pour la liste principale, le comportement d'aujourd'hui est gardé.
8. **Lecture** : `GET /api/v1/shopping-lists/open` rend toutes les listes en cours (principale d'abord, puis les types par date de création) ; `GET /shopping-lists/active/summary` et `PUT /shopping-lists/active` gardent leur sens (la liste principale).

### Recettes éclatées

9. **Une recette planifiée remplit une liste par destination de ses produits.** Chaque liste qui reçoit au moins un produit reçoit un **repas** (recette, nombre de personnes) ; les repas d'une même planification partagent `meal.planned_group_id`. Changer le nombre de personnes ou retirer la recette agit sur **tout le groupe**, en une transaction ; si une des listes du groupe est `SENT`, **tout est refusé** (`409 SHOPPING_LIST_SENT`, rien n'est écrit). Précisions de LST-005 : une recette dont tous les produits vont dans une seule liste n'a **ni groupe ni répartition** (un repas qui pose toute la recette, exactement comme avant : c'est le cas de tout compte sans type) ; le groupe se cherche parmi les listes du **propriétaire** seul ; une liste du groupe **terminée** est de l'histoire et n'est pas modifiée, une liste **en pause** (c'est ce que devient la liste d'un type supprimé) suit le groupe ; retirer la recette retrouve son repas dans la liste principale d'abord, puis dans la plus ancienne liste de type en cours (une recette dont aucun produit n'est allé dans la liste principale).
10. **Une recette déjà dans une des listes visées n'est pas planifiée deux fois** : tout est refusé avec l'erreur actuelle (`ShoppingListException::recipeAlreadyInList`), rien n'est écrit.
11. `PUT /shopping-lists/active/recipes/{recipeId}` répond `200 {lists: [{id, name, typeId}]}` (les listes qui ont reçu quelque chose : la principale d'abord, puis les types dans l'ordre où la recette les atteint ; `name` est celui de la liste ou à défaut sa date en français, comme `GET /shopping-lists/open`) au lieu de `204` ; ce corps sert au message du front. Augmenter ou diminuer un produit (`…/active/ingredients/{slug}/increase|decrease`) vise la liste de sa destination ; diminuer cherche d'abord la liste de sa destination, sinon n'importe quelle liste en cours qui en a une ligne.
12. `If-Match` (ADR 0016) s'applique à la liste principale, comme aujourd'hui, quand elle est visée ; il est ignoré pour les listes d'un type.

### Types, rayons, produits à part

13. **API** : `GET|POST /api/v1/shopping-list-types`, `GET|PUT|DELETE /api/v1/shopping-list-types/{id}` (`PUT` : `name` et `departmentSlugs`, le jeu complet des rayons) ; `PUT /api/v1/ingredients/{slug}/list-destination` `{destination: "default"|"main"|<typeId>}` (le slug passe par les alias, ADR 0028) ; `GET /ingredients/{slug}` gagne `listDestination` et la liste des rayons `listTypeId`. Voter `LIST_TYPE_OWNER` dans `OwnerVoter` (port `OwnershipLoader`), **sans passe-droit `ROLE_ADMIN`** : le type d'un autre est `404` en lecture et `403` en écriture.
14. **Plafonds** : 10 types par compte (`ShoppingListType::MAX_PER_OWNER`, test d'existence du N-ième, `422 LIST_TYPE_LIMIT_REACHED`), nom de 1 à 40 caractères, unique par compte une fois normalisé (`409 LIST_TYPE_NAME_ALREADY_USED`), première lettre en majuscule (`StringTool::capitalizeFirst`, ADR 0030).
15. **Supprimer un type ne perd rien** (une transaction) : ses rattachements de rayons sont effacés, `ingredient.list_destination` qui le désignait redevient vide, ses listes perdent leur type, et celle qui est `ACTIVE` passe `PAUSED` (version +1) sous son nom, visible dans « Mes listes ». Aucune ligne n'est déplacée ni fusionnée. Renommer un type ne renomme pas ses listes.
16. **Copie, amorçage, fusion, compte** : la copie d'un produit (copie de recette, amorçage d'un nouveau compte) ne porte jamais `list_destination` ; la fusion garde celui du produit conservé ; la purge du compte efface types, rattachements et listes (`UserOwnedDataCoverageTest`) ; les rayons sont des données globales, un rayon supprimé efface ses rattachements.

### Autres contextes

17. **Drive** : rien ne change, l'envoi se fait déjà par identifiant de liste. **Voix** (`Voice`) : ajouter va dans la liste de la destination du produit, demander et retirer cherchent dans toutes les listes en cours, la réponse nomme la liste quand ce n'est pas la principale ; par les ports et les Open Host Services existants (ADR 0035).
18. **Migration** : additive, compatible avec le code précédent (ADR 0017 règle 6) : tables `shopping_list_type` et `department_list_type`, colonnes `shopping_list.list_type_id`, `ingredient.list_destination` et `meal.planned_group_id` (LST-002), puis `meal.placed_ingredients` (LST-005, `JSONB`), toutes facultatives, aucune donnée écrite. Un retour arrière du code ignore les types et ne voit que la liste principale.

## Conséquences

- Positives : une option sans coût pour qui ne l'utilise pas ; le choix d'une liste est une fonction pure testée ; chaque liste reste un agrégat autonome (cochage, envoi au drive, fin) ; pas de nouvel agrégat.
- Négatives / coûts : une recette éclatée coûte plus d'écritures (chaque liste visée est lue et enregistrée une fois : le budget SQL suit le nombre de listes visées, jamais le nombre de produits pour les lectures) ; deux ajouts simultanés vers un type sans liste peuvent ouvrir deux listes (la lecture prend la plus ancienne) ; un ancien site ne montre que la liste principale.
- Garde-fous : `ListDestinationResolverTest`, tests de handlers sur `InMemoryRepository`, `ListTypesApiFlowTest` et `SplitRecipeApiFlowTest` (vraie base), `UserOwnedDataCoverageTest`, budgets SQL (`creer-type-de-liste`, `planifier-recette-eclatee`, `modifier-portions-repas-eclate`, `retirer-repas-eclate`, `ajouter-produit-a-un-type`) et `SplitRecipeReadsAreConstantTest` (les lectures ne dépendent pas du nombre de produits), `DocsListTypesTest` (cohérence du contrat).

## Alternatives écartées

- **Le repas vit seulement dans la liste principale** : la recette disparaîtrait de la vue des courses des autres types et les quantités ne pourraient plus se retirer proprement.
- **Fusionner les lignes à la suppression d'un type** : les unités peuvent différer (`addRow` suppose la même unité) ; mettre la liste en pause ne perd rien et laisse l'utilisateur décider.
- **Recalculer la répartition d'un repas d'après la destination du jour** : changer la destination d'un produit après la planification retirerait ou ajusterait la ligne de la mauvaise liste ; chaque repas se souvient donc de ses produits (décision 5).
- **Retrouver le produit d'un repas par « la liste porte une ligne de ce produit »** : un produit ajouté à la main dans une autre liste du groupe serait déduit deux fois.
- **Un type global défini par l'administrateur** : chaque foyer a ses magasins ; la règle « données propres à chaque compte » (ADR 0028) s'applique.
