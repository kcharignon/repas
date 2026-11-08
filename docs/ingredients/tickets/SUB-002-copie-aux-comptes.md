# SUB-002 — Le découpage voyage avec les ingrédients : amorçage, copie de recette, rattrapage (back)

- **Dépôt** : **back**
- **Jalon** : I8 Sous-ingrédients
- **Taille** : M
- **Effort recommandé pour l'agent** : **xhigh** (copie en masse, amorçage à l'activation dans la transaction d'un compte, rattrapage des comptes existants)
- **Dépend de** : SUB-001 fusionné
- **Statut** : à faire
- **Lire d'abord** : [ADR 0038](../../adr/0038-sous-ingredients.md) décisions 5, 18 à 24 ; [`sans-commun.md`](../sans-commun.md) §§ 2, 6, 13 ; [`migration.md`](../migration.md) ; `SeedUserIngredientsHandler`, `IngredientAdoptionBulkPostgreSQLRepository`, `CopyRecipeHandler` ; `CLAUDE.md` (puce « Amorçage »).

## But

Un compte qui reçoit une copie d'« Œuf » et de « Jaune d'œuf » retrouve le découpage du catalogue, par provenance, sans jamais recopier un slug étranger. Les comptes déjà amorcés sont rattrapés par la commande existante.

## À livrer

1. **Port et instruction** `IngredientPartLinkRepository::linkCopies(string $userId): int` (domaine ; implémentation PostgreSQL, **une instruction SQL**, rejouable). Règle de l'ADR 0038 décision 21 : pour chaque ingrédient `c` du compte **sans parent** dont l'origine `o` (`o.slug = c.adopted_from`) a un parent `po`, si le compte a `pc` avec `pc.adopted_from = COALESCE(po.adopted_from, po.slug)`, `pc` n'étant pas une partie et ayant moins de `Ingredient::MAX_PARTS` parties (compte tenu des liens posés dans la même instruction), alors `c.parent_slug = pc.slug`, `c.parent_ratio = o.parent_ratio`. Jamais d'écrasement d'un lien existant, jamais de chaîne.
2. **Amorçage** : `SeedUserIngredientsHandler` appelle `linkCopies` après les copies et leurs conversions, **avant** les vérifications (un défaut annule l'activation, D6) ; les copies réutilisées (`existingCopySlug`) sont reliées aussi. `AdoptionVerifier` : une copie reliée n'est pas une non-conformité (`nonConformingCopies` ne compare pas `parent_*`). Budget `amorcer-compte` : **+1 requête**, constant. `SeedResult` gagne `partsLinked` (le rapport de la commande le dit).
3. **Copie de recette** : `CopyRecipeHandler` (et le service partagé de MAR-003) appelle `linkCopies` après la copie de ses ingrédients, seulement si au moins un ingrédient a été **copié** (zéro requête de plus sinon). Aucun parent n'est copié pour compléter. `CopyRecipeHandlerTest` et son budget : inchangés pour une recette sans partie.
4. **Rattrapage** : `repas:ingredients:seed --complete` copie les parties manquantes **puis** relie ; `--check` ajoute une ligne « copies à relier : N » (la requête de santé : copies sans parent dont l'origine en a un et dont le compte a la copie du parent) ; le rapport à blanc dit le nombre de liens que `--apply` poserait ; aucune nouvelle commande, `amorcer-ingredients.sh` inchangé. Jamais lancé par un agent sur la production.
5. **Import Marmiton** (ADR 0037) : l'import appelle déjà le service de copie de MAR-003 ; vérifier (test) que la copie d'un utilisateur d'une neutre qui contient « Jaune d'œuf » retrouve **sa** partie par provenance et que l'ingrédient d'une neutre n'a jamais de parent (ses copies viennent du catalogue sans lien, décision 5).
6. **Doc** : `docs/ingredients/sans-commun.md` (une phrase à la fin du § 13), `docs/ingredients/roadmap.md`, ADR 0038 (amendements éventuels), skill `repas-ops` `references/` (ligne dans la fiche d'amorçage : le rapport dit « liens posés »).

## Tests

- Vraie base (`IngredientPartLinkTest`) : lien posé quand les deux copies existent ; aucun lien quand le parent manque ; **aucun écrasement** d'un lien de l'utilisateur ; copie de copie (origine = la racine) ; plafond de 10 ; jamais de chaîne ; rejouable (deuxième appel : 0) ; ingrédients d'un autre compte intacts ; ratio repris de l'origine.
- `SeedProductionLikeCatalogTest` étendu : catalogue avec « Œuf », « Jaune d'œuf », « Blanc d'œuf » de base → un compte neuf a les trois copies reliées ; un compte déjà amorcé (sans les parties) est rattrapé par `--complete` ; un compte qui a déjà un « Jaune d'œuf » à lui reçoit la copie et un doublon proposé, jamais un lien écrasé.
- `CopiedIngredientsNeverCarryAForeignParentTest` : `Ingredient::adopt`, `copyFromOriginal`, `copyIngredients` en masse, `copyCatalogIngredients` ne copient jamais `parent_slug`.
- `CopiedIngredientsKeepTheirConversionsTest` inchangé (les conversions propres des parties suivent).
- Handler (unitaire) : un échec de la liaison annule l'amorçage.
- Budgets : `amorcer-compte` +1 (annoncé dans la PR), `copier-recette*` inchangés.

## Critères d'acceptation

- [ ] `make test`, `make quality` verts ; budgets annoncés ; aucune baseline de plus.
- [ ] Un compte sans partie dans le catalogue : amorçage et copie sans écart, à la requête de liaison près.
- [ ] PR vers `main` (**À relire** : la règle de liaison par `adopted_from`, l'absence d'écrasement).
