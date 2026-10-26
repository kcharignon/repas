# VOX-306 — Retirer un repas à la voix, et proposer quand on ne comprend pas

- **Dépôt** : back (`Voice`, `Repas`, `Shared`) ; le front n'a rien à faire, sauf le texte de `/confidentialite` si une donnée de plus est retenue (aucune prévue)
- **Jalon** : M3
- **Taille** : L
- **Dépend de** : VOX-304, VOX-305
- **Faisable sans téléphone** : oui (faux Amazon, signatures de test) ; l'essai réel se fait avec le propriétaire
- **Statut** : à faire (décisions validées par le propriétaire le 2026-10-26, **pas codé dans la PR de VOX-305**)
- **Lire d'abord** : [`../alexa.md`](../alexa.md) §§ 2 et 3 ; [ADR 0029](../../adr/0029-commande-vocale-alexa.md) décisions 11, 12 et 13 ; [VOX-304](VOX-304-retirer-et-consulter.md) et [VOX-305](VOX-305-ajouter-un-repas-a-la-voix.md) ; la commande `RemoveMealFromPlan` et `UpdateServingMeal` ; `.claude/skills/repas-po/SKILL.md`.

## Contexte

Demande du propriétaire, en préparant VOX-305 : « si on enlève un repas ou si on réduit le nombre de personnes pour lesquelles il est prévu », comme on retire un produit (VOX-304). Et, quand Alexa ne comprend pas ce qu'on veut retirer, la même aide qu'à l'ajout : trois propositions et l'aiguillage vers l'autre type (produit ou repas).

## Décisions validées (propriétaire, 2026-10-26)

1. **Retirer un repas** : « enlève le repas hachis parmentier », « retire la recette hachis parmentier » (verbes de VOX-304) retire le repas **entier** de la liste active : ses lignes d'ingrédients diminuent de ce que le repas apportait (la logique de `RemoveMealFromPlan`).
2. **Baisser le nombre de personnes** : « enlève le repas hachis parmentier pour 2 personnes » **enlève 2 personnes** (4 devient 2) ; le repas est retiré quand on enlève tout ou plus (jamais un nombre de personnes nul ou négatif). Logique d'`UpdateServingMeal`. Même règle que les produits : on déduit, on ne « fixe » pas.
3. **Sans « repas » ni « recette », « enlève » reste réservé aux produits** (VOX-304 ne change pas).
4. **Retirer ne crée jamais rien et ne retient aucune phrase** (comme VOX-304) ; liste envoyée au drive jamais lue ni modifiée (« Ta liste est déjà partie au drive. »), aucune liste : « Tu n'as pas de liste en cours. ».
5. **Quand Alexa ne comprend pas ce qu'on veut retirer** (cela **amende la décision 12 de l'ADR 0029**, « retirer ne propose rien ») :
   - repas non compris : trois propositions **tirées de la liste** (les repas planifiés), puis l'issue « ou un produit ? » ;
   - produit non compris : trois propositions **tirées de la liste** (les lignes de la liste), puis l'issue « ou un repas ? ».
   - **Sans mémoire de phrase et sans création** : on propose ce qui est dans la liste, rien d'autre. Même règle anti ping-pong que VOX-305 (l'état retient l'autre côté déjà essayé ; une fois les deux essayés, plus d'issue d'aiguillage).
6. **Un seul repas ne peut pas être dans la liste deux fois** (domaine) : « enlève le repas … » ne peut donc jamais être ambigu entre deux lignes d'un même repas.

## Portée technique (esquisse)

- Intention `RemoveMealIntent` (créneaux `recipe` et `servings`) ; l'état du dialogue de VOX-305 porte déjà la cible (`item` ou `meal`), l'action (`add` ou `remove`) et « l'autre côté a déjà été essayé » : le départage de lignes à retirer de VOX-304 (`chooseToRemove`) s'étend aux repas.
- Ports `Shared` : `SpokenListLookup` gagne la lecture des repas planifiés (`meals`) ; `VoiceMealPlanner` gagne `removeMeal(userId, recipeId, servings)`.
- Budgets d'écriture `retirer-repas-par-la-voix*` (entier, partiel, introuvable, sans liste, liste envoyée).
- ADR 0029 : décision 12 amendée (retirer propose, depuis la liste), `docs/voice/alexa.md`, `docs/voice/seance-en-direct.md`.

## Critères d'acceptation

- « Enlève le repas hachis parmentier » le retire et diminue les lignes ; « … pour 2 personnes » le fait passer de 4 à 2 ; enlever autant ou plus le retire.
- Un repas absent de la liste : « Il n'y a pas ce repas dans ta liste. », rien n'est écrit.
- Repas ou produit non compris : trois propositions tirées de la liste et l'issue vers l'autre type ; une fois les deux essayés, plus d'issue.
- Une liste envoyée au drive n'est jamais modifiée ; aucune liste n'est créée.
- `make test`, `make quality` verts ; budgets SQL figés.
