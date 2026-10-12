# 0001 — Architecture hexagonale, règle de dépendance, bounded contexts

- **Statut** : Accepted (formalise l'existant)
- **Date** : 2026-10-12
- **Liens** : `CLAUDE.md`, `presentation-archi/presentation.md` §01, [0003](0003-cqrs-lecture-ecriture.md)

## Contexte

Le code est organisé en bounded contexts sous `src/` : `Repas` (recettes, ingrédients, listes de courses), `User` (inscription, auth, préférences) et `Shared` (briques transverses). Chaque contexte suit trois couches : `Domain`, `Application`, `Infrastructure`. La structure existe et fonctionne, mais la règle n'était écrite que dans `CLAUDE.md` et n'est vérifiée par aucun outil. Plusieurs entorses existent déjà :

- `User\Domain\Model\User` implémente des interfaces Symfony Security.
- `Repas\Domain\Model\ShoppingList` importe `User\Domain\Model\User` (dépendance inter-contextes dans le Domain).
- `Shared\Infrastructure\Repository\RepositoryTrait` importe des modèles et entités de `Repas` et `User`.
- `Domain\Service\{AuthenticatorEmail,UserChecker,UserProvider}` dépendent de Symfony.

## Décision

1. Sens de dépendance unique : `Infrastructure → Application → Domain`. Jamais l'inverse.
2. `Domain` ne dépend de rien : ni Application, ni Infrastructure, ni Symfony, ni Doctrine.
3. `Application` dépend de `Domain` et de `Shared\Application`/`Shared\Domain` uniquement, via des interfaces (ports).
4. `Infrastructure` implémente les ports et adapte le framework (HTTP, Doctrine, Twig, Security).
5. `Shared` n'importe jamais `Repas` ni `User`. Les contextes peuvent importer `Shared`.
6. Un contexte n'importe pas les `Infrastructure` d'un autre contexte.
7. Les entorses existantes sont listées dans la baseline Deptrac ([0011](0011-garde-fous-deptrac-phpstan.md)) et résorbées au fil de l'eau, jamais aggravées. Toute nouvelle classe respecte les règles ci-dessus sans passer par la baseline.

Arborescence d'un contexte :

```
src/{Context}/
  Domain/          Model, Interface (ports), Service, Event, Exception
  Application/     Command/{UseCase}, Query/{UseCase}, Loader (ports de lecture)
  Infrastructure/  Entity, Repository, Loader, Http/{Controller,Api,Form,View}, EventListener, DataFixture
```

## Conséquences

- Positives : le métier se teste sans base ni framework ; on peut changer l'infra sans toucher au métier.
- Coût : plus de classes (ports + adapters) pour un même besoin.
- Garde-fou : Deptrac (`quality-rules/Deptrac/deptrac.layer.yaml`, `deptrac.bounded-context.yaml`), exécuté en CI.

## Alternatives écartées

- Architecture en couches classique Symfony (controller → service → repository Doctrine) : le Domain se couple à Doctrine, les tests exigent une base.
