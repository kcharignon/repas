# 0004 — Repository (agrégats, écriture) vs Loader (projections, lecture)

- **Statut** : Accepted
- **Date** : 2026-10-12
- **Liens** : [0003](0003-cqrs-lecture-ecriture.md), [0005](0005-conventions-dto-lecture.md), `presentation-archi/presentation.md` §04

## Contexte

Les `*PostgreSQLRepository` convertissent une entité Doctrine en modèle de domaine via `convertEntityToModel()`. Comme les entités Doctrine ne portent aucune relation (uniquement des ids et slugs scalaires), cette conversion rappelle d'autres repositories : `ShoppingListPostgreSQLRepository` charge l'owner, les meals (qui chargent chacun une `Recipe`), les ingrédients et les lignes, puis chaque ligne recharge son ingrédient et son unité. Un cache de modèles (`ModelCache`, retiré depuis) limitait les doublons dans une requête mais n'évitait ni l'over-fetching ni le N+1 ; l'identity map de Doctrine et le préchargement par lot ([0013](0013-agregats-references-par-identifiant.md)) le remplacent.

Un seul « loader » existe : `Infrastructure/Loader/RecipeLoader`. Il étend `PostgreSQLRepository`, renvoie des `Recipe` du domaine (rows vides) et n'est derrière aucune interface.

## Décision

1. **Repository** : persistance d'agrégats. Il charge pour modifier, sauve, supprime. Port dans `Domain/Interface/{X}Repository`, implémentation `Infrastructure/Repository/{X}PostgreSQLRepository`. Retourne des modèles du domaine.
2. **Loader** : lecture de projections pour afficher. Port dans `Application/Loader/{X}Loader` (interface), implémentation `Infrastructure/Loader/{X}PostgreSQLLoader`. Retourne des DTO ([0005](0005-conventions-dto-lecture.md)), jamais un modèle du domaine.
3. Un Loader ne passe pas par `Entity → Model` : il lit les entités Doctrine (ou des colonnes scalaires) avec DQL/QueryBuilder et construit le DTO directement. Les jointures se font à la main sur les ids (`leftJoin(Entity::class, 'a', Join::WITH, 'x.id = a.xId')`), puisque les entités n'ont pas de relations. Pour une projection lourde (ex. liste de courses), SQL via DBAL est autorisé.
4. Un Loader par besoin de lecture cohérent, pas un « GodLoader ». Une classe d'implémentation peut servir plusieurs interfaces d'un même agrégat.
5. Nommage : méthode du port = verbe métier (`list()`, `getById()`, `getActiveSummary()`). Paramètres = types simples, value objects ou contextes de pagination, jamais l'objet `Query`.
6. Un Loader lève une exception de domaine `…NotFound` (404) quand la ressource n'existe pas, jamais `RuntimeException`.
7. `RecipeLoader` (legacy) est remplacé à la phase 5 de la roadmap par une interface `Application\Loader\Recipe…Loader` et une implémentation `RecipePostgreSQLLoader` retournant des DTO. D'ici là il reste en place sans être étendu.
8. Si on charge une entité pour la modifier → Repository. Si on affiche → Loader.

## Conséquences

- Positives : une requête (ou quelques-unes bornées) par lecture, pas d'hydratation de graphe, chaque lecture est optimisable sans toucher au domaine.
- Coûts : une requête de lecture peut dupliquer des conditions présentes dans un Repository ; les tests de Loader nécessitent la base.
- Garde-fou : Deptrac ([0011](0011-garde-fous-deptrac-phpstan.md)) ; tests `OutputAdapter` de chaque Loader avec la base réelle ([0010](0010-strategie-de-tests.md)).

## Alternatives écartées

- Ajouter des méthodes `findXxxForDisplay()` aux Repository : mélange écriture et lecture, vide l'intérêt du découpage.
- Introduire des relations Doctrine pour permettre les jointures ORM : refonte du mapping hors périmètre ; les jointures manuelles suffisent pour les projections.
