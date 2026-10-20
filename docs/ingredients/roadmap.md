# Roadmap : ingrédients propres à chaque utilisateur

Suivi vivant. Cocher une case quand le ticket est fusionné (et noter la PR). Chaque ticket est un fichier autonome dans [`tickets/`](tickets/). Décisions : [ADR 0028](../adr/0028-ingredients-propres-a-chaque-utilisateur.md) (**Acceptée** le 2026-10-20 : les dix décisions sont validées par le propriétaire). **Point d'entrée pour tout agent** : lire l'ADR, puis ce fichier, puis [`analyse.md`](analyse.md), puis le ticket choisi, et les skills `repas-dev`, `repas-drive-back` (tout ce qui touche au drive), `repas-po` (tout écran ou texte), `repas-ops` (migration en production).

Rédigé le 2026-10-20. Cadrage, puis réalisation ticket par ticket : ING-001 (le socle), ING-002 (visibilité et résolution) et ING-004 (rapprochement à la copie d'une recette) sont livrés, le reste est à faire. Un agent d'implémentation travaille **un ticket = une PR** ([`../drive/consignes-agents.md`](../drive/consignes-agents.md), qui valent ici, sauf la règle « ne pas durcir une enseigne » : ce chantier ne nomme aucune enseigne).

**Dépôts** : `back` = `kcharignon/repas` ; `front` = `kcharignon/repas-front`.

## Les fichiers

| Fichier | Rôle |
|---|---|
| [`analyse.md`](analyse.md) | Inventaire de tout ce qui référence ou possède un ingrédient (back, drive, front), avec `fichier:ligne` ; comportement actuel de la copie de recette ; défauts latents |
| [`migration.md`](migration.md) | Périmètre (qui est concerné), ce qui est copié et repointé, listes `SENT`, collisions de slug, commande et modes, vérifications, compatibilité avec le code précédent, ordre de déploiement, exploitation, requêtes de relevé |
| [`rapprochement.md`](rapprochement.md) | Algorithme de rapprochement à la copie d'une recette, **expérience chiffrée du seuil**, recommandation |
| [`fusion.md`](fusion.md) | Fusion de deux ingrédients : règles, API, doublons possibles, **interface** (textes exacts), tests |
| [`experiments/`](experiments/) | Script d'essai jetable (`evaluate.php`), générateur et jeu de **vérité terrain** synthétique (`ground-truth.json`), résultats rejouables (`resultats.md`) |
| [`tickets/ING-*.md`](tickets/) | Un ticket par fichier |

## Jalons et tickets

Taille : S (quelques heures), M (une journée), L (plusieurs jours). Effort : le niveau d'effort recommandé pour l'agent d'implémentation (grille validée : `xhigh` pour la sécurité, les droits, les migrations et les données de production ; `high` pour une fonctionnalité ; `medium` pour du texte ou de l'alignement).

### I1 — Socle

- [x] [ING-001](tickets/ING-001-socle-adoption.md) (PR #233) back · M · **xhigh** · Adopter un commun : colonne `adopted_from`, table `ingredient_alias`, slug, copie avec conversions, statistiques, purge de compte · dépend de : —
- [x] [ING-002](tickets/ING-002-visibilite-et-resolution.md) (PR #234) back · L · **xhigh** · Masquer les communs adoptés, résoudre les anciens slugs dans les écritures et dans le drive, test qui interdit l'oubli · dépend de : 001

### I2 — Migration

- [ ] [ING-003](tickets/ING-003-migration-de-donnees.md) back · L · **xhigh** · Commande `repas:ingredients:adopt-commons` (à blanc, `--apply`, `--check`, `--undo`), script d'exploitation, test d'équivalence du drive · dépend de : 001, 002 · exécution en production : **humain**

### I3 — Copie de recette

- [x] [ING-004](tickets/ING-004-rapprochement-et-copie-de-recette.md) (PR #236) back · L · **high** · `IngredientNameMatcher` (seuils mesurés), copie de recette qui se branche sur l'existant · dépend de : 001 (le matcher seul : rien)

### I4 — Fusion

- [ ] [ING-005](tickets/ING-005-fusion-back.md) back · L · **xhigh** · Aperçu, candidats et écriture de la fusion · dépend de : 001 (002 recommandé)
- [ ] [ING-006](tickets/ING-006-doublons-possibles.md) back · M · **high** · Liste des doublons possibles, « Pas le même » · dépend de : 004, 005

### I5 — Front

- [ ] [ING-007](tickets/ING-007-front-fusion-et-doublons.md) **front** · L · **high** · Écrans A (doublons), B (depuis la fiche), confirmation commune · dépend de : 005, 006 en production

### I6 — Plus tard (sur décision du propriétaire)

- [ ] [ING-008](tickets/ING-008-adoption-a-la-premiere-utilisation.md) back · M · **xhigh** · Adoption à la première utilisation pour tous (nouveaux comptes) · dépend de : 003 en production et stable
- [ ] [ING-009](tickets/ING-009-communs-a-l-administrateur.md) back · M · **xhigh** · Les communs deviennent les ingrédients de l'administrateur · dépend de : 008

### Transverse

- [ ] [ING-010](tickets/ING-010-documentation-recette-exploitation.md) back · S · **medium** · `CLAUDE.md`, skills, `docs/production.md`, recette, exploitation · se fait au fil des autres, se clôt après 007

## Ordre de livraison et dépendances

```
ING-001 ──► ING-002 ──► ING-003 ──► (production, observation) ──► ING-008 ──► ING-009
   │            │
   │            └────────► ING-004 ──► ING-006 ──► ING-007 (front)
   └──────────► ING-005 ──────────────────^
ING-010 : en parallèle, clôturé après ING-007
```

**Règles d'ordre (ADR 0017)** :

1. **Le back d'abord, toujours** : aucun appel du front vers une route qui n'est pas en production ; ING-007 part après ING-005 et ING-006 **déployés**.
2. **ING-001 et ING-002 sont sans effet visible** tant qu'aucun alias n'existe : ils partent seuls dans une release, et le site tourne avec quelques heures avant ING-003. Le moindre changement visible à ce stade est un défaut.
3. **ING-003 n'est jamais exécuté le même jour qu'une autre release du back** ; il est lancé par l'humain (script, sauvegarde, `--check`).
4. **Chaque migration de schéma est additive** (colonne nullable, tables) : le retour arrière du code ne défait pas le schéma, l'ancien code l'ignore ([`migration.md`](migration.md) § 11).
5. ING-004 peut partir avant ou après ING-003 ; il est plus utile après.

**Ce qui peut aller en parallèle** :

- le **matcher** d'ING-004 (domaine pur) commence dès maintenant, avant ING-001 ;
- ING-005 après ING-001, pendant ING-002 ;
- ING-010 et la rédaction de la recette, en continu ;
- **Deux agents du back en même temps** touchent `migrations/`, `tests/Repas/WriteBudget/budgets.php` et `docs/api/openapi.json` : rebaser avant chaque PR, numéroter les migrations après la dernière de `main`. L'autre chantier du back (contexte `Drive`) fait de même : coordonner les migrations par date. **Un seul agent à la fois sur ING-001, 002, 003**, dans cet ordre.

## Jalons de contrôle

| Jalon | Preuve |
|---|---|
| Après ING-002 en production | `GET /api/v1/ingredients`, les rayons, la recherche, une recette, une liste et un plan d'envoi sont **identiques à avant** pour un compte de test (aucun alias) ; budgets SQL annoncés |
| Après ING-003 (à blanc) | rapport relu avec le propriétaire : périmètre, collisions, anomalies, listes `SENT` ; aucune anomalie bloquante |
| Après ING-003 (appliqué) | `--check` : zéro écart ; un compte concerné ouvre une recette, sa liste, l'écran des produits du drive : mêmes noms, quantités, produits ; un compte non concerné : rien de différent |
| Après ING-007 | recette signée (`recette.md`, écrite par ING-010) |
| Avant ING-008 | requête de santé à zéro pendant plusieurs jours |

## Risques et inconnues

| # | Risque / inconnue | Gravité | Réponse |
|---|---|---|---|
| R1 | Une écriture oublie de résoudre le slug d'un commun adopté : une ligne retombe sur le commun masqué | haute | `IngredientSlugWritesAreResolvedTest` (ING-002) ; `--check` d'ING-003 et requête de santé d'ING-008 ; un deuxième `--apply` rattrape |
| R2 | Le repointage d'une recette sans celui de ses lignes de liste fausse les quantités au retrait d'un repas ([`analyse.md`](analyse.md) § 4) | haute | une transaction par utilisateur, empreintes avant/après (ING-003) |
| R3 | Un choix du drive perd ses produits mémorisés si le slug d'une liste `SENT` ne suit pas | haute | les listes `SENT` sont repointées (exception justifiée, [`migration.md`](migration.md) § 4) ; test d'équivalence du plan |
| R4 | Un envoi du drive en cours sur le téléphone pendant la migration (slugs périmés dans le brouillon) | moyenne | arrêt du lancement sans `--accept-sent` ; écritures du drive qui tolèrent l'ancien slug (ING-002 C) |
| R5 | Un faux rapprochement fusionne en silence deux produits | haute | seuil 0,97 + garde-fou « mot à mot » : 0 faux lien sur toute la vérité terrain ([`rapprochement.md`](rapprochement.md)) ; en cas de doute, copie |
| R6 | La fusion casse une liste `SENT` ou recrée une ligne sur l'ingrédient supprimé (écriture concurrente) | haute | refus si `SENT` ; verrou des deux lignes ; versions des recettes et listes (409/412) |
| R7 | Les 260 copies par compte multiplient les conversions de l'écran d'administration | basse | à surveiller ; filtre par utilisateur ou par créateur en backlog si l'écran devient pénible ([ADR 0028](../adr/0028-ingredients-propres-a-chaque-utilisateur.md) conséquences) |
| R8 | Les nouvelles règles font monter des budgets SQL | basse | annoncé dans chaque PR, ADR 0010 règle 12 |
| R9 | Retour arrière du code après la migration : copies visibles en double, alias orphelins à la suppression d'un compte | basse | acceptée et documentée ([`migration.md`](migration.md) § 11) ; requête de nettoyage |
| R10 | Deux chantiers du back écrivent des migrations en même temps | moyenne | rebaser ; une migration par PR ; relire `main` avant de nommer |
| I1 | Nombre de comptes administrateurs et rôle exact : « le compte administrateur n'est pas migré » suppose qu'ils gèrent les communs | à relever | requête 1 de [`migration.md`](migration.md) § 14 |
| I2 | Références orphelines ou croisées héritées de données anciennes | à relever | requêtes 2 et 3 |
| I3 | Listes `SENT` existantes à la date du lancement | à relever | requête 1 |
| I4 | Le propriétaire veut-il que l'adoption à la première utilisation (ING-008) soit allumée tôt ? Sans elle, les nouveaux comptes restent sur les communs partagés | décision | ADR 0028 décision b |

## Hypothèses à contrôler en commençant

1. PostgreSQL 16 en production (`deploy/compose.yaml`) : `gen_random_uuid()` existe, mais les identifiants viennent de `UuidGenerator` (aucune dépendance).
2. La table `user` a bien une colonne `roles` lisible en SQL (`roles::text LIKE '%ROLE_ADMIN%'`) : vérifié dans l'entité, à confirmer sur la base de développement avant d'écrire le Loader.
3. Aucun autre code que ceux de [`analyse.md`](analyse.md) ne lit `ingredient.creator` pour décider de la visibilité (`grep -rn "creatorId" src/` au démarrage d'ING-002).
