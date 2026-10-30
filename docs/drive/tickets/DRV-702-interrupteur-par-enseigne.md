# DRV-702 — Interrupteur par enseigne `DRIVE_RETAILERS`

- **Dépôt** : back (`src/Shared/`, `src/Drive/`, `deploy/`, `docs/production.md`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : S
- **Dépend de** : DRV-701
- **Statut** : fait (PR #XXX, 2026-10-30). Effort recommandé : `high` (touche `deploy/` : `make deploy-smoke`).
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) § 7 ; [`../architecture.md`](../architecture.md) § 5.3 (interrupteur) ; DRV-103 (`DRIVE_ENABLED`) ; skill `repas-ops` (`references/drive.md`) ; ADR 0017.

## Contexte

`DRIVE_ENABLED` coupe tout le drive. Pour réparer ou mettre en service **une** enseigne sans toucher l'autre, il faut un interrupteur par enseigne. L'humain seul l'allume (`consignes-agents.md`).

## Portée

1. Variable `DRIVE_RETAILERS` : liste d'identifiants séparés par des virgules, **par défaut `leclerc`** (`${DRIVE_RETAILERS:-leclerc}` dans `deploy/compose.yaml`, puis `deploy/.env.example`, `docs/production.md`, `.env`/`.env.test`/`phpunit.dist.xml` pour les tests). Un identifiant inconnu du registre est ignoré et journalisé une fois au démarrage (jamais une erreur qui empêche de démarrer).
2. Port `Shared\Application\Interface\DriveFeature` : `isRetailerEnabled(string $retailer): bool` et `enabledRetailers(): list<string>` ; implémentation `EnvDriveFeature` ; double `ControlledDriveFeature` des tests.
3. Les handlers du contexte `Drive` qui reçoivent une enseigne la refusent si elle est désactivée : `DriveFeatureException::retailerDisabled()` → `404 DRIVE_RETAILER_DISABLED` (après la sécurité et l'interrupteur global). Routes concernées : celles qui portent `retailer` dans le corps ou la requête, et plans/estimations.
4. `GET /drive/settings` ne liste que les enseignes activées dans `retailers`. Si `DRIVE_ENABLED=false`, rien ne change (`enabled: false`).
5. `DriveRoutesAreGuardedBySwitchTest` : tableau de toutes les routes avec enseigne ; `reopen` et la purge du compte restent permis quelle que soit l'enseigne.
6. Runbook (`docs/drive/runbook.md` § « couper ») et `docs/production.md` : « couper une seule enseigne » ; skill `repas-ops`.

## Hors portée

Allumer une enseigne en production (l'humain) ; l'interrupteur dans l'interface ; la sélection (DRV-703).

## Critères d'acceptation

- Étant donné `DRIVE_RETAILERS` absent, alors la production se comporte comme avant (Leclerc seul), tous les tests de l'interrupteur global passent sans changement d'attente.
- Étant donné `DRIVE_RETAILERS=` (vide), alors aucune enseigne : `PUT /drive/settings` répond `404 DRIVE_RETAILER_DISABLED`, `GET /drive/settings` répond `retailers: []`.
- Étant donné une enseigne désactivée, ses données restent en base et reparaissent quand elle est réactivée (test de base).
- `reopen` d'une liste `SENT` répond `204` même si toutes les enseignes sont désactivées.

## Tests exigés

`EnvDriveFeatureTest` (analyse de la liste, espaces, doublons, inconnus) ; handlers en unitaire avec `ControlledDriveFeature` ; `DriveRoutesAreGuardedBySwitchTest` ; `make deploy-smoke`.

## Definition of Done

Celle du skill `repas-dev` ; `make deploy-smoke` vert ; variable listée avec sa valeur par défaut dans `deploy/compose.yaml`, `deploy/.env.example` et `docs/production.md` ; `docs/api/openapi.json` (nouveau code `404 DRIVE_RETAILER_DISABLED`) ; roadmap cochée.

## Pièges connus

- Ne pas confondre avec un retrait de l'enseigne du registre : désactiver n'efface rien.
- Le code `DRIVE_DISABLED` existant ne change pas (l'application le connaît) ; le nouveau code est distinct.
- Un agent n'allume rien : la variable se règle dans `/opt/repas/.env` par l'humain.

## Réalisation (back, 2026-10-30)

Rien n'est publié ni activé : la production se comporte comme avant (`DRIVE_RETAILERS` absente vaut `leclerc`), aucun nom d'enseigne n'est ajouté, aucune enseigne n'est allumée.

**Contrat** : nouveau code `404 DRIVE_RETAILER_DISABLED` (documenté sur les sept routes qui reçoivent une enseigne dans `docs/api/openapi.json`, descriptions seulement) ; `DRIVE_DISABLED` ne change pas et passe avant. `GET /drive/settings` : `retailers` ne liste que les enseignes allumées (`[]` si aucune) ; `enabled`, `retailer` et `store` ne changent pas. Aucun champ ajouté ni retiré : le front n'a rien à recopier.

**Variable** : `DRIVE_RETAILERS`, une liste d'identifiants séparés par des virgules. Absente : `leclerc`. Vide : **aucune enseigne**. `.env`, `.env.test` et `phpunit.dist.xml` (forcée en test) la posent à `leclerc`. **Écart avec le ticket** : `deploy/compose.yaml` écrit `${DRIVE_RETAILERS-leclerc}` (sans les deux-points) et non `${DRIVE_RETAILERS:-leclerc}` : avec `:-`, une variable vide serait remplacée par `leclerc`, et le critère « `DRIVE_RETAILERS=` vide, alors aucune enseigne » serait faux en production. `make deploy-smoke` vérifie les deux cas (défaut `leclerc` ; vide reste vide). `deploy/.env.example` et `docs/production.md` la listent avec sa valeur par défaut.

**Port** : `DriveFeature::isRetailerEnabled()` et `enabledRetailers()` ; `EnvDriveFeature` (analyse : espaces, éléments vides et doublons retirés, ordre gardé, la casse compte ; un identifiant inconnu du registre est ignoré et journalisé, un avertissement par identifiant, à la construction du service) ; le registre arrive par le paramètre `repas.drive.known_retailers` (`!php/const Retailer::IDS` dans `config/services.yaml`), `Shared` ne nomme aucune enseigne. Double de test `ControlledDriveFeature(bool $enabled = true, ?array $retailers = null)` (`null` : toutes les enseignes du registre ; `switchRetailers()` / `switchEnabled()` pour changer d'état entre deux requêtes d'un test).

**Handlers** (le contrôle est la première instruction) : `SaveDriveSettings`, `SaveProductChoices`, `ReplaceIngredientPreference`, `ForgetChoice`, `ListChoices`, `GetDrivePlan`, `GetDriveEstimate` ; ils reçoivent `DriveFeature` en dernier argument. Exceptions : `GET /drive/settings` (filtre dans `DriveSettingsPostgreSQLLoader`), `send`, `reopen`, `PurgeUserDriveData`. Conséquence de « handler » : un corps invalide reste `422` avant d'arriver au cas d'usage, alors que l'interrupteur global répond avant les arguments (listener).

**Tests** : `EnvDriveFeatureTest` (liste, espaces, doublons, inconnus, journal), `DriveFeatureWiringTest`, un test par handler, `DriveSettingsPostgreSQLLoaderTest`, et `DriveRetailerSwitchApiFlowTest` (vrais bus, vraie base) : toutes les routes du drive sont listées avec ou sans enseigne (`testEveryDriveRouteIsListed` échoue pour une route nouvelle), chaque route avec enseigne répond puis `404 DRIVE_RETAILER_DISABLED`, le global garde son code, les données d'une enseigne éteinte restent et reviennent, `reopen` répond `204` sans aucune enseigne allumée. Les budgets SQL (`budgets.php`) ne bougent pas.

**À faire par l'humain / l'assistant (hors de ce ticket)** : un script `ecrire-drive-retailers.sh` dans `.claude/skills/repas-ops/references/` (sur le modèle de `ecrire-drive-enabled.sh` : écrit `DRIVE_RETAILERS` dans `/opt/repas/.env` avec copie `.env.avant-enseignes`, recrée `php`, vérifie `printenv` et la santé ; valeurs permises : des identifiants du registre séparés par des virgules, ou vide) ; jusque-là la variable se règle à la main dans `/opt/repas/.env`. `docs/production.md`, `docs/drive/runbook.md` § 4 et `references/drive.md` décrivent la procédure à la main. DRV-716 déclarera Auchan dans le registre : il sera alors reconnu par `EnvDriveFeature`, **éteint** tant que l'humain ne l'ajoute pas à la variable.
