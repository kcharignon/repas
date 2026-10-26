# VOX-306 — Retirer un repas à la voix, et proposer quand on ne comprend pas

- **Dépôt** : back (`Voice`, `Repas`, `Shared`) ; le front n'a rien à faire, sauf le texte de `/confidentialite` si une donnée de plus est retenue (aucune prévue)
- **Jalon** : M3
- **Taille** : L
- **Dépend de** : VOX-304, VOX-305
- **Faisable sans téléphone** : oui (faux Amazon, signatures de test) ; l'essai réel se fait avec le propriétaire
- **Statut** : fait (2026-10-26, décisions validées par le propriétaire le même jour ; code dans la PR de ce ticket)
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
   - **Sans mémoire de phrase et sans création** : on propose ce qui est dans la liste, rien d'autre. Même règle anti ping-pong que VOX-305 (l'état retient l'autre côté déjà essayé ; une fois les deux essayés, plus d'issue d'aiguillage : « Je ne trouve rien qui s'appelle X dans ta liste. »).
   - **Précision de réalisation** : « produit non compris » veut dire que la phrase ne désigne **aucun** ingrédient de l'utilisateur (« enlève le hachis parmentier »). Un produit **reconnu** mais absent de la liste garde la réponse définitive de VOX-304 (« Il n'y a pas de croquette dans ta liste. »), sans question. Côté repas, « non compris » veut dire qu'aucun repas planifié ne se laisse choisir sûrement (deux noms qui se touchent sont proposés, jamais devinés).
   - Le nombre de personnes dit au départ est gardé pendant les propositions ; passer d'un côté à l'autre ne reprend ni la quantité (produit) ni les personnes (repas) de l'autre côté.
6. **Un seul repas ne peut pas être dans la liste deux fois** (domaine) : « enlève le repas … » ne peut donc jamais être ambigu entre deux lignes d'un même repas.

## Portée technique (esquisse)

- Intention `RemoveMealIntent` (créneaux `recipe` et `servings`) ; l'état du dialogue de VOX-305 porte déjà la cible (`item` ou `meal`), l'action (`add` ou `remove`) et « l'autre côté a déjà été essayé » : le départage de lignes à retirer de VOX-304 (`chooseToRemove`) s'étend aux repas.
- Ports `Shared` : `SpokenListLookup` gagne la lecture des repas planifiés (`meals`) ; `VoiceMealPlanner` gagne `removeMeal(userId, recipeId, servings)`.
- Budgets d'écriture `retirer-repas-par-la-voix*` (entier, partiel, introuvable, sans liste, liste envoyée).
- ADR 0029 : décision 12 amendée (retirer propose, depuis la liste), `docs/voice/alexa.md`, `docs/voice/seance-en-direct.md`.

## Critères d'acceptation

- « Enlève le repas hachis parmentier » le retire et diminue les lignes ; « … pour 2 personnes » le fait passer de 4 à 2 ; enlever autant ou plus le retire.
- Un repas absent de la liste : « Je ne trouve pas le repas X dans ta liste. » suivi des repas planifiés qui lui ressemblent (ou « C'est un produit ? »), rien n'est écrit ; deux repas dont les noms se touchent sont proposés, jamais devinés.
- Un repas ou une recette d'un autre compte n'est jamais cité ni retiré ; un état forgé qui le désigne ne retire rien (`404`, phrase d'échec).
- Repas ou produit non compris : trois propositions tirées de la liste et l'issue vers l'autre type ; une fois les deux essayés, plus d'issue.
- Une liste envoyée au drive n'est jamais modifiée ; aucune liste n'est créée.
- `make test`, `make quality` verts ; budgets SQL figés (`retirer-repas-par-la-voix*`, `retirer-repas-dit-a-alexa*`).

## À refaire dans la console Amazon avant l'essai

Recoller `docs/voice/skill/interaction-model.fr-FR.json` (Build → Interaction Model → JSON Editor → Save → Build skill) après la mise en production de la version qui contient ce ticket (19 intentions : `RemoveMealIntent` s'ajoute) ; revérifier Build → Endpoint (HTTPS, `https://kantin.eu/api/voice/alexa`). Scénarios 21 à 24 de `docs/voice/seance-en-direct.md`.

## Page de confidentialité

Le texte de `/confidentialite` dit « Les produits que tu dictes sont ajoutés ou retirés de ta liste. Les repas que tu dictes y sont planifiés. » : à compléter par « … planifiés ou retirés » quand cette version part (aucune donnée de plus n'est retenue : retirer ne retient aucune phrase).
