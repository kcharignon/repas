# DRV-654 — Aligner les scripts d'émulateur sur l'envoi sans clic

- **Dépôt** : front (`android/emulator/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : S
- **Dépend de** : [DRV-648](DRV-648-envoi-sans-clic-inutile.md) (fait, PR front #180), DRV-625
- **Statut** : à faire, non urgent (relevé par l'agent de DRV-648)
- **Lire d'abord** : skill `repas-android-dev`, `android/emulator/`.

## Contexte

Depuis DRV-648 (et DRV-625), l'envoi ouvre le drive tout seul : il n'y a plus d'écran « Commencer » à toucher. Les scripts `android/emulator/smoke.sh` et `android/emulator/e2e.sh` attendent encore cet ancien écran et échouent ou attendent pour rien.

## Portée

1. `smoke.sh` et `e2e.sh` suivent le parcours actuel : de « Envoyer au drive » à l'écran du drive, sans toucher « Commencer » ni « Commencer mes courses ».
2. Les attentes et les captures sont mises à jour en conséquence (aucune donnée réelle, faux site de l'émulateur seulement).

## Hors portée

Tout changement du produit ; tout contact avec un vrai site d'enseigne.

## Critères d'acceptation

- Étant donné l'émulateur et le faux site, `smoke.sh` et `e2e.sh` passent de bout en bout avec le parcours sans clic.
- Aucune attente d'un écran qui n'existe plus.

## Definition of Done

Celle du dépôt front ; `make android-test` vert ; les deux scripts rejoués sur l'émulateur.
