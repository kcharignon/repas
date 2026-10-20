# ING-007 — Front : doublons possibles, fusion depuis la fiche, confirmation

- **Dépôt** : **front** (`kcharignon/repas-front`)
- **Jalon** : I5 Front
- **Taille** : L
- **Effort recommandé pour l'agent** : **high** (écrans et textes : suivre `/repas-po`)
- **Dépend de** : ING-005 et ING-006 **en production** (le front n'appelle jamais une route absente, ADR 0017 : le back d'abord) ; contrat `docs/api/openapi.json` du back à jour (`npm run api:update`)
- **Statut** : à faire
- **Lire d'abord** : [`fusion.md`](../fusion.md) **§ 9 en entier** (les textes exacts) ; maquette Claude Design (lien dans l'[ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md)) ; `CLAUDE.md` du front ; skills `repas-front-dev` et **`repas-po`** (`docs/drive/principes-produit.md` du dépôt back : tutoiement, aucun avertissement hors conditions d'utilisation, glossaire, erreurs en trois phrases) ; `docs/ingredients/analyse.md` § 12 du dépôt back.

## Contexte

Trois écrans à livrer (décision du propriétaire, ne pas la rediscuter) : **A « Doublons possibles »** (entrée proactive), **B « Depuis la fiche »** (entrée manuelle), et la **confirmation commune** (feuille du bas) qui reprend l'aperçu « Après la fusion ». Les pistes C (sélection multiple) et D (côte à côte) sont écartées.

## Portée

1. **Écran A** : route `/ingredients/doublons` (`src/features/ingredients/DuplicatesView.vue`), lien « N doublons possibles » sur la page **Rayons** (`DepartmentsView.vue`), visible seulement si `totalItems > 0` ; cartes groupées par rayon, première carte ouverte, ligne « À garder » basculable, phrase d'effet, « Fusionner » / « Pas le même », cartes repliées « Voir et fusionner » ([`fusion.md`](../fusion.md) § 9.1). Données : `GET /api/v1/ingredients/duplicates`.
2. **Entrée B** : dans `IngredientEditorView.vue` (mode modification, ingrédient à soi, **jamais un commun**) : ligne « Utilisé dans 7 recettes · 2 listes » (`recipeCount`, `listCount` d'`IngredientDTO`) et bouton secondaire « Fusionner avec un autre ingrédient » qui ouvre la **feuille de choix** (recherche, « Dans le même rayon · … » puis « Autres rayons », pastille « Très proche »), alimentée par `GET …/merge-candidates?q=` (antirebond existant `useDebounced.ts`) ([`fusion.md`](../fusion.md) § 9.2).
3. **Confirmation commune** `MergeConfirmSheet.vue` : titre « Fusionner « X » dans « Y » ? », trois lignes d'effets selon `GET …/merge-preview`, boutons « Fusionner » / « Annuler » ; cas « une unité manque » (bouton « Modifier « Y » »), cas « liste envoyée », erreur en trois phrases ; après succès : fermeture, `invalidateQueries` (ingrédients, rayons, recettes, listes de courses, résumé de liste, choix du drive), message court ([`fusion.md`](../fusion.md) § 9.3).
4. **Client d'API** : `queries.ts` (`useDuplicates`, `useMergeCandidates`, `useMergePreview`, `useMergeIngredients`, `useMarkDistinct`) sur le client généré (`src/api/schema.d.ts` régénéré) ; jamais de `fetch` à la main.
5. **Textes** dans `src/i18n/fr.ts` (clés `ingredients.merge.*`, `ingredients.duplicates.*`), tutoiement, phrases de 15 mots au plus ; `voice-and-glossary.spec.ts` étendu (aucun « vous », impératif en « -ez » ni mot interdit).
6. **Accessibilité** : vrais `<button>` et `<a>`, feuille avec focus piégé et retour du focus, libellés `aria` des lignes basculables, contraste 4,5:1 (le thème Basilic & safran existant).

## Hors portée

Les pistes C et D ; tout appel du back qui n'existe pas encore ; modifier la page des rayons au-delà d'une ligne de lien ; l'administration ; un écran « fiche » en lecture (la fiche **est** l'éditeur existant, [`fusion.md`](../fusion.md) § 9.2) ; hors-ligne (la fusion exige le réseau, le bandeau « Hors connexion » existant s'applique).

## Critères d'acceptation

- **A, parcours heureux en 3 touches** : sur Rayons, « 3 doublons possibles » -> « Fusionner » -> « Fusionner » (confirmation) ; la carte disparaît, le compteur baisse, un message « Fusionné dans « Tomate ». » s'affiche.
- **A, « À garder »** : toucher l'autre ligne bascule la pastille et la phrase d'effet (« 12 recettes et 3 listes passent sur « Tomates ». ») sans appel réseau superflu (l'aperçu est demandé à l'ouverture de la confirmation).
- **A, « Pas le même »** retire la carte et ne la remet pas au rechargement.
- **A, vide** : « Aucun doublon pour le moment. » ; le lien de la page Rayons est absent.
- **B, parcours en 4 touches** : Modifier -> « Fusionner avec un autre ingrédient » -> l'ingrédient -> « Fusionner » ; la recherche filtre ; « Dans le même rayon » en premier ; le bouton est **absent** pour un commun et pour un nouvel ingrédient.
- **Confirmation** : les trois lignes d'effets correspondent à l'aperçu (recettes et listes, « Tes quantités ne changent pas » ou la variante rayon/conversion, « « X » est supprimé. Tu ne pourras pas le retrouver. ») ; « Fusionner » est **désactivé** tant que l'aperçu charge ; en cas de `canMerge: false` pour unité manquante : pas de bouton « Fusionner », bouton « Modifier « Y » » qui ouvre l'éditeur de l'ingrédient gardé ; pour `IN_SENT_LIST` : « Une liste envoyée au drive utilise cet ingrédient. Rouvre-la ou termine-la, puis fusionne. ».
- **Erreurs** : chaque code du back (`fusion.md` § 7) donne une phrase en trois parties, jamais le code.
- **360 px** : aucun défilement horizontal, un seul bouton principal visible sans défiler dans la feuille, **captures lues** (Chromium) avant fusion.
- **Aucune régression** : les écrans existants (rayons, recherche, éditeur, recette, liste, drive) passent `make check` et `make e2e`.
- **Touches** : le parcours « Copier une recette » reste à **1** touche (aucun écran ajouté).

## Tests exigés

Vitest : `DuplicatesView` (états : chargement, vide, erreur, deux cartes, bascule, écart), `MergeConfirmSheet` (normal, rayon différent, conversion, unité manquante, liste envoyée, erreur, chargement), `MergePickerSheet` (recherche, groupes), `queries.ts` (clés d'invalidation) ; `voice-and-glossary.spec.ts` ; Playwright : parcours A et B sur le faux back (`e2e/`), 360 px ; `make check`. PR : section « À relire » avec les six questions de `/repas-po` (touches avant/après, une décision par écran, voix, erreurs, 360 px, captures lues).

## Definition of Done

`make check` vert (types, ESLint, Vitest, build) ; `make e2e` ; contrat à jour ; l'assistant coche `docs/ingredients/roadmap.md` dans le dépôt back après fusion (un agent du front ne touche pas au dépôt back).

## Pièges

- Le **slug change** à la fusion : après le succès, ne jamais naviguer vers l'ancienne URL de l'ingrédient absorbé ; revenir à la page du rayon de l'ingrédient gardé.
- Le cache (`staleTime` 5 min de `useIngredientOptions`, copie hors-ligne de la liste active sept jours, [`analyse.md`](../analyse.md) § 12) garde d'**anciens slugs** : invalider ce qu'il faut, et accepter que le back résolve les anciens slugs (ING-002, ING-005 règle 14).
- Le libellé de retour reprend le titre de la page d'où l'on vient : « ‹ Rayons » (la maquette disait « ‹ Mes ingrédients »).
- Les compteurs à zéro ne s'affichent pas (« 2 listes » et non « 0 recette · 2 listes »).
- Ne pas ajouter d'avertissement : la phrase « Tu ne pourras pas le retrouver. » dit ce qui se passe, elle n'est pas répétée ailleurs.
- Le front ne connaît **aucune enseigne** ici ; ne pas écrire de littéral d'enseigne.
- **Amendement du 2026-10-20 (plus d'ingrédient commun)** : rien à changer ici, mais deux points à connaître. (1) Après la passation, il n'y a plus d'ingrédient « commun » : le bouton « Fusionner avec un autre ingrédient » n'est plus jamais masqué pour cette raison, et `blockedBy: COMMON_INGREDIENT` / `422 CANNOT_MERGE_COMMON_INGREDIENT` disparaissent du back (ING-013) : le front garde sa tolérance d'un `blockedBy` inconnu. (2) L'administrateur pourra fusionner ses propres ingrédients ; ne pas coder de cas « administrateur » dans les écrans. Voir [`sans-commun.md`](../sans-commun.md) § 9.
