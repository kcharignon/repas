# LST-008 — Voix : Alexa et les types de liste

- **Dépôt** : back — **Taille** : M — **Effort** : high — **Dépend de** : LST-005
- **Lire d'abord** : ADR 0029, 0035, 0036 ; `docs/voice/alexa.md` ; skill `repas-po` (réponses d'Alexa).

## À faire

- **Ajouter** (produit ou repas) va dans la liste de la destination (ports et Open Host Services existants, `ShoppingListItemCommandPort`, `MealPlanCommandPort`) ; la réponse nomme la liste quand ce n'est pas la principale (« C'est ajouté à Boucherie. ») ; toujours deux phrases au plus, 15 mots, jamais de relance (`VoiceReplies`, `VoiceRepliesTest`).
- **Demander et retirer** (`CheckItemIntent`, `RemoveItemIntent`, retrait d'un repas) cherchent dans toutes les listes en cours ; un produit présent dans deux listes est dit avec son total par liste.
- `docs/voice/alexa.md` mis à jour ; budgets SQL des commandes vocales inchangés sans type.

## Critères d'acceptation

- Sans type, aucune réponse d'Alexa ne change (tests existants inchangés).
- « Ajoute de la viande hachée » avec Viande dans Boucherie : « C'est ajouté à Boucherie. »
