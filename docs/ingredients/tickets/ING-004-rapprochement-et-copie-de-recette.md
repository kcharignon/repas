# ING-004 — Rapprochement des ingrédients à la copie d'une recette

- **Dépôt** : back
- **Jalon** : I3 Copie
- **Taille** : L
- **Effort recommandé pour l'agent** : **high** (fonctionnalité ; le domaine est pur et testé par une vérité terrain fournie)
- **Dépend de** : ING-001 (alias, adoption) ; la partie `IngredientNameMatcher` peut démarrer **dès maintenant** (aucune dépendance)
- **Statut** : fait (PR #236)
- **Lire d'abord** : **[`rapprochement.md`](../rapprochement.md) en entier** (algorithme, seuils, mesures) ; [`analyse.md`](../analyse.md) § 6 (comportement actuel et ses défauts) ; [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décision f ; ADR 0019 (Jaro-Winkler) ; skill `/repas-dev` ; `experiments/ground-truth.json`.

## Contexte

Copier une recette copie aujourd'hui chaque ingrédient personnel de l'auteur chez le copieur sans regarder ce qu'il possède, et peut **écraser** un ingrédient existant du copieur ([`analyse.md`](../analyse.md) § 6). Il faut se brancher sur l'ingrédient du copieur quand le nom correspond **avec certitude**, dans le même rayon, sinon créer la copie ; et se brancher sur l'alias quand l'ingrédient source est l'adoption d'un commun.

## Portée

1. **`Repas\Domain\Service\IngredientNameMatcher`** (pur, sans base) : normalisation `N2`, garde-fou `G6`, `Shared\Domain\Tool\JaroWinkler`, niveaux exact / faute de frappe, marge d'ambiguïté, départage déterministe ([`rapprochement.md`](../rapprochement.md) § 3). Constantes nommées : `AUTO_THRESHOLD = 0.97`, `SUGGESTION_THRESHOLD = 0.90`, `AMBIGUITY_MARGIN = 0.02`, liste des mots vides. Ne **pas** utiliser `SearchRelevance`. API : `match(string $name, string $departmentSlug, array $candidates): ?IngredientMatch` pour le niveau automatique ; `suggest(…)` / `similar(string $a, string $b): MatchLevel` pour ING-006. Valeur `IngredientCandidate` (slug, nom normalisé, slug du rayon, unité d'achat, unités compatibles, `adoptedFrom`).
2. **Port de lecture légère** `Domain/Interface/IngredientMatchCandidates::visibleTo(string $userId): list<IngredientCandidate>` (une requête SQL, mêmes règles de visibilité qu'ING-002, **sans** hydrater de modèles) -> implémentation `IngredientMatchCandidatesPostgreSQLRepository` (le port est lu par un `CommandHandler` : ce n'est donc pas un `…Loader`, voir l'en-tête de `IngredientDepartmentResolver`).
3. **`CopyRecipeHandler` réécrit** ([`rapprochement.md`](../rapprochement.md) § 3 à 5) : pour chaque ligne de la recette d'origine, dans cet ordre : (0) provenance (alias du copieur de l'ingrédient source ou de son `adopted_from` ; adoption à la volée si le copieur est adoptant ; sinon le commun lui-même) ; (1-4) `IngredientNameMatcher::match` sur les candidats du copieur du **même rayon** ; (5) contrôle `ConversionService::assertConvertibleToPurchaseUnit` de l'unité de la ligne sur l'ingrédient retenu, sinon pas de lien ; sinon **copie** : `Ingredient::copyFromOriginal` avec `IngredientSlugPolicy` (jamais d'écrasement), conversions propres copiées, `adoptedFrom` conservé. La recette copiée référence les ingrédients retenus ; **le même ingrédient source n'est traité qu'une fois** même s'il apparaît sur plusieurs lignes.
4. **Statistiques** : `RecipesOrIngredientsCreatedEvent` ne porte que les slugs réellement **créés** par la copie.
5. **Correction** de `Recipe::copyFromOriginal` / `RecipeRow::copyFromOriginal` : ils ne recréent plus d'ingrédient (le handler leur fournit les ingrédients résolus) ; la copie de sa propre recette ne duplique plus rien.
6. **Jeu de vérité terrain** : copier `docs/ingredients/experiments/ground-truth.json` dans `tests/Repas/Domain/Service/IngredientNameMatching/ground-truth.json` (le déplacer ; le script d'expérience garde sa copie documentaire) et l'utiliser dans `IngredientNameMatcherTest` avec les constantes de production.

## Hors portée

L'écran de doublons et le chargeur de suggestions (ING-006, qui réutilise `similar`) ; la fusion (ING-005) ; l'adoption pour tous (ING-008). Aucune modification du front : la copie reste un `POST` sans corps.

## Critères d'acceptation

- **Vérité terrain.** Rejouée avec les constantes de production, **zéro faux lien** : aucune ligne `expected: null` n'est branchée à aucun candidat du même rayon ; **100 %** des catégories `casse`, `ponctuation`, `accent`, `pluriel` sont branchées sur le bon candidat ; au moins **50 %** de `faute`.
- **Provenance.** Étant donné un auteur dont la recette contient `tomate<A>` (adoption du commun `tomate`) et un copieur avec l'alias `tomate -> tomate<C>`, alors la recette copiée référence `tomate<C>` et **rien n'est créé**. Copieur adoptant sans alias : adoption à la volée. Copieur non adoptant : la ligne référence `tomate`.
- **Nom exact, même rayon, unité compatible** : lien ; **autre rayon** : copie ; **unité incompatible** : copie.
- **Pas d'écrasement.** Étant donné que le copieur possède `creme<C>` (rayon fromage) et que l'auteur a `creme` (rayon sauce), alors une copie `creme-2<C>` est créée et `creme<C>` est **intact** (rayon, unités, conversions).
- **Copier sa propre recette** : aucune création, aucune conversion dupliquée, mêmes ingrédients.
- **Ambiguïté** (deux candidats à moins de 0,02) : pas de lien. **Deux candidats exacts** : le premier par slug.
- **Statistiques** : `RecipesOrIngredientsCreatedEvent` ne contient que les créations ; un lien ou une adoption ne change pas `user.statistics.ingredients`.
- **Rien de plus à l'écran** : la réponse reste `201` + `Location`.
- `copier-recette` : budget SQL **annoncé** dans la PR (candidats et alias lus chacun en une requête ; ne dépend pas du nombre de lignes de la recette).

## Tests exigés

`IngredientNameMatcherTest` (table + vérité terrain, [`rapprochement.md`](../rapprochement.md) § 9) ; `CopyRecipeHandlerTest` en mémoire (tous les critères) ; `IngredientMatchCandidatesRepositoryTest` sur base réelle (rayon, communs adoptés masqués, ingrédients d'un autre jamais candidats) ; `CopyRecipeApiControllerTest` ; parcours avec les vrais bus (copier, planifier, liste, drive : le plan de la copie lit les produits mémorisés du copieur) ; `WriteBudget`. `make test quality`.

## Definition of Done

`repas-dev` § 4 ; PR « À relire » : valeurs des constantes, écarts à la maquette de décision, tout cas de la vérité terrain qui échoue (aucun attendu).

## Pièges

- **Un faux lien est invisible.** En cas de doute dans le code, ne pas brancher. Toute évolution des constantes se justifie par une relecture de `experiments/resultats.md`.
- Les candidats d'un **copieur adoptant** excluent les communs adoptés ; ceux d'un copieur non adoptant incluent les communs (il les voit).
- Ne pas confondre la normalisation de **comparaison** (`N2`, sans effet sur ce qui est stocké) avec `sluggedName` (colonne, `slugify` seul) : on ne réécrit jamais le nom d'un ingrédient.
- `IngredientPostgreSQLRepository::save` écrase par slug : toute création passe par `exists()` et `IngredientSlugPolicy`.
- Garder `JaroWinkler` inchangé (`JaroWinklerTest` de référence) : seul le matcher est nouveau.

## Réalisation (PR back #236)

Livré comme décrit ci-dessus, avec les écarts et ajouts suivants, tous décidés pour tenir l'intention du ticket. **Aucune migration de schéma, aucun changement de contrat** (seul le texte de description de la route `POST /recipes/{id}/copy` change dans `openapi.json`) : rien à craindre pour le retour arrière du code.

**Contrats** :

- `Repas\Repas\Domain\Service\IngredientNameMatcher` (pur) : constantes `AUTO_THRESHOLD = 0.97`, `SUGGESTION_THRESHOLD = 0.90`, `AMBIGUITY_MARGIN = 0.02`, `STOP_WORDS` ; `tokens(string $name): list<string>` (statique, la normalisation `N2`) ; `match(string $name, string $departmentSlug, iterable<IngredientCandidate> $candidates): ?IngredientMatch` ; `similar(string $first, string $second): MatchLevel`. Le port exact de `evaluate.php` (même garde-fou `G6`, même appariement glouton des mots par Jaro-Winkler, même départage) : la vérité terrain donne les mêmes chiffres que le rapport.
- Valeurs de `Domain\Model` : `IngredientCandidate(slug, name, departmentSlug, purchaseUnitSlug, compatibleUnitSlugs = [], creatorId = null, adoptedFrom = null)` (calcule `tokens` ; `normalizedName()`, `isCommon()`), `IngredientMatch(candidate, level, score)`, enum `MatchLevel` (`EXACT`, `TYPO`, `SUGGESTION`, `NONE`).
- Port `Domain\Interface\IngredientMatchCandidates::visibleTo(string $userId): list<IngredientCandidate>` (triés par slug) -> `IngredientMatchCandidatesPostgreSQLRepository` : **une** requête DQL, la règle `IngredientVisibility::visibleBy`, aucun modèle hydraté. Il ne s'appelle pas `…Loader` (lu par un `CommandHandler`).
- `Ingredient::copyFromOriginal(Ingredient $ingredient, string $authorId, string $slug)` : **le slug est choisi par l'appelant** (`IngredientSlugPolicy::forAdoption`, sans collision avec un ingrédient existant) ; la copie **retient l'origine** (`adopted_from` de l'original, ou le commun lui-même quand l'original est un commun) : une copie qui retient une origine n'est pas une création (`isAdoption()`). `RecipeRow::copyFromOriginal` et `Recipe::copyFromOriginal(id, original, authorId, array<slug d'origine, Ingredient>, callable $newRowId)` **ne créent plus aucun ingrédient** et refusent (`IngredientException::notFound`) celui d'un autre utilisateur ou un ingrédient non retenu.
- `CopyRecipeHandler` : l'arbre de l'ordre de retenue, **par ingrédient d'origine, une fois** : (1) l'ingrédient est déjà au copieur ; (2) la **provenance** (`IngredientSlugResolver::resolveForUser` sur le commun d'origine : alias, adoption à la volée pour un adoptant, sinon le commun lui-même s'il existe encore) ; (3) le **nom** (`IngredientNameMatcher`, candidats lus **une fois et seulement si nécessaire**), un commun retenu par son nom est adopté à la volée si le copieur est adoptant ; (4) sinon une **copie**. Le contrôle d'unité (`ConversionService::assertConvertibleToPurchaseUnit`) s'applique à **tout** ingrédient retenu en 2 ou 3 qui n'est pas l'ingrédient d'origine lui-même, pour chaque unité de ses lignes.
- Garde-fou `IngredientSlugWritesAreResolvedTest` : nouvelle liste `COPIES` (la commande de copie, qui ne porte aucun slug) et un test qui exige du handler le résolveur, les candidats, le rapprochement de noms et la politique de slug.

**Écarts avec le ticket** :

1. **`similar` seul, pas de `suggest`** : ING-006 appelle `similar` paire par paire ; un `suggest(...)` écrit sans son consommateur aurait deviné sa forme.
2. **La vérité terrain est copiée, pas déplacée** (`tests/Repas/Domain/Service/IngredientNameMatching/ground-truth.json`) : `evaluate.php` la lit à côté de lui et doit rester rejouable.
3. **Un nom sans lettre ni chiffre ne se rapproche de rien** (deux noms qui se normalisent en rien étaient « identiques ») : garde ajoutée au matcher.
4. **Le contrôle d'unité vaut aussi pour la provenance** (alias d'un copieur qui a modifié ses unités) ; **s'il échoue, la copie est faite sans comparer les noms** (c'est le même ingrédient) et elle garde le commun d'origine. Une adoption à la volée déjà faite pour ce lien reste (inoffensive : l'utilisateur l'aurait adoptée à sa première utilisation).
5. **Aucun alias n'est écrit pour une copie** : un alias d'adoption ferait du copieur un « adoptant » (ING-002).
6. **Statistiques** : seuls les ingrédients **créés** sont annoncés, et une copie qui retient une origine (donc `isAdoption()`) n'est pas comptée, comme la commande de recalcul `UpdateUserStatisticsCommand`.
7. **Le copieur est son propre auteur** : ses ingrédients sont gardés tels quels avant toute comparaison (même quand un alias désigne autre chose).
8. **Jeu de test de la base** : `IngredientVisibilityTest` vérifie aussi le port de candidats contre la règle de visibilité (mêmes ensembles que les autres lectures, avec et sans alias).

**Preuve « jamais d'écrasement »** : `RecipeCopyApiFlowTest::testAnIngredientOfTheCopierIsNeverOverwrittenByTheOneOfTheAuthor` (vrais bus, vraie base) échoue sur le code d'avant (`src/` de `origin/main`) et passe : l'ingrédient du copieur (nom, rayon, unités, image, unités compatibles, conversion) est identique avant et après, la copie est `praline-2<U>` avec les unités, le rayon, l'image et la conversion de l'auteur.

**Vérité terrain, constantes de production** (`IngredientNameMatcherTest`) : 66 cas « aucun » (qualificatifs, frères trompeurs) et les 256 communs rejoués contre leur rayon : **0 faux lien** ; casse 263/263, ponctuation 258/258, accent 69/69, pluriel 284/284 ; faute de frappe 256/481 (53 %, aucun lien vers un autre produit) ; 1 130 bons liens sur 1 355 attendus (83,4 %), 225 doublons créés : les chiffres de `rapprochement.md` § 7.

**Budgets SQL** (`tests/Repas/WriteBudget`) : `copier-recette` (recette de communs, copieur sans alias) 16 -> **17** (la lecture des alias, comme les onze écritures d'ING-002). Nouveaux scénarios : `copier-recette-ingredient-personnel` **19** (un ingrédient personnel copié avec une conversion propre), `copier-recette-ingredient-rapproche` **15** (lié par le nom : aucune écriture d'ingrédient), `copier-recette-adoptant` **22** (adoption à la volée d'un commun d'origine). Ce qui ne dépend pas du nombre de lignes : une lecture des alias, **une** lecture des candidats (seulement si un ingrédient personnel reste à comparer), le chargement des ingrédients retenus par lot. Ce qui dépend du nombre d'**ingrédients** (pas de lignes) : un ingrédient copié coûte une requête de plus qu'avant (`exists()` avant `save()`, pour ne jamais écraser), un ingrédient lié dont l'unité de ligne diffère de son unité d'achat coûte une lecture de ses conversions (le contrôle d'unité).

**Compatibilité avec le code précédent (ADR 0017 règle 6)** : aucun changement de schéma ni de données ; le code d'avant ignore tout ce qui est ajouté. Ce que cette version écrit (des ingrédients personnels, éventuellement avec `adopted_from`) est déjà lu par l'ancien code.

**Points d'attention pour ING-003 (migration)** :

- **Ce ticket doit être en production avant la migration** : sans lui, copier la recette d'un utilisateur migré par un autre utilisateur migré écrase `tomate<U>` et duplique ses conversions.
- Après la migration, presque toutes les lignes d'une recette sont des adoptions : elles se rapprochent par **provenance**, sans comparer de noms (l'alias doit être **valide** : copie existante, `creator = U`, sinon l'adoption à la volée recommence).
- Copier une recette n'écrit un alias que pour un copieur **déjà adoptant** (adoption à la volée) ; un copieur non adoptant le reste : il le devient par la migration (ING-003) ou l'adoption pour tous (ING-008), jamais par une copie.
- Deux copies d'une même recette par un adoptant ne créent rien de plus (provenance, puis nom exact).

**Points d'attention pour ING-005 / ING-006** :

- Le rapprochement ne fusionne **jamais** deux ingrédients existants du copieur ; deux ingrédients de même nom et de même rayon chez un copieur (cas courant après une collision de slug de la migration, `-2`) donnent un lien déterministe vers le **premier par slug** : l'écran « Doublons possibles » les proposera (`SAME_NAME`).
- ING-006 réutilise `IngredientNameMatcher::similar` (niveaux `EXACT`, `TYPO`, `SUGGESTION`, `NONE`, avec le même garde-fou) et `IngredientCandidate::isCommon()` / `adoptedFrom` pour la règle « jamais deux adoptions de communs différents ». Le port `IngredientMatchCandidates::visibleTo` rend aussi les communs non adoptés : pour « seulement les ingrédients **de l'utilisateur** » filtrer sur `creatorId`.
- ING-005 : une fusion écrit un alias `merge` ; la copie de recette le résout (`resolveForUser`) comme tout alias valide.
