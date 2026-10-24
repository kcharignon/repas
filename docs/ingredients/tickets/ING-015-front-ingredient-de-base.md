# ING-015 — Front : l'interrupteur « Ingrédient de base » et sa pastille

- **Dépôt** : **front** (`kcharignon/repas-front`)
- **Jalon** : I6 Plus d'ingrédient commun
- **Taille** : M
- **Effort recommandé pour l'agent** : **high** (écran visible, textes à la voix du produit, captures lues)
- **Dépend de** : ING-011 **en production** (champs `isBase`, `canSetBase`, `isBase` des lignes du rayon, `403 INGREDIENT_BASE_FORBIDDEN`). Il peut sortir avant la passation (l'interrupteur n'apparaît que pour le propriétaire du catalogue **sur un ingrédient à lui**, donc pas avant la passation) ; son essai de bout en bout se fait après (`sans-commun.md` § 4, étape 12)
- **Statut** : fait (front PR #195 ; en production)
- **Lire d'abord** : [`sans-commun.md`](../sans-commun.md) §§ 1, 10 ; [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) amendement du 2026-10-20 (décision 4, D2) ; `CLAUDE.md` du front ; skills `repas-front-dev` et **`repas-po`** (`docs/drive/principes-produit.md` du dépôt back : tutoiement, aucun avertissement hors conditions d'utilisation, glossaire, erreurs en trois phrases) ; `docs/ingredients/analyse.md` § 12 du dépôt back.

## Contexte

Le propriétaire du catalogue (le compte administrateur protégé) décide quels ingrédients sont **de base** : ceux-là sont copiés aux nouveaux comptes. Il faut un interrupteur sur la fiche d'un ingrédient et un repère dans sa liste. **Pour tous les autres comptes rien ne change à l'écran.** L'ancienne distinction « ingrédient commun / personnel » n'existe plus dans le produit : aucun texte ne doit en parler.

## Portée

1. **Contrat** : `npm run api:update` (le contrat du back figé à un commit où ING-011 est fusionné) ; types régénérés. Les champs `isBase` (fiche et lignes du rayon) et `canSetBase` (fiche) sont **lus comme facultatifs** (absents d'un ancien back : traités comme faux) ; le front n'envoie `isBase` que lorsque l'interrupteur existe, jamais `null`.
2. **`IngredientEditorView.vue`** (mode modification) : un interrupteur **« Ingrédient de base »** avec l'aide d'une ligne **« Copié dans les nouveaux comptes. »** (textes à confirmer par `repas-po` ; tutoiement, 15 mots au plus, aucun avertissement), **affiché seulement si `ingredient.canSetBase` est vrai** (le back décide : le front ne devine pas qui est le propriétaire, il n'utilise pas `session.isAdmin`) ; enregistré avec le `PUT` du formulaire (un seul bouton « Enregistrer », pas d'appel en plus). Absent du formulaire de création.
3. **`DepartmentView.vue`** : une pastille **« de base »** sur les lignes dont `isBase` est vrai (donc seulement chez le propriétaire). Le lien de modification garde `v-if="ingredient.canEdit"` (toujours vrai pour ce qui est visible ; **ne pas le retirer** : un compte non encore amorcé verrait sinon un lien qui répond `403`).
4. **Erreur** : `403 INGREDIENT_BASE_FORBIDDEN` (cas impossible depuis l'écran, possible si un client périmé insiste) : le message générique « Rien n'a changé. » en trois phrases, jamais le code.
5. **Textes** dans `src/i18n/fr.ts` (`ingredients.baseSwitch`, `ingredients.baseHint`, `ingredients.baseBadge`) ; `voice-and-glossary.spec.ts` couvre les nouveaux textes.
6. **Doubles et e2e** : `src/__tests__/helpers.ts`, faux back des e2e (`e2e/`), `Ingredients.spec.ts`, `e2e/ingredients.spec.ts` : ajouter `isBase` / `canSetBase` aux réponses simulées ; **retirer toute hypothèse « un ingrédient commun n'est pas modifiable par un utilisateur »** ; ajouter le cas du propriétaire.
7. **À vérifier sans rien changer** (captures lues) : `ConversionsAdminView.vue` (la liste est filtrée par le back, ING-013 ; le sélecteur d'ingrédient montre déjà les ingrédients du compte), `IngredientSearchResults.vue`, le sélecteur de recette (`RecipeRowEditor.vue`), le parcours « fusionner » d'ING-007.

## Hors portée

Retirer `canEdit` ou `creatorId` du contrat ; un écran de gestion du catalogue ; copier le catalogue à la main ; toute mention de « commun » ; le drive.

## Critères d'acceptation

- **Propriétaire** : sur la fiche d'un de ses ingrédients, l'interrupteur est visible, reflète `isBase`, et l'enregistrement le change (le `PUT` porte `isBase`) ; la pastille « de base » apparaît dans le rayon, disparaît quand on le décoche.
- **Tout autre compte** (utilisateur ordinaire, administrateur qui n'est pas le propriétaire) : ni interrupteur, ni pastille ; le formulaire envoie exactement ce qu'il envoyait avant (aucun `isBase`).
- **Création** d'un ingrédient : pas d'interrupteur.
- **Ancien back** (champs absents) : aucune erreur, aucun interrupteur.
- **360 px** : aucun défilement horizontal, un seul bouton principal, cibles ≥ 44 px, clair et sombre ; **captures lues** (Chromium) avant fusion : le propriétaire (interrupteur, pastille), un compte migré, un compte neuf amorcé.
- **Aucune régression** : rayons, recherche, éditeur, recette, liste, drive passent `make check` et `make e2e`.

## Tests exigés

Vitest : `IngredientEditorView` (propriétaire / autre / création / ancien back), `DepartmentView` (pastille), `queries.ts` (corps du `PUT` avec et sans `isBase`), `voice-and-glossary.spec.ts` ; Playwright : parcours « marquer puis démarquer » sur le faux back, 360 px ; `make check`. PR : « À relire » avec les six questions de `/repas-po` (touches avant/après, une décision par écran, voix, erreurs, 360 px, captures lues).

## Definition of Done

`make check` vert ; `make e2e` ; contrat à jour ; l'assistant coche `docs/ingredients/roadmap.md` dans le dépôt back après fusion (un agent du front ne touche pas au dépôt back).

## Pièges

- **Tout ajout au JSON doit rester toléré par le site** : lire les champs comme facultatifs, ne jamais envoyer `null` pour `isBase` ; un faux back de test qui n'ajoute pas les champs ne doit pas casser.
- Le propriétaire du catalogue n'est pas « tout administrateur » : se fier à `canSetBase`.
- Le slug ne change pas quand on marque un ingrédient : pas de navigation, pas d'invalidation autre que celle des ingrédients (liste du rayon, fiche, options de recette).
- Ne pas ajouter de texte qui explique le catalogue : une phrase qui dit ce qui se passe suffit (principes produit).
- Aucune enseigne n'est nommée ici.
