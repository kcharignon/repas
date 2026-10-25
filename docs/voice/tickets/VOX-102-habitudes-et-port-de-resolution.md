# VOX-102 — Chargeur des habitudes et port `Shared\SpokenIngredientResolver`

- **Dépôt** : back
- **Jalon** : M1 Back `Repas`
- **Taille** : M
- **Dépend de** : VOX-101
- **Faisable sans téléphone** : oui
- **Statut** : fait (PR #261, 2026-10-25)
- **Lire d'abord** : [`../alexa.md`](../alexa.md) §§ 3, 4 ; [ADR 0004](../../adr/0004-repository-vs-loader.md) ; `IngredientPostgreSQLLoader::search()` (même forme : une requête, scoring en PHP) ; `IngredientVisibility::visibleBy`.

## Contexte

Le ranker (VOX-101) a besoin, pour un utilisateur, de ses ingrédients et de leur usage sur douze mois. Le contexte `Voice` ne connaît pas `Repas` : il appelle un port de `Shared`.

## Portée

1. `Shared\Application\Interface\SpokenIngredientResolver::resolve(string $userId, string $phrase): ?ResolvedIngredient` et DTO `Shared\Application\Dto\ResolvedIngredient` (`slug`, `name`, `departmentName`, `confidence` en chaîne).
2. Port `Repas\Application\Loader\SpokenIngredientCandidates::forUser(string $userId): list<SpokenCandidate>` et son implémentation `Infrastructure\Loader\SpokenIngredientCandidatesPostgreSQLLoader` : ingrédients visibles (`visibleBy`) + `uses12m` = lignes de listes (listes de l'utilisateur, 12 mois) + lignes de recettes (recettes de l'utilisateur), **trois requêtes au plus quel que soit le nombre d'ingrédients**.
3. Adaptateur `Repas\Infrastructure\Voice\SpokenIngredientResolverAdapter` (implémente le port de `Shared`) : charge les candidats, appelle le ranker, mappe en DTO. Mémoire de phrase : `null` ici (VOX-301 l'ajoute).
4. Câblage du port dans `config/services.yaml`.

## Critères d'acceptation

- Sur une base réelle (`DatabaseTestCase`) : l'utilisateur A ne reçoit **jamais** un ingrédient de B ; `uses12m` compte lignes de listes et de recettes, ignore ce qui a plus de douze mois ; un ingrédient jamais utilisé a `uses12m = 0`.
- Nombre de requêtes constant (test avec 5 puis 50 ingrédients).
- `resolve` d'un compte sans ingrédient → `null` (depuis VOX-303 le port n'a plus que `resolveOrSuggest`, qui rend le choix ou les propositions en une lecture).

## Tests exigés

Loader sur base réelle (`tests/Repas/OutputAdapter/`) ; adaptateur en unitaire avec un double de port de candidats. Aucun handler concerné.

## Pièges connus

- Pas de fixture globale : créer les données dans le test (`DataPersister`).
- Deptrac : `Shared` ne dépend de rien ; le DTO est un simple objet.
