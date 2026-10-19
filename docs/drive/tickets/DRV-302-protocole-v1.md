# DRV-302 — Protocole v1 (schémas, codec, validation)

- **Dépôt** : front, dossier `drive-agent/protocol/`
- **Jalon** : M3 Agent
- **Taille** : M
- **Dépend de** : DRV-301
- **Statut** : fait (PR [repas-front#77](https://github.com/kcharignon/repas-front/pull/77), 2026-10-17)
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
3. Règles de validation du plan (toutes testées) : `v === 1` ; `type` ∈ `plan|setup` ; `runId` uuid ; `retailer === 'leclerc'` ; `store.id` `^[0-9]{1,12}$` et `store.host` `^[a-z0-9-]+\.leclercdrive\.fr$` ; `returnPath === '/drive/retour'` ; lignes ≤ 100 ; `ingredientSlug` `^[a-z0-9-]{1,100}$` ; `status` ∈ `KNOWN|NEW|HAVE_AT_HOME|SKIP` ; `products` (liste classée par priorité, **5 au plus, sans doublon**, chaque produit `{id `^[0-9]{1,12}$`, label ≤ 255, content? `{value>0, unit ∈ g|kg|ml|cl|l|piece}`, packs entier 1..99 ou null, packsStatus ∈ `COMPUTED|TO_CHECK|null`}`) : `KNOWN` en exige au moins 1, `NEW` n'en a aucun, `HAVE_AT_HOME` et `SKIP` n'en ont pas obligatoirement ; `rejected` (liste de `{id, label}`, **20 au plus**) ; **aucun produit à la fois dans `products` et dans `rejected`** ; `replaces?` `{id,label}` ; chaînes bornées ; **aucune clé inconnue tolérée** (rejet) ; identifiants de ligne uniques.
4. Règles du résultat : `v === 1`, `type`, `runId`, `agentVersion` semver, `outcome` ∈ `completed|partial|aborted|error`, `store.id`, `error?` `{code ∈ liste, message ≤ 200}`, lignes (`outcome` ∈ `ADDED|CHOSEN|HAVE_AT_HOME|SKIPPED|UNAVAILABLE|FAILED`). `ADDED` exige `productId`, `quantity`, `usedRank` (entier 1..5) et accepte `unavailableIds` (≤ 4 identifiants) ; `CHOSEN` exige `product` `{id, ean?, label, content?}`, `quantity` et `placement` ∈ `PRIMARY|FALLBACK|ONCE`, et accepte `unavailableIds` ; `UNAVAILABLE` accepte `unavailableIds` ; toute ligne accepte `rejected` (≤ 20 produits `{id, label, ean?, content?}`) ; `unavailableIds` et `rejected` sans doublon. `setup-result` : `store {id, host, baseUrl, label|null}`.
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

- **Prix (décision D22)** : le `product` d'une ligne du message de résultat peut porter `price` : `{cents: entier ≥ 0, perMeasure?: {cents: entier ≥ 0, unit: 'kg'|'l'|'piece'}}` (borné, facultatif). Voir `architecture.md`.

## Mise à jour G1 (2026-10-17)

Le transport est **interchangeable** : le protocole v1 (messages `plan`, `setup`, `result`, validation, bornes) reste identique, mais l'agent reçoit le plan et renvoie le résultat par un **pont de l'application Android** (DRV-313) au lieu du fragment d'adresse. Prévoir dans le module de l'agent une interface de transport avec deux implémentations (fragment d'adresse pour le favori de bureau et les tests, pont pour l'application). Côté Repas, le plan part dans un lien `repasdrive://open?plan=<base64url>` (DRV-314, DRV-402) ; le retour reste `https://kantin.eu/drive/retour#result=<base64url>`.

## Réalisation (2026-10-17, PR repas-front#77)

`drive-agent/protocol/` (alias `@drive-protocol`) : types, limites, `encodeMessage` / `decodeMessage` (64 Ko), `validatePlan` / `validateResult`, `ProtocolError`, schémas JSON `plan.schema.json` / `result.schema.json`, 17 exemples valides et 53 invalides. `drive-agent/src/transport/` : interface `Transport { listen(onMessage, onInvalid); send(result) }`, `fragmentTransport(page, returnOrigin)` et `bridgeTransport(bridge)` (le pont est un objet avec `postMessage(texte)` et `addEventListener('message', …)`, JSON en texte). Précisions : `store` est obligatoire dans un `setup` ; `error` obligatoire si l'issue globale est `error` ; une ligne `FAILED` porte un code `error` ; EAN de 8 à 14 chiffres ou `null` ; `packs` et `packsStatus` facultatifs ou `null`.

## Réalisation : corrections de la recette réelle (2026-10-18 et 19)

- **Cause dans `error.message`** (PR [repas-front#127](https://github.com/kcharignon/repas-front/pull/127), agent 0.9.1) : « Le site a changé (SEARCH_NO_STATE) ». Ni champ ni code d'erreur ajouté (1 à 200 caractères), format du protocole inchangé.
- **Champ facultatif `result.stats`** (PR [repas-front#138](https://github.com/kcharignon/repas-front/pull/138), agent 0.15.0) : `{requests, errors, blocks}`, trois entiers de 0 à 100 000, les trois obligatoires, aucune autre clé, absent d'un `setup-result` ; schéma, validateur (`result.ts`), types, 2 exemples valides (`result-avec-compteurs.json`, `result-compteurs-a-zero.json`) et 8 invalides, `drive-agent/protocol/README.md`. Description : [`../architecture.md`](../architecture.md) § 8.
- **Conséquence de déploiement** : un récepteur qui ne connaît pas `stats` rejette le résultat entier. Le front (validateur du protocole) se déploie **avant** l'application qui embarque cet agent : [`../architecture.md`](../architecture.md) § 12, [`../runbook.md`](../runbook.md) § 7.4.
- Ligne du jalon : [`roadmap.md`](../roadmap.md) § M3c.
