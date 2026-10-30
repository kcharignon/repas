# DRV-726 — Recette réelle d'Auchan sur le téléphone (WebView, connexion, un envoi)

- **Dépôt** : — (tâche **humaine** sur téléphone ; rapport `docs/drive/spikes/DRV-726-rapport.md`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-717, DRV-724, DRV-725, DRV-728 (et DRV-707 à 710 pour l'écran de choix)
- **Statut** : à faire (**dernier maillon avant d'allumer Auchan**). Pas d'effort de sous-agent : l'humain observe, l'assistant relit.
- **Lire d'abord** : [`../recette.md`](../recette.md) (façon de procéder, gabarit DRV-711) ; [`../hypotheses-site-reel-auchan.md`](../hypotheses-site-reel-auchan.md) (chaque ligne « I » doit sortir de cette recette) ; [`../spikes/DRV-715-decision-auchan.md`](../spikes/DRV-715-decision-auchan.md) § 4 (les risques gardés ouverts).

## Contexte

Tout ce qui précède est prouvé contre un **faux** site. Cette recette est la première fois qu'un agent travaille sur le **vrai** Auchan, dans la **WebView** de l'application, sur le téléphone de test, avec le compte de test du propriétaire (hors dépôt). `DRIVE_RETAILERS` est allumé **en environnement de test seulement**, par l'humain ; jamais en production.

## Portée (une ligne chacun : observé / non observé / pourquoi)

1. **Connexion dans la WebView** : le formulaire de `compte.auchan.fr` s'affiche ; e-mail et mot de passe passent ; le reCAPTCHA invisible ne bloque pas ; retour sur `www.auchan.fr` ; l'agent n'est **pas** injecté sur la page de connexion ; Apple et Google : refusés ou acceptés.
2. **Magasin** : « Choisir mon magasin » lit le drive par `GET /journey` ; le libellé s'affiche ; le magasin reste le même après fermeture de l'application, après une heure, après un jour (A-7 de `hypotheses-site-reel-auchan.md`) ; effet d'une déconnexion.
3. **Un envoi réel** de quelques lignes (recherche, choix, panier rempli) : types de résultats, quantité absolue, jamais réduite, EAN mémorisé ; **ne rien commander, aucun créneau, rien payer** ; abandon du panier à la fin.
4. **Anti-robot** : un défi est-il apparu ? à quel geste ? la reprise marche-t-elle ? cadence tolérée (à l'écrit : ce qui a été essayé, jamais « tolérée en général »).
5. **Visiteur et fusion** : envoi sans être connecté, puis connexion : le panier est-il conservé ? même produit dans les deux paniers : somme ou maximum (C-8).
6. **Sous-ressources en WebView** : images (`cdn.auchan.fr`), suivi, `dd.ecom.auchan.fr`, `google.com/recaptcha` ne sont pas bloquées par la politique de navigation.
7. **Valeurs encore inconnues** relevées au passage : produit indisponible, plafond de quantité, messages d'erreur (A-8, B-5, C-5, C-7).

## Règles de sécurité de l'essai

Compte de test seulement ; à cadence humaine ; **ne jamais répéter une action qui a déclenché un défi** : noter, attendre, s'arrêter ; rien d'identifiant dans le dépôt (aucun numéro de magasin, ville, e-mail, cookie, jeton, UUID réel) : des formes et des valeurs inventées.

## Critères d'acceptation

- Le rapport existe, chaque ligne est renseignée ou dit pourquoi non ; `hypotheses-site-reel-auchan.md` est à jour (les « I » deviennent « M » ou restent « I » avec la raison).
- Une décision écrite : **allumer Auchan** (`DRIVE_RETAILERS=leclerc,auchan`, geste de l'humain, après un déploiement stable) ou **corriger d'abord** (tickets).

## Definition of Done

Rapport fusionné ; runbook « Auchan a changé » complété (`runbook.md`) ; roadmap cochée.

## Pièges connus

- Le téléphone de test est connecté au compte de l'administrateur de Repas : il ne prouve rien sur un compte sans droits.
- `adb shell am force-stop` juste après un renouvellement de session fait perdre la session (`consignes-agents.md`).
