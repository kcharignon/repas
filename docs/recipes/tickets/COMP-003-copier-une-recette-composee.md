# COMP-003 — Copier une recette composée : copier aussi ses recettes utilisées (back)

- **Dépôt** : **back**
- **Jalon** : I9 Recettes utilisées dans d'autres recettes
- **Taille** : M
- **Effort recommandé pour l'agent** : **xhigh** (règle de visibilité de la recette secrète à la copie, plafond de compte, rapprochement des ingrédients)
- **Dépend de** : COMP-001 (modèle, `findByIds`, arêtes) ; COMP-002 recommandé (les règles d'écriture, pour construire les composants de la copie) ; **un agent à la fois**
- **Statut** : à faire
- **Lire d'abord** : [ADR 0040](../../adr/0040-recettes-utilisees-dans-d-autres-recettes.md) décisions 33 à 36 ; [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décision 8 ; [`../../ingredients/rapprochement.md`](../../ingredients/rapprochement.md) ; [ADR 0033](../../adr/0033-recette-secrete.md) ; [ADR 0037](../../adr/0037-copier-une-recette-depuis-marmiton.md) (le point d'entrée unique `RecipeCopier::copy`) ; [ADR 0038](../../adr/0038-sous-ingredients.md) décisions 20 et 24 (la liaison des parties) ; `CLAUDE.md`.

## But

Copier la recette composée d'un autre copie **aussi ses recettes utilisées** (la structure est le but), avec **un seul rapprochement d'ingrédients**, sans jamais dépasser le plafond du compte ni révéler une recette secrète. Copier une recette sans composant ne change ni le comportement ni les budgets.

## Vérifié dans le code le 2026-11-08

- `RecipeCopier::copy(Recipe $original, string $copierId, string $newRecipeId): Recipe` est **l'unique** point d'entrée (`CopyRecipeHandler` et `ImportMarmitonRecipeHandler` l'appellent) ; il vérifie `isVisibleTo`, rapproche les ingrédients (`ingredientsAndUnitsOf` + `retainOrCopy`, qui traitent chaque ingrédient une fois), lie les parties si `$copiedAPart` (`IngredientPartLinkRepository::linkCopies`), appelle `Recipe::copyFromOriginal` (qui reprend `serving`, `type`, `originalId`, `sourceUrl`, et **`secret` seulement si le copieur est l'auteur**), `setName(capitalizeFirst)`, `save`, puis **un** `RecipesOrIngredientsCreatedEvent` (recipeIds).
- `assertCanCopy(copierId)` juge `Recipe::MAX_PER_AUTHOR_WITH_COPIES` (2 000) **avant toute lecture** ; `CopyRecipeHandler` l'appelle d'abord, puis charge l'original.
- `RecipeRepository::findCopyIdOf(originalId, authorId)` : une copie par original et par auteur, la plus ancienne, une requête.
- Les ingrédients d'une recette utilisée sont ceux de **l'auteur de l'original** (même compte, ADR 0040 décision 7) ; la copie les rapproche de ceux du copieur comme pour une recette ordinaire.

## À livrer

1. **`RecipeCopier::copy()` copie l'arbre** quand `$original->hasComponents()` (sinon strictement le code d'aujourd'hui, zéro requête de plus) :
   - les recettes utilisées de l'original sont lues **en lot** (`findByIds(componentIds())`) ; une arête orpheline est ignorée ;
   - **le copieur est l'auteur de l'original** : la copie **partage** ses recettes utilisées (mêmes identifiants, multiplicateur et position gardés), aucune recette utilisée n'est recopiée ; la copie d'une secrète est secrète (ADR 0033, déjà dans `copyFromOriginal`) ;
   - **sinon** : pour chaque recette utilisée, le copieur a-t-il déjà une copie (`RecipeRepository::findCopyIdsOf(list<string> $originals, string $copierId): array<string,string>`, **en lot**, une requête) ? Elle sert **pourvu qu'elle puisse l'être** (sans composants, et ordinaire si la recette copiée l'est : sinon une copie neuve) ; les autres sont copiées (`Recipe::copyFromOriginal`, ordinaires, nom en majuscule, lien d'origine) **avant** la recette composée, qui reçoit des composants qui désignent les copies (`setComponents`, drapeau vrai) ;
   - **un seul rapprochement d'ingrédients** : `ingredientsAndUnitsOf` est appliqué à l'**union** des lignes de l'original et de toutes les recettes utilisées à copier (un ingrédient présent dans la tarte et dans la pâte est rapproché ou copié **une** fois, ses unités fusionnées), puis la liaison des parties **une fois** (`$copiedAPart`) ;
   - **un seul événement** `RecipesOrIngredientsCreatedEvent` dont `recipeIds` liste toutes les recettes **créées** (les copies réutilisées n'y sont pas) ;
   - la recette copiée en tête est celle que renvoie `copy()` (la réponse `201` + `Location` de `POST /recipes/{id}/copy` ne change pas).
2. **Le plafond** : `RecipeCopier` juge, **avant la première écriture**, `authorHasAtLeast(copieur, MAX_PER_AUTHOR_WITH_COPIES − à créer + 1)` avec `à créer = 1 + recettes utilisées sans copie réutilisable` ; `422 RECIPE_LIMIT_REACHED` sinon, rien n'est écrit. `assertCanCopy` du handler (le plafond de la seule recette, avant toute lecture) ne change pas.
3. **La recette secrète d'un autre** reste `404` avant toute écriture (la garde existante, répétée pour les recettes utilisées : une recette ordinaire n'en a que d'ordinaires, mais une arête qui ferait voir une secrète d'un autre est ignorée, jamais copiée).
4. **Import Marmiton** : inchangé (la neutre n'a jamais de composants, sa copie est une recette ordinaire qui pourra en avoir).
5. **Docs** : ADR 0040 (amendements), roadmap, `docs/ingredients/rapprochement.md` (une phrase : le rapprochement porte sur l'union des lignes d'une recette composée), skill `repas-dev` (Définition of Done, ligne « Copie de recette » : une recette composée copie aussi ses recettes utilisées par `RecipeCopier::copy()`).

## Tests

- `CopyRecipeHandlerTest` étendu (unitaire, doubles en mémoire) : un autre compte copie « Tarte + Pâte » → deux recettes créées (la pâte d'abord), composants de la copie sur la copie de la pâte, multiplicateur et position gardés ; **un seul** « Œuf » ou « Farine » pour le copieur même si la tarte et la pâte l'utilisent ; une copie de la pâte déjà là est réutilisée (et **pas** si elle a des composants, ni si elle est secrète pour un parent ordinaire) ; l'auteur copie sa propre composée : mêmes composants, aucune pâte recopiée, secrète → copie secrète ; orphelin ignoré ; la secrète d'un autre `404` sans écriture ; plafond : 1 recette de moins que la limite avec une composée à deux recettes à créer → `RECIPE_LIMIT_REACHED`, rien d'écrit ; **une copie réutilisée ne compte pas dans le plafond** ; un seul événement avec les bons identifiants.
- `CopyComposedRecipeTest` (vraie base) : parcours complet sur trois comptes ; la partie d'ingrédient d'une recette utilisée est reliée une fois ; les copies restent autonomes (modifier la pâte de l'auteur ne change pas celle du copieur) ; `GET` de la copie rend `components` vers les copies.
- Budgets SQL : `copier-recette-composee` figé à sa mesure ; **`copier-recette`, `-ingredient-personnel`, `-ingredient-rapproche`, `-sans-ingredients` ne bougent pas** ; test de lectures constantes (le nombre de requêtes de la copie ne dépend pas du nombre de recettes utilisées, hors une écriture par recette créée).

## Critères d'acceptation

- [ ] `make test`, `make quality`, `make openapi-check` verts ; aucune dépendance entre contextes de plus.
- [ ] Copier une recette sans composant : mêmes requêtes qu'avant (budgets inchangés).
- [ ] PR vers `main` (**À relire** : le plafond jugé sur « compte + à créer », la réutilisation d'une copie existante, le partage des composants par l'auteur).
