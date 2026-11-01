# DRV-759 — La carte du guide : ce que la recette réelle de 1.6.0 a encore trouvé

- **Dépôt** : front (`drive-agent/`, `android/`, `src/`)
- **Jalon** : M7 Plusieurs enseignes (recette réelle du 2026-11-01, application 1.6.0, regard d'une personne qui ne connaît rien)
- **Taille** : M (deux PR possibles : 1 à 3, puis 4 à 7)
- **Dépend de** : DRV-757
- **Statut** : à faire (agent lancé le 2026-11-01, relecture indépendante par un autre agent ensuite, application `android-v1.6.1`)
- **Lire d'abord** : [DRV-757](DRV-757-carte-du-guide-plus-legere-et-plus-juste.md), `drive-agent/src/ui/keep-clear.ts`, `drive-agent/src/leclerc/guide-look.ts`, `drive-agent/src/auchan/guide-look.ts`, `drive-agent/src/diagnostic/foreground.ts`, `android/` (la carte et son affichage)

## Contexte

Recette réelle de 1.6.0 sur le téléphone de test (compte de démo), Leclerc puis Auchan, en lisant chaque étape comme une personne novice. Le parcours va jusqu'au bout sur les deux enseignes (liste à 100 %), mais le guide laisse la personne seule ou la piège à plusieurs endroits. Positions lues dans l'application (écran de 2400 pixels de haut).

## Constats

1. **La carte pleine, en haut, couvre le lien du haut du mur de cookies de Leclerc.** Le lien « Continuer sans accepter » est à `[614,613][997,668]` ; le bouton « Arrêter pour l'instant » de la carte est à `[70,553][1010,713]`. Un toucher sur le lien arrête l'envoi. Stable sur 22 s. Les tests de géométrie ne couvraient que les trois boutons du bas du mur.
2. **La page de connexion de Leclerc garde le texte de l'étape magasin** (« Étape 1 sur 2 · Écris ton code postal »), barre réduite comme carte pleine. Sur la page boutique, le texte était bon.
3. **Auchan : la carte et le repère disparaissent** quand le panneau « Choisir un drive ou la livraison » est ouvert, et sur la page de connexion d'Auchan. Cause inconnue (le « Diagnostic de l'agent » ne se lit pas par `uiautomator dump`).
4. **Auchan : le texte de la carte ne reprend pas le mot du bouton.** La carte dit « Choisir vos courses », la page dit « Choisir mon mode de livraison ». Le bouton de connexion du bandeau n'a aucun nom accessible alors que la carte le cite (« Me connecter »).
5. **Pluriels** : « Il te faut : 2 unité. » (choix d'un produit), « 1 sur 1 articles dans le panier » (liste envoyée).
6. **Réglages du drive** : Leclerc montre le nom du magasin, Auchan seulement « Magasin …abc enregistré. » (le nom n'est pas gardé, ou pas montré).
7. **Barre réduite en haut des résultats de magasins** : elle recouvre en partie « Retour » et « Me géolocaliser » de la page.

Constats sans correctif demandé (à regarder) : les sites posent leurs propres bannières (« OUVRIR » chez Leclerc, « Vers l'App » chez Auchan) qui peuvent faire quitter l'envoi ; la fenêtre du gestionnaire de mots de passe du téléphone s'ouvre à la connexion d'Auchan ; la liste envoyée n'offre « Rouvrir la liste » que dans le menu « ⋮ », dont le bouton n'a pas de nom accessible.

## À faire

1. Le repère de non-recouvrement (`keep-clear`) compte **tout** bouton ou lien de réponse du mur de cookies, en haut comme en bas, pour Leclerc et Auchan. Une carte pleine ne pose jamais « Arrêter pour l'instant » sur une zone que la page demande de toucher : elle passe en barre réduite.
2. La page de connexion de Leclerc est lue comme une étape 2 : e-mail, « Continuer », mot de passe, case « Je suis un humain » (la personne la coche elle-même), « Se connecter », code reçu par e-mail.
3. Auchan : la carte reste, en barre réduite si besoin, avec le texte de l'étape, y compris panneau ouvert et page de connexion ; la cause de la disparition est dite.
4. Les textes reprennent le mot que la personne voit ; la carte dit où est un bouton sans nom (« l'icône en haut à droite »).
5. Pluriels i18n pour les deux cas.
6. Le nom du magasin Auchan est montré s'il est disponible dans ce que l'agent renvoie ; sinon on le dit, on ne l'invente pas.
7. La barre réduite ne recouvre pas « Retour » des résultats de magasins.
8. Tests d'abord (le test échoue avant le correctif) : géométrie du lien du haut à 360 et 412 px, clavier fermé et ouvert ; texte de la page de connexion ; carte présente sur le panneau d'Auchan ; pluriels. Budget de l'agent : 288 000 octets. `AGENT_VERSION` 0.62.0.
9. Textes avec `repas-po` (tutoiement, 15 mots au plus par phrase, aucun jargon, jamais « Fermer »).

## Ordre de livraison

Agent et application (`android-v1.6.1`, approuvée par le propriétaire, puis `APP_LATEST_VERSION` écrit par lui après le run vert). Le site change seulement pour les pluriels et les réglages du drive (release `vX.Y.Z` du site approuvée, jamais deux en même temps).

## Recette

Rejouer sur le téléphone de test, Leclerc et Auchan, en novice : mur de cookies (lien du haut touché sans arrêter l'envoi), page de connexion de chaque enseigne, panneau « Choisir un drive » d'Auchan, pluriels, réglages du drive.
