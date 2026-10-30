# 0034 — Supprimer un type de recette, recettes favorites

- **Statut** : Accepted (2026-10-31, demande du propriétaire)
- **Date** : 2026-10-31
- **Liens** : [0007](0007-conventions-api-json.md) (conventions d'API), [0013](0013-agregats-references-par-identifiant.md), [0016](0016-controle-de-version-etag-if-match.md), [0017](0017-deploiement-et-releases.md) règle 6, [0033](0033-recette-secrete.md) (même modèle de drapeau de l'auteur), `RemoveRecipeTypeHandler`, `ChangeRecipeFavoriteHandler`, `RecipeTypeRemovalApiFlowTest`, `RecipeFavoriteApiFlowTest`, `docs/recipes/tickets/REC-003-type-de-recette-supprimer-et-favoris.md`

## Contexte

Deux demandes du propriétaire, le même soir. (1) Les types de recette (entrée, plat, dessert…) se créent et se modifient par l'écran d'administration, mais jamais ne se suppriment : un type créé par erreur reste. (2) Une recette qu'on cuisine souvent se perd dans sa catégorie : il veut la mettre en favoris depuis sa fiche et la retrouver en tête de la liste des types, tout en la gardant aussi dans son type.

## Décision

### Supprimer un type de recette

1. **`DELETE /api/v1/recipe-types/{slug}?transferTo={slug}`**, administrateur seul (comme la création et la modification : les types sont des données globales, sans propriétaire). `transferTo` est **obligatoire** : le type qui reçoit **toutes** les recettes du type supprimé, de **tous** les utilisateurs. Un type n'est donc jamais supprimé en laissant des recettes sans type ; et comme la cible existe et diffère de la source, **il reste toujours au moins un type**.
2. **Une commande, une transaction** (`RemoveRecipeTypeHandler`, le bus des commandes est transactionnel) : les deux types sont lus (`404` si l'un est inconnu, la source d'abord), `RecipeType::assertCanBeReplacedBy` refuse la cible identique (`422 RECIPE_TYPE_TRANSFER_TO_ITSELF`), puis `RecipeRepository::moveToType` déplace les recettes en une seule instruction SQL, puis `RecipeTypeRepository::delete`. Rien n'est déplacé si le type n'est pas supprimé.
3. **La version de chaque recette déplacée augmente de un** (`version = version + 1` dans la même instruction, comme `makeCopiesOriginal` et la fusion d'ingrédients) : le type fait partie de son contenu, un formulaire ouvert avant le déplacement est vu comme périmé (`412`), jamais enregistré sur l'ancien type. Aucun contrôle de version par recette (opération en masse), aucun événement.
4. **Où les types sont référencés** (relevé) : la seule colonne est `recipe.type`. Les listes de courses, la planification et la voix lisent le type de la recette par jointure, sans le copier ; les repas déjà planifiés suivent donc le nouveau type. Aucune migration, aucune autre table.
5. Le slug d'un type supprimé peut être réutilisé par un nouveau type du même nom.

### Recettes favorites

6. **Un drapeau** : `recipe.favorite` (booléen, `FALSE` par défaut, `NOT NULL`), `Recipe::isFavorite()`. C'est le **choix de l'auteur**, comme `secret` (ADR 0033) : `Recipe::changeFavorite(favorite, userId)` refuse (`403 RECIPE_FAVORITE_FORBIDDEN`) un utilisateur qui n'est pas l'auteur (un administrateur compris : il peut modifier une recette ordinaire d'un autre, pas la mettre dans les favoris de son auteur) quand la valeur diffère de l'actuelle ; renvoyer la valeur actuelle est sans effet. La recette secrète d'un autre reste inconnue (`404`, jamais `403`, ADR 0033). Une recette secrète peut être favorite de son auteur et reste secrète.
7. **Une seule route d'écriture** : `PUT /api/v1/recipes/{id}/favorite` `{favorite: boolean}`, corps **strictement booléen** et obligatoire (`422` sinon), `204`. Elle suit la version de la recette (`If-Match`, `412`, ADR 0016) et **augmente la version** comme tout enregistrement d'une recette (`RecipeRepository::save`) ; le front relit la fiche après l'avoir changée. Elle n'a pas de route jumelle `DELETE` : un use case, une commande, un controller.
8. **Une lecture** : `GET /api/v1/recipes/favorites` (tout utilisateur connecté) rend `{member: [{id, name, secret}], totalItems}`, les recettes **de l'utilisateur** mises en favoris, tous types confondus, secrètes comprises, triées par slug puis identifiant. Vide tant qu'il n'a pas de favori. La route est sans conflit avec `GET /recipes/{id}` (l'identifiant est contraint à un UUID).
9. **Le contrat de la fiche** : `GET /api/v1/recipes/{id}` rend `favorite: boolean`, clé toujours écrite, vraie pour l'auteur seul si la recette est en favoris ; **toujours `false` pour la recette d'un autre** (le choix est celui de l'auteur, il ne se lit pas). `GET /recipe-types/{slug}/recipes` ne change pas : une recette favorite **reste dans son type**.
10. **Le type « Favoris » est une présentation du front, pas un type de recette** : il n'existe pas dans `recipe_type`, `GET /recipe-types` ne le rend pas (l'éditeur de recette et l'écran d'administration n'ont pas à le connaître), il n'a pas de slug réservé en base. Le front l'ajoute **en première position** quand `GET /recipes/favorites` n'est pas vide, avec son étoile ; sans favori, il est invisible.
11. **Les copies** : copier une recette (la sienne ou celle d'un autre) ne copie jamais le drapeau — une copie n'est jamais favorite ; mettre en favoris ses copies est un geste de plus.
12. **Migration** : `Version20261031100000` ajoute `recipe.favorite BOOLEAN DEFAULT FALSE NOT NULL` (`IF NOT EXISTS`, rejouable), sans écrire de donnée. Additive, compatible avec le code précédent (ADR 0017 règle 6) : l'ancien code ignore la colonne, ses `INSERT` la laissent à `FALSE` et ses `UPDATE` ne la touchent pas ; un retour arrière du code ne perd aucun favori, il les ignore le temps du retour. Aucune table ni colonne ne désigne un utilisateur de plus : la purge du compte n'a rien à gagner (le drapeau part avec la recette).

## Conséquences

- Positives : un type créé par erreur se retire sans perdre une recette ; une recette favorite se retrouve d'un geste ; peu de code (une commande, une requête, une colonne), aucune table de plus.
- Négatives / coûts : mettre en favoris coûte l'enregistrement d'une recette (9 requêtes, `mettre-recette-en-favoris`) et augmente sa version — un formulaire d'édition ouvert dans un autre onglet pendant ce geste est vu comme périmé ; supprimer un type touche toutes les recettes du type en une instruction (volume modéré : quelques milliers de lignes au plus). Un type supprimé ne revient pas : le recréer ne rend pas les recettes déplacées.
- Garde-fou : `RemoveRecipeTypeHandlerTest`, `RemoveRecipeTypeApiControllerTest`, `RecipeTypeRemovalApiFlowTest` (vraie base, recettes de plusieurs auteurs, refus sans écriture) ; `RecipeFavoriteTest`, `ChangeRecipeFavoriteHandlerTest`, `ChangeRecipeFavoriteApiControllerTest`, `ListFavoriteRecipesApiControllerTest`, `RecipePostgreSQLLoaderTest`, `RecipeFavoriteMigrationTest`, `RecipeFavoriteApiFlowTest` (auteur, autre, administrateur, secrète, version, copie) ; budgets SQL `supprimer-type-recette` et `mettre-recette-en-favoris`.

## Alternatives écartées

- **Suppression sans cible, ou refusée tant que le type a des recettes** : la première laisse des recettes sans type (la jointure les ferait disparaître), la seconde oblige à les déplacer une à une. La cible obligatoire dit la volonté en un geste.
- **Un vrai type « Favoris » en base** : il apparaîtrait dans l'éditeur de recette (on pourrait y ranger une recette) et dans l'écran d'administration, sa suppression casserait la fonction, et une recette ne pourrait plus être à la fois « Favoris » et « Dessert » (une seule colonne `type`).
- **Une table `recipe_favorite (user_id, recipe_id)`** : utile si plusieurs utilisateurs pouvaient mettre en favoris la même recette, mais la fiche d'une recette d'un autre ne propose que « Copier » ; elle ajouterait une purge du compte, un effacement avec la recette et une jointure à chaque lecture, pour un cas qui n'existe pas.
- **Un champ `favorite` dans le `PUT /recipes/{id}`** : il obligerait à renvoyer toute la recette pour changer une étoile ; le geste est un clic sur la fiche.
- **Deux routes `PUT` et `DELETE …/favorite`** (comme la planification d'un repas) : une commande de plus pour le même effet ; un corps booléen strict dit les deux cas.
- **Une version inchangée pour le drapeau** (écriture ciblée sans `save`) : plus légère, mais l'`ETag` d'une fiche dont le corps change ne doit pas rester le même, et la règle « toute écriture sur une recette passe par l'agrégat et sa version » (ADR 0016) vaut mieux qu'une exception.
