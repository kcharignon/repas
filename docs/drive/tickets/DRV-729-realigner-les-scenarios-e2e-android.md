# DRV-729 — Réaligner les scénarios 2 à 14 de l'émulateur sur le comportement actuel de l'agent

- **Dépôt** : front (`android/emulator/e2e.sh`)
- **Jalon** : M7 Plusieurs enseignes (maintenance du banc de test)
- **Taille** : S
- **Dépend de** : —
- **Statut** : en cours le 2026-10-30 (agent de maintenance). Effort recommandé : `medium`.
- **Lire d'abord** : le rapport de DRV-707 (PR front #252) ; `../principes-produit.md` ; skill `repas-android-dev` (verrou `/tmp/repas-android-e2e.lock`).

## Contexte

`make android-e2e` est rouge sur `main` pour les scénarios 2 à 14 depuis avant le 2026-10-30 : l'agent n'affiche plus l'écran « Prêt à remplir ton panier » (« envoyer au drive sans clic inutile », DRV-648/649) mais les scénarios l'attendent. Le scénario 15 a un comptage de touches à revoir et le 16 un contrôle `ui_has` flou. La CI ne joue visiblement pas ce banc.

## Portée

Aligner les scénarios sur le parcours actuel **sans affaiblir ce qu'ils prouvent** (invariants, défi, reprise, mémoires) ; ne modifier ni l'agent ni l'application ; décrire tout vrai défaut trouvé dans le rapport.

## Critères d'acceptation

`make android-e2e` complet vert sous le verrou de l'émulateur ; rapport : scénarios réalignés, ceux qui ont changé de sens, durée.
