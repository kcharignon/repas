# VOX-201 — Contexte `Voice` : squelette, interrupteur `ALEXA_ENABLED`, migration, purge

- **Dépôt** : back
- **Jalon** : M2 Back `Voice`
- **Taille** : M
- **Dépend de** : —
- **Faisable sans téléphone** : oui
- **Statut** : à faire
- **Lire d'abord** : [ADR 0029](../../adr/0029-commande-vocale-alexa.md) décisions 2, 8, 9 ; `src/Shared/Infrastructure/Feature/EnvDriveFeature.php`, `RequiresDrive` et son listener ; `deptrac*.yaml` ; [ADR 0021](../../adr/0021-gestion-des-comptes-par-l-administrateur.md) §10 (`PurgeUserDriveData`, `UserOwnedDataCoverageTest`).

## Contexte

Poser le contexte vide mais câblé : dossiers, Deptrac, interrupteur, tables, purge. Les tickets suivants le remplissent.

## Portée

1. `src/Voice/{Domain,Application,Infrastructure}` ; couches ajoutées à Deptrac (`Voice` → `Shared` seulement, **sans baseline**) ; namespaces et `services.yaml`.
2. Interrupteur : `Shared\Domain\Interface\VoiceFeature::isEnabled()` ← `EnvVoiceFeature` (`ALEXA_ENABLED`, faux par défaut, y compris absente), attribut `#[RequiresVoice]` + listener `kernel.controller` (`404` `VOICE_DISABLED` avant la validation du corps), sur le modèle de `#[RequiresDrive]`.
3. Migration **additive** (compatible avec la version précédente du code) : `voice_link` (`id`, `user_id`, `alexa_user_id`, `created_at`, `last_used_at`, `revoked_at`), `voice_authorization_code` (`code_hash`, `user_id`, `client_id`, `redirect_uri`, `code_challenge`, `expires_at`, `used_at`), `voice_access_grant` (`access_hash`, `refresh_hash`, `link_id`, `expires_at`, `refresh_expires_at`) ; index sur les hachés et sur `user_id`. Entités Doctrine séparées du modèle.
4. `PurgeUserVoiceData` répond à `UserDeletedEvent` (même transaction) : efface liaisons, codes, jetons du compte. `UserOwnedDataCoverageTest` vert.
5. `.env` : `ALEXA_ENABLED=` vide, `ALEXA_SKILL_ID=`, `ALEXA_OAUTH_CLIENT_ID=repas-alexa`, `ALEXA_OAUTH_CLIENT_SECRET_HASH=` (jamais le secret), `ALEXA_REDIRECT_URIS=` (liste séparée par des virgules) ; `deploy/.env.example` documenté **et** les variables listées dans `deploy/compose.yaml` avec leur valeur par défaut (`ALEXA_ENABLED: ${ALEXA_ENABLED:-false}`…), comme `DRIVE_ENABLED` : sinon le `.env` embarqué dans l'image s'applique en silence.

## Critères d'acceptation

- `make quality` vert, Deptrac sans baseline ; coupé, une route marquée `#[RequiresVoice]` répond `404 VOICE_DISABLED` et n'exécute rien.
- Supprimer un compte efface ses lignes `voice_*` (test de base).
- La migration s'applique sur une base vide et sur la base de dev sans toucher aux tables existantes.

## Tests exigés

Listener d'interrupteur ; purge (`tests/Voice/OutputAdapter/`) ; `UserOwnedDataCoverageTest` ; `make deploy-smoke` si `deploy/.env.example` change.

## Pièges connus

- Aucune clé étrangère dans ce dépôt : les purges sont explicites.
- Ne pas stocker le secret du client en clair, même chiffré : comparer un haché à temps constant.
