# 0001 — Architecture hexagonale, règle de dépendance, bounded contexts

- **Statut** : Accepted (formalise l'existant)
- **Date** : 2026-10-12
- **Liens** : `CLAUDE.md`, `presentation-archi/presentation.md` §01, [0003](0003-cqrs-lecture-ecriture.md)

## Contexte

Le code est organisé en bounded contexts sous `src/` : `Repas` (recettes, ingrédients, listes de courses), `User` (inscription, auth, préférences) et `Shared` (briques transverses). Chaque contexte suit trois couches : `Domain`, `Application`, `Infrastructure`. La structure existe et fonctionne, mais la règle n'était écrite que dans `CLAUDE.md` et n'est vérifiée par aucun outil. Plusieurs entorses existent déjà :

- `User\Domain\Model\User` implémente des interfaces Symfony Security.
- `Domain\Service\{AuthenticatorEmail,UserChecker,UserProvider}` dépendent de Symfony.
- `Repas` dépend de `User` : `Repas\Domain\Model\ShoppingList` importe `User\Domain\Model\User`, et la plupart des handlers d'écriture injectent `UserRepository` / lèvent `UserException` (60 dépendances).
- `User` dépend de `Repas` (11 dépendances, dont `OwnerVoter` qui injecte trois repositories de `Repas` et le listener de statistiques).
- `Shared` dépend de `Repas` et `User` (6 dépendances, dont `RepositoryTrait`).

Les trois dernières entorses sont détectées par Deptrac et figées dans la baseline (77 entrées, 120 occurrences à l'introduction de l'outil). Les deux premières (dépendance à Symfony) ne le sont pas : Symfony n'est pas une couche Deptrac, elles restent signalées ici.

## Décision

1. Sens de dépendance unique : `Infrastructure → Application → Domain`. Jamais l'inverse.
2. `Domain` ne dépend de rien : ni Application, ni Infrastructure, ni Symfony, ni Doctrine.
3. `Application` dépend de `Domain` et de `Shared\Application`/`Shared\Domain` uniquement, via des interfaces (ports).
4. `Infrastructure` implémente les ports et adapte le framework (HTTP, Doctrine, Twig, Security).
5. `Shared` n'importe jamais `Repas` ni `User`. Les contextes peuvent importer `Shared`.
6. Un contexte n'importe pas les `Infrastructure` d'un autre contexte.
7. Les entorses existantes sont listées dans la baseline Deptrac ([0011](0011-garde-fous-deptrac-phpstan.md)) et résorbées au fil de l'eau, jamais aggravées. Toute nouvelle classe respecte les règles ci-dessus sans passer par la baseline.
8. L'utilisateur connecté vu depuis un autre contexte que `User` est `Repas\Shared\Domain\Interface\AuthenticatedUser` (un seul accesseur : `getId()`), implémenté par `User\Domain\Model\User`. Un controller du contexte `Repas` l'obtient par `#[CurrentUser] AuthenticatedUser $user` (ou `assert($this->getUser() instanceof AuthenticatedUser)`) et passe `$user->getId()` à sa query ou à sa commande. Il n'importe jamais `User\Domain\Model\User` : la baseline Deptrac ne peut que diminuer (les controllers déjà migrés ont vu leur entrée retirée).

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
