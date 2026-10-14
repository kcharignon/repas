# 0007 — API REST `/api/v1` consommée par un front

- **Statut** : Accepted
- **Date** : 2026-10-12
- **Liens** : [0003](0003-cqrs-lecture-ecriture.md), [0005](0005-conventions-dto-lecture.md), [0008](0008-auth-session-et-jwt.md), [0012](0012-pieges-compliance.md)

## Contexte

L'application est rendue côté serveur (Twig). Les actions dynamiques passent par un protocole maison : 14 controllers renvoient `{status?, alerts:[…], views:[{selector, html}]}` et `public/js/ajax.js` remplace des morceaux de DOM. Un seul endpoint (`GET /ingredients/{slug}/units`) renvoie de la vraie donnée JSON. Un front séparé a besoin d'une API de données, pas de fragments HTML. Symfony serializer, validator et api-doc ne sont pas installés.

## Décision

1. Les controllers API vivent dans `src/{Context}/Infrastructure/Http/Api/{UseCase}/`, un dossier par use case contenant le controller `{UseCase}ApiController` (une route = une action, `#[Route]` sur `__invoke()`) et ses éventuels objets de requête (`{UseCase}RequestQuery`, `{UseCase}Payload`). Les `*ViewController` Twig restent dans `Http/Controller/`.
2. Préfixe `/api/v1`. Le chemin complet est écrit dans chaque `#[Route]`. Noms de routes : `api_{verbe}_{ressource}`.
3. Un controller API n'appelle que `QueryBusInterface::ask()` (GET) ou `CommandBusInterface::dispatch()` (écritures). Aucune logique métier, aucun repository, aucun loader.
4. Entrées : `#[MapQueryString]` et `#[MapRequestPayload]` sur des objets `readonly` validés par `#[Assert\…]`, avec `validationFailedStatusCode: 422` **explicite**. La validation de forme est ici ; la validation métier reste dans le Domain.
5. Sorties de lecture : on renvoie le DTO de la query ([0005](0005-conventions-dto-lecture.md)). Collection : `{"member": [...], "totalItems": N}`, plus `"view"` (liens first/last/previous/next) seulement si la liste est paginée (`page`, `itemsPerPage`, positifs).
6. Sorties d'écriture : `204 No Content` (modification/suppression) ou `201 Created` + `Location` (création). Le client relit l'état avec une query. Pas de fragment HTML.
7. Erreurs : toujours `{"error": {"message": "..."}}`. Mapping par un listener limité à `^/api` : `DomainException` → son code HTTP (`404` pour `…NotFound`, `422` sinon), validation → `422`, `NotFoundHttpException` → `404`, `AccessDeniedException` → `403`, authentification manquante → `401`, le reste → `500` avec message générique.
8. OpenAPI obligatoire : chaque controller porte `#[OA\Get|Post|Put|Patch|Delete]`, `#[OA\Tag]`, `#[OA\Response]` ; documentation générée par `nelmio/api-doc-bundle` (zone `^/api`, UI `/api/doc`, JSON `/api/doc.json`). Les DTO se référencent via `new Model(type: XDTO::class)`.
9. Contrôle d'accès : l'autorisation métier (propriétaire d'une liste, admin) vit dans le Domain/Application ou un voter, jamais dans le front ; chaque endpoint déclare son niveau (`#[IsGranted]` ou `access_control`). Un voter qui ne trouve pas la ressource lève un `NotFoundHttpException` (404, sur l'API comme sur Twig) ; `OwnerVoter` couvre l'ingrédient, la recette, la liste, le repas et la ligne de courses. Une mutation n'est jamais acceptée en `GET`.
10. Le test d'un controller API vérifie : la `Query`/`Command` construite, le statut HTTP, et les **clés JSON** du DTO (contrat front).
11. **Conventions des endpoints d'écriture** (phase 9). Un use case d'écriture = une commande = un controller. Créer, modifier, supprimer une ressource : `POST` (201 + `Location` vers la lecture), `PATCH` / `PUT` (204), `DELETE` (204). Une transition d'état qui est une commande à part : sous-ressource d'action en `POST …/{action}` (`activate`, `pause`, `complete`, `tick`, `uncheck`, `increase`, `decrease`), sans corps. Les ressources de l'utilisateur connecté s'adressent par l'alias `active` (`/shopping-lists/active/recipes/{recipeId}`) : l'identité vient toujours du jeton JWT (`#[CurrentUser] AuthenticatedUser`), **jamais du corps ni de l'URL**. Un identifiant créé par le serveur est généré dans le controller (`UuidGenerator`) et rendu dans `Location`. Quand l'identifiant dérive du domaine (le slug d'un ingrédient vient de son nom et de son créateur), le controller ne peut pas le deviner : le handler de création le renvoie, `CommandBusInterface::dispatch()` le rend (`CreateIngredientHandler` renvoie le slug), et `MockCommandBus::willReturn()` le programme dans les tests.
12. **Codes des exceptions de domaine** (relayés tels quels par le listener) : `404` pour une ressource introuvable, `409` pour un conflit avec l'état courant (recette déjà dans la liste), `403` réservé à l'autorisation, `422` pour une règle métier non respectée. Une exception de domaine neuve choisit son code selon cette grille.
13. **Tests d'un endpoint d'écriture** : un test de controller par endpoint avec `QueryBusWebTestCase::mockCommandBus()` (commande construite, statut, `Location`, 401, 403 propriétaire, 404 inconnu, 422 corps invalide), plus un parcours de bout en bout par ressource avec les vrais bus (`ShoppingListWriteApiFlowTest` : jeton → handler → base → listeners). Le client de test redémarre le noyau entre deux requêtes : le double de bus ne vaut que pour une requête par test.

## Conséquences

- Positives : un contrat stable pour le front, doc vivante, même couche Application que Twig.
- Coûts : dépendances à ajouter (serializer, validator, property-access, nelmio api-doc, nelmio cors) ; double surface (Twig + API) à maintenir pendant la coexistence.
- Garde-fou : `make api-doc-validate` et `make api-doc-coverage` en CI ; règle PHPStan « controller API ⇒ test ».

## Alternatives écartées

- API Platform : impose un modèle de ressources qui contourne Command/Query.
- Réutiliser les controllers Twig avec un `Accept: application/json` : mélange rendu et données, mauvaise séparation des responsabilités.
