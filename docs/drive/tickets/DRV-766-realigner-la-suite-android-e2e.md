# DRV-766 — Réaligner la suite `make android-e2e` (scénarios rouges sur `main`)

- **Dépôt** : front (`android/emulator/`, faux sites `drive-agent/fake-leclerc/` et `fake-auchan/`)
- **Jalon** : M7 Plusieurs enseignes (dette de test découverte pendant DRV-765)
- **Taille** : M
- **Dépend de** : rien (le scénario 22, session de Repas à rotation, vient de [DRV-765](DRV-765-session-de-repas-perdue-a-la-mise-a-jour.md))
- **Statut** : en cours (agent de code sur la branche `fix/drv-766-realigner-android-e2e`, relecture indépendante avant la fusion)
- **Lire d'abord** : `android/README.md` (« Émulateur de test », « Les scénarios complets »), `android/emulator/e2e.sh`, [DRV-754](DRV-754-leclerc-par-la-version-mobile.md) et les tickets DRV-755, 757, 759, 760, 761 (ce qui a changé dans le parcours et les cartes depuis le dernier alignement)

## Contexte

`make android-e2e` rejoue de vrais passages (l'agent réel, la vraie WebView, dans l'émulateur Android de Docker) contre le faux site Leclerc complet. **Il ne tourne qu'en local** : la CI n'a pas KVM, `make check` ne le lance jamais. Sa dernière mise en phase date du 2026-10-30 (agent 0.39.0, « une intention = un clic »).

Pendant DRV-765 (2026-11-02), l'agent de code a constaté que les scénarios **1, 1b et 1c échouent déjà sur `origin/main`**, sans lien avec sa PR, avec des messages du type « l'agent n'a pas demandé confirmation », « bouton Utiliser ce magasin introuvable », « le lien Se connecter est introuvable ». Il a arrêté son exécution complète pendant le scénario 2 : l'état des scénarios 2 à 22 est **inconnu**.

Depuis le dernier alignement, le produit a beaucoup changé : Leclerc par sa version mobile (DRV-754), le magasin choisi lu par l'agent et accepté par Repas (DRV-755, clé `chosenStore`), la carte du guide repliable avec sous-étapes (DRV-757), le mur de cookies, le nom accessible, la carte qui ne couvre plus un champ de saisie (DRV-759 à 764). Les scénarios et le faux Leclerc décrivent probablement l'ancien parcours.

## À faire

1. Jouer la suite **entière** sur `main` sans rien changer, et lister tous les scénarios rouges (message, rapport, capture).
2. Classer chaque rouge **avec preuve** : (a) le scénario décrit un ancien parcours que le produit a changé volontairement ; (b) le faux site ne reproduit pas le site réel mesuré ; (c) une **vraie régression** de l'agent ou de l'application.
3. Réaligner les cas (a) et (b) (scénario ou faux site) **sans jamais affaiblir une assertion** pour passer ; décrire précisément les cas (c) sans les corriger en douce, et laisser leur scénario rouge ou explicitement marqué.
4. Mettre à jour les en-têtes de `e2e.sh`, `android/README.md` (paragraphe daté du réalignement) et la procédure de publication : avant une étiquette `android-v…` qui change l'agent ou le parcours du drive, jouer `make android-e2e` en local et le noter dans la PR.

## Non vérifié

- Les scénarios 2 à 22 : leur état est à établir par la première exécution complète.
- Le temps d'une exécution complète (environ une à deux heures attendues).

## Livraison

Front (tests et faux sites seulement : ni l'agent, ni l'application, ni le site ne changent, sauf une vraie régression décidée à part). PR, relecture indépendante, fusion si la CI est verte ; pas de nouvelle version de l'application.
