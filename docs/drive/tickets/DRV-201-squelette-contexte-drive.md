# DRV-201 — Squelette du contexte `Drive`

- **Dépôt** : back
- **Jalon** : M2 Back `Drive`
- **Taille** : S
- **Dépend de** : DRV-005 (décision G1 : Go)
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 3, 5 ; ADR 0001, 0011, 0013 ; `quality-rules/Deptrac/*.yaml` (7 fichiers) ; `quality-rules/Phpstan/` ; skill `repas-dev`.

## Contexte

Le contexte `Drive` est nouveau : il doit exister, être vérifié par Deptrac et PHPStan, être chargé par Symfony et Doctrine, avant d'y ajouter des cas d'usage. Il ne dépend que de `Shared`.

## Portée

1. Dossiers `src/Drive/{Domain,Application,Infrastructure}` avec la même structure que `Repas` (voir `CLAUDE.md` du back) : `Domain/{Model,Interface,Service,Exception}`, `Application/{Command,Query,Loader}`, `Infrastructure/{Entity,Repository,Loader,Http/Api,EventListener,DataFixture}` ; créer seulement les dossiers nécessaires pour que les outils tournent (un fichier `.gitkeep` si un dossier de mapping doit exister).
2. **Deptrac** (`quality-rules/Deptrac/deptrac.bounded-context.yaml`) : couche `Drive` (`^Repas\\Drive\\.*` hors `Infrastructure\\DataFixture`) et règle `Drive: [Shared]`. Lire les six autres fichiers : `deptrac.no-database-in-handler-tests.yaml` écrit en dur `(Repas|User)` → y ajouter `Drive` ; vérifier que `deptrac.layer.yaml`, `no-builder-in-src`, `no-loader-in-commandhandler`, `no-repo-in-queryhandler`, `no-repository-in-http` couvrent `Drive` (ils filtrent par noms de dossiers `Application/Command`, `Application/Query`, `Infrastructure/Http`).
3. `config/routes.yaml` : import `drive_api_controllers` (`path: ../src/Drive/Infrastructure/Http/Api/`, `namespace: Repas\Drive\Infrastructure\Http\Api`, `type: attribute`).
4. `config/packages/doctrine.yaml` : mapping `Drive` (`type: attribute`, `dir: src/Drive/Infrastructure/Entity`, `prefix: Repas\Drive\Infrastructure\Entity`, `alias: Drive`).
5. `phpunit.dist.xml` : exclure `src/Drive/Infrastructure/DataFixture` de la couverture, comme les deux autres contextes.
6. `config/services.yaml` : vérifier l'autowiring et l'enregistrement des handlers pour l'espace de noms `Repas\Drive\` (suivre ce qui est fait pour `Repas\Repas\` et `Repas\User\`).
7. PHPStan (`quality-rules/Phpstan/`) : la règle « handler ou contrôleur ⇒ test » doit couvrir `Drive` ; adapter les motifs d'espace de noms si besoin ; `phpstan.dist.neon` à jour ; aucune nouvelle entrée de baseline.
8. Tests : dossiers `tests/Drive/{Domain,Application,InputAdapter,OutputAdapter}` miroir de l'existant ; si les helpers (`DatabaseTestCase`) listent les contextes, les étendre.
9. Documentation : `CLAUDE.md` du back (section « Bounded contexts » : ajouter `Drive` quand l'ADR 0022 est `Accepted`, sinon mentionner « en cours ») ; roadmap cochée.

## Hors portée

Toute classe métier (DRV-203 et suivants).

## Critères d'acceptation

- `make deptrac` est vert avec le nouveau contexte, et une classe d'essai de `Drive` qui importerait `Repas\Repas\…` ou `Repas\User\…` ferait échouer Deptrac (vérifié par un essai local décrit dans la PR, non commité).
- `make phpstan` et `make test` verts ; `bin/console debug:container` charge sans erreur ; `doctrine:schema:validate` ne signale rien (aucune entité encore).
- Aucune règle existante n'est assouplie pour `Repas` et `User`.

## Tests exigés

Les commandes de qualité ci-dessus ; aucun test fonctionnel nouveau.

## Definition of Done

Celle du skill `repas-dev` § 4 (`make test`, `make quality`).

## Pièges connus

- Un dossier de mapping Doctrine absent fait échouer le noyau : créer `src/Drive/Infrastructure/Entity/.gitkeep`.
- Les fixtures de développement composent les contextes (elles sont hors des règles Deptrac) : ne pas y mettre de logique `Drive` tant que ce n'est pas utile.
