# DRV-409 — Administration des unités : dimension et facteur

- **Dépôt** : front
- **Jalon** : M4 Front
- **Taille** : S
- **Dépend de** : DRV-105 (contrat du back fusionné)
- **Statut** : fait (PR front #117, 2026-10-18)
- **Lire d'abord** : écran d'administration des unités du front (`src/features/admin/`), contrat régénéré.

## Portée

Dans l'écran d'administration des unités : afficher et modifier `dimension` (liste : aucune, poids, volume, nombre de pièces, conditionnement) et `baseFactor` (nombre > 0, requis si une dimension est choisie) ; erreurs `422` du back affichées ; textes dans `fr.ts` ; aide en une phrase (« Sert à calculer combien de produits acheter »).

## Critères d'acceptation

Un administrateur peut renseigner la dimension et le facteur d'une unité ; une incohérence (dimension sans facteur) est refusée avec le message du back.

## Tests exigés

Vitest ; e2e existant des unités complété ; test visuel.

## Réalisation (2026-10-18, PR repas-front#117)

- Liste des unités : ce que l'unité mesure et son facteur (« Poids, × 1000 ») ; formulaire : « Ce que l'unité mesure » (Aucune, Poids, Volume, Nombre de pièces, Conditionnement), « Facteur de base » et une aide.
- Envoi : toujours les deux champs ensemble (`null` et `null` effacent la paire) ; le facteur doit être un nombre supérieur à zéro ; les refus du back (`422 UNIT_MEASURE_INCOMPLETE`, `UNIT_BASE_FACTOR_INVALID`) sont traduits en français.
- Tests : nouvel e2e `e2e/admin-units.spec.ts` (bureau et Pixel 7, axe clair et sombre), `e2e/admin.spec.ts` complété et joué contre le vrai back de dev.
