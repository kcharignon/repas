# Revue de sécurité et de qualité du back Drive

Revue indépendante du back du chantier « envoyer la liste de courses au drive », faite le **2026-10-18** sur le code fusionné jusqu'à la PR #156 (`8cf4ce5`, `main`). Elle cherche de vrais défauts, les prouve, corrige ceux qui sont confirmés et laisse à l'utilisateur les décisions de conception. Aucune donnée réelle, aucun secret, aucun contact avec Leclerc, aucun déploiement.

## En deux phrases

Le back tient sa promesse sur l'essentiel : chaque route est derrière l'interrupteur et la sécurité, chaque donnée est filtrée par l'utilisateur du jeton, le verrou `SENT` n'a pas de trou, les migrations sont additives et l'interrupteur est coupé partout par défaut. **Trois défauts ont été corrigés** (PR #158, #159, #160), dont un qui contournait un contrôle de la préférence d'un ingrédient ; **un risque de conception reste à décider** : les fiches de produits sont partagées et n'importe quel utilisateur inscrit peut en changer le nom, le contenu et le prix pour les autres (voir C1).

## Périmètre relu

- `src/Drive/` en entier : domaine (`Retailer`, `Store`, `Product`, `Choice`, `PackCalculator`, `ListCostEstimator`), 5 cas d'écriture et 4 lectures, 8 contrôleurs et leurs corps, 4 loaders et 3 dépôts (SQL direct compris), purge du compte.
- `src/Shared/` : `#[RequiresDrive]`, `DriveEnabledListener`, `EnvDriveFeature`, ports et DTO `ShoppingListForDrive`, `IngredientNameLoader`, `ApiExceptionListener`.
- Verrou `SENT` dans `src/Repas/` : l'agrégat `ShoppingList` et les 16 cas d'usage qui touchent une liste, leurs écouteurs, `send`, `reopen`, `OwnerVoter`.
- Migrations des unités, de `sent_at`, de `drive_settings` et des trois tables `drive_*` ; `config/` (sécurité, services, routes, messagerie, limiteurs) ; `deploy/` (`compose.yaml`, `.env.example`, `smoke-test.sh`, `Caddyfile`), `.env*`, `phpunit.dist.xml`, `docs/api/openapi.json`, les deux scripts d'exploitation (`ecrire-drive-enabled.sh`, `rouvrir-listes-envoyees.sh`) et la note de vie privée.
- Hors périmètre : le front, l'agent, l'application Android (dépôt front), et tout ce que Leclerc fait.

## Méthode

1. Lecture du code contre `consignes-agents.md`, `architecture.md` (§ 4, 5, 7, 10), les ADR 0022 et 0023 et `analyse.md` (D13 à D28).
2. Sondes jetables (tests PHPUnit non conservés) qui appellent les **vraies routes avec un vrai jeton** : environ 140 entrées hostiles (JSON mal formé, mauvais types, bornes, caractères de contrôle et invisibles, adresses de magasin piégées, objets à la place de listes, administrateur, deux utilisateurs qui se visent), et recherche de toute réponse 5xx.
3. Pour chaque défaut : un test qui échoue d'abord, la correction minimale, `make test` et `make quality` verts, une PR, la CI entièrement verte avant la fusion.
4. Contrôles de cohérence : `doctrine:schema:validate` (migrations contre mapping), mutation du garde-fou de l'interrupteur (retirer `#[RequiresDrive]` d'un contrôleur fait échouer le nouveau test), relecture du contrat OpenAPI et de la baseline PHPStan.

Non fait : charge, vraie concurrence entre deux connexions (les tests partagent une connexion et une transaction), revue du front et de l'agent.

## Défauts corrigés

| # | Gravité | Défaut | Preuve | Correctif |
|---|---|---|---|---|
| D1 | Moyenne | `PUT /drive/choices/{ingredientSlug}` : avec `accepted` et `rejected` envoyés en **objets JSON** (`{"a": "<id>"}`) au lieu de listes, `[...$accepted, ...$rejected]` laissait la clé d'une liste écraser celle de l'autre. Le même produit pouvait être déclaré accepté **et** refusé (la clé primaire de `drive_choice_product` répondait alors `500`), et un produit « masqué » par l'écrasement échappait au contrôle « produit connu et de ce magasin » (un identifiant inconnu ou d'un autre magasin se rangeait dans les préférences). | 3 cas de handler et 1 parcours de bout en bout rouges (`500` au lieu de `422`), verts après. | #158 : le handler normalise les deux listes avec `array_values()` avant tout contrôle. |
| D2 | Basse | Le libellé d'un produit (fiche **partagée** entre utilisateurs) refusait les caractères de contrôle mais acceptait les séparateurs de ligne et de paragraphe (U+2028, U+2029), les inversions et marques du sens de lecture (U+202A à U+202E, U+2066 à U+2069, U+200E, U+200F), les espaces de largeur nulle (U+200B à U+200D, U+2060, U+FEFF) et un libellé fait d'une seule espace insécable : un texte qui ne se lit pas comme il est écrit, ou un libellé invisible, servi aux autres. | 11 cas de domaine rouges, verts après (un cas HTTP ajouté en plus) ; un libellé réel (accents, apostrophe courbe, tiret demi-cadratin, œ, ×, %, emoji) reste accepté. | #159 : ces caractères et les libellés faits d'espaces Unicode sont refusés (`422 DRIVE_CHOICE_INVALID`) ; description OpenAPI complétée (une ligne). |
| D3 | Basse | Une liste envoyée puis terminée (`SENT` → `COMPLETED`) garde `sent_at` ; si l'utilisateur décoche une ligne (ou active la liste), elle repasse `ACTIVE` en gardant la date : l'API répondait `status: ACTIVE`, `isSent: false` et `sentAt` renseigné, contre le contrat (« null si la liste n'est pas envoyée »). Sans effet dans le front (il teste `isSent`), mais donnée incohérente pour tout futur relevé. | Test de domaine et parcours (envoi, fin, décochage, lecture) rouges, verts après. | #160 : `ShoppingList::activated()` efface `sentAt`. Une liste terminée garde sa date. |

Tests ajoutés sans défaut derrière (PR #162) : garde-fou structurel de l'interrupteur sur toutes les routes du drive, administrateur refusé (`403`) sur le plan et l'estimation d'un autre, `userId` du corps ignoré sur toutes les écritures, adresses de magasin hostiles supplémentaires. Correction documentaire (cette PR) : des exemples de `architecture.md`, du ticket DRV-307 et de `recherche.md` portaient un numéro de magasin qui peut être réel ; remplacés par les valeurs factices du dépôt (`123456`, `exemple.leclercdrive.fr`).

## Constats non corrigés (décisions pour l'utilisateur)

> **Suite (2026-10-18, [DRV-613](tickets/DRV-613-durcissement-fiches-produits.md) partie 1, PR #166)** : décision de l'assistant, défense en profondeur plutôt que des données par utilisateur. C1 : premier écrivain gagnant pour le libellé, le contenu et le code-barres, prix réservé à qui accepte le produit (le prix reste modifiable par qui accepte le produit : reste ouvert) ; C2 : plafonds de 1 000 préférences par magasin et 5 000 écritures de produits par jour et par utilisateur (`422 DRIVE_LIMIT_REACHED`), pas de limiteur de débit à ce stade (ajouté ensuite par [DRV-615](tickets/DRV-615-limiteur-de-debit.md), 2026-10-18 : 60 écritures et 120 lectures coûteuses par minute et par utilisateur, `429 DRIVE_RATE_LIMITED`) ; C3 : `reopen` disponible interrupteur coupé. La partie 2 (l'agent recalcule le nombre de produits sur le contenu lu en direct) est au front. Le texte ci-dessous est celui de la revue, laissé tel quel.

### C1 — Les fiches de produits sont réécrites par n'importe quel utilisateur du même magasin (moyenne)

`drive_product` est « un fait public sans propriétaire » (D22) : la clé est `(enseigne, magasin, identifiant du produit)` et chaque `ADD_PRODUCT` ou `REJECT_PRODUCT` d'un agent **remplace** le libellé, le code-barres, le contenu et le prix de la fiche. Les inscriptions sont ouvertes (une adresse e-mail confirmée suffit pour être `ACTIVE`) : une fois `DRIVE_ENABLED=true`, un inconnu inscrit peut viser un autre utilisateur dont il connaît le numéro de magasin et un identifiant de produit.

Preuve (sonde jetable, valeurs factices) : Alice mémorise « Farine T55 1 kg », 1 kg, 1,49 € pour sa liste de 1 kg. Mallory, dans le même magasin, envoie le même identifiant avec un autre libellé, 100 g et 1 centime. Le plan d'Alice devient `label: "Texte trompeur"`, `content: 100 g`, **`packs: 10`, `COMPUTED`** (donc sans question de l'agent : 10 produits au lieu d'1 ; l'écran de confirmation de l'agent est la seule barrière) et son estimation passe à 0,10 €. Sans prix ni contenu cohérents, la fonction se trompe en silence, mais l'agent ne commande ni ne paie jamais.

Pourquoi pas corrigé : réparer vraiment demande un changement de modèle (contenu, libellé et prix **par utilisateur**, la fiche publique ne gardant que l'identité du produit), donc une migration et les trois loaders. Les remèdes partiels sont mauvais : « premier écrivain gagne » fige un contenu faux pour toujours (l'extraction de l'agent évoluera) et n'empêche pas d'écrire en premier.

Options : **A** (recommandée si l'inscription reste ouverte) données par utilisateur, un ticket ; **B** garder le modèle et fermer l'inscription (activation par un administrateur au lieu du simple e-mail), acceptable pour un cercle familial ; **C** garder tout et faire afficher par l'agent un écart avec la dernière fois (« 10 au lieu de 1 »), côté front. En attendant, ne pas allumer l'interrupteur pour des comptes inconnus. Si A est retenu, la phrase de la note de vie privée « Chacun ne retrouve que les produits qu'il a lui-même mémorisés » reste vraie mais il faut y ajouter que le contenu d'une fiche peut venir d'un autre utilisateur (ou la rendre sans objet avec A).

### C2 — Pas de plafond ni de limite de débit sur les écritures (basse)

Une requête `PUT /drive/choices` crée jusqu'à 200 préférences et 200 fiches, pour des slugs d'ingrédients qui n'ont pas besoin d'exister, sans limite par utilisateur ni de débit sur l'API authentifiée (les limiteurs ne couvrent que l'inscription, la connexion, le renouvellement du jeton et la confirmation e-mail). Un compte malveillant peut remplir la base d'un serveur d'1 Go. Même propriété que les autres écritures de l'API, mais ici elle alimente une table commune. Recommandation : un plafond par utilisateur (par exemple 500 préférences) et un limiteur de débit sur `PUT /drive/*` ; à décider avec C1.

### C3 — Interrupteur coupé : une liste `SENT` ne peut plus être rouverte (décision documentée, basse)

Coupé, `reopen` répond `404 DRIVE_DISABLED` (règle voulue, ADR 0023 §9, `runbook.md`, `production.md`). Les listes déjà envoyées restent lisibles, terminables et supprimables, mais **figées** ; une coupure d'urgence (Leclerc change son site) bloque donc les proches avec une liste verrouillée, jusqu'au script `rouvrir-listes-envoyees.sh` ou à la remise en route. `reopen` n'appelle pourtant rien de Leclerc. À décider : laisser `reopen` répondre quand l'interrupteur est coupé (retirer l'attribut, adapter le garde-fou de la PR #162 et le runbook).

### C4 à C9 — informatifs, sans correction

- **C4 — L'identifiant d'une fiche se calcule** (UUID v5 de `(enseigne, magasin, identifiant)` avec une constante publique) : `PUT /drive/choices/{slug}` accepte tout produit du magasin et `GET /drive/choices` le relit. On peut ainsi lire les fiches connues de son propre magasin en les devinant, ce que la règle « aucune route ne liste `drive_product` » veut éviter. Données publiques, sans propriétaire ni date d'usage, d'où aucune correction. Les dates `price_seen_at` et `last_seen_at` bougent quand **un autre** utilisateur du magasin revoit le produit : signal très faible sur l'activité d'autrui (à garder en tête avec C1).
- **C5 — Courses résiduelles** (lues dans le code, non reproduites : les tests partagent une connexion). (a) Cocher ou décocher une ligne contrôle `isSent` puis écrit la ligne sans version : un envoi simultané de la même liste (même utilisateur) peut laisser passer un coche. Les autres écritures de liste sont protégées par la version (`409`). (b) Deux premières écritures simultanées d'une même préférence (double envoi du résultat) : la seconde échoue sur l'unicité `(utilisateur, enseigne, magasin, ingrédient)` en `500`, et la nouvelle tentative réussit. Rares, sans fuite.
- **C6 — Journal d'accès**. `DELETE /drive/choices/{slug}?retailer=&storeId=` met le numéro de magasin en query string et le slug de l'ingrédient dans le chemin ; l'accès journalisé par le serveur web du conteneur `php` (Caddy de FrankenPHP, rotation de 3 fichiers de 10 Mo) les conserve avec l'adresse du visiteur. La bordure de production (`deploy/Caddyfile`) ne déclare aucun journal d'accès. Le contrat est figé par le front : recommandation, filtrer `storeId` dans le format du journal (`frankenphp/Caddyfile`, bloc `format filter`) si l'on veut tenir à la lettre « aucune donnée d'enseigne en query string ».
- **C7 — Anciens choix après un changement de magasin** : ils restent en base, ne se lisent plus et ne peuvent plus être oubliés tant qu'on n'est pas revenu à ce magasin ; seule la suppression du compte les efface (déjà dit dans la note de vie privée).
- **C8 — Un nombre à virgule là où le corps attend un entier** (`{"cents": 1.5}`) reçoit bien un `422`, mais PHP journalise au passage la dépréciation « Implicit conversion from float to int » (constructeur appelé par le sérialiseur). Bruit de journal, aucun effet ; pas de correction, la forme actuelle des corps étant figée par le front.
- **C9 — Un administrateur** passe `OwnerVoter` et peut donc `send`, `reopen`, terminer ou supprimer la liste de n'importe qui, comme toute route `SHOPPING_LIST_OWNER` de l'API (ADR 0021) ; le plan et l'estimation, eux, refusent (testé). Le libellé du **magasin** n'est pas durci (privé, l'agent le réduit à une ligne, un refus bloquerait l'enregistrement du magasin) et son second groupe de chiffres n'est pas comparé au premier (voulu et testé).

## Points vérifiés sans défaut

**1. Autorisation et isolation**
- Les dix routes (huit du drive, `send`, `reopen`) passent par `access_control ^/api → ROLE_USER` (`401`), `#[IsGranted('ROLE_USER')]`, puis `#[RequiresDrive]` sur neuf d'entre elles (`GET /drive/settings` répond `enabled: false` coupé, exprès). Un test le garantit désormais pour toute route future.
- L'utilisateur vient toujours de `#[CurrentUser] AuthenticatedUser` ; aucun corps ni aucune requête n'a de champ utilisateur, et un `userId` envoyé est ignoré. Toute lecture ou écriture est filtrée par cet identifiant : réglages, préférences, oubli, remplacement. `PUT` et `DELETE /drive/choices/{slug}` ne peuvent viser que ses propres préférences par construction.
- Plan et estimation : voter propriétaire **et** contrôle du propriétaire dans le loader (`403 SHOPPING_LIST_FORBIDDEN`), y compris pour un administrateur ; une liste inconnue donne `404`. Interrupteur coupé : `404` après la sécurité (un appel anonyme reste `401`).

**2. Validation des entrées** (une sonde par ligne, sauf mention)
- Adresse du magasin : motif `^https://[a-z0-9-]+\.leclercdrive\.fr/magasin-(\d+)-(\d+)$` avec `D`, sans `u` : `@`, port, autre hôte, deuxième sous-domaine, suffixe `.example`, majuscules, IDN, hôte encodé, retour à la ligne avant, dans ou après, octet nul, chiffres non ASCII, barre oblique inverse, schéma `http` : tous refusés (`RetailerTest`).
- Bornes : identifiant de produit `^[0-9]{1,12}$`, libellé 1 à 255 caractères, EAN 8 à 14 chiffres, contenu 0,001 à 100 000 fini, prix 0 à 1 000 000, slug `^[a-z0-9-]{1,255}$`, lot de 200 intentions, 5 acceptés et 20 refusés (contrôlés avant toute lecture). Types stricts : un entier pour une chaîne, une chaîne pour un nombre, `null`, tableau ou objet imbriqué : `422`.
- Environ 140 entrées hostiles sur les écritures : **aucun `5xx` hors D1**. Un octet nul dans le libellé du magasin est tronqué par le pilote PDO, sans erreur.
- SQL direct (`INSERT … ON CONFLICT`, `DELETE … IN`, lectures par clé) : **toutes les valeurs sont des paramètres liés**, aucune concaténation de donnée d'entrée ; les listes d'identifiants passent par `ArrayParameterType`.
- Contenu servi aux autres : il n'y a que du JSON (pas de page, pas de HTML) ; le front (`src/`, `drive-agent/src/`, `android/`, hors tests) n'utilise ni `v-html` ni `innerHTML` ; un libellé HTML est stocké tel quel et renvoyé échappé par JSON. Voir D2 et C1 pour ce qui reste.

**3. Verrou de liste**
- `assertEditable()` est dans les neuf mutateurs de l'agrégat (`addMeal`, `removeMeal`, `addRow`, `subtractRow`, `addIngredient`, `removeIngredient`, `setName`, `pause`, `activated`) ; `UpdateServingMeal` l'appelle explicitement ; cocher et décocher passent par `isSent()` ; planifier, retirer un repas et `+`/`-` d'un ingrédient ne trouvent pas une liste `SENT` (`404`). Il n'y a pas de fonction d'import dans le code.
- `RemoveRecipe` et `RemoveIngredient` refusent ce qu'une liste utilise, listes `SENT` comprises ; la copie d'une recette et les statistiques ne touchent pas aux listes ; `send` et `reopen` n'émettent aucun événement ; `complete` depuis `SENT` émet `ShoppingListCompletedEvent` comme pour toute liste.
- Concurrence : l'enregistrement d'une liste est un compare-and-set de la version (`UPDATE … WHERE version = ?`), donc un envoi, une réouverture ou une édition simultanés ne s'écrasent pas (`409`) ; `If-Match` donne `412`. `UncheckLine` ne réactive jamais une liste `SENT`. Reste C5 (a).

**4. Fuites et vie privée**
- Aucune réponse ne contient de donnée d'un autre utilisateur, hors C1 et C4 (fiches publiques). La suppression d'un compte efface réglages, préférences et lignes de produits sans orphelin (`AccountAdministrationFlowTest`, `UserOwnedDataCoverageTest`) ; les fiches restent (publiques).
- Aucun journal applicatif dans `Drive` ni `Shared` ; une exception inattendue donne le message générique `An unexpected error occurred.` (testé en 500) ; la production utilise `php.ini-production` (arguments d'exception ignorés).
- `docs/api/openapi.json` : aucun champ mot de passe, cookie ou jeton d'enseigne dans les schémas du drive ; les seuls champs d'identification sont ceux des routes d'utilisateur existantes.
- Le dépôt ne contient aucun secret (balayage des motifs usuels : clés d'API, clés privées, mots de passe en clair) ni donnée d'enseigne réelle côté code ; voir la correction documentaire plus haut.

**5. Migrations et déploiement**
- Les quatre migrations (`unit.dimension` et `base_factor` nullables, `shopping_list.sent_at` nullable, `drive_settings`, `drive_*`) sont additives et ignorées par la version précédente du code ; `doctrine:schema:validate` est vert (migrations contre mapping) ; le statut `SENT` est la seule valeur que l'ancien code ne sait pas lire, d'où l'interrupteur.
- `DRIVE_ENABLED` est coupé partout : `.env` (`false`), `deploy/compose.yaml` (`${DRIVE_ENABLED:-false}`, déclaré exprès sinon le `.env` de l'image s'appliquerait), `deploy/.env.example`, contrôlé par `deploy/smoke-test.sh` ; aucun autre fichier (`Dockerfile`, entrypoint, compose de dev, CI) ne le pose. Il n'est à `true` que dans `.env.test` et `phpunit.dist.xml`. La lecture `%env(bool:…)%` échoue du bon côté (une valeur incompréhensible vaut `false`).
- Les scripts d'exploitation sont sûrs : valeur `true`/`false` seulement, copie de l'ancien `.env` avant écriture, remise en état des listes dans **une** transaction après sauvegarde, règle « une seule liste active par propriétaire » ; les noms de colonnes ont été contrôlés sur le schéma.
- Si l'on revient à une version du code d'avant le chantier, un compte supprimé entre-temps laisserait des lignes `drive_*` orphelines (l'ancien code ne les purge pas) : sans conséquence pour les autres utilisateurs, à nettoyer au retour.

**6. Qualité**
- Requêtes constantes, testées sur une vraie liste : plan et estimation **4**, lecture des préférences **2**, écritures `choix-drive` et `choix-drive-lot` **7** (1 ou 41 intentions), `remplacer-preference-drive` 6, `oublier-choix-drive` 4, `reglages-drive` 2, `envoyer-liste` 9, `rouvrir-liste` 18 (deux listes enregistrées). Chaque écriture du drive a son scénario `WriteBudget`.
- PHPStan : niveau 5, baseline de 42 entrées, **aucune ne concerne `Drive`** et elle n'a pas bougé depuis la PR #57.
- Contrat OpenAPI : tous les contrôleurs documentés, `make openapi-check` vert, réponses `404 DRIVE_DISABLED`, `409` et `422` conformes au comportement observé.

## Risques résiduels

1. C1 (fiche partagée) tant qu'il n'est pas tranché : **à régler avant d'allumer `DRIVE_ENABLED` pour d'autres que des proches de confiance**.
2. C2 : un compte inscrit peut remplir la base ; surveiller l'espace disque (`pgdata`) après l'allumage.
3. C3 : une coupure d'urgence fige les listes déjà envoyées.
4. Tout ce qui dépend de Leclerc (DataDome, changement du site, CGU) est hors du back et déjà couvert par `runbook.md` et la note de vie privée.

## Recommandations pour la recette (DRV-505)

- **Avant d'allumer** : trancher C1 (et C2 si l'inscription reste ouverte) ; `printenv DRIVE_ENABLED` dans le conteneur `php` doit répondre `false` après le déploiement du back ; relire que le nombre de comptes `ACTIVE` est celui attendu.
- **À la recette avec deux comptes** (valeurs factices ou comptes de test, jamais de données réelles dans le chat) : un second compte du même magasin mémorise le même produit avec un autre contenu, puis vérifier que le plan du premier compte et sa quantité à confirmer ne changent pas en silence (tant que C1 est ouvert, ils changent : c'est l'essai qui chiffre le risque).
- Vérifier à l'écran de confirmation de l'agent que les quantités calculées sont lisibles (`packs`, `TO_CHECK`) et que `GET /drive/settings` répond `enabled: false` interrupteur coupé.
- Après l'allumage : `docker compose logs --since 10m php | grep -ci error` (pas de rafale, y compris de `500` sur `PUT /drive/choices` : C5 b), et l'espace disque.
- Couper l'interrupteur pour s'entraîner à `rouvrir-listes-envoyees.sh` en lecture seule (C3).

## Hors périmètre, vu en passant

Ces points ne concernent pas le drive et n'ont pas été modifiés. Le dépôt public contient (1) une adresse e-mail réelle d'un tiers dans les exemples OpenAPI et les fixtures de développement (`grep -rn "@gmail" docs/api src/Repas/Infrastructure/DataFixture`), (2) l'adresse IP du serveur de production et la connexion `root` dans `docs/production.md` et les fiches `repas-ops`. À remplacer par des valeurs factices si l'on veut tenir la règle « aucune donnée réelle dans les dépôts publics » partout.

## Suite côté agent (2026-10-18)

Défaut D2 : l'agent retire désormais à la lecture exactement les caractères que le back refuse dans un libellé (`drive-agent/src/text.ts`, PR repas-front#120, agent 0.8.2), donc un seul libellé marqué ne fait plus échouer tout le lot. Le motif du back (`LABEL_FORBIDDEN_CHARACTERS_PATTERN`, `Product.php`) est recopié côté front dans `drive-agent/test/back-label-rules.ts` ; un test éprouve tous les points de code Unicode. Le back n'interdit que U+2060 (pas U+2061 à U+2064) : à élargir ou non, décision à prendre.
