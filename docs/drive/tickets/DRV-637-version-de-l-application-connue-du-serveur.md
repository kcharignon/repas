# DRV-637 — Le serveur connaît la version de l'application de chacun

- **Dépôt** : back (contexte `User`) + front (web et `android/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M
- **Dépend de** : —
- **Statut** : partie back faite (PR back [#224](https://github.com/kcharignon/repas/pull/224), 2026-10-20 : points 2, 3 et 4, voir « Réalisation (partie back) ») ; **partie front faite** (PR front [#173](https://github.com/kcharignon/repas-front/pull/173), site v0.5.12, 2026-10-20 : points 1, 5, 6 et 7, voir « Réalisation (partie front) »). Idée de l'utilisateur du 2026-10-19 : « tu peux stocker ça en base pour savoir qui est à jour ou non ».
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

## Réalisation (partie back, PR #224, 2026-10-20)

**Contrat que le front consomme** (`docs/api/openapi.json`, champs additifs : l'ancien front ne casse pas) :

- `PUT /api/v1/users/me/app-version`, jeton JWT (l'utilisateur vient du jeton, jamais du corps), corps `{"platform": "android", "version": "0.1.8"}`.
  - `204` : version retenue. **Aussi `204`, sans rien écrire, pour un deuxième appel du même compte dans la minute** (aucune erreur visible, pas de `Retry-After`).
  - `422` `{error:{message}}` : `platform` autre que `android` (casse comprise), `version` qui n'est pas trois nombres séparés par des points (`^[0-9]+\.[0-9]+\.[0-9]+$`, ni suffixe, ni quatrième nombre, ni espace) ou de plus de 20 caractères, champ absent ou d'un autre type. Une version de débogage `0.0.0-dev` est refusée : ne pas l'envoyer (ou ignorer le `422`).
  - `401` : sans jeton, jeton d'un compte désactivé ou supprimé.
  - Rien d'autre n'est lu (un `deviceId` ou un modèle de téléphone envoyé en plus est ignoré).
- `GET /api/v1/me` (et `GET /api/v1/users/{id}`, même DTO) : nouveau champ `appUpdate: {latest: string|null, minimum: string|null, outdated: boolean}`, clés toujours écrites. `latest` = `APP_LATEST_VERSION`, `minimum` = `APP_MIN_VERSION` (`null` si vides ou mal formées). `outdated` est vrai si la **dernière version enregistrée** pour ce compte (Android) est plus ancienne que `latest` **ou** que `minimum`, comparée nombre par nombre (`0.1.10` > `0.1.9`, `1.0.0` > `0.99.99`). Faux si aucune version n'est connue (navigateur seul, application qui ne l'a jamais envoyée) ou si le serveur ne connaît ni `latest` ni `minimum`. Jamais d'erreur.
- `GET /api/v1/users` (administrateur) : chaque `member` gagne `appVersion: string|null` (dernière version Android vue) et `appOutdated: boolean` (même règle que `outdated`).

**Ce que le front doit savoir** :

- `outdated` vient de la version **enregistrée**, pas de celle qui tourne : un compte qui a utilisé une ancienne application puis ouvre le site dans un navigateur peut lire `outdated: true`. Le bandeau ne s'affiche que dans l'application (pont `window.repasNative` présent). Juste après une mise à jour, `outdated` reste vrai tant que la nouvelle application n'a pas envoyé sa version (au démarrage suivant, au plus une écriture par minute) : envoyer la version **avant** de relire `GET /api/v1/me`, ou comparer la version du pont à `latest` et `minimum` (nombre par nombre) pour décider du bandeau.
- La route n'accorde aucun droit : la version vient de l'application, elle ne sert qu'à informer.

**Fait** :

- Domaine (`User`) : `Model/AppPlatform` (`android`), `Model/AppVersion` (motif, 20 caractères, comparaison nombre par nombre, `tryFromString()` sans erreur pour les réglages), `Model/AppRelease` (`latest`, `minimum`, `isOutdated()`), `Model/UserAppVersion` (compte, plateforme, version, heure : rien d'autre), `Exception/AppVersionException::invalidVersion()` (`422 INVALID_APP_VERSION`), ports `Interface/UserAppVersionRepository` (`save`, `deleteAllOfUser`) et `Interface/AppReleaseProvider`.
- Écriture `Application/UpdateUserAppVersion/` (commande + handler, une requête SQL, idempotente). Controller `Http/Api/UpdateUserAppVersion/` (payload validé : `Choice`, `Regex`, `Length`), limiteur `app_version` (fenêtre glissante, 1 par minute, clé `user-<id>` du jeton, état dans le cache : aucune requête SQL) dans `AppVersionRateLimitListener`.
- Table `user_app_version(user_id, platform, version, seen_at)`, clé primaire `(user_id, platform)`, migration additive `Version20261020120000` (aucune clé étrangère, `user` intact) ; écriture en SQL direct (`UserAppVersionPostgreSQLRepository`).
- Lecture : `UserPostgreSQLLoader` joint `user_app_version` (une requête pour `get`, une pour `list`, aucune par compte) ; `AppUpdateDTO` ; `UserSummaryDTO` gagne `appVersion` et `appOutdated`. Réglages lus par `Infrastructure/AppRelease/EnvAppReleaseProvider` (`%env(default::…)%` : une variable absente vaut vide).
- Purge : `DeleteUserHandler` efface les versions du compte (après le compte, voir « Écarts ») ; `UserAppVersion::class => ['userId']` dans `UserOwnedDataCoverageTest` ; budget `supprimer-compte` 20 → 21 ; nouveau scénario `version-application` = 1.
- Exploitation : `APP_LATEST_VERSION` et `APP_MIN_VERSION` vides dans `.env`, `deploy/compose.yaml` (`${…:-}`), `deploy/.env.example`, `docs/production.md` ; contrôle dans `deploy/smoke-test.sh` ; script `ecrire-app-version.sh` du skill `repas-ops` (comme `DRIVE_ENABLED`).
- Tests : domaine à table (`AppVersionTest`, `AppReleaseTest`, `UserAppVersionTest`), handler en mémoire (`UpdateUserAppVersionHandlerTest`, `UserAppVersionInMemoryRepository` qui n'écrit que pour un compte connu), dépôt et loader sur base (`UserAppVersionRepositoryTest`, `UserPostgreSQLLoaderTest`, nombre de requêtes compris), controller (`UpdateUserAppVersionApiControllerTest` : corps invalides, `401` sans jeton, compte désactivé et compte supprimé, deux appels dans la minute, minute suivante, limite par compte, corps invalide qui ne consomme pas la minute), parcours avec les vrais bus (`AppVersionApiFlowTest`, `AccountAdministrationFlowTest`), `EnvAppReleaseProviderTest`.

**Écarts et choix** :

- **Lecture de l'utilisateur courant** : la route est `GET /api/v1/me` (le ticket écrit `/api/v1/users/me`, qui n'existe pas) ; `appUpdate` est dans `CurrentUserDTO`, donc aussi dans `GET /api/v1/users/{id}`.
- **Plafond sans erreur** : au-delà d'une écriture par minute, `204` sans écriture (et non `429` comme les autres plafonds, ADR 0007 § 7, amendé) : ce n'est pas une protection contre un abus, seulement l'assurance qu'un démarrage répété n'écrit pas plus d'une fois par minute. Le jeton du limiteur n'est consommé **qu'après** la sécurité et la validation du corps (les autres plafonds comptent aussi les corps invalides) : un corps invalide reçoit son `422` sans priver l'appel valide suivant de son écriture. Conséquence assumée : une mise à jour de l'application suivie d'un redémarrage dans la minute n'est enregistrée qu'au démarrage suivant.
- **`outdated` compare aussi à `minimum`** (et pas seulement à `latest`) : sinon un serveur qui n'aurait que `APP_MIN_VERSION` n'inviterait personne. Avec un réglage normal (`minimum` ≤ `latest`), c'est la règle « plus ancienne que `latest` » du ticket. Même règle pour `appOutdated` de l'administration.
- **Un compte supprimé ne retrouve pas de ligne**, même si l'écriture croise la suppression : l'écriture est une seule instruction (`INSERT … SELECT … FROM "user" … FOR KEY SHARE … ON CONFLICT`), qui n'écrit que si le compte existe et verrouille sa ligne jusqu'à la fin de la transaction ; `DeleteUserHandler` supprime le compte **puis** ses versions. Vérifié à la main avec deux connexions PostgreSQL concurrentes (les deux ordres : aucune ligne ne reste ; sans le verrou, la ligne renaît) ; non rejouable en PHPUnit (une seule connexion, transaction annulée).
- Réglage vide, fait d'espaces ou mal formé (`0.1.10-dev`) : « inconnu », jamais une erreur.

**Vérifié en plus** :

- Base vide migrée de bout en bout puis `doctrine:schema:validate` vert ; `down` supprime la table, `up` la remet ; le SQL de la migration est celui que Doctrine déduit de l'entité.
- Code précédent (`origin/main`) sur la base migrée : le `doctrine:migrations:migrate --no-interaction --all-or-nothing` du démarrage répond « Already at the latest version » (avertissement pour la migration inconnue) ; sa suite entière passe avec la table présente (2593 tests).
- Image de production (`make deploy-smoke`, nouvelle ligne : les deux variables arrivent vides dans `php`) ; sur cette pile, `ecrire-app-version.sh latest 0.1.10` puis une application `0.1.8` : `GET /api/v1/me` répond `"appUpdate":{"latest":"0.1.10","minimum":null,"outdated":true}` ; deuxième envoi dans la minute `204` sans écriture ; `0.1.8-dev` `422` ; `latest ''` remet `latest: null, outdated: false`. Le script refuse une version mal formée (`0.1.10-dev`) sans rien écrire.

**Reste à faire (front, autre PR)** : points 1, 5, 6 et 7 de la portée, avec le contrat ci-dessus (`npm run api:update` sur le commit du back fusionné). Ordre de publication inchangé : back (route inactive tant que le front ne l'appelle pas), puis site, puis application ; `APP_LATEST_VERSION` s'écrit **après** la publication de l'application (sinon les téléphones seraient invités à une version absente).

## Réalisation (partie front, PR front #173, site v0.5.12, 2026-10-20)

- **Envoi** : dans l'application, une fois la session ouverte, la version du pont part une fois par session (`PUT /api/v1/users/me/app-version`) ; rien dans un navigateur, rien pour `0.0.0-dev` ou une version hors `X.Y.Z` ; échec, hors connexion ou `422` : silence, pas de nouvel essai.
- **Bandeau « Mettre à jour » dans « Moi »** (application seulement) : « Une nouvelle version de l'application est prête. » + « Mettre à jour » (vers `/drive/aide#mise-a-jour`). **Décision** : le bandeau compare la version du pont, nombre par nombre, à `latest` et à `minimum` ; le `outdated` du serveur ne sert que de repli quand le pont ne dit pas sa version (il vient de la version enregistrée, pas de celle qui tourne) ; rien si `latest` et `minimum` sont `null`.
- **Administration des comptes** : « Application : 0.1.8 » (« — » si inconnue) et le repère « À mettre à jour » (`appOutdated`).
- **Vie privée** : une phrase dans les conditions d'utilisation (`driveTerms.saves.appVersion`), recopiée dans [`../note-vie-privee.md`](../note-vie-privee.md).
- **Le site s'adapte à l'application** : principe écrit dans `android/PUBLICATION.md` du front (`MIN_DRIVE_APP_VERSION`, `RAYON_HINTS_APP_VERSION`).
- **Exploitation** : `APP_LATEST_VERSION` est écrit par l'assistant avec `ecrire-app-version.sh` (skill `repas-ops`), **après** chaque publication de l'application (jamais avant) ; procédure dans [`../../production.md`](../../production.md).
