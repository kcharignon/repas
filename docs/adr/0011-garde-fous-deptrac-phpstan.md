# 0011 — Garde-fous : Deptrac et PHPStan

- **Statut** : Accepted
- **Date** : 2026-10-12
- **Liens** : [0001](0001-architecture-hexagonale.md), [0003](0003-cqrs-lecture-ecriture.md), `presentation-archi/presentation.md` §20

## Contexte

Repas n'a aucun outil d'analyse : ni Deptrac, ni PHPStan, ni Rector, ni CS-Fixer. La CI exécute PHPUnit (seuil 80 %), la validation du schéma Doctrine et un lint Docker. Les règles d'architecture ne reposent que sur la revue. Compliance applique cinq règles Deptrac (une par fichier `quality-rules/Deptrac/*.yaml`) et PHPStan niveau 8.

## Décision

1. **Deptrac** (`deptrac/deptrac`, en dev), cinq fichiers sous `quality-rules/Deptrac/`, exécutés par `make deptrac` et en CI :
   - `deptrac.layer.yaml` : `Application → Domain` ; `Infrastructure → Domain, Application`.
   - `deptrac.bounded-context.yaml` : `Repas → Shared`, `User → Shared`, `Shared` n'importe aucun contexte.
   - `deptrac.no-repo-in-queryhandler.yaml` : les classes de `…\Application\Query\…` ne dépendent d'aucun `*Repository`.
   - `deptrac.no-loader-in-commandhandler.yaml` : les classes de `…\Application\Command\…` ne dépendent d'aucun `*Loader`.
   - `deptrac.no-builder-in-src.yaml` : aucun `*Builder` (hors `*QueryBuilder`) sous `src/`.
   Les règles `Query`/`Command` s'appuient sur le **namespace** (pas sur le suffixe `…CommandHandler`) car les handlers d'écriture existants s'appellent `{UseCase}Handler`.
2. Les violations existantes (liste en ADR 0001) sont figées dans la baseline Deptrac `quality-rules/Deptrac/baseline/bounded-context.baseline.yaml` (importée par `deptrac.bounded-context.yaml`). Les trois autres règles n'ont aucune violation existante, donc pas de baseline. **Le nombre d'entrées de la baseline ne peut que diminuer** ; la régénérer (commande en tête du fichier de config) après avoir résorbé une dépendance. Deptrac 4.5 exige `--config-file` avant les autres options.
3. **PHPStan** (`phpstan/phpstan`, `phpstan/phpstan-phpunit`) : `phpstan.neon` sur `src/` et `tests/`, **niveau 5** au départ, baseline générée pour l'existant (`phpstan-baseline.neon`). Le niveau monte par paliers (6, 7, 8) quand la baseline est vidée. Tout code nouveau doit passer sans entrée de baseline.
4. Règle PHPStan custom `quality-rules/Phpstan/EnsureControllerAndHandlerHaveTestRule` : une classe d'`Application/Query/**` (`*QueryHandler`) ou d'`Infrastructure/Http/Api/**` (`*ApiController`) doit avoir un fichier `{ShortName}Test.php` quelque part sous `tests/`. Limitée aux nouveaux dossiers pour ne pas bloquer l'existant.
5. Cibles Make : `deptrac`, `phpstan`, `api-doc-validate`, `api-doc-coverage`, `quality` (les agrège). Étapes équivalentes dans `.github/workflows/ci.yml`.
6. Hors périmètre pour l'instant : Rector, PHP-CS-Fixer, Infection. Un ADR dédié s'il y a lieu.

## Conséquences

- Positives : l'architecture est vérifiée au build, plus à la revue ; les régressions de dépendances sont visibles dans la CI.
- Coûts : une baseline à entretenir ; temps de CI en plus.
- Garde-fou : la CI elle-même.

## Alternatives écartées

- Règles par suffixe de classe comme dans Compliance (`…CommandHandler`, `…QueryHandler`) : ne couvre pas les handlers d'écriture existants.
- Niveau PHPStan 8 immédiat : baseline ingérable sur le code existant.
