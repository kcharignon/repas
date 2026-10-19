# DRV-706 — Hôtes autorisés, origines du pont et mémoires par enseigne (cœur Kotlin)

- **Dépôt** : front (`android/core/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-704 (contrat des enseignes et protocole par enseigne)
- **Statut** : à faire. Effort recommandé : `xhigh` (sécurité : navigation, origines du pont, livraison du plan).
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) §§ 3.5, 4, 8 ; `android/ARCHITECTURE.md` (règle d'or : le cœur n'importe rien d'Android) ; `.claude/skills/repas-android-dev/SKILL.md` ; ADR 0022 (invariants) ; revue de sécurité du front (`docs/revue-securite-drive.md`) ; AUTH-015 (tronc commun).

## Contexte

Le cœur Kotlin contient la sécurité de navigation de l'application : `NavigationPolicy` (`START_URL`, `ALLOWED_DOMAIN = "leclercdrive.fr"`, formes `/magasin-N-N`, connexion, site mobile), `BridgeMessages` (`ORIGIN_RULES = setOf("https://*.leclercdrive.fr")`, `isAllowedOrigin`), `DrivePlan` (`plan.opt("retailer") != "leclerc"`, `STORE_HOST`, `startUrl`), `DriveEntry`, `StorePathMemory`, `DebugPlan`. Ce sont les seules règles qui décident quelle page garde l'agent et reçoit un plan ; **un joker trop large est un défaut de sécurité**.

## Portée

1. **`RetailerSite`** (Kotlin pur, dans le cœur) : `id`, `name`, `nameDe`, `startUrl`, domaine autorisé, règle de page de magasin, règle de page de connexion, règle de site mobile, `siteMode`. **`RetailerSites`** : la seule liste (aujourd'hui `leclerc`), injectable dans les tests.
2. **`NavigationPolicy`** : `decide(url)` garde sa signature et son résultat pour Leclerc ; une variante `decide(site, url)` n'autorise que le domaine **de cette enseigne**. Les formes d'adresse (`isStorePage`, `isMobileSite`, `isLoginPage`, `isHome`) deviennent des méthodes de `RetailerSite`.
3. **`BridgeMessages`** : `ORIGIN_RULES` se déduit de l'ensemble des enseignes **activées** (jamais `*`) ; `isAllowedOrigin(site, origin)` ; **une origine d'une enseigne ne reçoit jamais le plan d'une autre**.
4. **`DrivePlan`** : `retailer` ∈ registre, hôte du magasin ∈ domaine **de cette enseigne**, `startUrl` = accueil de **cette** enseigne ; `INVALID_PLAN` sinon (comportement d'aujourd'hui pour toute autre valeur que `leclerc`).
5. **Mémoires** (`StorePathMemory`, `FinalizeOutcome`) : une entrée **par enseigne** (clé = identifiant), migration de la valeur existante vers la clé `leclerc` sans perte ; `DriveEntry` choisit l'enseigne d'après l'hôte du lien.
6. **Annonce** : une fonction pure `announcedRetailers(): List<String>` pour `app.info` (DRV-707 l'appelle).
7. **Tests d'architecture** : `CoreArchitectureTest` et `core.spec.ts` inchangés ; ajouter un test qui échoue si « leclerc » apparaît dans le cœur hors de l'entrée du registre et des tests.

## Hors portée

L'écran du drive, l'injection, le profil, les chaînes (DRV-707) ; un domaine d'Auchan (DRV-716) ; tout changement de comportement pour Leclerc.

## Critères d'acceptation

- Étant donné les tables existantes de `NavigationPolicyTest`, `BridgeMessagesTest`, `DrivePlanTest`, `StorePathMemoryTest`, `DriveEntryTest`, alors **chaque entrée donne la même décision qu'avant** sans modifier une attente.
- Étant donné **deux enseignes fictives** dans les tests (`*.exemple-a.test` et `*.exemple-b.test`), alors : une page de A n'est pas autorisée dans l'écran de B ; un plan de A n'est pas livré à une origine de B ; un plan de A dont l'hôte est celui de B est refusé ; `exemple-a.test.evil.test`, `exemple-aXtest` et les formes à barre oblique inverse, `@`, port, point final, non-ASCII sont refusés comme aujourd'hui pour Leclerc.
- Étant donné une mémoire de chemin déjà enregistrée par l'ancienne version, alors elle est relue comme celle de `leclerc`.

## Tests exigés

`make android-test` (cœur), tests en table des deux enseignes fictives (mêmes familles d'entrées hostiles que `NavigationPolicyTest`), `android/__tests__/core.spec.ts`, `make check`. Pas d'émulateur nécessaire (l'e2e est rejoué par DRV-707).

## Definition of Done

Celle du dépôt front et du skill `repas-android-dev` ; `android/ARCHITECTURE.md` (tableau « Où est quoi ») à jour ; la revue de sécurité du front relit la liste d'origines ; aucune étiquette ni publication.

## Pièges connus

- Un refus envoie l'adresse au navigateur du téléphone (sans danger) ; une acceptation à tort ne l'est pas : en cas de doute, refuser.
- `isAllowedOrigin` compare l'origine **entière** (schéma, hôte, port 443 permis) : ne pas passer à un `endsWith` plus lâche.
- L'ensemble des origines du pont est la **réunion des enseignes activées pour l'écran ouvert**, pas pour toute l'application : l'écran d'une enseigne n'ouvre que son domaine (DRV-707).
- Le cœur ne doit rien importer d'Android : pas de `BuildConfig`, pas de `Uri`.
