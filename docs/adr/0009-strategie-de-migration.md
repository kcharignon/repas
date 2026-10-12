# 0009 — Stratégie de migration « strangler » par slice de lecture

- **Statut** : Accepted
- **Date** : 2026-10-12
- **Liens** : [0003](0003-cqrs-lecture-ecriture.md), `docs/migration-roadmap.md`

## Contexte

Le projet compte 49 controllers, 38 templates, 6 form types et 1 voter qui lisent directement les repositories. Une migration en bloc est risquée : il n'y a quasiment aucun test de controller ni de template (`AddNewUnitControllerTest` assert `true`), et le travail doit se faire par petits pas.

## Décision

1. La migration se fait **par slice** : un use case de lecture complet à la fois, de bout en bout, mergeable seul. L'ordre et le suivi sont dans `docs/migration-roadmap.md`.
2. Une slice contient, dans l'ordre : DTO → interface `Loader` → `Query` + `QueryHandler` → `PostgreSQLLoader` → bascule du `ViewController` Twig (le template lit le DTO, plus de logique domaine) → nouveau controller API → tests → case cochée dans la roadmap.
3. **Coexistence** : une slice ne supprime pas la route Twig. Elle change seulement sa source de données. Les routes Twig et AJAX existantes (session, fragments HTML) restent valides jusqu'à décision d'extinction (phase 9 de la roadmap).
4. Une slice ne modifie pas le comportement visible : même HTML (à la logique d'affichage près), mêmes statuts HTTP, mêmes autorisations.
5. Ordre de priorité : valeur (N+1 supprimés, logique Twig sortie) puis risque croissant. Phase 1 = données de référence (pilote), puis listes de courses, recettes, ingrédients/conversions, formulaires et voter, enfin endpoints d'écriture.
6. **Definition of Done d'une slice** : `make test`, `make deptrac`, `make phpstan`, `make api-doc-validate` verts ; aucun `Repository` référencé par le controller migré ; aucun appel de méthode de domaine dans les templates concernés ; test du `QueryHandler`, test du Loader sur base réelle, test du controller API (query construite, statut, clés JSON) ; roadmap mise à jour ; nouvel ADR si une décision d'architecture a été prise.
7. Les bugs découverts en route sont notés dans la roadmap (section « Hors périmètre »), pas corrigés dans la slice, sauf s'ils la bloquent.
8. Un commit par unité logique, message court, jamais de push automatique.

## Conséquences

- Positives : chaque pas est livrable et réversible ; le front peut démarrer dès la phase 1.
- Coûts : pendant la coexistence, deux contrôleurs (Twig + API) lisent la même donnée via la même query — acceptable car la logique est dans la query.
- Garde-fou : DoD ci-dessus, vérifiée en revue et en CI.

## Alternatives écartées

- Big bang : trop risqué sans filet de tests.
- Réécrire d'abord les templates en SPA : repousse tout le bénéfice côté serveur.
