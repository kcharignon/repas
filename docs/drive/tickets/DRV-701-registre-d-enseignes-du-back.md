# DRV-701 — Registre d'enseignes du back, enseigne en paramètre des lectures, contrat des enseignes

- **Dépôt** : back (`src/Drive/`, `docs/api/openapi.json`, `docs/drive/samples/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : —
- **Statut** : fait (PR #NNN, 2026-10-30). Effort recommandé : `high`.
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) §§ 3.2, 4, 6, 8, 10 ; [ADR 0027](../../adr/0027-plusieurs-enseignes-de-drive.md) ; `.claude/skills/repas-dev/SKILL.md` et `.claude/skills/repas-drive-back/SKILL.md` ; [`../consignes-agents.md`](../consignes-agents.md) (« Ne pas durcir Leclerc »).

## Contexte

L'enum `Retailer` (`src/Drive/Domain/Model/Retailer.php`) a un seul cas et porte seule la règle d'adresse du magasin ; trois loaders figent `Retailer::LECLERC` (`DriveSettingsPostgreSQLLoader`, `DrivePlanPostgreSQLLoader`, `DriveEstimatePostgreSQLLoader`) ; le contrat OpenAPI écrit `enum: ['leclerc']` à huit endroits ; l'identifiant de produit est supposé numérique (`Product::RETAILER_PRODUCT_ID_PATTERN`). La base, elle, est déjà à plusieurs enseignes (clés avec `retailer`, audit § 3.1) : **aucune migration**.

## Portée

1. **Registre.** `Retailer` reste la liste des identifiants (`LECLERC`), plus une constante `Retailer::IDS` (liste des valeurs, pour les attributs OpenAPI) gardée par un test contre `Retailer::cases()`. Un profil pur PHP par enseigne (`RetailerProfile`, valeur, sous `Domain/Model/`) porte : `name` (« Leclerc »), `nameDe` (« de Leclerc »), la règle d'adresse du magasin (l'actuel `LECLERC_STORE_URL`), `productIdPattern` (l'actuel `^[0-9]{1,12}$`). `Retailer::profile()`, `isValidStoreUrl()`, `storeIdOf()` et `isValidProductId()` en dérivent ; plus aucun `match` à un bras.
2. **Identifiant de produit.** `Product::RETAILER_PRODUCT_ID_PATTERN` et les validations de `ProductInput`/`ProductPayload` passent par `isValidProductId()` de l'enseigne. Comportement inchangé pour Leclerc.
3. **Enseigne en paramètre des trois lectures.** `GetDriveSettingsQuery`, `GetDrivePlanQuery`, `GetDriveEstimateQuery` gagnent `?Retailer $retailer = null` ; les loaders reçoivent l'enseigne (plus de `private const Retailer RETAILER`). L'enseigne par défaut vient d'**un seul endroit** (`Retailer::default()`, remplacé par la sélection de DRV-703) et vaut `leclerc`.
4. **API additive.** `GET /api/v1/drive/plans/{id}?retailer=` et `GET /api/v1/drive/estimates/{id}?retailer=` (facultatifs). `GET /api/v1/drive/settings` garde `{enabled, retailer, store}` et **ajoute** `retailers: [{retailer, name, configured, store}]` (une entrée par enseigne connue ; la sélection et le drive habituel arrivent avec DRV-703, l'interrupteur par enseigne avec DRV-702).
5. **OpenAPI.** Les huit `enum: ['leclerc']` deviennent `enum: Retailer::IDS` ; les exemples d'adresse `…leclercdrive.fr…` de `DriveStorePayload` et `DriveStoreDTO` deviennent neutres (`https://exemple.invalid/…` n'est pas une adresse valide pour Leclerc : l'exemple d'adresse du magasin vient de la règle de l'enseigne). `make openapi`, relire le diff.
6. **Contrat des enseignes.** `docs/drive/samples/retailers.sample.json` (identifiant, nom, `nameDe`, règle d'adresse en texte, motif d'identifiant) généré par `make drive-samples`, comparé par `DriveContractSamplesTest`. Le front le recopie (DRV-704, 708) ; le format est décrit dans `multi-enseignes.md` § 4.2.
7. **Garde-fou.** Un test d'architecture (comme `QueryConventionTest`) échoue si `Retailer::LECLERC` ou la chaîne `leclercdrive` apparaît dans `src/` ailleurs que dans `Retailer.php` et `RetailerProfile.php`.
8. **Plafond quotidien (D-M5).** Documenter dans `DriveSettings` et dans le skill `repas-drive-back` que `MAX_PRODUCT_WRITES_PER_DAY` est **par utilisateur et par enseigne** ; un test à deux enseignes (données de test, la seconde ajoutée dans le test seulement) le montre.
9. Les builders de test (`tests/Helper/Builder/`) prennent l'enseigne en paramètre (défaut inchangé).

## Hors portée

Interrupteur par enseigne (DRV-702), sélection et drive habituel (DRV-703), tout nom ou règle d'Auchan (DRV-716), modification de `Store` (D-M7), toute migration de schéma.

## Critères d'acceptation

- Étant donné la production d'aujourd'hui (une seule enseigne), quand on rejoue `DriveContractSamplesTest`, alors `plan`, `choices`, `estimate` sont **identiques** aux exemples d'avant, et `settings` ne diffère que par les champs ajoutés.
- Étant donné `GET /drive/plans/{id}?retailer=leclerc`, alors la réponse est celle de la requête sans paramètre ; `?retailer=inconnue` donne `422`.
- Étant donné une enseigne de test ajoutée dans un test (profil fictif), alors une adresse de magasin, un identifiant de produit et un plan se valident selon **ses** règles, sans modifier le code de production.
- Étant donné le test d'architecture, alors un `Retailer::LECLERC` ajouté dans un handler le fait échouer.

## Tests exigés

Handlers en unitaire (`GetDrivePlanQueryHandler` transmet l'enseigne au loader) ; loaders sur base réelle (`tests/Drive/OutputAdapter/`) avec deux enseignes ; contrôleurs avec `MockQueryBus` (clés JSON) ; `RetailerTest` (profil, règle d'adresse, motif de produit) ; `DriveContractSamplesTest` ; test d'architecture ; `make test`, `make quality`, `make openapi`.

## Definition of Done

Celle du skill `repas-dev` (§ 4) et `repas-drive-back` : aucun nouveau scénario `WriteBudget` (aucune écriture ajoutée : prouver qu'`budgets.php` ne bouge pas), `docs/api/openapi.json` régénéré, `docs/drive/architecture.md` §§ 5.1 et 7 mis à jour, roadmap cochée, statut du ticket « fait (PR #…, date) ».

## Pièges connus

- Un attribut OpenAPI n'accepte qu'une expression constante : `Retailer::IDS` (constante de classe) convient, un appel à `array_map` non ; d'où le test qui garde `IDS` contre `cases()`.
- Ne pas renommer l'enum ni les valeurs stockées : `leclerc` est dans la base de production.
- Les anciens clients envoient et lisent `retailer`/`store` : ne rien retirer de la réponse de `GET /drive/settings`.
- `Store::create` valide déjà par la règle de l'enseigne : ne pas y toucher (D-M7).
