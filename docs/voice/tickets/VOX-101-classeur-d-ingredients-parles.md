# VOX-101 — Service de domaine `SpokenIngredientRanker` et table de cas

- **Dépôt** : back
- **Jalon** : M1 Back `Repas`
- **Taille** : M
- **Dépend de** : —
- **Faisable sans téléphone** : oui
- **Statut** : fait (PR #258, 2026-10-25)
- **Lire d'abord** : [`../alexa.md`](../alexa.md) § 4 ; [ADR 0029](../../adr/0029-commande-vocale-alexa.md) décision 3 ; [ADR 0019](../../adr/0019-recherche-d-ingredients-par-pertinence.md) ; `src/Shared/Domain/Tool/SearchRelevance.php` ; `src/Repas/Domain/Service/IngredientNameMatcher.php` (`tokens`, `STOP_WORDS`) ; `.claude/skills/repas-dev/SKILL.md`.

## Contexte

Choisir, parmi les ingrédients d'un utilisateur, le plus probable pour une phrase prononcée, d'après la pertinence du nom et les habitudes. Fonction pure : ni base ni horloge.

## Portée

1. `Repas\Domain\Model\SpokenCandidate` (`slug`, `sluggedName`, `name`, `departmentName`, `uses12m`) et `RankedIngredient` (`slug`, `name`, `departmentName`, `score`, `Confidence` enum `SURE|PROBABLE|UNSURE`).
2. `Repas\Domain\Service\SpokenIngredientRanker::rank(string $phrase, list<SpokenCandidate> $candidates, ?string $rememberedSlug = null): ?RankedIngredient`.
   - phrase normalisée : `StringTool::slugify`, mots vides (`IngredientNameMatcher::STOP_WORDS` + `du`, `de-la`, `des`, `un`, `une`) retirés, pluriels repliés ; vide après nettoyage : `null` ;
   - `$rememberedSlug` présent parmi les candidats : renvoyé, `SURE` ;
   - sinon `score = SearchRelevance::score(...) + 0.1 * n / (n + 3)` pour `pertinence ≥ SearchRelevance::MINIMUM` ;
   - confiance : voir `alexa.md` § 4.5 ; ordre déterministe (score, nom, slug).
3. Constantes nommées (`HABIT_WEIGHT = 0.1`, `HABIT_SOFTNESS = 3`, `CLEAR_MARGIN = 0.03`) commentées.

## Critères d'acceptation

- « tomate » avec *tomate* (0 achat) et *tomates pelées* (40 achats) → *tomate*, `SURE`.
- « tomates pelées » → *tomates pelées*. « des pommes de terre » → *pomme de terre*.
- « farine » avec *farine de blé* (30), *farine de maïs* (2) → *farine de blé*, `PROBABLE`.
- « xyz » → `null`. Phrase de mots vides seulement → `null`. Aucun candidat → `null`.
- Mémoire de phrase : *tomate cerise* mémorisée pour « tomate » → *tomate cerise*, `SURE`, même avec zéro achat.
- Deux appels identiques donnent le même résultat (ex æquo).

## Tests exigés

`SpokenIngredientRankerTest` en unitaire, table de cas lisible (`yield` nommé), sans base. Pas de test de base.

## Pièges connus

- Ne pas utiliser `IngredientNameMatcher::compare` : il se trompe volontairement vers le doublon (voir `alexa.md` § 4).
- Une habitude ne doit pas faire passer un nom *trop différent* : le seuil de pertinence filtre avant le départage.
