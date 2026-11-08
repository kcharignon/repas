# COMP-006 — Front : ajouter une de mes recettes à une recette, la voir dans la fiche, la copier (front)

- **Dépôt** : **front** (`kcharignon/repas-front`)
- **Jalon** : I9 Recettes utilisées dans d'autres recettes
- **Taille** : L (éditeur M, fiche M, copie S, suppression et erreurs S, tests M)
- **Effort recommandé pour l'agent** : **high** (écrans visibles, textes à la voix du produit, captures lues, maquette du designer faite avant)
- **Dépend de** : **COMP-001 à COMP-004 en production** (champs `components`, routes `GET /recipes/usable` et `GET /recipes/{id}/used-in`, `components` au `POST`/`PUT`, les codes d'erreur) ; **COMP-005 validée par le propriétaire** ; COMP-003 recommandé (le toast de copie). Les champs sont lus comme **facultatifs** (un ancien back : traités comme vides, la section n'apparaît pas).
- **Statut** : à faire
- **Lire d'abord** : [ADR 0040](../../adr/0040-recettes-utilisees-dans-d-autres-recettes.md) décisions 1, 2, 5, 19 à 26, 29, 31, 36 ; la maquette de COMP-005 ; `CLAUDE.md` du front ; skills `repas-front-dev`, **`repas-po`** (`docs/drive/principes-produit.md` : tutoiement, 15 mots au plus, aucun avertissement, erreurs en trois phrases), `repas-designer` ; SUB-007 et MAR-004 (écrans voisins) ; issue #435.

## Vérifié dans le code du front le 2026-11-08

- `src/features/recipes/RecipeEditorView.vue` : le formulaire garde `form.rows` ; à l'enregistrement il n'envoie que `ingredientSlug`, `unitSlug`, `quantity` par ligne (**un remplacement complet : tout champ inconnu est perdu**) et `secret` (conditionnel : `secretKnown`, `isCommon`) ; la règle d'aujourd'hui « au moins une ligne » (`errors.rowsEmpty`, `form.rows.length > 0`).
- `src/features/recipes/RecipeDetailView.vue` : `toFix` = `rows.filter(row => row.convertible === false)` (seulement `isMine`), `blocked = toFix.length > 0`, `PlanButton :blocked`, indice `recipes.blockedHint` ; la liste des lignes est `:key="row.ingredientSlug"` (ligne 343) : **une clé qui se répéterait entre les lignes propres et celles d'une recette utilisée** si on les mélangeait.
- `src/features/recipes/useCopyRecipeAction.ts` (copie) et `OtherRecipesView.vue` (« Copiée », `copiedRecipes` store) ; tests `src/features/recipes/__tests__/`, e2e `e2e/recipe-editor.spec.ts`, `e2e/recipes-others.spec.ts` ; `src/i18n/__tests__/voice-and-glossary.spec.ts` (mots interdits à l'écran) ; doubles : `src/__tests__/helpers.ts`.
- **Il n'existe pas de liste « toutes mes recettes »** (les listes sont par type) : d'où `GET /recipes/usable` (ADR 0040 décision 22).

## Portée

1. `npm run api:update` (contrat figé à un commit où COMP-001 à 004 sont fusionnés), types régénérés, nouveaux champs facultatifs.
2. **Éditeur** (écrans 1 à 3 et 8 de la maquette) : section « Recettes utilisées » sous les ingrédients ; « Ajouter une de mes recettes » ouvre la feuille alimentée par `GET /recipes/usable?forRecipeId=…&secret=…&q=…` (la recette elle-même et celles qui ont déjà des composants n'y sont jamais ; `secret` suit l'interrupteur du formulaire ; `canHaveComponents` faux masque la section) ; chaque recette utilisée a son nombre de fois (0,01 à 100), « Retirer » ; **l'enregistrement envoie `components` seulement si la section a été touchée ou si la recette en avait** (sinon la clé reste absente : inchangé) et `[]` quand l'utilisateur retire tout ; la règle « au moins une ligne » devient « une ligne **ou** une recette utilisée » ; la liste des ingrédients propres peut être vide.
3. **Fiche** (écrans 4 à 6) : la section « Avec » (une carte par recette utilisée, ingrédients mis à l'échelle `quantité × fois × personnes choisies ÷ personnes de la recette`, clés distinctes `components[i].recipeId + ingredientSlug`) ; `toFix` et `blocked` étendus aux lignes non convertibles d'une recette utilisée (message qui la nomme, « Ouvrir Pâte sablée ») ; la note « Cette recette sert dans … » et « Voir lesquelles » (`GET /recipes/{id}/used-in`, appelé à l'ouverture de la fiche **de l'auteur** seulement, jamais pour la recette d'un autre) ; la suppression refusée (`409 RECIPE_USED_AS_COMPONENT`) ouvre la feuille avec les recettes qui l'utilisent.
4. **Copie** (écran 7) : le toast dit ce qui est copié d'après les `components` de la recette lue avant la copie ; rien d'autre ne change (« Copiée », réponse `201`).
5. **Messages d'erreur** en trois phrases au plus, jamais le code (`src/i18n/fr.ts`) : tous ceux de la maquette (`RECIPE_USED_AS_COMPONENT`, `_CHAIN`, `_COMPONENT_SECRET`, `RECIPE_SECRET_USED_BY_PUBLIC`, `_LIMIT_REACHED`, `_MULTIPLIER_INVALID`, `RECIPE_EMPTY`, `_DUPLICATE`). **La bascule « recette secrète » du formulaire montre `RECIPE_SECRET_USED_BY_PUBLIC` et `RECIPE_COMPONENT_SECRET` à leur place** (le `409` revient du `PUT`).
6. **Le front n'envoie jamais** une recette d'un autre compte ni une recette qui a déjà des composants, et masque la section pour une recette sans propriétaire (la neutre Marmiton) et pour une recette qui sert déjà ailleurs.
7. **Glossaire** : `voice-and-glossary.spec.ts` interdit « sous-recette », « composant » et « parent » à l'écran ; aucun mot « partie » pour une recette.
8. **Doubles et tests** : `src/__tests__/helpers.ts` (faux back : `components`, `usable`, `used-in`), `RecipeEditor.spec.ts` (absent = clé non envoyée, `[]` quand on retire tout, envoi dans l'ordre, la bascule secrète), `RecipeCommon.spec.ts` / un `RecipeComponents.spec.ts` (la fiche, l'échelle, `blocked`), `OtherRecipes.spec.ts` (toast), e2e `e2e/recipe-components.spec.ts` **dans le budget des e2e (suite sur faux sites 5 minutes au plus, flux critique seulement : créer « Tarte » avec « Pâte » puis la voir dans la fiche, la planifier)**.
9. À vérifier sans rien changer (captures lues) : la liste de courses (« Farine 250 g », aucune ligne « dont »), les choix du drive, l'écran de planification.

## Critères d'acceptation

- [ ] Maquette validée avant la première ligne de code ; captures lues avant de fusionner (clair, sombre, au moins un thème saisonnier).
- [ ] Un compte sans recette utilisée ne voit aucun changement d'écran hors le bouton de l'éditeur ; l'ancien client de l'éditeur (sans `components`) n'efface jamais rien (garanti par le back).
- [ ] CI du front verte (`npm run check`) ; release du site **après** celle du back, une à la fois ; aucune nouvelle version de l'application Android (la coquille charge le site distant).
- [ ] PR front vers `main` (description concise : Quoi / Vérifié / À relire).
