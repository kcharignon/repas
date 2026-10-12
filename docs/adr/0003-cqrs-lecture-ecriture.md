# 0003 — CQRS : écriture par CommandBus, lecture par QueryBus

- **Statut** : Accepted
- **Date** : 2026-10-12
- **Liens** : [0004](0004-repository-vs-loader.md), [0006](0006-buses-messenger.md), `presentation-archi/presentation.md` §03, §11, `presentation-archi/CQRS-schema.png`

## Contexte

L'écriture est déjà en CQRS : 31 `Command` + `Handler` (`#[AsMessageHandler]`) dispatchés via `CommandBusInterface`. La lecture ne l'est pas. Les controllers `Get*ViewController`, les controllers AJAX hybrides, les form types et `OwnerVoter` injectent directement les `Domain\Interface\*Repository`, hydratent des agrégats complets, puis Twig appelle le domaine (`shoppingList.hasRecipe(recipe)`, `getIngredientQuantity`, `current_user.getUser().isEqual(...)`). `QueryBusInterface` et `QueryBus` existent mais aucun query handler n'est enregistré ; `Application/Query` est vide.

`CLAUDE.md` indiquait jusqu'ici que « les controllers de lecture appellent directement le repository ». Cette règle est abrogée par cet ADR.

## Décision

1. **Écriture** : `Controller → CommandBusInterface::dispatch(Command) → Handler → Domain → Repository`.
2. **Lecture** : `Controller → QueryBusInterface::ask(Query) → QueryHandler → Loader (port Application) → DTO`. Le controller ne connaît ni le handler ni le Loader.
3. Un use case de lecture = un dossier `Application/Query/{UseCase}/` avec `{UseCase}Query`, `{UseCase}QueryHandler` et le ou les `{X}DTO`.
4. Une `Query` est un objet `final readonly` à propriétés publiques, qui implémente `QueryInterface`, sans logique. Elle porte les filtres sous forme de types simples ou de value objects.
5. Un `QueryHandler` ne modifie jamais l'état. Pas d'événement, pas de `save()`. Une lecture qui crée une ressource si elle manque (cas de `GetActiveShoppingListViewController`) est scindée : une `Command` crée, puis une `Query` lit.
6. Un `QueryHandler` n'appelle **jamais** un `Repository`, uniquement un `Loader`. Un `CommandHandler` n'appelle **jamais** un `Loader`, uniquement des `Repository`.
7. Le DTO retourné est la seule chose que le controller (Twig ou API) manipule. Plus de modèle de domaine dans les templates.
8. Une commande ne retourne pas de données de lecture ; si le client a besoin de l'état résultant, il fait une query ensuite.

## Conséquences

- Positives : lectures optimisées (une requête, pas d'hydratation d'agrégat, fin du N+1), logique d'affichage sortie de Twig, contrat de sortie explicite réutilisable par l'API ([0007](0007-api-rest-v1.md)).
- Coûts : plus de classes par use case ; double chemin à maintenir (Repository pour écrire, Loader pour lire) ; risque de divergence entre les deux lectures de la même table.
- Garde-fou : Deptrac `no-repo-in-queryhandler` et `no-loader-in-commandhandler` ([0011](0011-garde-fous-deptrac-phpstan.md)).

## Alternatives écartées

- Lire via `Repository` dans les `QueryHandler` : conserve l'hydratation d'agrégats et le N+1, mélange les deux responsabilités.
- Garder les lectures dans les controllers : bloque l'API (le contrôleur API et le contrôleur Twig dupliqueraient la logique de lecture).
