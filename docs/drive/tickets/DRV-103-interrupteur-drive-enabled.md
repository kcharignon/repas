# DRV-103 — Interrupteur `DRIVE_ENABLED`

- **Dépôt** : back
- **Jalon** : M1 Back `Repas`
- **Taille** : S
- **Dépend de** : —
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 5.3, 12 ; ADR 0017 ; `docs/production.md` ; `.claude/skills/repas-ops/` (pour savoir comment une variable d'environnement atteint la production).

## Contexte

Le statut `SENT` n'existe pas pour l'ancien code : tant que la fonction n'est pas stable en production, elle doit rester coupée, sans qu'aucune liste `SENT` n'existe. L'activation est une décision humaine.

## Portée

1. Port `Repas\Shared\Application\Interface\DriveFeature` avec `isEnabled(): bool`.
2. Implémentation `Repas\Shared\Infrastructure\…\EnvDriveFeature` (autowiring avec `#[Autowire('%env(bool:DRIVE_ENABLED)%')]`).
3. Variable `DRIVE_ENABLED` : `.env` (valeur `false`), `.env.test` (valeur `true`, avec un double `DriveFeature` désactivé pour les tests du cas coupé), `deploy/compose.yaml` (dans la liste explicite `environment:` du service PHP : `DRIVE_ENABLED: ${DRIVE_ENABLED:-false}`), `deploy/.env.example` (commentaire : « active l'envoi de la liste au drive ; laisser false tant que la fonction n'est pas validée »), `docs/production.md`, et si le script `deploy/smoke-test.sh` énumère les variables, l'y ajouter.
4. Une aide pour les contrôleurs : méthode ou petit service (ex. `DriveFeature::assertEnabled()` ou un `Request listener` limité aux routes concernées) qui répond `404` avec le message `DRIVE_DISABLED` quand l'interrupteur est coupé. Utilisable par DRV-102, 203, 204, 206.
5. `docs/drive/architecture.md` § 12 et `docs/production.md` à jour si le nom ou la valeur par défaut change.

## Hors portée

L'exposition dans `GET /drive/settings` (DRV-203) ; l'activation en production (DRV-505).

## Critères d'acceptation

- Étant donné `DRIVE_ENABLED=false`, quand on appelle un contrôleur protégé, alors `404` et `{"error":{"message":"DRIVE_DISABLED"}}`.
- Étant donné `DRIVE_ENABLED=true`, le contrôleur répond normalement.
- Une variable absente de l'environnement de production vaut `false` (pas d'activation silencieuse par la valeur de `.env` embarquée dans l'image).
- `make deploy-smoke` passe (la modification touche `deploy/`).

## Tests exigés

Unitaire de l'implémentation (valeurs vrai/faux) ; test du comportement `404` sur une route d'essai ou sur `send` (avec le double coupé) ; `make deploy-smoke`.

## Definition of Done

Celle du skill `repas-dev` § 4 + `make deploy-smoke` (le dossier `deploy/` change) + `docs/production.md` relu.

## Pièges connus

- Le `.env` est embarqué dans l'image de production par `composer dump-env prod` : une variable non listée dans `deploy/compose.yaml` prend silencieusement la valeur de développement. D'où la liste explicite avec valeur par défaut `false`.
- Les secrets ne sont jamais saisis dans le chat (ADR 0017) ; ici il n'y a pas de secret, seulement un booléen.
