# DRV-409 — Administration des unités : dimension et facteur

- **Dépôt** : front
- **Jalon** : M4 Front
- **Taille** : S
- **Dépend de** : DRV-105 (contrat du back fusionné)
- **Statut** : à faire
- **Lire d'abord** : écran d'administration des unités du front (`src/features/admin/`), contrat régénéré.

## Portée

Dans l'écran d'administration des unités : afficher et modifier `dimension` (liste : aucune, poids, volume, nombre de pièces, conditionnement) et `baseFactor` (nombre > 0, requis si une dimension est choisie) ; erreurs `422` du back affichées ; textes dans `fr.ts` ; aide en une phrase (« Sert à calculer combien de produits acheter »).

## Critères d'acceptation

Un administrateur peut renseigner la dimension et le facteur d'une unité ; une incohérence (dimension sans facteur) est refusée avec le message du back.

## Tests exigés

Vitest ; e2e existant des unités complété ; test visuel.
