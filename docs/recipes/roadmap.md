# Roadmap : recettes (chantier en cours : recettes utilisées dans d'autres recettes)

Suivi vivant. Cocher une case quand le ticket est fusionné (et noter la PR). Chaque ticket est un fichier autonome dans [`tickets/`](tickets/). Décisions : [ADR 0040](../adr/0040-recettes-utilisees-dans-d-autres-recettes.md) (**acceptée le 2026-11-08**, demande du propriétaire, issue [#435](https://github.com/kcharignon/repas/issues/435) : l'étude d'impact y est publiée en commentaire, ainsi que sa précision). **Point d'entrée pour tout agent** : lire l'ADR, puis le ticket, puis `CLAUDE.md` (puces « Recette secrète », « Copier une recette depuis Marmiton », « Types de liste », « Sous-ingrédients »).

Rédigé le 2026-11-08. Les autres chantiers de recettes, terminés, ont leurs tickets dans le même dossier : REC-001 (recette secrète, [ADR 0033](../adr/0033-recette-secrete.md)), REC-003 (types supprimables et favoris, [ADR 0034](../adr/0034-supprimer-un-type-de-recette-et-recettes-favorites.md)), MAR-001 à MAR-005 (copier une recette depuis Marmiton, [ADR 0037](../adr/0037-copier-une-recette-depuis-marmiton.md), le front MAR-004 reste à faire).

Un agent d'implémentation travaille **un ticket = une PR** ([`../drive/consignes-agents.md`](../drive/consignes-agents.md), qui valent ici, sauf la règle « ne pas durcir une enseigne » : ce chantier ne nomme aucune enseigne). **Un agent à la fois** sur COMP-001 à 004 : ils touchent tous `Recipe`, `RecipePostgreSQLRepository`, `docs/api/openapi.json`, `budgets.php` et `migrations/`, et la base de test est commune.

**Dépôts** : `back` = `kcharignon/repas` ; `front` = `kcharignon/repas-front`.

## En une phrase

Une recette garde ses lignes et gagne des **composants** (une autre recette du même compte, « n fois telle qu'écrite », une position) ; la planification, le retrait et le changement de personnes **aplatissent** l'arbre en lignes d'ingrédients, de sorte que la liste, le drive, la voix et l'envoi ne changent pas. Zéro composant = rien ne change.

## Les fichiers

| Fichier | Rôle |
|---|---|
| [`../adr/0040-recettes-utilisees-dans-d-autres-recettes.md`](../adr/0040-recettes-utilisees-dans-d-autres-recettes.md) | Les 44 décisions : modèle, formule, lectures, écritures, codes d'erreur, copie, budgets, retour arrière, ce qui est hors v1 |
| [`tickets/COMP-*.md`](tickets/) | Un ticket par fichier |
| `composants-exploitation.md` | **À créer par COMP-007** : le pas à pas du propriétaire et la recette sur trois comptes |

## Ordre exact d'exécution et de livraison

1. **COMP-001** (modèle, table, lecture) : la base de tout, inerte en production.
2. **COMP-004** (aplatissement) : **avant COMP-002** ; il se teste avec des composants posés par les dépôts, sans route d'écriture.
3. **COMP-002** (écriture, suppression refusée, bascules du secret, recettes utilisables, suppression de compte).
4. **COMP-003** (copie d'une recette composée).
5. **Une seule release du back** qui porte COMP-001, 004, 002 et 003 (**004 au plus tard avec 002 : règle d'ordre de l'ADR 0040 décision 32**) ; l'approbation du déploiement est celle du propriétaire.
6. **Le contrat** (`make openapi`, figé dans le front par `npm run api:update`).
7. **COMP-005** (maquette) : peut commencer **dès maintenant**, en parallèle du back ; **validée par le propriétaire avant la première ligne de COMP-006**.
8. **COMP-006** (front) après le back en production ; puis **la release du site** (jamais avec celle du back).
9. **COMP-007** (rapport de santé `repas:recipes:check`, `docs/production.md`, recette sur trois comptes) : le code et les documents partent avec le back (le rapport sert dès les premiers composants), la recette est faite par le propriétaire après le site.

Aucun interrupteur : sans composant créé, rien ne change pour personne. **Aucune nouvelle version de l'application Android.**

## Jalons et tickets

Taille : S (quelques heures), M (une journée), L (plusieurs jours). Effort : le niveau d'effort recommandé pour l'agent d'implémentation (grille validée : `xhigh` pour la sécurité, les droits, les migrations et les données de production ; `high` pour une fonctionnalité ; `medium` pour du texte ou de l'alignement).

### I9 — Recettes utilisées dans d'autres recettes (ADR 0040, demande du propriétaire du 2026-11-08, issue #435)

- [x] [COMP-001](tickets/COMP-001-modele-table-et-lecture-des-composants.md) (PR #461) back · L · **xhigh** · Modèle `RecipeComponent`, table `recipe_component`, `recipe.component_count`, règles de base, lecture `components`, chargement en lot · dépend de : —
- [x] [COMP-004](tickets/COMP-004-aplatir-a-la-planification.md) (PR #462) back · L · **xhigh** · `RecipeFlattener`, planifier / retirer / changer les personnes, voix (conversion manquante nommée), budgets · dépend de : 001 · **à livrer avant ou avec 002**
- [x] [COMP-002](tickets/COMP-002-ecrire-les-composants-et-leurs-refus.md) (PR #463) back · L · **xhigh** · `components` au `POST`/`PUT` (absent = inchangé), les neuf refus, bascules du secret, suppression refusée, `usable` et `used-in`, suppression de compte · dépend de : 001, **004**
- [x] [COMP-003](tickets/COMP-003-copier-une-recette-composee.md) (PR à venir) back · M · **xhigh** · Copier une recette composée : ses recettes utilisées aussi, un seul rapprochement d'ingrédients, plafond sur « compte + à créer » · dépend de : 001 (002 recommandé)
- [ ] [COMP-005](tickets/COMP-005-maquette-recettes-utilisees.md) designer · S à M · **high** · La maquette : éditeur, fiche, copie, erreurs, états · dépend de : l'ADR · **à valider par le propriétaire avant COMP-006**
- [ ] [COMP-006](tickets/COMP-006-front-recettes-utilisees.md) **front** · L · **high** · Éditeur, fiche « Avec », copie, messages, tests, e2e dans le budget · dépend de : 001 à 004 en production, 005 validée
- [ ] [COMP-007](tickets/COMP-007-exploitation-sante-et-recette.md) back (commande en lecture seule, documents) + opérations de l'humain · S à M · **high** · `repas:recipes:check`, `docs/production.md`, recette sur trois comptes, retour arrière · dépend de : 001 à 004 ; recette après 006

## Hors v1 (décidé, voir l'ADR)

Un instantané des lignes aplaties dans le repas (retrait exact après modification d'une recette utilisée) ; « dont Pâte sablée » dans la liste de courses ; deux niveaux ; un drapeau ou un type « préparation » (le type « Bases » se crée avec l'écran d'administration) ; la recette d'un autre compte utilisée sans copie ; le retrait vocal d'une recette absorbée par un parent.
