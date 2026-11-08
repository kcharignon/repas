# COMP-003 — Copier une recette composée : copier aussi ses recettes utilisées (back)

- **Dépôt** : **back**
- **Jalon** : I9 Recettes utilisées dans d'autres recettes
- **Taille** : M
- **Effort recommandé pour l'agent** : **xhigh** (règle de visibilité de la recette secrète à la copie, plafond de compte, rapprochement des ingrédients)
- **Dépend de** : COMP-001 (modèle, `findByIds`, arêtes) ; COMP-002 recommandé (les règles d'écriture, pour construire les composants de la copie) ; **un agent à la fois**
- **Statut** : fait (2026-11-09, PR à venir)
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

- [x] `make test`, `make quality`, `make openapi-check` verts ; aucune dépendance entre contextes de plus.
- [x] Copier une recette sans composant : mêmes requêtes qu'avant (budgets inchangés).
- [ ] PR vers `main` (**À relire** : le plafond jugé sur « compte + à créer », la réutilisation d'une copie existante, le partage des composants par l'auteur).

## Réalisation (2026-11-09)

- **Le point d'entrée** : `RecipeCopier::copy()` décide d'abord (`planComponents` → `CopiedComponents`), écrit ensuite. Sans composant (`Recipe::hasComponents()` faux) c'est strictement la copie d'avant : ni verrou, ni lecture en lot, ni recherche de copie (`copier-recette*` 20 / 41 / 21 / 17 et `importer-recette-marmiton*` 57 / 22 / 3 inchangés). `CopyRecipeHandler` et `ImportMarmitonRecipeHandler` ne changent pas.
- **`planComponents`** : le verrou `RecipeComponentLock` du COPIEUR (décision 27 : une copie qui pose des composants en est une ; jamais celui de l'auteur de l'original ; après le contrôle de visibilité, avant les lectures qu'il protège), puis `findByIds` EN LOT des recettes utilisées. Une arête dont la recette manque ou que la nouvelle règle `RecipeComponentRules::isKeptByCopy` refuse est ignorée (autre auteur que l'original, illisible du copieur, secrète sous une copie ordinaire) ; les rangs sont renumérotés sans trou. L'auteur qui copie sa propre recette **partage** ses recettes utilisées ; un autre compte demande en UNE requête (`RecipeRepository::findUsableCopyIdsOf`) les copies qu'il a déjà, **jugées dans la requête** (`component_count = 0`, `secret = false`, la plus ancienne), réutilise celles-là et choisit un identifiant pour une copie neuve des autres. `findCopyIdsOf` du ticket est devenu `findUsableCopyIdsOf` : la condition « ordinaire si la recette copiée l'est » est toujours « ordinaire » (seul un autre compte crée des recettes utilisées, et sa copie est ordinaire), et la juger dans la requête évite qu'une copie composée ou secrète, plus ancienne, cache une copie utilisable.
- **Le plafond** : `authorHasAtLeast(copieur, MAX_PER_AUTHOR_WITH_COPIES − à créer + 1)` avec `à créer = 1 + copies neuves`, avant toute écriture et toute copie d'ingrédient, `RECIPE_LIMIT_REACHED` ; une copie réutilisée ne compte pas ; il n'est jugé que s'il y a une copie neuve (avec la seule recette composée à créer, c'est `assertCanCopy`, déjà fait par le handler).
- **L'écriture** : `ingredientsAndUnitsOf(original, ...recettes à copier)` (un seul rapprochement sur l'union, unités fusionnées), `linkCopies` une fois, les recettes utilisées d'abord (`copyOf` : `Recipe::copyFromOriginal` + `capitalizeFirst`), la recette composée avec ses arêtes (`RecipeComponent::load` + `Recipe::setComponents`), UN seul événement (`recipeIds` : les recettes créées, la composée en dernier). `Recipe::isSecretWhenCopiedBy` est écrit une fois, lu par `copyFromOriginal` et par la règle de la copie.
- **La réponse** de `POST /recipes/{id}/copy` ne change pas (aucune clé : l'ADR décision 36 laisse le front dire ce qui est copié d'après les `components` qu'il a lus). `docs/api/openapi.json` : seulement les descriptions de la route.
- **Budget** : `copier-recette-composee` **87**. `CopyComposedRecipeReadsAreConstantTest` : de 1 à 10 recettes utilisées, réutilisées ou non, par un autre ou par l'auteur, mêmes lectures ; seules les écritures des recettes **créées** suivent (une recette neuve cherche son identifiant et ses lignes déjà écrites avant de s'écrire).
- **Tests** : `CopyComposedRecipeHandlerTest` (23 cas unitaires : l'arbre, l'ordre, l'événement, une composée sans ligne à elle, un seul rapprochement et l'union des unités, la partie d'ingrédient liée une fois, la copie réutilisée / composée / secrète, l'auteur, la secrète d'un autre `404` sans verrou, l'arête secrète ou d'un autre auteur ou orpheline, le plafond aux deux bornes avec et sans copie réutilisée, sans composant ni verrou ni lecture, le modèle relu quand ses arêtes ne sont pas lues), `CopyComposedRecipeApiFlowTest` (vraie base, trois comptes : copier, planifier la copie avec les bonnes quantités, `other-people`, copies autonomes, copie d'une copie, réutilisation, partage de l'auteur, secrète, partie liée une fois, plafond), `RecipeRepositoryTest`, `RecipeComponentRulesCopyTest`, `SubRecipeWritesCheckAuthorTest` (l'ordre de la copie).
- **Décisions à valider** : le verrou pris par la copie (non écrit dans le ticket, imposé par la décision 27) ; la règle « d'un autre auteur que l'original » ajoutée à celles du ticket pour ignorer une arête ; `findUsableCopyIdsOf` à la place de `findCopyIdsOf`.
- **Release** : COMP-001, 004, 002 et 003 partent dans la même version du back (décision 43).
