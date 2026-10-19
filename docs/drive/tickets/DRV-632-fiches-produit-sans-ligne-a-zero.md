# DRV-632 — Fiches produit de l'agent : pas de « En promotion : 0,00 € » et des pluriels justes

- **Dépôt** : front (`drive-agent/src/ui/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : S
- **Dépend de** : —
- **Statut** : à faire (constat réel du 2026-10-19, [`hypotheses-site-reel.md`](../hypotheses-site-reel.md) § 11)
- **Lire d'abord** : [`principes-produit.md`](../principes-produit.md) (« pas de compteur à zéro », règle 10), skills `repas-po`, `repas-agent-dev`.

## Portée

1. Une fiche produit n'affiche la ligne « En promotion : x,xx € » **que si** une promotion existe (prix promotionnel > 0 et différent du prix) ; jamais « 0,00 € ».
2. Les quantités s'accordent : « Il te faut : 3 unités » (pas « 3 unité »), « 1 unité », « 500 g », « 2 paquets ». Vérifier toutes les unités affichées (unité, paquet, boîte, pièce, g, kg, ml, cl, l).
3. Rien d'autre ne change.

## Critères d'acceptation

- Étant donné un produit sans promotion, quand la liste s'affiche, alors aucune ligne « En promotion ».
- Étant donné 1 puis 3 unités, quand l'écran dit « Il te faut », alors « 1 unité » puis « 3 unités ».

## Tests exigés

Tests jsdom de l'agent (fiche avec et sans promotion, pluriels), captures lues à 360 × 640, `make agent-verify`.

## Definition of Done

Celle du dépôt front ; agent incrémenté, empreinte recalculée.
