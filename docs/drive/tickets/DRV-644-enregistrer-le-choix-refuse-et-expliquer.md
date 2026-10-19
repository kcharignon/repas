# DRV-644 — Un produit choisi mais refusé par le panier est enregistré, avec la raison

- **Dépôt** : front (`drive-agent/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : S
- **Dépend de** : DRV-635, DRV-633
- **Statut** : en cours (demande de l'utilisateur du 2026-10-19 : « très frustrant, impossible de le choisir ; et même si ce n'est pas possible de le mettre au panier, au moins l'enregistrer pour les prochaines fois, car visiblement c'est lui que je veux en priorité, et m'expliquer pourquoi le refus »). Effort : `high`. Absorbe DRV-632 (fiches sans « En promotion : 0,00 € »).

## Contexte

« Choisir » sur un produit que le panier refuse (rupture) affichait « Leclerc n'a pas pu mettre … au panier. Choisis-en un autre. » et n'enregistrait rien. Mesure réelle (téléphone de test, application officielle Leclerc Drive, magasin de l'utilisateur, 2026-10-19) : le produit est « Bientôt disponible » aussi dans l'application officielle, sans bouton d'ajout : le refus est réel.

## Portée

1. Le choix est **enregistré** pour les prochaines fois (ligne `CHOSEN` avec son produit, sans nouvelle clé du protocole) et le passage continue.
2. Une phrase dit pourquoi : « Leclerc indique « Bientôt disponible » : tu ne peux pas le commander pour l'instant. » ou « Leclerc le dit en rupture dans ton magasin. » ou « Leclerc n'a pas voulu le mettre au panier, sans en donner la raison. » ; la fin du passage dit en une phrase par produit qu'il est enregistré mais pas au panier.
3. La prochaine fois, le produit mémorisé est retenté une fois (DRV-635) ; la mémoire n'est jamais effacée sans geste.
4. Fiche : plus de ligne « En promotion : 0,00 € » ; « Plus en stock. » seul pour un produit absent.

## Hors portée

« Produits similaires » (l'application officielle en propose un).

## Critères d'acceptation

- Étant donné un produit « Bientôt disponible » choisi, alors il est mémorisé, le passage continue et la phrase de la raison s'affiche ; protocole identique octet pour octet sauf la ligne choisie.

## Tests exigés

Vitest, `make agent-e2e` (modes `real-out` et `misleading` du faux site), Playwright `agent-ui`, captures lues.

## Definition of Done

Celle du dépôt front. Part avec l'application suivante.
