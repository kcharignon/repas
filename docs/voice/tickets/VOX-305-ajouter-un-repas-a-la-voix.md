# VOX-305 — Ajouter un repas (une recette) à la voix

- **Dépôt** : back (`Voice`, `Repas`, `Shared`) ; le front n'a rien à faire, sauf le texte de `/confidentialite` (une phrase)
- **Jalon** : M3
- **Taille** : L
- **Dépend de** : VOX-304 ; la recette réelle de la bêta chez la compagne du propriétaire (2026-10-26)
- **Faisable sans téléphone** : oui (faux Amazon, signatures de test) ; l'essai réel se fait avec le propriétaire
- **Statut** : à faire (idée du propriétaire, 2026-10-26 ; **la liste de décisions ci-dessous est à lui faire valider avant de coder**)
- **Lire d'abord** : [`../alexa.md`](../alexa.md) §§ 2 et 3 ; [ADR 0029](../../adr/0029-commande-vocale-alexa.md) décisions 5, 11 et 12 ; la commande `PlannedMeal` (`src/Repas/Application/Command/PlannedMeal/`) et `UpdateServingMeal` ; `.claude/skills/repas-po/SKILL.md` (les textes d'Alexa).

## Contexte

Demande du propriétaire : « Alexa, demande à Jarvis d'ajouter un hachis parmentier » : on ne précise pas le nombre de personnes, c'est celui du compte (`AuthenticatedUser::getDefaultServing()`). L'application sait déjà planifier un repas dans la liste (`PlannedMeal`, avec un nombre de personnes) : il manque seulement la voix.

## Décisions proposées (à valider par le propriétaire)

1. **Une seule façon de parler** : « ajoute un hachis parmentier ». Alexa cherche d'abord parmi les **produits** de l'utilisateur (comme aujourd'hui, `SpokenIngredientResolver`) ; si aucun produit ne colle sûrement, elle cherche parmi **ses recettes** (les siennes seulement : jamais celles d'un autre compte) et planifie le repas.
2. **Quand les deux existent** (« pizza » en produit ET en recette) : « Un produit ou une recette ? » (le départage de la décision 11, avec une action `meal` de plus dans `SpokenDialogueState`). Des verbes sans ambiguïté marchent toujours : « prépare un hachis parmentier », « mets au menu un hachis parmentier » (intention `AddMealIntent`, qui ne cherche que parmi les recettes).
3. **Nombre de personnes** : celui du compte. Un créneau « pour six » (type `QUANTITY`) pourra venir plus tard ; jamais demandé.
4. **Réponse** : « C'est noté : hachis parmentier, pour 4 personnes. » ; recette inconnue : les trois recettes les plus proches (même classeur que les produits, plancher 0,80), jamais de création de recette à la voix ; la phrase choisie est retenue pour cette recette (table à part ou colonne `kind` de `spoken_phrase_choice` : à trancher).
5. **Même recette déjà dans la liste** : à trancher avec l'écran existant (un second repas du même plat, ou le nombre de personnes qui augmente).
6. **Aucune liste active** : comme l'ajout d'un produit, une liste est créée et dite ; une liste envoyée au drive n'est jamais modifiée.
7. **Retirer un repas** (« enlève le hachis parmentier ») : hors périmètre de ce ticket, à ouvrir ensuite si le besoin se confirme.

## Portée technique (esquisse)

- Ports `Shared` : `SpokenRecipeResolver::resolveOrSuggest` (recettes de l'utilisateur, classeur de noms, mémoire de phrase) et `VoiceMealPlanner::plan(userId, recipeId)` (implémenté par `Repas` en réutilisant `PlannedMealHandler` avec `getDefaultServing()`).
- `AddMealIntent` (créneau `recipe`, type `RECIPE`) ou extension de `AddItemIntent` ; **le type des noms de recettes de l'utilisateur ne peut pas être dans le modèle d'interaction** (une liste par utilisateur n'existe pas) : la reconnaissance passe par `item`, que le webhook rapproche des recettes comme des produits.
- Textes dans `VoiceReplies` (15 mots, tutoiement, aucune relance), modèle d'interaction régénéré (`make voice-model`), budget SQL des écritures, `docs/voice/alexa.md`, ADR 0029 (décision 13), page `/confidentialite` (Alexa « planifie aussi un repas »).

## Critères d'acceptation

- Étant donné une recette « hachis parmentier » à l'utilisateur et aucun produit de ce nom, quand il dit « ajoute un hachis parmentier », alors le repas est planifié dans sa liste active pour le nombre de personnes de son compte et Alexa le dit.
- Étant donné un produit et une recette du même nom, alors Alexa demande « un produit ou une recette ? » et n'écrit rien avant la réponse.
- Étant donné une recette d'un autre compte, alors elle n'est ni proposée ni planifiée.
- Étant donné une recette inconnue, alors trois propositions et aucune création.
