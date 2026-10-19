# DRV-637 — Le serveur connaît la version de l'application de chacun

- **Dépôt** : back (contexte `User`) + front (web et `android/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M
- **Dépend de** : —
- **Statut** : à faire (idée de l'utilisateur du 2026-10-19 : « tu peux stocker ça en base pour savoir qui est à jour ou non »)
- **Lire d'abord** : [ADR 0021](../../adr/0021-gestion-des-comptes-par-l-administrateur.md) (purge à la suppression du compte, `UserOwnedDataCoverageTest`), `docs/adr/0007-*` (API), `docs/drive/principes-produit.md` (règles 3 et 10), skills `repas-dev`, `repas-front-dev`, `repas-android-dev`, `repas-po`.

## Contexte

Les publications du site et de l'application sont indépendantes, et une personne peut rester sur une ancienne application sans le savoir (« Finaliser » a donné une page introuvable aux anciennes versions, puis aux 0.1.8). Il n'existe aucun moyen de savoir qui utilise quelle version, ni de faire s'adapter le site à la version de l'application, ni d'inviter à mettre à jour.

## Portée

1. **Le front web lit la version** de l'application (`window.repasNative`, déjà affichée dans « Moi » : « Version 0.1.8 ») et, **une fois par session de connexion** (au démarrage de l'application, après la connexion), l'envoie au back : `PUT /api/v1/users/me/app-version` (corps `{platform: "android", version: "0.1.8"}`) ; dans un navigateur, rien n'est envoyé. Échec ou hors connexion : silencieux, jamais bloquant.
2. **Back** : `UpdateUserAppVersion` (une écriture, idempotente, au plus 1 par minute et par utilisateur : limiteur existant) ; table `user_app_version(user_id, platform, version, seen_at)` (clé `(user_id, platform)`, version bornée à 20 caractères, motif `^[0-9]+\.[0-9]+\.[0-9]+$`) ; **purgée avec le compte** (`UserOwnedDataCoverageTest`) ; migration additive compatible avec la version précédente du code ; budget SQL ; contrat OpenAPI.
3. **Version minimale connue** : paramètre serveur `APP_MIN_VERSION` (variable d'environnement, vide par défaut) et `APP_LATEST_VERSION`, exposés dans la réponse de l'utilisateur courant (`GET /api/v1/users/me` : champs `appUpdate: {latest, minimum, outdated}` calculés pour la version enregistrée ; jamais d'erreur si inconnue).
4. **Administrateur** : la liste des comptes (écran d'administration existant) montre la version de l'application de chacun et « à mettre à jour » quand elle est inférieure à `APP_LATEST_VERSION`.
5. **Invitation à mettre à jour** (une décision, un bouton, aucun avertissement) : quand `outdated`, un bandeau discret en haut de « Moi » : « Une nouvelle version de l'application est prête. » + bouton « Mettre à jour » (vers la page `/drive/aide` du mode d'emploi de mise à jour, qui existe). Rien de plus ; aucune fenêtre bloquante.
6. **Le site s'adapte à l'application** : le front web ne dépend plus de l'ordre de publication : une fonction du web qui exige une application récente (par exemple « Finaliser ») vérifie la version connue du pont natif et retombe sur un comportement sûr pour les anciennes ; ce principe est écrit dans `android/PUBLICATION.md`.
7. **Vie privée** : seule la version est retenue (aucun identifiant d'appareil, aucun modèle de téléphone) ; une phrase dans « Conditions d'utilisation » (page des conditions, pas dans le parcours).

## Hors portée

Forcer la mise à jour (aucun blocage), iPhone (D5 : plus tard), statistiques, historique des versions.

## Critères d'acceptation

- Étant donné une application `0.1.8` connectée, quand elle démarre, alors la table contient `android` / `0.1.8` pour ce compte ; deuxième démarrage dans la minute : aucune écriture de plus.
- Étant donné `APP_LATEST_VERSION=0.1.10` et une version `0.1.8` : l'écran « Moi » de cette personne montre le bandeau, et l'administrateur la voit « à mettre à jour ».
- Étant donné un navigateur : aucune version envoyée, aucun bandeau.
- Supprimer le compte efface la ligne (test de couverture de la purge).

## Tests exigés

Back : handler unitaire, loader sur base, controller avec doubles de bus, budget SQL, purge ; front : Vitest (envoi une fois, silence en navigateur, bandeau), e2e Playwright, captures lues à 360 px ; `make android-test` si le pont change.

## Definition of Done

Celle des deux dépôts ; ordre de publication : back (route inactive tant que le front ne l'appelle pas), puis site, puis application ; `APP_LATEST_VERSION` écrit par un script que l'utilisateur lance (comme `DRIVE_ENABLED`) après chaque publication de l'application.

## Pièges connus

- La version vient de l'application, donc d'une source non fiable : ne s'en servir que pour informer, jamais pour accorder un droit.
- Un compte supprimé pendant l'envoi : la ligne ne doit pas renaître (écrire seulement pour un utilisateur existant).
