# Roadmap : ingrédients propres à chaque utilisateur

Suivi vivant. Cocher une case quand le ticket est fusionné (et noter la PR). Chaque ticket est un fichier autonome dans [`tickets/`](tickets/). Décisions : [ADR 0028](../adr/0028-ingredients-propres-a-chaque-utilisateur.md) (**Acceptée** le 2026-10-20 : les dix décisions sont validées par le propriétaire). **Point d'entrée pour tout agent** : lire l'ADR (son amendement du 2026-10-20 compris), puis ce fichier, puis [`sans-commun.md`](sans-commun.md), puis [`analyse.md`](analyse.md) (l'inventaire d'origine), puis le ticket choisi, et les skills `repas-dev`, `repas-drive-back` (tout ce qui touche au drive), `repas-po` (tout écran ou texte), `repas-ops` (migration en production).

Rédigé le 2026-10-20. Cadrage, puis réalisation ticket par ticket : ING-001 (le socle), ING-002 (visibilité et résolution), ING-004 (rapprochement à la copie d'une recette), ING-003 (la commande de migration, exécutée en production le 2026-10-20), ING-005 (aperçu et écriture de la fusion), ING-006 (doublons possibles et « Pas le même », code livré) ING-011 (marqueur « de base » et propriétaire du catalogue), ING-012 (plus de nouveau commun), ING-008 (amorçage des comptes à l'activation, rattrapage et interrupteur coupé par défaut) et ING-009 (la commande de passation du catalogue, ses données et ses scripts : le code est livré, **l'exécution en production reste à faire par l'humain**) sont livrés ; ING-013 (visibilité « créateur = moi », droits, résolveur, copie de recette, fusion, doublons et conversions sans commun) est livré par la PR #247, qui ne se fusionne qu'après la passation.

> **Amendement du 2026-10-20 : plus d'ingrédient commun** ([ADR 0028](../adr/0028-ingredients-propres-a-chaque-utilisateur.md#amendement-du-2026-10-20--plus-dingrédient-commun), plan et ordre d'exécution : [`sans-commun.md`](sans-commun.md)). Les 261 anciens communs deviennent les ingrédients du compte administrateur protégé ; tout compte a ses ingrédients, copiés à l'activation parmi ceux que l'administrateur marque « de base » (151 sur 261, [`base-ingredients.tsv`](base-ingredients.tsv)). Les anciens **ING-008** (adoption à la première utilisation) et **ING-009** (communs à l'administrateur, plus tard) sont **remplacés** par les tickets réécrits de ce nom, et **ING-011 à ING-015** sont nouveaux. Le reste du chantier (rapprochement, fusion, doublons) ne change pas.

Un agent d'implémentation travaille **un ticket = une PR** ([`../drive/consignes-agents.md`](../drive/consignes-agents.md), qui valent ici, sauf la règle « ne pas durcir une enseigne » : ce chantier ne nomme aucune enseigne).

**Dépôts** : `back` = `kcharignon/repas` ; `front` = `kcharignon/repas-front`.

## Les fichiers

| Fichier | Rôle |
|---|---|
| [`analyse.md`](analyse.md) | Inventaire de tout ce qui référence ou possède un ingrédient (back, drive, front), avec `fichier:ligne` ; comportement actuel de la copie de recette ; défauts latents |
| [`migration.md`](migration.md) | Périmètre (qui est concerné), ce qui est copié et repointé, listes `SENT`, collisions de slug, commande et modes, vérifications, compatibilité avec le code précédent, ordre de déploiement, exploitation, requêtes de relevé |
| [`rapprochement.md`](rapprochement.md) | Algorithme de rapprochement à la copie d'une recette, **expérience chiffrée du seuil**, recommandation |
| [`fusion.md`](fusion.md) | Fusion de deux ingrédients : règles, API, doublons possibles, **interface** (textes exacts), tests |
| [`sans-commun.md`](sans-commun.md) | **Amendement du 2026-10-20** : le modèle sans ingrédient commun, la règle d'amorçage transitoire, l'ordre exact des sorties et des opérations, les contrôles SQL (avant, après, retour arrière), l'inventaire de ce qui change et de ce qui meurt, les risques |
| [`base-ingredients.tsv`](base-ingredients.tsv) | Le classement validé des 261 anciens communs : 151 « de base », 110 « spécifiques » (donnée de la passation) |
| [`experiments/`](experiments/) | Script d'essai jetable (`evaluate.php`), générateur et jeu de **vérité terrain** synthétique (`ground-truth.json`), résultats rejouables (`resultats.md`) |
| [`tickets/ING-*.md`](tickets/) | Un ticket par fichier |

## Jalons et tickets

Taille : S (quelques heures), M (une journée), L (plusieurs jours). Effort : le niveau d'effort recommandé pour l'agent d'implémentation (grille validée : `xhigh` pour la sécurité, les droits, les migrations et les données de production ; `high` pour une fonctionnalité ; `medium` pour du texte ou de l'alignement).

### I1 — Socle

- [x] [ING-001](tickets/ING-001-socle-adoption.md) (PR #233) back · M · **xhigh** · Adopter un commun : colonne `adopted_from`, table `ingredient_alias`, slug, copie avec conversions, statistiques, purge de compte · dépend de : —
- [x] [ING-002](tickets/ING-002-visibilite-et-resolution.md) (PR #234) back · L · **xhigh** · Masquer les communs adoptés, résoudre les anciens slugs dans les écritures et dans le drive, test qui interdit l'oubli · dépend de : 001

### I2 — Migration

- [x] [ING-003](tickets/ING-003-migration-de-donnees.md) (PR #237) back · L · **xhigh** · Commande `repas:ingredients:adopt-commons` (à blanc, `--apply`, `--check`, `--undo`), script d'exploitation, test d'équivalence du drive · dépend de : 001, 002 · **le code est livré et la migration a été exécutée en production le 2026-10-20 (procédure : [`migration.md`](migration.md) § 13)**

### I3 — Copie de recette

- [x] [ING-004](tickets/ING-004-rapprochement-et-copie-de-recette.md) (PR #236) back · L · **high** · `IngredientNameMatcher` (seuils mesurés), copie de recette qui se branche sur l'existant · dépend de : 001 (le matcher seul : rien)

### I4 — Fusion

- [x] [ING-005](tickets/ING-005-fusion-back.md) (PR #238 ; **amendé le 2026-10-21** : totaux dans l'aperçu et conversions récupérées de l'absorbé) back · L · **xhigh** · Aperçu, candidats et écriture de la fusion · dépend de : 001 (002 recommandé)
- [x] [ING-006](tickets/ING-006-doublons-possibles.md) (PR #239) back · M · **high** · Liste des doublons possibles, « Pas le même » · dépend de : 004, 005

### I5 — Front

- [ ] [ING-007](tickets/ING-007-front-fusion-et-doublons.md) **front** · L · **high** · Écrans A (doublons), B (depuis la fiche), confirmation commune · dépend de : 005, 006 en production

### I6 — Plus d'ingrédient commun (amendement du 2026-10-20)

- [x] [ING-011](tickets/ING-011-marqueur-de-base.md) (PR #242) back · M · **xhigh** · Le marqueur « de base » (`ingredient.is_base`), le propriétaire du catalogue (port `Shared`), le champ d'API et son contrôle `403` · dépend de : cadrage · sortie **R1**
- [x] [ING-012](tickets/ING-012-plus-de-nouveau-commun.md) (PR #243) back · S · **high** · Plus de nouveau commun : l'administrateur crée des ingrédients à lui · dépend de : 011 · **R1**
- [x] [ING-008](tickets/ING-008-amorcage-des-comptes.md) (PR #244) back · L · **xhigh** · Amorçage à l'activation (événement, cas d'usage, écouteur, interrupteur coupé), rattrapage (`repas:ingredients:seed`), script · dépend de : 011 · **R1** · *remplace l'ancien ING-008*
- [x] [ING-009](tickets/ING-009-passation-des-communs.md) (PR #245) back · L · **xhigh** · La passation : classement 151/110, amorçage du compte vide, préconditions, les 261 au propriétaire ; données, commande, script · dépend de : 011, 008 · **R1** (code livré ; **exécution en production : opérations humaines, `sans-commun.md` § 4 et § 14**, pas encore faite) · *remplace l'ancien ING-009*
- [x] [ING-013](tickets/ING-013-simplifier-visibilite-droits-resolveur.md) (PR #247) back · L · **xhigh** · Simplifier : visibilité « créateur = moi », droits, résolveur, copie de recette, fusion, doublons, conversions · dépend de : **passation exécutée et contrôlée** · **R2**
- [x] [ING-014](tickets/ING-014-retirer-le-code-mort.md) (PR #250 ; **la passation `hand-over-catalog` et le « ou vide » du catalogue restent, retrait à part**) back · M · **high** · Retirer le code mort, `NoSharedIngredientTest`, migration-garde, fixtures, documents · dépend de : 013 · **R2**
- [ ] [ING-015](tickets/ING-015-front-ingredient-de-base.md) **front** · M · **high** · L'interrupteur « Ingrédient de base » et sa pastille (propriétaire seulement) · dépend de : 011 en production

### Transverse

- [ ] [ING-010](tickets/ING-010-documentation-recette-exploitation.md) back · S · **medium** · `CLAUDE.md`, skills, `docs/production.md`, pas à pas d'exploitation, recette · se fait au fil des autres, se clôt après 015 *(réécrit)*

## Ordre de livraison et dépendances

```
Fait :  ING-001 ──► ING-002 ──► ING-003 (exécuté le 2026-10-20) ;  ING-004 ;  ING-005 ──► ING-006

Amendement du 2026-10-20 (sans ingrédient commun) :

  ING-011 (marqueur, propriétaire) ──► ING-012 (plus de nouveau commun)
       │
       └──► ING-008 (amorçage, rattrapage, interrupteur) ──► ING-009 (passation)
                                                               │
   ══ R1 : sortie du back (fonctionne avant ET après la passation) ═╪═══
                                                               ▼
   opérations (humain) : à blanc ► classement + amorçage ► interrupteur ► essai ► PASSATION ► contrôles
                                                               │
   ══ R2 : sortie du back, un autre jour ═════════════════════════╪═══
                                                               ▼
                              ING-013 (comportement) ──► ING-014 (code mort, garde-fous)

  ING-015 (front : « de base ») : après ING-011 en production ; ING-007 (front : fusion, doublons) : indépendant
  ING-010 (documentation, recette, exploitation) : en parallèle, clôturé après ING-015
```

**Règles d'ordre (ADR 0017)** :

1. **Le back d'abord, toujours** : aucun appel du front vers une route qui n'est pas en production ; ING-007 part après ING-005 et ING-006 **déployés**.
2. **ING-001 et ING-002 sont sans effet visible** tant qu'aucun alias n'existe : ils partent seuls dans une release, et le site tourne avec quelques heures avant ING-003. Le moindre changement visible à ce stade est un défaut.
3. **ING-003 n'est jamais exécuté le même jour qu'une autre release du back** ; il est lancé par l'humain (script, sauvegarde, `--check`).
4. **Chaque migration de schéma est additive** (colonne nullable, tables) : le retour arrière du code ne défait pas le schéma, l'ancien code l'ignore ([`migration.md`](migration.md) § 11).
5. ING-004 peut partir avant ou après ING-003 ; il est plus utile après.
6. **Amendement : R1 fonctionne sur la base d'avant et d'après la passation ; R2 seulement après** (migration-garde d'ING-014 : R2 refuse de démarrer tant qu'un ingrédient sans créateur existe). Les PR d'ING-013 et d'ING-014 ne se fusionnent **qu'après** le contrôle de la passation.
7. **La passation n'est jamais exécutée le jour d'une sortie du back** (R1 comme R2), ni avant que le compte vide et tout compte actif soient amorcés et l'interrupteur allumé (préconditions de la commande). Le détail, étape par étape : [`sans-commun.md`](sans-commun.md) § 4.

**Ce qui peut aller en parallèle** :

- le **matcher** d'ING-004 (domaine pur) commence dès maintenant, avant ING-001 ;
- ING-005 après ING-001, pendant ING-002 ;
- ING-010 et la rédaction de la recette, en continu ;
- **ING-011 puis ING-012** (mêmes fichiers : même agent) ; **ING-008 après ING-011** ; **ING-009 après ING-008** (sa phase 2 appelle l'amorçage ; son squelette, les données et le rapport, peut démarrer après ING-011) ; **ING-013 et ING-014** se préparent pendant ce temps mais restent des PR ouvertes ; **ING-015** (front) se développe dès que le contrat d'ING-011 est fusionné ;
- **Deux agents du back en même temps** touchent `migrations/`, `tests/Repas/WriteBudget/budgets.php` et `docs/api/openapi.json` : rebaser avant chaque PR, numéroter les migrations après la dernière de `main`. L'autre chantier du back (contexte `Drive`) fait de même : coordonner les migrations par date. **Un seul agent à la fois sur ING-001, 002, 003**, dans cet ordre.

## Jalons de contrôle

| Jalon | Preuve |
|---|---|
| Après ING-002 en production | `GET /api/v1/ingredients`, les rayons, la recherche, une recette, une liste et un plan d'envoi sont **identiques à avant** pour un compte de test (aucun alias) ; budgets SQL annoncés |
| Après ING-003 (à blanc) | rapport relu avec le propriétaire : périmètre, collisions, anomalies, listes `SENT` ; aucune anomalie bloquante |
| Après ING-003 (appliqué) | `--check` : zéro écart ; un compte concerné ouvre une recette, sa liste, l'écran des produits du drive : mêmes noms, quantités, produits ; un compte non concerné : rien de différent |
| Après R1 en production | rien ne change à l'écran pour aucun compte ; l'interrupteur d'amorçage est coupé ; `GET /api/v1/health` ; un ingrédient créé par l'administrateur n'est vu que par lui |
| Passation à blanc | rapport relu avec le propriétaire : comptes à amorcer (le compte vide), classement 151/110, préconditions P1 à P6, aucune anomalie bloquante |
| Après classement + amorçage | le compte vide voit ses ingrédients de base ; ses listes et choix du drive sont intacts ; aucun compte déjà migré n'a changé (somme de contrôle) |
| Après l'interrupteur allumé | un compte d'essai confirmé a ses ~150 ingrédients et leurs conversions ; sa suppression efface tout |
| Après la passation | contrôles SQL « après » de `sans-commun.md` § 5 : zéro sans créateur, 261 chez le propriétaire (sommes de contrôle identiques), invariant « toute ligne désigne un ingrédient de son compte » à zéro, listes envoyées et versions inchangées ; l'administrateur voit les mêmes ingrédients qu'avant |
| Après R2 | migration-garde passée ; l'administrateur ne modifie plus les ingrédients des autres ; budgets SQL en baisse |
| Après ING-007 et ING-015 | recette signée (`recette.md`, écrite par ING-010) |

## Risques et inconnues

| # | Risque / inconnue | Gravité | Réponse |
|---|---|---|---|
| R1 | Une écriture oublie de résoudre le slug d'un commun adopté : une ligne retombe sur le commun masqué | haute | `IngredientSlugWritesAreResolvedTest` (ING-002) ; `--check` d'ING-003 et requête de santé d'ING-008 ; un deuxième `--apply` rattrape |
| R2 | Le repointage d'une recette sans celui de ses lignes de liste fausse les quantités au retrait d'un repas ([`analyse.md`](analyse.md) § 4) | haute | une transaction par utilisateur, empreintes avant/après (ING-003) |
| R3 | Un choix du drive perd ses produits mémorisés si le slug d'une liste `SENT` ne suit pas | haute | les listes `SENT` sont repointées (exception justifiée, [`migration.md`](migration.md) § 4) ; test d'équivalence du plan |
| R4 | Un envoi du drive en cours sur le téléphone pendant la migration (slugs périmés dans le brouillon) | moyenne | arrêt du lancement sans `--accept-sent` ; écritures du drive qui tolèrent l'ancien slug (ING-002 C) |
| R5 | Un faux rapprochement fusionne en silence deux produits | haute | seuil 0,97 + garde-fou « mot à mot » : 0 faux lien sur toute la vérité terrain ([`rapprochement.md`](rapprochement.md)) ; en cas de doute, copie |
| R6 | La fusion casse une liste `SENT` ou recrée une ligne sur l'ingrédient supprimé (écriture concurrente) | haute | refus si `SENT` ; verrou des deux lignes ; versions des recettes et listes (409/412) |
| R7 | Les copies par compte multiplient les conversions de l'écran d'administration | basse | ING-013 limite l'écran de l'administrateur à ses ingrédients et aux conversions générales (D4) |
| R8 | Les nouvelles règles font monter des budgets SQL | basse | annoncé dans chaque PR, ADR 0010 règle 12 |
| R9 | Retour arrière du code après la migration : copies visibles en double, alias orphelins à la suppression d'un compte | basse | acceptée et documentée ([`migration.md`](migration.md) § 11) ; requête de nettoyage |
| R10 | Deux chantiers du back écrivent des migrations en même temps | moyenne | rebaser ; une migration par PR ; relire `main` avant de nommer |
| I1 | Nombre de comptes administrateurs : le propriétaire du catalogue est le compte protégé, un seul | à relever | relevé R1 de [`sans-commun.md`](sans-commun.md) § 12 |
| I2 | Références orphelines ou croisées héritées de données anciennes | à relever | requêtes 2 et 3 |
| I3 | Listes `SENT` existantes à la date du lancement | à relever | requête 1 |
| I4 | *(levé par l'amendement du 2026-10-20 : tout compte est amorcé à l'activation, plus d'adoption à la première utilisation)* | — | ADR 0028 amendement |
| S1 à S10 | **Risques propres à l'amendement** : compte aveugle après la passation, R2 sorti trop tôt, ligne restée sur un ingrédient sans créateur, chemin d'activation sans événement, amorçage en échec, classement faux, robinet rouvert, statistiques, second propriétaire, migrations concurrentes | haute à basse | table complète et réponses : [`sans-commun.md`](sans-commun.md) § 12 |
| I5 | Le compte vide a-t-il des lignes sur des ingrédients sans créateur ? Comptes en attente ou désactivés avec des lignes ? | à relever | relevés R2 à R4 de [`sans-commun.md`](sans-commun.md) § 12 ; la règle « de base plus ce que ses données référencent » couvre tous les cas |

## Hypothèses à contrôler en commençant

1. PostgreSQL 16 en production (`deploy/compose.yaml`) : `gen_random_uuid()` existe, mais les identifiants viennent de `UuidGenerator` (aucune dépendance).
2. La table `user` a bien une colonne `roles` lisible en SQL (`roles::text LIKE '%ROLE_ADMIN%'`) : vérifié dans l'entité, à confirmer sur la base de développement avant d'écrire le Loader.
3. Aucun autre code que ceux de [`analyse.md`](analyse.md) ne lit `ingredient.creator` pour décider de la visibilité (`grep -rn "creatorId" src/` au démarrage d'ING-002).
