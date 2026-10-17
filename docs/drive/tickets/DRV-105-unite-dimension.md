# DRV-105 — Unités : `dimension` et `baseFactor`

- **Dépôt** : back
- **Jalon** : M1 Back `Repas`
- **Taille** : M
- **Dépend de** : —
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) § 6 ; ADR 0017 règle 6 (migrations compatibles) ; skill `repas-dev` ; `src/Repas/Domain/Model/Unit.php`.

## Contexte

`Unit` n'a que `slug`, `name`, `symbol` : le type (poids, volume, pièce, conditionnement) est implicite dans le graphe des conversions. Le calcul du nombre de produits à acheter (`PackCalculator`, DRV-205) a besoin de la dimension de l'unité d'achat d'une ligne et de son facteur vers l'unité de base (g, ml, pièce).

## Portée

1. Enum `Repas\Shared\Domain\Model\Dimension` : `WEIGHT`, `VOLUME`, `COUNT`, `PACKAGE` (dans `Shared` car `Repas` et `Drive` l'utilisent ; `Drive` ne peut pas importer `Repas`).
2. Modèle `Unit` : `?Dimension $dimension`, `?float $baseFactor` ; `create()`/`update()` les acceptent ; règle : `dimension` et `baseFactor` vont ensemble (les deux nuls ou les deux renseignés) et `baseFactor > 0` ; `UnitException` (factory) sinon.
3. Entité Doctrine `Unit` : colonnes `dimension` (texte nullable, `enumType`) et `base_factor` (numeric nullable) ; migration **compatible avec la version précédente du code** (colonnes nullables) qui amorce :
   `gramme` WEIGHT 1 · `kilo` WEIGHT 1000 · `millilitre` VOLUME 1 · `centilitre` VOLUME 10 · `litre` VOLUME 1000 · `unite` COUNT 1 · `paquet`, `boite`, `sachet`, `bouteille`, `conserve`, `brique`, `pot`, `flacon`, `plaquette`, `tablette`, `buche` PACKAGE 1 ; les autres unités (`cuillere-a-soupe`, `cuillere-a-cafe`, `tasse-a-cafe`, `verre`, `bol`, `tranche`, `cube`, `gousse`) restent `NULL`. Vérifier les slugs exacts dans `src/Repas/Infrastructure/DataFixture/Data/unit.json`.
4. Fixtures : `unit.json` porte les mêmes valeurs ; `UnitBuilder` accepte les deux champs.
5. API existante des unités (`UpdateUnit`, `CreateUnit`, `ListUnits`) : les corps de création et de modification acceptent `dimension` (nullable, valeurs de l'énumération) et `baseFactor` (nullable) avec validation 422 ; `UnitDTO` expose les deux champs ; `make openapi`.
6. Le port `Shared` de DRV-202 lira ces colonnes.

## Hors portée

L'écran d'administration (DRV-409). Les conversions par ingrédient.

## Critères d'acceptation

- Après la migration, `gramme` a `WEIGHT`/`1`, `kilo` `WEIGHT`/`1000`, `paquet` `PACKAGE`/`1`, `cuillere-a-soupe` `NULL`/`NULL`.
- Créer ou modifier une unité avec une dimension sans facteur (ou l'inverse), ou un facteur ≤ 0, répond `422`.
- Une unité existante modifiée sans fournir ces champs garde ses valeurs (ou les efface seulement si le corps les fournit à `null` — choisir et documenter dans le contrat).
- La liste des unités (`GET /api/v1/units`) renvoie les deux champs.
- L'ancien code continue de fonctionner sur le schéma migré (colonnes nullables ignorées).

## Tests exigés

Domaine (règles de cohérence) ; handlers en unitaire (`UnitInMemoryRepository`) ; dépôt et loader sur base réelle ; contrôleurs avec `MockCommandBus`/`MockQueryBus` ; budgets `WriteBudget` des use cases d'unité concernés ; `make openapi-check`.

## Definition of Done

Celle du skill `repas-dev` § 4.

## Pièges connus

- Les ingrédients ont une unité d'achat par défaut (`defaultPurchaseUnit`) : la plupart sont `gramme`, `unite` ou `paquet`. Ne pas toucher aux conversions existantes.
- Le facteur de base d'une unité de conditionnement (`PACKAGE`) vaut 1 : sa valeur n'est pas utilisée par le calcul, le nombre de produits est la quantité arrondie.
