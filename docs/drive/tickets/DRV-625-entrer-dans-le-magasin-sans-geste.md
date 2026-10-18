# DRV-625 — Entrer dans le magasin, revenir et finaliser sans geste inutile

- **Dépôt** : front (`drive-agent/src/flow/`, `android/`, `src/features/drive/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M
- **Dépend de** : DRV-314, DRV-405, DRV-622 ; **vérification humaine préalable** (voir ci-dessous)
- **Statut** : à faire, **ne pas lancer avant la vérification humaine**
- **Lire d'abord** : [`principes-produit.md`](../principes-produit.md) (règles 2 à 4), [`audit-simplicite.md`](../audit-simplicite.md) (F2, F3, F16), [`hypotheses-site-reel.md`](../hypotheses-site-reel.md) § 8, [`DRV-314`](DRV-314-passage-du-plan-et-retour.md), [`DRV-405`](DRV-405-retour-et-recapitulatif.md), skills `repas-po`, `repas-agent-dev`, `repas-android-dev`.

## Contexte

Trois gestes inutiles pour l'utilisateur : (1) toucher « Commencer mes courses » à la main sur le site de Leclerc, avec un bandeau de 30 mots ; (2) toucher « Retourner dans Repas » à la fin de l'agent ; (3) « Finaliser sur Leclerc » mène à une page 404 (`BASKET_PATH` vide) et s'ouvrirait dans le navigateur du téléphone, où l'utilisateur n'est pas connecté.

## Vérification humaine préalable (avec l'assistant, sur le téléphone, une fois)

Relever sur le vrai site, dans l'application : (a) le « Commencer mes courses » est-il un lien (`href`) que l'agent peut suivre, et qu'arrive-t-il si l'agent le suit lui-même (défi anti-robot ?) ; (b) l'adresse de la page du panier **dans l'application connectée** (forme `/magasin-N-N…/…`, sans donnée personnelle dans le dépôt : seulement la forme) ; (c) que l'ouverture de cette page dans `DriveActivity` montre bien le panier rempli. Les trois faits sont consignés dans `hypotheses-site-reel.md` avant que le ticket parte.

## Portée

1. **Entrer sans geste** : quand un plan est ouvert et que la page est l'accueil du magasin avec le lien « Commencer mes courses », l'agent affiche « Ouverture de votre magasin… » et suit le lien lui-même, avec le même rythme que ses autres actions (`pacer`). En cas d'échec ou de page inattendue, repli sur le bandeau actuel (court : « Touchez Commencer mes courses. »).
2. **Revenir sans geste** : à la fin d'un envoi terminé, l'agent poste le résultat tout de suite et l'application ferme le drive pour revenir à Repas, qui affiche le récapitulatif (DRV-622). L'écran de fin de l'agent n'existe plus que pour les cas où il y a quelque chose à lire (partiel, arrêté, erreur) et garde « Retourner dans Repas ».
3. **Finaliser dans l'application** : « Finaliser sur Leclerc » ouvre le panier **dans le drive de l'application** (session connectée), à l'adresse relevée (`BASKET_PATH`), construite côté natif à partir du magasin du plan (jamais une adresse tirée de la page). Si l'adresse est inconnue ou refusée, repli sur la page d'accueil du drive (`NavigationPolicy.START_URL`).
4. Les textes (`finalizeHelp`…) suivent le glossaire.

## Hors portée

Commande, créneau et paiement (l'utilisateur les fait lui-même sur le site), toute écriture de l'agent hors du panier.

## Critères d'acceptation

- Deuxième envoi, tout connu : Envoyer au drive, Confirmer, Ouvrir le drive, Commencer, puis « Finaliser sur Leclerc » : **5 touches** (7 avant).
- « Finaliser sur Leclerc » montre le panier rempli, connecté, sans quitter l'application.
- Un site qui change d'accueil retombe sur le bandeau, sans boucle ni écriture.

## Tests exigés

Faux site fidèle (`drive-agent/fake-leclerc/`) : accueil avec lien, accueil sans lien, défi anti-robot simulé ; émulateur (`make android-e2e`) : entrée automatique, retour automatique, finalisation ; recette réelle (`recette.md` partie 6 et 8) à rejouer par l'utilisateur.

## Definition of Done

Celle du dépôt front ; version de l'agent incrémentée, empreinte recalculée ; l'assistant met à jour `recette.md`, `hypotheses-site-reel.md` et le runbook.

## Pièges connus

- Suivre un lien par programme est une action que DataDome peut juger autrement qu'un toucher : la vérification (a) existe pour ça. Au moindre défi, on garde le geste manuel.
- Le retour automatique ne doit pas se produire quand l'utilisateur a arrêté l'envoi lui-même (il lit alors l'écran de l'agent).
