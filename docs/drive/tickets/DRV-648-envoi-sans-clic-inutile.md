# DRV-648 — Envoyer au drive sans clic inutile

- **Dépôt** : front (site, `drive-agent/`, application Android)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M
- **Dépend de** : DRV-622 (écran d'envoi), DRV-645 (panier prêt), DRV-647 (choix par rayon)
- **Statut** : fait (PR front #180, 2026-10-20 ; demande de l'utilisateur)
- **Lire d'abord** : `docs/drive/principes-produit.md` (règles 1 à 3), skills `repas-po`, `repas-front-dev`, `repas-agent-dev`.

## Contexte

L'utilisateur, 2026-10-20 : « Quand j'envoie ma liste au drive (j'aime bien la pop-up de confirmation pour éviter les mis-clics), après je dois encore "Ouvrir le drive" : pourquoi ? […] Une fois que j'ai cliqué sur mon magasin et commencé mes courses je dois encore cliquer sur "Commencer" : pourquoi ? […] Garde les clics qui évitent un mis-clic, mais si un clic donne une intention (lancer mes courses drive), ne redemande pas un clic qui aurait la même intention. »

## Règle (décidée le 2026-10-20)

**Une intention = un clic.** L'intention « envoyer ma liste au drive » est donnée par « Envoyer au drive » et sa fenêtre de confirmation (gardée : c'est l'anti mis-clic, une seule fois). Rien de ce qui suit ne la redemande. Les clics qui restent sont de vraies décisions : choisir un produit, « Pas cette fois », « J'en ai toujours », « Terminer la liste » ou « Rouvrir la liste ».

## Parcours mesuré (téléphone de test, application 0.1.22, 2026-10-20)

| # | Clic | Décision |
|---|---|---|
| 1 | « Envoyer au drive » (en-tête de la liste) | garder |
| 2 | « Envoyer au drive » (fenêtre de confirmation) | garder |
| 3 | « Ouvrir le drive » (écran « Ta liste est prête pour le drive ») | **supprimer** : ouverture automatique |
| 4 | « Commencer mes courses » (fenêtre du site Leclerc) | **supprimer** : l'agent appuie lui-même, repli sur la consigne actuelle |
| 5 | « Commencer » (écran « Prêt à remplir ton panier » de l'agent) | **supprimer** : l'agent démarre seul |
| 6 | Choisir / « Pas cette fois » / « J'en ai toujours » par article nouveau | garder |
| 7 | « Terminer la liste » / « Rouvrir la liste » au retour | garder |

Avant : 5 clics avant le premier choix de produit, dont 3 inutiles. Après : 2.

## Portée

1. **Repas** : après la confirmation, et après « Reprendre l'envoi », le drive s'ouvre tout de suite (état « Ouverture du drive… »), une seule fois par arrivée (jamais au retour arrière ni au rechargement). L'écran du plan ne reste que pour les cas qui demandent une décision ou une explication (erreur, hors connexion, hors application, application trop ancienne, autre envoi en cours, reprise des échecs).
2. **Agent** : plus d'écran bloquant « Prêt à remplir ton panier » ; l'agent démarre dès que le plan est validé (mêmes validations, mêmes erreurs). Progression non bloquante, abandon inchangé.
3. **Agent** : appui automatique sur « Commencer mes courses » du magasin du plan (id identique), clic natif de l'élément, aucune requête inventée ; si rien ne change après environ 3 s, repli sur la consigne actuelle. Contrôle ajouté au diagnostic. Même logique pour une étape « choisir mon magasin » quand le magasin du plan est connu.
4. **Audit** des autres parcours du drive : doublons exacts supprimés, le reste proposé dans la PR.

**Conséquence assumée** : sur une ligne déjà connue, « Choisir un autre produit » et « Pas cette fois » ne sont plus proposés avant l'ouverture. Un produit habituel se change dans « Mes produits » ; le panier se corrige sur le site.

## Contraintes

Protocole v1, ajouts optionnels seulement ; un site neuf avec l'application 0.1.22 et un site ancien avec une application neuve restent compatibles. Sécurité de l'agent inchangée. Ne pas durcir Leclerc : le bouton de démarrage est cherché par l'adaptateur de l'enseigne, pas dans le cœur de l'agent.

## Critères d'acceptation

- Given une liste prête et l'application à jour, When je confirme l'envoi, Then aucun clic n'est demandé avant le premier sélecteur de produit (test e2e qui compte les interactions = 0).
- Given le repli (bouton introuvable ou page inchangée après 3 s), Then la consigne « Touche « Commencer mes courses » » s'affiche comme avant.
- Given je reviens dans Repas sans résultat, Then l'écran d'attente existant s'affiche et le drive ne se rouvre pas tout seul.

## Recette sur téléphone réel (PO)

Clic automatique sur « Commencer mes courses » (dépend de la vraie structure du site), reprise d'un envoi, retour arrière.

## Réalisation (PR front #180, 2026-10-20)

Mesuré sur le téléphone de test : l'envoi passe de 5 clics à 2, et « Reprendre l'envoi » de 4 à 1. Les scripts d'émulateur `smoke.sh` et `e2e.sh` attendent encore l'ancien écran « Commencer » : [DRV-654](DRV-654-aligner-les-scripts-d-emulateur-sur-l-envoi-sans-clic.md). Propositions restantes de l'audit : [DRV-655](DRV-655-propositions-restantes-de-l-audit-des-clics.md).
