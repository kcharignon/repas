# DRV-624 — Choisir un produit sans jargon, avec « passer les suivants »

- **Dépôt** : front (`drive-agent/src/ui/`, `drive-agent/src/flow/phase-b.ts`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M
- **Dépend de** : DRV-306, DRV-208, DRV-621
- **Statut** : à faire
- **Lire d'abord** : [`principes-produit.md`](../principes-produit.md), [`audit-simplicite.md`](../audit-simplicite.md) (F7, F8), [`DRV-306`](DRV-306-choix-guide-phase-b.md), [`DRV-208`](DRV-208-remplacement-preference.md), skills `repas-po`, `repas-agent-dev`.

## Contexte

Le premier envoi demande un choix par nouvel article : c'est l'effort principal de l'utilisateur. Deux frictions : (1) quand le produit choisi n'est pas celui que Repas retient déjà, une fenêtre propose trois options (« Mon nouveau produit préféré », « Produit de secours », « Seulement cette fois ») qui exposent l'ordre de préférence ; (2) aucun moyen de dire « pas cette fois pour tout le reste » quand on est pressé.

## Portée

1. **Fenêtre de remplacement** (`placement`) : deux choix seulement, en boutons : « Toujours prendre celui-ci » (par défaut, premier bouton) et « Cette fois seulement » ; plus de « produit de secours » à l'écran. Le serveur garde son ordre de préférence : « Toujours prendre celui-ci » place le nouveau produit en tête et l'ancien le suit en remplacement (comportement de DRV-208, à vérifier et à décrire dans la PR). Le protocole v1 ne change pas ; l'agent cesse seulement d'émettre le placement « secours » (le décodeur du front l'accepte toujours).
2. **Écran de choix** (`chooser`) : un bouton « Passer les {n} suivants » (visible dès qu'il reste plus d'un article à choisir) qui traite tous les articles restants comme « Pas cette fois » ; il demande une confirmation d'une ligne si au moins un article restant est déjà « à choisir » pour la première fois (rien n'est retenu pour eux). Les boutons existants (« Choisir », « Pas cette fois », « J'en ai toujours », « Annuler l'envoi ») sont reclassés : « Choisir » en principal, « Pas cette fois » et « J'en ai toujours » en secondaires, « Annuler l'envoi » discret en bas.
3. **Aucun choix demandé pour ce que Repas sait déjà** : vérifier qu'un article dont le produit retenu est disponible n'ouvre jamais l'écran de choix (la phase A le traite) ; décrire le résultat dans la PR.
4. Textes selon `principes-produit.md` (« Il vous faut : 800 g. », « 500 g par produit : 2 produits. »), en vouvoiement.

## Hors portée

Les suggestions automatiques (DRV-601), toute modification du back ou du protocole.

## Critères d'acceptation

- Remplacer un produit retenu pose **une** question à deux boutons, sans mot « secours » ni « préféré ».
- Un envoi de 4 nouveaux articles peut se terminer en 2 touches (choisir le premier, « Passer les 3 suivants »).
- Le serveur reçoit les mêmes messages qu'avant pour « Toujours » et « Cette fois seulement ».

## Tests exigés

Tests de l'agent (jsdom et faux site) : fenêtre à deux boutons, « passer les suivants » avec et sans confirmation, absence de l'écran de choix pour un produit retenu disponible ; e2e émulateur du parcours de la phase B ; captures de l'agent lues.

## Definition of Done

Celle du dépôt front ; version de l'agent incrémentée, empreinte recalculée ; touches avant / après dans la PR.

## Pièges connus

- « Passer les suivants » ne doit rien mémoriser (ni `HAVE_AT_HOME`, ni rejet) : c'est « Pas cette fois ».
- Le focus clavier et l'annonce `aria-live` du compteur (« 2 sur 4 ») doivent suivre la nouvelle disposition.
