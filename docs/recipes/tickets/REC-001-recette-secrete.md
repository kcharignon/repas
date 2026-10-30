# REC-001 — La recette secrète (back)

- **Dépôt** : **back** (cette fiche) ; le front est **REC-002** (fait, front #278)
- **Taille** : M
- **Effort recommandé pour l'agent** : **xhigh** (fonction de vie privée : une fuite annule son intérêt)
- **Dépend de** : —
- **Statut** : fait (PR de la branche `feat/recette-secrete`)
- **Lire d'abord** : [ADR 0033](../../adr/0033-recette-secrete.md) ; `CLAUDE.md` (puces « Recette secrète », « Ingrédients propres à chaque utilisateur », « Bornes et plafonds de `Repas` ») ; skill `repas-dev`.

## Contexte

Demande d'un utilisateur du propriétaire (2026-10-30) : « quand on crée une recette (originale), il faut pouvoir la rendre secrète : non visible par les autres ». Maquette validée (Claude Design, planche 6, `Recette-secrete`) : un interrupteur « Recette secrète : toi seul la vois. Les autres ne la trouvent pas dans « Recettes des autres » » dans le formulaire d'une recette, et une pastille « Secrète » (cadenas) sur la carte dans « Mes recettes ».

## Règle

Une recette a un drapeau `secret` (booléen, **faux par défaut : toute recette existante reste visible**). Une recette secrète est **visible de son auteur seul. Aucun passe-droit administrateur** pour la lire, la lister, la copier, la modifier ni la supprimer.

| Chemin | Avant | Maintenant |
|---|---|---|
| `GET /api/v1/recipes/other-people` (liste, `totalItems`, recherche `q`) | toutes les originales des autres, non copiées | sans les secrètes : `RecipePostgreSQLLoader::otherPeopleQuery` (`secret = false`), la seule requête dont partent la page, le total et la recherche |
| `GET /api/v1/recipes/{id}` | lisible par tout utilisateur connecté | `RecipeLoader::get(id, userId)` : la secrète d'un autre est `404` (message d'une recette inconnue, jamais `403`), administrateur compris |
| `POST /api/v1/recipes/{id}/copy` | aucun contrôle de visibilité | `404` pour la secrète d'un autre, avant toute écriture (aucun ingrédient copié) ; garde répétée dans `Recipe::copyFromOriginal` |
| `PUT` / `DELETE /api/v1/recipes/{id}` par un non-auteur | `403` ; l'administrateur passait | `404` pour la secrète d'un autre, **administrateur compris** (`OwnerVoter` + `OwnershipLoader::recipeAuthorIdFor`), `403` pour une ordinaire ; l'administrateur garde l'édition et la suppression des ordinaires |
| `PUT` / `DELETE /api/v1/shopping-lists/active/recipes/{recipeId}` | `PUT` déjà `404` pour la recette d'un autre (`ShoppingList::addMeal`) ; `DELETE` retirait le repas hérité d'une recette d'un autre | `PUT` inchangé ; `DELETE` : `404` pour la secrète d'un autre (`removeMealFromPlanHandler`) |
| Repas hérités d'une recette d'un autre (d'avant #62) : `GET /shopping-lists/{id}`, liste active de la voix, `PUT /meals/{id}/serving` | nom et type de la recette rendus, quantités recalculables | secrète d'un autre : le repas n'est plus rendu (`ShoppingListPostgreSQLLoader`, `PlannedMealsInActiveListPostgreSQLLoader`), `PUT …/serving` et `DELETE` en `404` comme pour une recette retirée |
| Voix (`SpokenRecipeCandidates`, `PlannedMealsInActiveList`) | les recettes de l'utilisateur seulement | inchangé (sa propre recette secrète est comprise) |
| `recipeCount` de `GET /api/v1/users` (administrateur) | nombre de créations | inchangé (un nombre, sans nom ni identifiant) |

## Contrat

- `POST` et `PUT /api/v1/recipes` : champ **facultatif** `secret`, un **booléen JSON strict** (`422` pour `"oui"`, `1`, `0`, `"true"`, une liste). Absent ou `null` : faux à la création, **inchangé** à la modification. Il suit la version (`ETag`, `If-Match`, `412`).
- Seul l'auteur change le drapeau : un administrateur qui modifie une recette ORDINAIRE d'un autre avec `secret: true` reçoit `403 RECIPE_SECRET_FORBIDDEN` et rien n'est écrit (la valeur actuelle, ou l'absence du champ, est une modification ordinaire).
- `GET /api/v1/recipes/{id}` et chaque recette de `GET /api/v1/recipe-types/{slug}/recipes` rendent `secret: boolean` (clé toujours écrite). `other-people` n'a pas cette clé.
- La copie que l'auteur fait de sa propre recette secrète est secrète aussi ; toute autre copie est ordinaire ; les copies déjà faites par d'autres restent à eux quand l'original devient secret.
- Migration `Version20261030210000` : `recipe.secret BOOLEAN DEFAULT FALSE NOT NULL`, `IF NOT EXISTS`, aucune donnée écrite, compatible avec le code précédent. Aucune table ni colonne utilisateur de plus : rien à ajouter à la purge du compte.

## Tests (les preuves)

`SecretRecipeApiFlowTest` (vrais bus, vraie base, vrai voter, administrateur compris ; la réponse d'une secrète est comparée, statut, corps et en-têtes, à celle d'un identifiant inconnu pour lire, copier, remplacer, rendre publique, supprimer, planifier et retirer du plan ; repas hérités), `ShoppingListPostgreSQLLoaderTest`, `PlannedMealsInActiveListPostgreSQLLoaderTest`, `RecipePostgreSQLLoaderTest`, `OwnershipPostgreSQLLoaderTest`, `RecipeSecretTest`, `CopyRecipeHandlerTest`, `UpdateRecipeHandlerTest`, `CreateRecipeHandlerTest`, `UpdateRecipeApiControllerTest`, `GetRecipeApiControllerTest`, `OwnerVoterTest`, `RecipeSecretMigrationTest`, `SpokenRecipeResolverFlowTest`.

## Limites connues

- Retour arrière du code (ADR 0017) : l'ancien code ignore `secret` et montre de nouveau les recettes secrètes à tous le temps du retour arrière.
- Un repas hérité qui pointe vers la recette secrète d'un autre ne se voit plus ni ne se modifie ni ne se retire : la liste peut être supprimée ou terminée.
- Les identifiants de recettes de `GET /shopping-lists/active/summary` ne portent aucun nom, laissés tels quels.

# REC-002 — La recette secrète (front)

**Fait** (front #278, 2026-10-30). Dépôt `kcharignon/repas-front`. Contrat figé sur le back `17a4b493`.

- Formulaire d'une recette : interrupteur « Recette secrète » (`input` `role="switch"`, cadenas décoratif, aide « Toi seul la vois. Les autres ne la trouvent pas dans « Recettes des autres ». »), éteint par défaut à la création, reflète `secret` à la modification.
- Pastille « Secrète » (cadenas, jetons `secret-soft` / `on-secret-soft`, clair et sombre, AA gardé par `secretContrast.spec.ts`) dans « Mes recettes » et près du titre de la recette. Rien ailleurs.
- Back plus ancien : à la modification, sans la clé `secret` dans la recette lue, pas d'interrupteur et rien d'envoyé ; à la création, la clé ne part que si l'interrupteur est allumé (une clé inconnue est ignorée par le back). **Déployer le back avant le site** : sinon une recette créée « secrète » resterait publique.
- Le `404` d'une recette secrète d'un autre est déjà géré par l'écran de lecture (« Cet élément n'existe plus. »). La copie n'a rien à changer côté front.
