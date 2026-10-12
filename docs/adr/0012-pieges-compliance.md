# 0012 — Pièges observés dans Compliance à ne pas reproduire

- **Statut** : Accepted
- **Date** : 2026-10-12
- **Liens** : [0003](0003-cqrs-lecture-ecriture.md), [0006](0006-buses-messenger.md), [0007](0007-api-rest-v1.md), `/home/kantin/Projet/Compliance` (état au 2026-10-12)

## Contexte

Repas reprend le découpage de Compliance (Query / QueryHandler / Loader / DTO, controllers qui passent par les buses, Deptrac, OpenAPI). La relecture du code de Compliance a montré des défauts qui ne doivent pas être copiés. Cet ADR sert de liste de contrôle.

## Décision

| # | Constat dans Compliance | Règle pour Repas |
|---|---|---|
| 1 | `MessengerBus` est câblé sur `command.bus` pour les commandes **et** les queries (donc sous `doctrine_transaction`) ; `query.bus` est déclaré mais jamais utilisé. | Deux buses réellement séparés dès le départ ([0006](0006-buses-messenger.md)). |
| 2 | `#[MapQueryString]` invalide renvoie 404 par défaut (`MapQueryString::$validationFailedStatusCode`), non mappé par le listener → 500. Un test l'entérine (`assertResponseStatusCodeSame(500)`). | `validationFailedStatusCode: 422` explicite ; le listener mappe `NotFoundHttpException` → 404 ([0007](0007-api-rest-v1.md)). |
| 3 | `RidesDoctrineLoader::getRideDetails` lève `RuntimeException('Ride not found')` → 500. | Un Loader lève une exception de domaine `…NotFound` (404) ([0004](0004-repository-vs-loader.md)). |
| 4 | Nommage des queries incohérent (`ReadRidesQuery` dans `GetRides/` mais `GetDriversQuery`). | `Get{X}` pour un élément, `List{X}` pour une collection ; `{Nom}Query` / `{Nom}QueryHandler` / `{X}DTO` uniformes. |
| 5 | `PaginatedResult` n'est pas `readonly`. | Tout objet de transport est `final readonly`. |
| 6 | `CLAUDE.md` dérive du code : chemins de tests (`ApplicationCore/Query/…` annoncé, tests à plat dans le code), emplacement des controllers, « InMemory Loaders » inexistants. | La source de vérité vivante est `docs/adr/` + `docs/migration-roadmap.md` + le skill `repas-dev` ; `CLAUDE.md` y renvoie. Toute modification de convention met à jour ces trois endroits dans le même commit. |
| 7 | Pas de fake réutilisable de Loader : `Mock*Loader` écrits au cas par cas. | `tests/Helper/Loader/Fake{X}Loader` explicites, un par interface ([0010](0010-strategie-de-tests.md)). |
| 8 | Les tests de controller ne vérifient pas la forme JSON du DTO. | Un test par endpoint API assert les clés JSON ([0007](0007-api-rest-v1.md)). |
| 9 | `ecs.php` présent sans dépendance ni cible : config morte. | Pas de configuration d'outil non exécutée ; chaque outil a sa cible Make et son étape CI ([0011](0011-garde-fous-deptrac-phpstan.md)). |
| 10 | Copier-coller dans un `#[OA\RequestBody]` (mauvais `Payload` référencé). | Le skill impose de vérifier la doc générée (`make api-doc-validate`) et la relire pour chaque endpoint. |
| 11 | Pas d'authentification sur `/api` (firewall sans authenticator). | JWT dès le premier endpoint ([0008](0008-auth-session-et-jwt.md)). |

## Conséquences

- Positives : on bénéficie de l'expérience de Compliance sans hériter de ses défauts.
- Coûts : écart assumé avec Compliance sur ces points ; à ne pas « réaligner » par mégarde.
- Garde-fou : revue + skill `repas-dev`.
