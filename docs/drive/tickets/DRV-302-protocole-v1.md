# DRV-302 — Protocole v1 (schémas, codec, validation)

- **Dépôt** : front, dossier `drive-agent/protocol/`
- **Jalon** : M3 Agent
- **Taille** : M
- **Dépend de** : DRV-301
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) § 8 (messages, validation) ; [ADR 0022](../../adr/0022-remplissage-du-panier-drive.md) décision 5.

## Contexte

Repas et l'agent échangent des messages par fragment d'URL. Le format doit être défini une fois, validé strictement des deux côtés et testé pour que le front et l'agent restent d'accord. L'agent doit rester petit : pas de bibliothèque de validation dans le favori.

## Portée

1. Schémas JSON (source de vérité documentaire) : `protocol/plan.schema.json` (types `plan` et `setup`), `protocol/result.schema.json` (types `result` et `setup-result`), exemples valides et invalides dans `protocol/examples/` (au moins 6 de chaque), `protocol/README.md` (renvoie vers `architecture.md` § 8).
2. Module TypeScript `protocol/index.ts` partagé par l'agent et le front (alias `@drive-protocol`, configuré dans `tsconfig` et `vite`/`vitest`) :
   - types : `PlanMessage`, `SetupMessage`, `PlanLine`, `ResultMessage`, `SetupResultMessage`, `ResultLine`, codes d'erreur, issues de ligne ;
   - `encodeMessage(message): string` (JSON → UTF-8 → base64url sans bourrage) et `decodeMessage(text): unknown` (refuse > 64 Ko décodés, refuse JSON invalide) ;
   - `validatePlan(unknown): PlanMessage | SetupMessage` et `validateResult(unknown): ResultMessage | SetupResultMessage` : validateurs **écrits à la main**, sans dépendance, qui lèvent `ProtocolError{code:'INVALID_MESSAGE', path}` ;
   - constantes : `PROTOCOL_VERSION = 1`, limites (100 lignes, `packs` 1..99, libellés ≤ 255).
3. Règles de validation du plan (toutes testées) : `v === 1` ; `type` ∈ `plan|setup` ; `runId` uuid ; `retailer === 'leclerc'` ; `store.id` `^[0-9]{1,12}$` et `store.host` `^[a-z0-9-]+\.leclercdrive\.fr$` ; `returnPath === '/drive/retour'` ; lignes ≤ 100 ; `ingredientSlug` `^[a-z0-9-]{1,100}$` ; `status` ∈ `KNOWN|NEW|HAVE_AT_HOME|SKIP` ; `KNOWN` exige `product` (`id` `^[0-9]{1,12}$`, `label` ≤ 255, `content?` `{value>0, unit ∈ g|kg|ml|cl|l|piece}`) ; `packs` entier 1..99 ou null ; `packsStatus` ∈ `COMPUTED|TO_CHECK|null` ; `replaces?` `{id,label}` ; chaînes bornées ; **aucune clé inconnue tolérée** (rejet) ; identifiants de ligne uniques.
4. Règles du résultat : `v === 1`, `type`, `runId`, `agentVersion` semver, `outcome` ∈ `completed|partial|aborted|error`, `store.id`, `error?` `{code ∈ liste, message ≤ 200}`, lignes (`outcome` ∈ `ADDED|CHOSEN|HAVE_AT_HOME|SKIPPED|UNAVAILABLE|FAILED`, `CHOSEN` exige `product`, `ADDED` exige `productId`). `setup-result` : `store {id, host, baseUrl, label|null}`.
5. Tests de conformité : chaque exemple valide passe, chaque exemple invalide échoue avec le bon `path` ; un test (dans le front, avec Ajv en dépendance de **développement seulement**) vérifie que les schémas JSON et les validateurs manuscrits donnent le même verdict sur tous les exemples.

## Hors portée

Comportements de l'agent, écrans du front.

## Critères d'acceptation

- Un plan valide (exemple du § 8 de `architecture.md`) est accepté ; un message avec une clé inconnue, 101 lignes, `packs` 100, un slug avec majuscule, un `returnPath` différent ou un hôte hors `leclercdrive.fr` est rejeté.
- `decodeMessage(encodeMessage(m))` redonne `m` pour des libellés accentués et des emojis.
- Le module ne contient aucune dépendance d'exécution et ajoute moins de 6 Ko au bundle.

## Tests exigés

Vitest : validateurs, codec (aller-retour, taille maximale, base64url invalide), conformité aux schémas.

## Definition of Done

Celle du front ; `architecture.md` § 8 mis à jour si le format évolue (le schéma et le doc doivent rester identiques).

## Pièges connus

- base64url : remplacer `+`→`-`, `/`→`_`, supprimer `=` ; décoder en restaurant le bourrage.
- Ne jamais interpréter un champ du message comme HTML ni comme URL de navigation.
