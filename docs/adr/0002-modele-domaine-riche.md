# 0002 — Modèle de domaine riche : `create()` vs `load()`, injection de Clock et UuidGenerator

- **Statut** : Accepted (formalise l'existant)
- **Date** : 2026-10-12
- **Liens** : `CLAUDE.md` (« Domain Model »), `presentation-archi/presentation.md` §02, §05, §06, §10

## Contexte

Les modèles de `src/*/Domain/Model/` sont des classes `final` (sauf `User`) au constructeur non public, avec `create()` et `load(array)`. Ils implémentent `ModelInterface` + `ModelTrait`. Les listes passent par `Shared\Domain\Tool\Tab`. Les repositories reconstruisent les modèles depuis des entités Doctrine séparées (`Infrastructure\Entity\*`).

## Décision

1. Le modèle porte ses invariants. Pas de setter public qui contourne une règle métier : on expose des méthodes nommées par le métier (`update(...)`, `activate()`, …).
2. Pas de constructeur public. Deux factories statiques :
   - `create(...)` : nouvelle instance, applique les règles métier de création.
   - `load(array $datas)` : reconstitution depuis une source de confiance (repository, builder de test). Elle saute la validation.
3. **`load()` n'est jamais appelé depuis un `CommandHandler` pour créer une entité.** Réservé à l'Infrastructure et aux builders de test.
4. Identifiants et dates ne sont jamais générés dans le Domain : le handler injecte `UuidGenerator` et `Clock` (`Shared\Domain\Interface\UuidGenerator`, `Shared\Domain\Tool\Clock`) et passe les valeurs à `create()`. Pas de `new \DateTimeImmutable()` ni `Uuid::v4()` dans le Domain.
5. Comparaison de modèles par `isEqual()`, jamais `===`.
6. Les modèles ne sont **pas** des objets de sortie HTTP : `ModelTrait::toArray()` n'est pas utilisé pour répondre à un client ([0005](0005-conventions-dto-lecture.md)).
7. Un agrégat = une racine qui garantit la cohérence ; ses sous-objets (ex. `RecipeRow` pour `Recipe`) ne sont modifiés que via la racine.

## Conséquences

- Positives : tests déterministes (clock et ids fakes), invariants centralisés.
- Coût : verbosité (factories, builders de test).
- Garde-fou : revue ; builders dans `tests/Helper/Builder/` uniquement (jamais dans `src/`, règle Deptrac `no-builder-in-src`).
