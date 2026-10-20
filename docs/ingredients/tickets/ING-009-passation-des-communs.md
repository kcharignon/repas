# ING-009 — La passation : les 261 anciens communs deviennent les ingrédients de l'administrateur

- **Dépôt** : back (+ données et script d'exploitation)
- **Jalon** : I6 Plus d'ingrédient commun
- **Taille** : L
- **Effort recommandé pour l'agent** : **xhigh** (réécrit le propriétaire de tout le catalogue en production, préconditions de visibilité de tous les comptes)
- **Dépend de** : ING-011 (marqueur, propriétaire du catalogue) et ING-008 (le rattrapage que la commande appelle)
- **Statut** : à faire — **remplace** l'ancien ING-009 (« les communs deviennent les ingrédients de l'administrateur », plus tard), qui supposait une adoption préalable de tous par la première utilisation
- **Sortie** : **R1** ; l'**exécution** en production est une étape humaine (`sans-commun.md` § 4, étapes 6 à 12), jamais celle de l'agent
- **Lire d'abord** : [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) amendement du 2026-10-20 (décisions 1, 4, 6, 7, 9) ; **[`sans-commun.md`](../sans-commun.md) en entier** (surtout §§ 2, 4 à 7) ; [`base-ingredients.tsv`](../base-ingredients.tsv) (le classement validé) ; ING-003 et sa section « Réalisation » (le style de la commande, du rapport, du script, des tests) ; ADR 0017 règle 6, 0021 ; skills `/repas-dev`, `/repas-ops`.

## Contexte

Le propriétaire a demandé que les ingrédients communs soient ceux de l'administrateur (« plus de traitement particulier ») et que seuls ceux qu'il juge **de base** partent chez les futurs comptes. Le classement est fait et validé : 261 anciens communs, **151 de base, 110 spécifiques**. Ce ticket livre la **commande de passation** et ses données : elle pose le marqueur d'après le fichier, amorce les comptes actifs qui n'ont rien (le compte vide), contrôle les préconditions, passe les 261 au propriétaire, vérifie. C'est une **écriture sur la production** : tout se prouve avant (à blanc), pendant (empreintes, une transaction) et après (contrôles SQL).

## Portée

1. **Les données du changement.** `src/Repas/Infrastructure/Data/base-ingredients.json` : les 261 lignes de `docs/ingredients/base-ingredients.tsv` (`slug`, `name`, `department`, `base`), même ordre. `docs/` n'est pas dans l'image (`.dockerignore`), d'où la copie sous `src/` ; le TSV reste l'enregistrement validé. Port `Domain/Interface/BaseIngredientClassification::all(): array<string, bool>` (slug → de base), implémentation lisant le JSON, double de test. `BaseIngredientsDataTest` : 261 entrées, slugs uniques, 151 vrais et 110 faux, sommes de contrôle `a43e401aaf59b6e65bd1f433f7347c7b` (tous les slugs) et `3776a0ad5d1fbc427f9431656c14b7e2` (ceux de base), JSON et TSV identiques.
2. **Cas d'usage** (`Application/Command/…`, transactionnels par le bus, **aucun événement sauf § 5**) :
   - `ClassifyBaseIngredients` (phase 1) : pose `is_base` sur les ingrédients sans créateur d'après le port ; **refuse** (`IngredientCatalogException::classificationMismatch`, message sans identifiant) si l'ensemble des slugs sans créateur n'est pas exactement celui du fichier (un commun inconnu ou manquant : il faut le classer d'abord). Idempotent.
   - `HandOverCatalog` (phase 4) : verrou consultatif `pg_advisory_xact_lock(hashtext('catalog-handover'))` ; **préconditions P1 à P6 relues dans la transaction** (`sans-commun.md` § 6) ; empreinte d'avant ; `UPDATE ingredient SET creator = :owner WHERE creator IS NULL AND slug IN (<fichier>)` ; empreinte d'après (tout sauf `creator`) comparée ; si des lignes ont changé, annonce `RecipesOrIngredientsCreatedEvent(owner, <les slugs passés>, [])` (le compteur « ingrédients créés » du propriétaire reflète ses 261 ; **une seule fois**, un rejeu ne change aucune ligne et n'annonce rien). Port `Domain/Interface/IngredientCatalogHandoverRepository` (`lock`, `catalogueSlugsWithoutCreator`, `classify`, `assignToOwner`, `fingerprint`), implémentation SQL, lots, nombre de requêtes **constant**.
   - L'amorçage (phase 2) est **ING-008** : la commande console dispatche `SeedUserIngredientsCommand` pour chaque compte actif non amorcé, hors propriétaire, `source = rattrapage-AAAA-MM-JJ`, `acceptSent = true`.
3. **Lecture à blanc** : `GetCatalogHandoverReport` (Query + QueryHandler + Loader `CatalogHandoverReportLoader`, lecture seule, **aucun verrou**) : le propriétaire (référence courte, statut, administrateur), le catalogue (nombre sans créateur, accord avec le fichier, classement posé, sommes de contrôle), les comptes à amorcer (référence, statut, ingrédients, ce qui serait copié et repointé, listes envoyées), les préconditions évaluables sans écrire, et le verdict « prêt » / « pas prêt » avec les raisons. Jamais d'adresse, d'identifiant ni de nom d'ingrédient personnel (les comptes sont numérotés ; les noms des anciens communs sont publics mais on n'affiche que des nombres).
4. **Commande de console** `php bin/console repas:ingredients:hand-over-catalog` (`src/Command/`, boucle et affichage seulement) : sans option, **à blanc** ; `--check` (lecture seule, « Rien à faire : passation faite et conforme » ou ce qui reste ; code de sortie 0 seulement si tout est conforme) ; `--apply` (phases 1 à 5 de `sans-commun.md` § 6, **au premier échec elle s'arrête**) ; `--apply --stop-before-handover` (phases 1 et 2 seulement) ; `--accept-sent` (listes envoyées d'un compte rattrapé) ; `--source=<nom>`. Refuse `--apply` hors des environnements `prod` et `dev` (`repas.adoption.environments`, comme ING-003). Rejouable à tout moment : rien à faire = rien d'écrit, versions comprises.
5. **Script d'exploitation** `.claude/skills/repas-ops/references/passer-communs-a-l-administrateur.sh`, modèle `adopter-ingredients.sh` : sans argument, lecture seule (le rapport à blanc) ; `--appliquer` : le rapport à blanc (s'arrête s'il dit « pas prêt »), **`./backup.sh avant-passation-communs`**, `--apply`, `--check` ; `--jusqu-a-l-amorcage` (ajoute `--stop-before-handover`) ; `--verifier` ; `--accepter-listes-envoyees`. Lancé par l'humain (`!`), jamais par un agent. Essayé sur une copie par `PasserCommunsScriptTest` (`REPAS_DIR`).
6. **Documentation d'exploitation minimale** : `docs/production.md` section « Passation des ingrédients communs » (ce que c'est, quand, qui, tableau des commandes, retour arrière → `sans-commun.md` § 5). Le pas à pas guidé, la recette et le skill sont ING-010.
7. **Statistiques** : un seul recalcul, par l'événement du § 2 ; ne pas appeler `repas:update-users-statistics` (il passe tous les comptes).

## Hors portée

L'exécution en production ; le nettoyage de la visibilité, du résolveur, des droits (ING-013, ING-014) ; le front ; les ingrédients du propriétaire autres que les 261 ; supprimer ou modifier quoi que ce soit d'autre (`INSERT`, `DELETE`, lignes de recettes, listes, choix, versions, alias, conversions : **zéro** dans la phase 4).

## Spécification technique

**Phases** (`sans-commun.md` § 6) : 1 classer ; 2 amorcer ; 3 contrôler ; 4 passer ; 5 vérifier. **Préconditions** (relues dans la transaction de la phase 4, la commande dit laquelle manque et n'écrit rien) :

| | |
|---|---|
| P1 | le propriétaire est résolu par `IngredientCatalogOwner`, `ACTIF`, administrateur, protégé |
| P2 | aucune ligne (recette par auteur, liste et ingrédient de liste par propriétaire, choix du drive par `Shared`) d'un compte **autre que le propriétaire**, quel que soit son statut, ne référence un ingrédient sans créateur |
| P3 | tout compte `ACTIF` autre que le propriétaire est amorcé (`isAdopter`) et a au moins un ingrédient |
| P4 | `IngredientSeedingFeature::isEnabled()` est vrai (la commande tourne dans le conteneur qui lit la variable) |
| P5 | les slugs sans créateur sont exactement ceux du fichier ; aucun n'a d'origine ; aucun ne finit par l'identifiant d'un compte (la garantie du retour arrière, `sans-commun.md` § 5) |
| P6 | aucune référence orpheline (`recipe_row`, `shopping_list_row`, `shopping_list_ingredient`, `drive_choice`, `conversion`) |

**Empreinte des 261** : somme de contrôle de `(slug, name, slugged_name, image, department, default_cooking_unit, default_purchase_unit, compatible_units, adopted_from)` triée par slug, plus le nombre de conversions propres de chacun ; **identique avant et après** (seuls `creator` et, en phase 1, `is_base` changent). Les tables de lignes (recettes, listes, choix) ont les mêmes nombres, les mêmes sommes de quantités, les mêmes versions et, pour les listes, les mêmes statut et date d'envoi : calculés avant et après par `AdoptionSnapshotReader`.

**Contrôles SQL** : les blocs de `sans-commun.md` § 5 (`passation-controles-avant-sql`, `passation-controles-apres-sql`, `passation-retour-arriere-sql`) sont **ceux que le test exécute** (`CatalogHandoverDocumentedSqlTest`, sur le modèle d'`AdoptionDocumentedSqlTest` : lire le document, `preg_match` sur le marqueur, remplacer `:'owner'`, exécuter sur la base de test, comparer au résultat de la commande, et jouer le retour arrière puis rejouer la passation).

## Critères d'acceptation

(Jeu de test : un propriétaire avec une recette, une liste envoyée au drive et un choix du drive sur trois anciens communs ; deux comptes migrés ; un compte vide actif ; un compte en attente ; cinq communs classés par un double du port.)

- **À blanc.** Aucun `INSERT`, `UPDATE` ni `DELETE` n'est émis (capture) ; le rapport dit ce qui serait fait et si l'on est « prêt » ; code de sortie 0, ou 1 si une anomalie bloquante interdirait d'écrire.
- **Phase 1.** Les communs reçoivent le marqueur d'après le port (nombre de vrais et de faux exact) ; un commun hors fichier ou un commun du fichier absent : refus, rien n'est écrit.
- **Phase 2.** Le compte vide reçoit les ingrédients de base **et** ceux que ses lignes référencent, ses lignes sont repointées, empreintes identiques ; les comptes migrés et le propriétaire ne reçoivent rien (somme de contrôle de toutes les tables, versions comprises) ; le compte en attente n'est pas touché.
- **Préconditions.** Pour chacune de P1 à P6, un test où elle manque : `--apply` s'arrête avant la phase 4, **aucun** ingrédient n'a changé de créateur. En particulier : un compte actif non amorcé (P3), une ligne d'un autre compte sur un ingrédient sans créateur (P2), l'interrupteur coupé (P4).
- **Phase 4.** Les cinq anciens communs ont `creator` = le propriétaire ; **rien d'autre** n'a changé (somme de contrôle de toutes les tables hors `ingredient.creator`, versions des recettes et des listes comprises ; la liste envoyée garde statut et date d'envoi) ; le plan d'envoi et l'estimation du drive du propriétaire sont **identiques** (`GetDrivePlan`, `GetDriveEstimate`) ; le compteur « ingrédients créés » du propriétaire a augmenté de cinq, une fois.
- **Idempotence.** Un second `--apply` n'écrit aucune ligne et n'annonce rien ; `--check` répond « Rien à faire » (code 0).
- **Atomicité.** Une erreur injectée dans la phase 4 (déclencheur PostgreSQL) annule `UPDATE` et annonce ; un échec de la phase 2 pour un compte arrête la commande, les comptes déjà amorcés le restent.
- **Concurrence.** Une activation qui amorce un compte pendant la phase 4 réussit (la règle d'amorçage lit les deux formes) ; un compte activé entre P3 et la fin et non amorcé (interrupteur coupé) est détecté par la vérification de la phase 5.
- **Retour arrière.** Le SQL documenté, joué sur la base passée, remet « sans créateur » les seuls anciens communs (pas un ingrédient créé après), puis une nouvelle passation rétablit l'état.
- **Compatibilité** (ADR 0017 règle 6) : aucune migration dans ce ticket ; sur la base passée, le code **d'avant ce ticket** (image précédente, `deploy-smoke`) lit recettes, listes, plan d'envoi et ingrédients de l'administrateur et des comptes migrés.
- **Jeu de données de production** (reproduit en test) : 261 communs, 151 de base ; les sommes de contrôle du fichier sont celles du document.
- **Budget** : `passation-communs` (phase 4) figé, constant en nombre d'ingrédients.

## Tests exigés

Handlers (en mémoire) ; **base réelle** pour le dépôt de passation et le chargeur de rapport avec des données de tous les types (listes `ACTIVE`, `PAUSED`, `SENT`, `COMPLETED`, choix du drive de plusieurs enseignes, collisions, conversions propres et générales, comptes de tous statuts) ; console (`CommandTester` : tous les modes et refus, environnement) ; `CatalogHandoverDocumentedSqlTest` ; équivalence du drive ; `BaseIngredientsDataTest` ; script (`REPAS_DIR`) ; `AdoptionAfterMergeTest` et la suite d'ING-003 continuent de passer (la commande d'ING-003 reste, intacte) ; `UserOwnedDataCoverageTest` inchangé ; `make test quality`.

## Definition of Done

`repas-dev` § 4 ; roadmap cochée ; `docs/production.md` ; PR « À relire » : le rapport à blanc d'un jeu reproduisant la production (nombres seulement), les requêtes SQL émises par phase, le temps mesuré. **L'agent n'exécute jamais le script ni la commande en production.**

## Pièges

- **L'ordre des phases est tout** : 1, 2, puis 3 et 4 dans la transaction. Une phase 4 qui précède l'amorçage rend le compte vide aveugle.
- Après un `UPDATE` en masse, vider l'identity map de Doctrine (`clear()`) avant toute relecture ; ne charger aucun agrégat dans la commande.
- **Le moteur de la phase 2 est celui d'ING-008** : ne pas en écrire un troisième.
- Ne pas modifier `recipe.version` ni `shopping_list.version` : rien n'est repointé pour le propriétaire.
- Ne pas afficher l'adresse du propriétaire ni un identifiant : la référence courte (8 caractères du SHA-256 de l'identifiant), comme ING-003. Dans les tests, une adresse de test, jamais celle de `User::PROTECTED_EMAIL`.
- Le fichier de classement est une **donnée de production validée** : ne jamais « corriger » un classement dans ce ticket ; une demande de changement passe par le propriétaire, puis le TSV et le JSON ensemble.
- La commande ne crée ni ne supprime aucun ingrédient : si un test le voit faire, c'est un défaut.
- Une sortie du back le jour de la passation est interdite (règle d'ING-003) ; la commande ne le sait pas, le script et le guide le disent.
