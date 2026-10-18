# DRV-613 — Durcissement des fiches de produits partagées et plafonds

- **Dépôt** : back (partie 1) puis front `drive-agent/` (partie 2)
- **Jalon** : M2c Durcissement (revue de sécurité du back, constat C1, C2, C3)
- **Taille** : M
- **Dépend de** : DRV-204, DRV-305, DRV-306 (faits)
- **Statut** : fait (partie 1 back : PR #166 ; partie 2 agent : PR front #116, 2026-10-18)
- **Lire d'abord** : [`../revue-securite-back.md`](../revue-securite-back.md) (C1, C2, C3), [`DRV-204`](DRV-204-produits-et-choix.md), [`DRV-305`](DRV-305-orchestrateur-phase-a.md), [`DRV-306`](DRV-306-choix-guide-phase-b.md), skills `repas-drive-back` / `repas-agent-dev`, `docs/drive/consignes-agents.md`.

## Contexte

Les fiches `drive_product` sont publiques et communes à tous les utilisateurs du même magasin : n'importe quel utilisateur peut réécrire le libellé, le contenu et le prix du produit d'un autre (constat C1 : un contenu falsifié change le nombre de produits du plan d'un autre utilisateur). Les inscriptions sont ouvertes. Décision de l'assistant (2026-10-18) : défense en profondeur plutôt qu'une migration lourde « données par utilisateur ».

## Portée — partie 1 (back)

1. **Premier écrivain gagnant pour l'identité du produit** : une écriture venant d'un `ADD_PRODUCT` ou d'un `REJECT_PRODUCT` ne réécrit **ni le libellé, ni le contenu, ni le code-barres** d'une fiche déjà connue ; elle ne remplit que les champs encore vides. Le prix et sa date ne se mettent à jour que par `REFRESH_PRICE` et par l'`ADD_PRODUCT` de l'utilisateur qui accepte le produit (déjà accepté par lui). Tests : un second utilisateur n'altère pas la fiche du premier (preuve du constat C1 rejouée, rouge avant correction).
2. **Plafonds par utilisateur** (constat C2) : au plus 1 000 préférences (ingrédients) par utilisateur et par magasin, 5 000 écritures de produits par jour et par utilisateur ; au-delà `422 DRIVE_LIMIT_REACHED` ; les valeurs sont des constantes documentées ; tests.
3. **`reopen` permis interrupteur coupé** (constat C3) : `POST /shopping-lists/{id}/reopen` reste disponible même si `DRIVE_ENABLED` est faux (une liste figée par un retour arrière peut être rouverte) ; `send` reste derrière l'interrupteur ; mettre à jour l'ADR 0023 (paragraphe sur le déploiement), `docs/production.md`, `docs/drive/runbook.md`, le skill `repas-ops` (`references/drive.md`) et `architecture.md` ; tests.
4. `docs/api/openapi.json` régénéré, budgets, `make test` et `make quality` verts.

## Portée — partie 2 (front, `drive-agent/`)

5. **Le contenu en direct fait foi** : en phase A, l'agent relit le produit sur le site (il le fait déjà pour le stock) et **recalcule le nombre de produits** à partir du contenu lu en direct et de `needed` (comme en phase B, `packsFor`) quand les deux sont disponibles ; si le nombre diffère de celui du plan, il utilise le calcul en direct et le signale dans le récapitulatif (« contenu différent de celui mémorisé »). Sans `needed`, comportement actuel. Tests (jsdom, faux site), `make agent-verify`, `make android-agent-hash`.

## Hors portée

Données de produits par utilisateur (migration), activation des comptes par un administrateur (décision de l'utilisateur).

## Critères d'acceptation

- Un second utilisateur qui renvoie l'identifiant d'un produit connu avec un autre libellé, un autre contenu ou un autre prix ne modifie ni la fiche ni le plan du premier.
- Au-delà des plafonds, `422 DRIVE_LIMIT_REACHED`.
- `reopen` répond `204` interrupteur coupé pour une liste `SENT`.
- Avec un contenu différent en direct, l'agent met la quantité calculée sur le contenu réel.

## Definition of Done

Celle du dépôt concerné ; PR concises ; fusion si tous les contrôles de la CI sont verts.

## Réalisation partie 1 (back, 2026-10-18, PR repas#166)

- **Premier écrivain gagnant (C1)** : `Product::see()` devient `Product::complete(ean, label, content, price, now)` : ne remplit que les champs vides (code-barres, contenu, prix), le libellé d'une fiche connue ne change jamais, `lastSeenAt` avance ; les valeurs reçues restent **contrôlées** (`422 DRIVE_CHOICE_INVALID`) même quand elles sont ignorées. Dans `SaveProductChoicesHandler`, le prix d'une fiche connue ne change que par `REFRESH_PRICE` (déjà limité aux produits que l'utilisateur accepte pour l'ingrédient) et par l'`ADD_PRODUCT` d'un produit que l'utilisateur **accepte déjà** pour cet ingrédient (`Product::seePrice()`) ; un `ADD_PRODUCT` d'un produit encore inconnu de lui laisse le prix de la fiche, un `REJECT_PRODUCT` n'y touche jamais. L'upsert SQL (`ProductPostgreSQLRepository::saveAll`) applique la même règle jusque dans la base (`COALESCE` pour le code-barres et le contenu, libellé jamais réécrit) : deux requêtes qui créent la même fiche en même temps ne s'écrasent pas. **Preuve de C1 rejouée** (`DrivePlanApiFlowTest::testASecondUserCannotRewriteTheProductOfTheFirst`, deux cas `ADD_PRODUCT` / `REJECT_PRODUCT`, vraie base et vrais bus) : rouge avant la correction (le plan d'Alice passait à `packs: 10` avec le libellé de Mallory), verte après (libellé, code-barres, contenu, `packs: 1` et estimation à 150 centimes inchangés).
- **Plafonds (C2)** : `422 DRIVE_LIMIT_REACHED` (`DriveException::limitReached()`). `Choice::MAX_PER_USER_AND_STORE` = **1 000 préférences** par utilisateur et par magasin (`ChoiceRepository::countByUserAndStore()`, lu seulement quand un lot `PUT /drive/choices` ou un `PUT /drive/choices/{slug}` **crée** une préférence ; modifier ou vider une préférence existante reste permis au plafond). `DriveSettings::MAX_PRODUCT_WRITES_PER_DAY` = **5 000 écritures de produits** (intentions `ADD_PRODUCT`, `REJECT_PRODUCT`, `REFRESH_PRICE`) par jour **UTC** et par utilisateur, comptées dans `drive_settings` (colonnes `product_writes_day` DATE nullable et `product_writes_count` INT par défaut 0, migration additive `Version20261018150000`, compatible avec le code précédent ; un changement de magasin ne les remet pas à zéro ; elles disparaissent avec le compte, donc aucune ligne de plus à purger). Un lot sans produit (`HAVE_AT_HOME`) ne compte pas. Un lot qui dépasserait le plafond est refusé en entier, rien n'est enregistré. Les réponses `422` des deux contrôleurs sont documentées (`openapi.json` régénéré, textes seulement).
- **Budgets SQL relevés, en connaissance de cause** : `choix-drive` 7 → **8** (la mise à jour du compteur du jour dans les réglages, déjà lus), `choix-drive-lot` 7 → **9** (le compteur, et le décompte des préférences parce que le lot en crée une), nouveau scénario `creer-preference-drive` = **7** (`remplacer-preference-drive` reste à 6 : le décompte n'est lu que lors d'une création). Ils restent constants quelle que soit la taille du lot.
- **`reopen` interrupteur coupé (C3)** : `#[RequiresDrive]` retiré de `ReopenShoppingListApiController` seul (sécurité `ROLE_USER` et voter propriétaire conservés) ; `send` et les routes de `Drive` restent derrière l'interrupteur. `DriveRoutesAreGuardedBySwitchTest` a maintenant une liste d'exceptions explicite et commentée (`ALWAYS_ANSWERING` : `api_get_drive_settings`, `api_reopen_shopping_list`) et échoue aussi si une exception reçoit l'attribut (mutation vérifiée). Documents mis à jour : ADR 0023 §9 (amendement), `docs/production.md`, `docs/drive/runbook.md`, `.claude/skills/repas-ops/references/drive.md`, `docs/drive/architecture.md`, le skill `repas-drive-back`, la note de vie privée (une phrase sur le premier écrivain).
- **Tests** : `ProductTest` (premier écrivain, champs vides, valeurs ignorées mais contrôlées), `SaveProductChoicesHandlerTest` (second utilisateur `ADD`/`REJECT`, prix par l'acceptant, plafonds du jour et des préférences, remise à zéro le lendemain UTC, décompte lu seulement à la création), `ReplaceIngredientPreferenceHandlerTest`, `DriveSettingsTest`, `ProductRepositoryTest` (le second écrivain concurrent ne réécrit ni libellé, ni code-barres, ni contenu), `ChoiceRepositoryTest` (décompte en une requête), `DriveSettingsRepositoryTest` (compteur relu, conservé par un changement de magasin), `DriveChoicesApiFlowTest` (`422 DRIVE_LIMIT_REACHED` de bout en bout, lecture et oubli encore permis), `ReopenShoppingListApiControllerTest` et `ShoppingListWriteApiFlowTest` (`204` interrupteur coupé pour une liste `SENT`, `send` en `404 DRIVE_DISABLED`, voter et `401` conservés).
- **Écarts et limites assumées** : le prix d'une fiche reste modifiable par quiconque **accepte** le produit (`REFRESH_PRICE` après un premier `ADD_PRODUCT`, deux requêtes) : seules des données par utilisateur (hors portée) le protégeraient entièrement ; un code-barres vide peut être rempli par le premier qui en lit un ; `last_seen_at` avance toujours quand un autre revoit le produit (inutilisé, constat C4). Pas de limiteur de débit sur `PUT /drive/*` (la recommandation de C2 ; seuls les plafonds par utilisateur sont demandés ici). La partie 2 (front, `drive-agent/`) reste à faire : l'agent doit recalculer le nombre de produits sur le contenu lu en direct.
- **Pour la partie 2 (front)** : aucun champ de l'API ne change ; `PUT /drive/choices` et `PUT /drive/choices/{slug}` peuvent répondre `422` avec le message `DRIVE_LIMIT_REACHED` (à traiter comme une erreur affichable, distincte de `DRIVE_CHOICE_INVALID`) ; `POST /shopping-lists/{id}/reopen` répond désormais interrupteur coupé (le front peut proposer « Rouvrir » même quand `GET /drive/settings` renvoie `enabled: false`) ; le contenu d'une fiche déjà connue ne se met plus à jour par l'agent : l'agent ne peut compter que sur le contenu qu'il lit en direct.

## Réalisation partie 2 (agent, 2026-10-18, PR repas-front#116)

- **Phase A** (`drive-agent/src/flow/phase-a.ts`, `settleKnownLine`) : l'agent recalcule le nombre de produits sur le contenu lu en direct, avec `packsFor` (fonction de la phase B) et `needed` du plan. La recherche qui donne le stock donne aussi le contenu : aucune requête de plus. S'il diffère de celui du plan, le nombre en direct part au panier, sans question.
- **Cas inchangés** : sans `needed`, sans contenu lisible, avec un nombre du plan « à vérifier » (question à l'utilisateur comme avant), ou calcul non sûr (autre dimension que la quantité demandée) : comportement d'avant.
- **Résultat** : `ADDED.quantity` est toujours ce que le panier du site a gardé (réponse vérifiée), jamais le contenu mémorisé du plan.
- **Récapitulatif de fin** : « Contenu différent de celui mémorisé », avec « Farine : 10 au lieu de 1 » par ligne concernée ; la mention survit à un rechargement (état de reprise `changes`, validé). Le résultat envoyé à Repas ne change pas.
- **Tests** : unitaires (dont deux sur la vraie page de recherche en fixture) et trois cas e2e contre le faux site (contenu différent avec mention, même nombre sans mention, contenu illisible ou d'une autre dimension avec repli) ; le faux site gagne un 31e produit (contenu illisible). Agent 0.8.0, `android/agent.js.sha384` mis à jour.
- **Limites assumées** : un nombre du plan « à vérifier » reste une question à l'utilisateur même si le contenu en direct est lisible ; un contenu en direct d'une autre dimension garde le plan sans question ; `refusedOf` renvoie encore le contenu mémorisé d'un produit refusé (le back l'ignore pour une fiche connue).

## Suites liées (2026-10-18)

- PR repas-front#120 (agent 0.8.2) : l'agent retire des libellés les caractères que le back refuse (voir la revue de sécurité du back, « Suite côté agent »).
- PR repas-front#121 : sur « Reprendre l'envoi », interrupteur coupé (`404 DRIVE_DISABLED`), l'écran propose « Rouvrir la liste » (`reopen` répond 204 interrupteur coupé).
