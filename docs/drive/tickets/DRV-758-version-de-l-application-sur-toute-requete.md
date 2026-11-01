# DRV-758 — La version de l'application est retenue sur toute requête authentifiée

- **Dépôt** : back (contexte `User`) + front (en parallèle, autre agent)
- **Jalon** : M7 Plusieurs enseignes (constat du propriétaire du 2026-11-01)
- **Taille** : S
- **Dépend de** : DRV-637
- **Statut** : partie back faite (PR back, 2026-11-01) ; le front envoie les deux en-têtes sur chaque requête quand il connaît la version (autre agent)
- **Lire d'abord** : [DRV-637](DRV-637-version-de-l-application-connue-du-serveur.md), `src/User/Infrastructure/EventListener/RecordAppVersionOnRequestListener.php`, `docs/adr/0010-*` (budgets SQL), ADR 0021 (compte désactivé), ADR 0035 (contextes étanches)

## Constat

Le site n'appelait `PUT /api/v1/users/me/app-version` qu'une fois par session de connexion, et notait « déjà envoyé » avant de savoir si l'envoi avait réussi. Quand le propriétaire a mis l'application à jour et s'est reconnecté, l'administration (`GET /api/v1/users`, `appVersion`) n'a pas vu la nouvelle version : il a fallu se déconnecter volontairement puis se reconnecter. Demande : « dès que l'utilisateur fait une seule requête avec une version, on doit mettre à jour ».

## Décision

Le back lit la version sur **toute** requête authentifiée de `/api/v1/*` : en-têtes `X-App-Version` (trois nombres séparés par des points, comme `AppVersion::fromString`) et `X-App-Platform` (`android` seulement, comme `AppPlatform`). Les noms sont fixés (le front les envoie sur chaque requête quand il connaît la version). `PUT /api/v1/users/me/app-version` reste en place, compatible.

## Réalisation (back)

- **Écouteur** `RecordAppVersionOnRequestListener` (`User/Infrastructure/EventListener`, `kernel.terminate`) : après la réponse, jamais un échec ni un changement de réponse pour l'utilisateur ; une écriture qui lève est journalisée (classe et endroit, jamais le message) et sans effet visible. Il ne prend le bus, le cache et le limiteur qu'au moment d'écrire (fermetures de service).
- **Zéro requête SQL de plus sur le chemin courant** : la dernière version écrite par compte et plateforme est en mémoire dans le cache de l'application (`cache.app`, alias `repas.app_version_cache`, `LastSeenAppVersions`, une heure). Une écriture (`UpdateUserAppVersion`, par le bus) n'a lieu que si la version vue est **plus récente** que celle en mémoire (deux appareils du même compte ne se disputent pas la ligne : la plus récente gagne) ou si la mémoire est vide (premier passage, expiration, redémarrage).
- **Au plus une écriture par minute et par compte** : le limiteur `app_version` du `PUT`, le même compteur (un seul), consommé seulement quand une écriture est due. Refusée par le limiteur, la version est reprise à la requête suivante (la mémoire n'a pas bougé). Le `PUT` met la même mémoire à jour (l'écouteur ne réécrit pas ce que le `PUT` vient de poser) ; l'écouteur ne traite jamais la route du `PUT`.
- **Information non fiable** : un en-tête absent, mal formé (`AppVersion::tryFromString`) ou d'une plateforme inconnue (`AppPlatform::tryFrom`, casse comprise) est ignoré en silence, jamais une erreur. Le compte vient du jeton, jamais d'un en-tête ; une route publique (aucun jeton) et un compte désactivé (ADR 0021) n'écrivent rien ; la version n'accorde aucun droit.
- **Contrat** : en-têtes décrits dans la description générale de l'API (`config/packages/nelmio_api_doc.yaml`) et dans celle du `PUT` ; `docs/api/openapi.json` régénéré.

## Critères d'acceptation

- Étant donné une application `0.1.8` connectée, quand n'importe quelle requête authentifiée porte les deux en-têtes, alors la table contient `android` / `0.1.8` pour ce compte et l'administration la montre.
- Étant donné la même version déjà connue : exactement les mêmes requêtes SQL qu'une requête sans en-tête, aucune écriture.
- Version plus récente dans la même minute : rien n'est écrit, puis écrite à la première requête après la minute ; version plus ancienne que celle connue : rien n'est écrit.
- Version mal formée, plateforme inconnue, route publique, compte désactivé, écriture en panne : aucune erreur, réponse identique.

## Tests

`tests/User/Infrastructure/EventListener/RecordAppVersionOnRequestListenerTest.php` (unitaire, vrai limiteur et vraie mémoire en mémoire, double du bus) ; `tests/User/InputAdapter/AppVersionOnEveryRequestFlowTest.php` (vrai jeton, vrai bus, vraie base : écriture, réponse inchangée, zéro requête de plus, minute, PUT, route publique, compte désactivé, panne).

## Ordre de livraison

Back d'abord (les en-têtes sont ignorés tant que le front ne les envoie pas), puis le site. Aucune migration, aucune variable d'environnement. Le propriétaire approuve chaque déploiement.

## Limites connues (voulues)

- Un utilisateur peut polluer SA PROPRE ligne de version (par le `PUT` ou par l'en-tête, par exemple `99999.0.0` gardé une heure en mémoire) : la version n'accorde aucun droit, elle informe seulement.
- Avec deux appareils du même compte à des versions différentes, ou après un vidage du cache (déploiement), la ligne peut repasser brièvement à la version la plus ancienne avant de revenir à la plus récente.
- La rétrogradation de l'application (installer une version plus ancienne) n'est vue qu'après l'expiration de la mémoire (une heure) ou par le `PUT`, qui écrit sans comparer.
- CORS : `allow_headers` de `config/packages/nelmio_cors.yaml` permet `X-App-Version` et `X-App-Platform` (sinon le préflight du site de développement refuserait toutes les requêtes) ; `testThePreflightAllowsTheVersionHeaders` le prouve.
- `kernel.terminate` sous FrankenPHP : non mesuré en production ; à vérifier sur l'image de production au prochain déploiement.
