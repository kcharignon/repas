> **Copie intégrale du plan du 2026-10-17** (analyse, architecture, découpage en tickets). Les autres fichiers de `docs/drive/` en sont dérivés ; en cas d'écart, `architecture.md`, `roadmap.md` et les tickets font foi. **Le véhicule de l'agent y est périmé** : le favori JavaScript et le transport par fragment d'adresse sont abandonnés au profit de l'application Android (voir [`pivot-application-android.md`](pivot-application-android.md)). La section « Passation » ci-dessous décrit l'état au moment de la rédaction : depuis, le plan a été copié ici et l'avancement se lit dans `roadmap.md`.

# Remplir le panier du drive Leclerc depuis la liste de courses — analyse, architecture et tickets

## Passation (à lire en premier par tout agent qui reprend ce travail)

- **État au 2026-10-17** : analyse, architecture et découpage en 41 tickets **terminés dans ce fichier** (il contient tout : faits sourcés, décisions, architecture, protocole, tickets, risques). **Rien n'est encore écrit dans le dépôt ni dans la mémoire** : ce fichier est hors dépôt (`/home/kantin/.claude/plans/je-vais-avoir-un-breezy-lightning.md`).
- **Prochaine action** : appliquer la section « 9. Livrables de documentation et étapes » (copie du plan dans `docs/drive/plan-initial.md` d'abord, puis `docs/drive/`, ADR 0022 et 0023, issues, note mémoire). C'est de la documentation uniquement.
- **Règles de l'utilisateur sur ce chantier** : l'assistant ne code rien (d'autres agents implémentent) ; il ne demande pas « quand lance-t-on le code » ; tout doit être stocké pour qu'un autre agent reprenne sans perte d'information ; ne jamais mettre d'identifiant ni de secret dans le chat ni dans le dépôt (public).
- **Dépôts** : back `/home/kantin/Projet/Repas` (`kcharignon/repas`, public), front `/home/kantin/Projet/repas-front` (`kcharignon/repas-front`). Lire leurs `CLAUDE.md` avant d'écrire.

## Résumé

- **Quoi** : sur Android, la liste est prête, l'utilisateur touche « Envoyer au drive ». La liste est **verrouillée** (statut `SENT`), Repas ouvre le drive Leclerc dans Chrome, l'utilisateur touche un favori JavaScript (l'« agent »). L'agent met au panier les produits déjà connus avec la bonne quantité, fait choisir un produit pour chaque nouvel ingrédient sur la page Leclerc, puis renvoie vers Repas qui mémorise les choix et affiche un récapitulatif. L'utilisateur finalise sa commande sur leclercdrive.fr.
- **Qui fait quoi** : l'assistant (moi) fait l'analyse, l'architecture et les tickets, **sans coder**. D'autres agents implémentent à partir de tickets autonomes. Tout est stocké dans le dépôt (`docs/drive/`), suivi par des issues GitHub, résumé dans la mémoire de l'assistant.
- **Architecture** : trois morceaux — back (`Repas` : verrou de liste ; nouveau contexte `Drive` : réglages, produits, choix, plan d'envoi), agent (favori autonome, dans le dépôt front), front (écrans, pont vers l'agent).
- **Invariants** : aucun identifiant ni cookie Leclerc chez nous ; le back ne contacte jamais Leclerc ; aucun code distant dans le favori.
- **Découpage** : 41 tickets en 6 jalons, M0 (spikes, décision G1) d'abord ; 10 idées de backlog non détaillées.
- **Le plus risqué** : DataDome face au favori sur téléphone, CGU de Leclerc. Les spikes les lèvent avant tout code produit.

## Contexte

Le sujet : à la fin de sa liste de courses, sur son téléphone Android, l'utilisateur touche un bouton et le panier de son drive (E.Leclerc d'abord, Auchan et Intermarché ensuite) se remplit. Chaque ingrédient correspond à un produit ; l'utilisateur choisit le produit des nouveaux ingrédients, le choix est mémorisé et la phase devient plus rapide à chaque liste. Questions posées : API publique ? appeler soi-même les routes internes du site ? l'utilisateur se connecte-t-il via notre site ? stocke-t-on ses identifiants, avec quelle sécurité ?

Aucun skill « Product Owner » n'est installé (présents : `repas-dev`, `repas-ops`, caveman…). La grille PO est appliquée à la main : besoin (JTBD), options, hypothèses à risque, découpage vertical, critères go/no-go, risques. Rien n'a été modifié dans le dépôt ni envoyé à Leclerc : tout vient de recherche web (consultée le 2026-10-17) et de lecture du code back et front.

**Rôle de l'assistant sur ce chantier (demande de l'utilisateur, 2026-10-17)** : il ne code rien. Il rédige l'analyse, l'architecture, les ADR et des tickets suffisamment complets pour que d'autres agents les exécutent sans relire la conversation. Il ne demande plus « quand lance-t-on le code ». Tout est stocké, rien ne reste seulement dans le chat. Le dépôt `kcharignon/repas` est **public** : factuel, sourcé, daté, sans donnée personnelle ni secret.

**Décisions de l'utilisateur (2026-10-17)**
1. **Aucun identifiant, cookie ni jeton Leclerc ne passe par notre serveur** ; un script agit dans la session de l'utilisateur, qui se connecte lui-même sur le site.
2. **Le téléphone est indispensable dès le départ** : Android, commande par le site leclercdrive.fr dans le navigateur (pas l'appli). Un geste de plus (toucher un favori) est accepté.
3. Public : lui et ses proches.
4. Flux : produits connus mis au panier avec la bonne quantité, nouveaux ingrédients choisis sur la page Leclerc ouverte sur leur nom, choix mémorisé dans un modèle `Produit`, panier finalisé sur le site.
5. **Une liste « envoyée » au drive n'est plus modifiable.**
6. Documentation complète dans le dépôt pour y revenir plus tard.

**Recommandations de l'assistant (retenues dans ce plan, modifiables)** : favori Chrome (Firefox en repli) ; choix du produit sur la page Leclerc native dans un cadre de même origine (repli : liste de résultats de notre script) ; `Produit` et `Choix` séparés ; « J'en ai toujours » dès la v1 ; spikes sur le téléphone avant tout code produit.

## 1. Faits établis (sources en annexe)

- **Pas d'API publique E.Leclerc Drive.** Ni portail développeur ni API partenaire. Existent : des scrapers payants (Apify, Piloterr) et des projets open source par rétro-ingénierie.
- **Routes internes connues** (`skunkobi/mcp-leclerc-drive`, validé le 2026-06-13 sur un magasin) :
  - un hôte par magasin `fdN-courses.leclercdrive.fr/magasin-{id}-{id}` ; la session est liée à un seul drive ;
  - recherche : `GET recherche.aspx?TexteRecherche=…`, HTML rendu serveur avec JSON embarqué (`iIdProduit`, `sLibelleLigne1/2`, `nrPVUnitaireTTC`, `nrPVParUniteDeMesureTTC` / `sPrixParUniteDeMesure`, `iQteDisponible`, `iQuantitePanier`, vignette, rayon/famille) ; **pas d'EAN** ;
  - panier : `POST panier.aspx?op=1`, champ `d` = JSON `{eTypeAction, iIdProduit, iQuantite, sNoPointLivraison}` ; la quantité est **absolue**, donc rejouable sans doublon ; **pas de lecture propre du panier** (embarqué dans le HTML) ;
  - annuaire des magasins : `api-recherchemagasins.leclercdrive.fr/API_RechercheMagasins/api/v1/{autocomplete,autocomplete/coordinates,MapPoint/nearby}`.
- **DataDome (anti-bot) en mode actif.** Requêtes HTTP nues bloquées (mon WebFetch a reçu un 403 sur l'accueil), rejeu de cookies inefficace, écritures parallèles = 403 sur le client. Les projets qui marchent pilotent **un vrai navigateur dans la session de l'utilisateur**, connexion faite à la main (`ncleton/leclerc-drive-mcp`). Intermarché est aussi derrière DataDome ; rien de documenté pour Auchan.
- **`robots.txt`** de leclercdrive.fr : `Allow: /`, seuls `/Statistiques/`, `/.well-known/`, `/_res/release/`, `/mobile/conseils-drive/` exclus. Indicatif, pas une autorisation. **Les CGU n'ont pas pu être lues** (Leclerc bloque aussi la lecture automatique de ses pages légales) : à lire par l'utilisateur.
- **Droit.** Pour une base de données sans protection d'auteur ni sui generis, la CJUE (*Ryanair c. PR Aviation*, C-30/14, 15 janvier 2015) admet que l'exploitant restreigne par contrat l'extraction automatisée : les CGU comptent même si `robots.txt` autorise. Pas un avis juridique.
- **Prior art.** Jow fait ce besoin avec Leclerc, Auchan, Carrefour, Intermarché (enseigne « associée au compte », frais rapportés, pannes de connexion Leclerc signalées en 2026). Mealz vend un widget B2B aux sites de drive. Un petit projet (`KFMichael/site-anniversaire` PR #18) a choisi **de ne pas automatiser** : lien de recherche par produit, mémorisation du lien et du prix.
- **Mobile.**
  - Chrome Android : toujours **pas d'extensions**.
  - Firefox Android : extensions oui, mais la version stable n'installe que depuis le catalogue officiel (l'installation d'un fichier signé hors catalogue n'existe qu'en Nightly, à re-vérifier) : publication publique nécessaire.
  - Safari iPhone : extensions web via une app enveloppe (compte développeur Apple payant).
  - **Favori JavaScript (bookmarklet)** : sur Chrome Android on le lance en tapant son nom dans la barre d'adresse et en choisissant l'entrée « javascript: » ; Safari iOS est bricolé. Firefox bloque les favoris JavaScript sur les pages dont la CSP n'autorise pas le script en ligne (bug Mozilla 866522, ancien) ; WebKit applique `connect-src` à leurs requêtes. **Aucune source récente ne tranche : à tester sur le téléphone réel.**
- **Pont page ↔ extension** (si extension un jour) : Chrome a `externally_connectable`, **Firefox non** (bug 1319168) : relais par script de contenu et `window.postMessage`.

## 2. Réponses directes

| Question | Réponse |
|---|---|
| API publique ? | Non. |
| Appeler les routes internes depuis notre serveur ? | Écarté : DataDome bloque un serveur (IP de datacentre, pas de JS), il faudrait les identifiants, et le back n'a ni worker ni tâche planifiée (serveur de 1 Go de RAM, `max_execution_time` de 30 s, un appel HTTP dans un handler garde la transaction de `command.bus` ouverte). |
| L'utilisateur se connecte via notre site ? | **Non.** Il se connecte sur leclercdrive.fr comme d'habitude ; le script travaille dans cette session. |
| On sauvegarde ses identifiants ? | **Non, par conception** (§ 4.7 : ce que ça coûterait sinon). |

## 3. Flux cible

1. La liste est `ACTIVE`, elle a au moins une ligne. Bouton « Envoyer au drive » (visible si `DRIVE_ENABLED`).
2. Confirmation : « La liste ne sera plus modifiable. Tu pourras la rouvrir. » → `POST /api/v1/shopping-lists/{id}/send` (avec `If-Match`) → statut **`SENT`**, `sentAt` enregistré. La place de liste active est libérée : l'utilisateur peut préparer la liste suivante.
3. Écran `/drive/envoi/:listId` : le back construit le **plan** (`GET /api/v1/drive/plans/{shoppingListId}`) ; le front affiche toute la liste avec, pour chaque ligne, le produit retenu ou « nouveau », la quantité en nombre de produits, et permet par ligne : « Choisir un autre produit » (la ligne devient nouvelle pour ce passage), « Passer cette fois ». Bouton « Ouvrir Leclerc ».
4. Repas ouvre le site du magasin mémorisé **dans Chrome complet** (pas dans l'onglet intégré de la PWA installée, qui n'a ni barre d'adresse ni favoris : à vérifier au spike) avec le plan dans le fragment d'URL. L'utilisateur se connecte si besoin et touche le favori.
5. L'agent affiche un récapitulatif et demande confirmation. **Phase A** : produits connus mis au panier en série, quantité absolue. **Phase B** : pour chaque nouvel ingrédient, la recherche Leclerc sur son nom ; l'utilisateur ajoute le produit qu'il préfère, l'agent l'enregistre et ajuste la quantité (« 800 g demandés, 500 g par produit : 2 ») ; boutons « Passer cette fois » et « J'en ai toujours ». Un produit connu devenu indisponible repasse en phase B.
6. L'agent renvoie vers `/drive/retour#…` (origine écrite en dur). Repas enregistre les nouveaux choix, affiche le récapitulatif (ajoutés, choisis, passés, échecs) et propose : « Finaliser sur Leclerc » (page panier du site), « Réessayer les échecs », « Terminer la liste » (`COMPLETED`), « Rouvrir la liste » (`SENT` → `ACTIVE`, par erreur d'envoi).
7. L'utilisateur choisit son créneau et paie sur leclercdrive.fr. L'agent ne le fait jamais.

Précisions : Leclerc **ne s'affiche pas dans Repas** (cadre intégré à écarter : refus possible par Leclerc, CSP, cookies de connexion) ; on quitte Repas pour Leclerc puis on revient. Le panier Leclerc n'est jamais vidé ni réduit par Repas : l'agent ne fixe que la quantité des produits du plan. Plus l'utilisateur a de produits mémorisés, plus la phase A domine et plus la phase B raccourcit.

## 4. Architecture technique

### 4.1 Vue d'ensemble

| Morceau | Dépôt | Responsabilité |
|---|---|---|
| Contexte `Repas` (existant) | back | Cycle de vie de la liste dont le **verrou** (`SENT`) ; expose les lignes d'une liste au contexte `Drive` par un port `Shared`. |
| Contexte `Drive` (nouveau) | back | Réglages du drive de l'utilisateur, `Produit`, `Choix`, calcul des conditionnements, **plan d'envoi** ; purge avec le compte. Isolé : supprimable d'un bloc. |
| `Shared` | back | Port `ShoppingListForDriveLoader` (interface + DTO), événement `UserDeletedEvent` existant. |
| Agent Drive | front, `drive-agent/` | Script autonome exécuté dans la page leclercdrive.fr (favori). Aucun appel à notre API. |
| Front Repas | front | Écrans (envoi, retour, réglages, liste verrouillée), pont `driveBridge`, brouillon de passage, aide. |
| Protocole | front, `drive-agent/protocol/` | Schémas JSON des messages plan/résultat/configuration, partagés par l'agent et le front (source de vérité, recopiée en lecture dans `docs/drive/architecture.md`). |

Dépendances : `Drive → Shared` seulement (Deptrac). `Drive` référence les ingrédients par slug (ADR 0013), l'utilisateur par id. Le front orchestre `Repas` et `Drive`.

### 4.2 Règle « liste envoyée = non modifiable » (ADR 0023)

État actuel du code (lu) : `ShoppingListStatus` = `ACTIVE | PAUSED | COMPLETED` (colonne texte `status`, aucune migration pour une valeur de plus). L'agrégat `ShoppingList` ne garde **aucun invariant** sur le statut. `PlannedMeal`, `RemoveMealFromPlan`, `AddIngredientToShoppingList`, `RemoveIngredientToShoppingList` n'agissent que sur la liste `ACTIVE` du propriétaire (`findOneActivateByOwner`), donc ne touchent déjà pas une liste non active. En revanche `UpdateServingMeal` (par id de repas), `UpdateShoppingList` (renommer, par id), `TickLineOnShoppingList` et `UncheckLineOnShoppingList` (par id de ligne), `ActivatedShoppingList`, `PausedShoppingList` agissent **par identifiant, sans regarder le statut** ; `UncheckLine` réactive même une liste `COMPLETED`.

**Nouveau statut `SENT`** et colonne `sent_at` (nullable). Transitions :

| De → vers | Action | Remarques |
|---|---|---|
| `ACTIVE` → `SENT` | `POST /shopping-lists/{id}/send` | liste non vide (`422 SHOPPING_LIST_EMPTY`), sinon `409 SHOPPING_LIST_NOT_SENDABLE` si pas `ACTIVE` ; `If-Match` accepté (`412`) ; idempotent si déjà `SENT` (`204`) ; enregistre `sentAt` (horloge injectée) |
| `SENT` → `ACTIVE` | `POST /shopping-lists/{id}/reopen` | explicite, avec confirmation dans l'UI ; met en pause la liste `ACTIVE` éventuelle (comme `activate`) ; efface `sentAt` ; `409 SHOPPING_LIST_NOT_SENT` sinon |
| `SENT` → `COMPLETED` | `POST /shopping-lists/{id}/complete` (existant) | émet `ShoppingListCompletedEvent` comme aujourd'hui |
| autres | — | `PAUSED` → `SENT` interdit (activer d'abord) ; `SENT` → `PAUSED` interdit |

**Opérations sur une liste `SENT`** : autorisées = lire, `complete`, `reopen`, supprimer. **Bloquées** (`409 SHOPPING_LIST_SENT`, message = code, comme `PROTECTED_ACCOUNT` de l'ADR 0021) = ajouter/retirer une recette, changer les portions, `+`/`-` d'un ingrédient, renommer, cocher, décocher, `activate`, `pause`.

**Implémentation prescrite (DRV-101)** :
- `ShoppingList::assertEditable()` (lève `ShoppingListException::sent($id)`, 409) appelée **dans chaque mutateur de l'agrégat** (`addMeal`, `addRow`, `subtractRow`, `addIngredient`, `removeIngredient`, `removeMeal`, `setName`) et en tête des handlers par identifiant (`UpdateServingMealHandler`, `UpdateShoppingListHandler`, `ActivatedShoppingListHandler`, `PausedShoppingListHandler`) ; `send()` / `reopen()` / `completed()` portent leurs propres gardes.
- Cocher / décocher chargent une `ShoppingListRow` sans la liste : ajouter au port `ShoppingListRepository` une question booléenne `isSent(string $shoppingListId): bool` (ADR 0013 : l'état d'un agrégat se demande par un port booléen) ; `UncheckLine` ne réactive jamais une liste `SENT`.
- `send` et `reopen` ajoutent `?int $expectedVersion = null` et appellent `assertVersion()` (ADR 0016) ; ils enregistrent donc la liste (version +1).
- Lecture : `ShoppingListDTO` et `ShoppingListDetailDTO` ajoutent `isSent` et `sentAt` (ATOM, nullable) ; l'énumération `status` de la doc OpenAPI gagne `SENT`.
- Création d'une nouvelle liste : `CreateShoppingListHandler` ne touche pas une liste `SENT` (seule la `ACTIVE` est mise en pause) ; `RemoveShoppingListCompletedEventListener` continue de ne supprimer que les `COMPLETED`. Une liste `SENT` reste donc jusqu'à `complete` ou suppression.
- Statistiques : aucun événement à l'envoi ni à la réouverture.
- **Compatibilité de déploiement (ADR 0017 règle 6)** : le retour arrière ne défait pas le schéma ni les données ; l'ancien code ne connaît pas `SENT` (`ShoppingListStatus::from()` lèverait). D'où `DRIVE_ENABLED=false` par défaut : tant que l'interrupteur est coupé, `send` répond `404` et aucune ligne `SENT` n'existe ; on l'active seulement après un déploiement stable. Retour arrière avec des listes `SENT` : `UPDATE shopping_list SET status='ACTIVE', sent_at=NULL WHERE status='SENT'` (dans le runbook, DRV-502).

### 4.3 Contexte `Drive`

**Modèle (domaine PHP pur)**
- `Retailer` (enum `LECLERC` ; porte la règle d'URL de magasin autorisée, ex. `https://*.leclercdrive.fr/magasin-<id>-<id>`).
- `DriveSettings` : `userId`, `retailer`, `store` (`id`, `label`, `baseUrl`).
- `Product` (**fait public, sans propriétaire**) : `id` (uuid), `retailer`, `storeId`, `retailerProductId`, `ean` (nullable), `label`, `content` (valeur + `ContentUnit`, nullable), `lastSeenAt`. Clé naturelle `(retailer, storeId, retailerProductId)` ; `storeId` fait partie de la clé tant que le spike DRV-002 n'a pas montré que les identifiants Leclerc sont communs aux magasins.
- `ContentUnit` (enum : `G` poids 1, `KG` poids 1000, `ML` volume 1, `CL` volume 10, `L` volume 1000, `PIECE` pièce 1) et `Dimension` (`WEIGHT`, `VOLUME`, `COUNT`, `PACKAGE`).
- `Choice` (**privé, purgé avec le compte**) : `userId`, `retailer`, `storeId`, `ingredientSlug`, `kind` (`PRODUCT` | `HAVE_AT_HOME`), `productId` (nullable), `chosenAt`. Unique `(userId, retailer, storeId, ingredientSlug)`.
- `PackCalculator` (service de domaine pur) : voir § 5.

**Tables** (aucune clé étrangère, colonnes en `snake_case`, migrations compatibles avec la version précédente du code)
- `drive_settings(user_id, retailer, store_id, store_label, base_url, updated_at)` — PK `(user_id, retailer)`.
- `drive_product(id, retailer, store_id, retailer_product_id, ean, label, content_value, content_unit, last_seen_at)` — unique `(retailer, store_id, retailer_product_id)`, index sur `ean`.
- `drive_choice(id, user_id, retailer, store_id, ingredient_slug, kind, product_id, chosen_at)` — unique `(user_id, retailer, store_id, ingredient_slug)`.
- Colonnes `user_id` → champs `userId` : couverts par `UserOwnedDataCoverageTest` (à étendre) ; `drive_product` n'en a pas, rien à purger.

**Cas d'usage (CQRS, ADR 0003)**
- Écritures : `SaveDriveSettings`, `SaveProductChoices` (lot : upsert des `Product` puis des `Choice`, atomique, ≤ 200 éléments), `ForgetChoice`, `PurgeDriveData` (sur `UserDeletedEvent`).
- Lectures : `GetDriveSettings`, `ListChoices`, `GetDrivePlan`.
- Ports : `DriveSettingsRepository`, `ProductRepository`, `ChoiceRepository`, loaders `DriveSettingsLoader`, `ChoiceLoader`, et le port `Shared` `ShoppingListForDriveLoader::get(listId)` → `ShoppingListForDrive{id, ownerId, status, version, lines[{ingredientSlug, ingredientName, quantity, unitSlug, unitSymbol, unitDimension, unitBaseFactor}]}` implémenté par `Repas\Infrastructure\Loader\ShoppingListForDrivePostgreSQLLoader` (précédent : `OwnershipLoader`).
- Droits : `#[IsGranted('SHOPPING_LIST_OWNER', 'shoppingListId')]` pour le plan ; les ressources `Drive` sont adressées par le JWT (`#[CurrentUser] AuthenticatedUser`), jamais par le corps.

### 4.4 API `/api/v1` (JWT ; erreurs `{error:{message}}` ; message = code en majuscules pour les cas métier)

| Route | Contexte | Réponse |
|---|---|---|
| `POST /shopping-lists/{id}/send` | Repas | `204` ; `404` `DRIVE_DISABLED` ; `409` `SHOPPING_LIST_NOT_SENDABLE` ; `412` ; `422` `SHOPPING_LIST_EMPTY` |
| `POST /shopping-lists/{id}/reopen` | Repas | `204` ; `404` `DRIVE_DISABLED` ; `409` `SHOPPING_LIST_NOT_SENT` ; `412` |
| (existant, modifié) écritures sur une liste `SENT` | Repas | `409` `SHOPPING_LIST_SENT` |
| (existant, modifié) lecture d'une liste | Repas | `status` + `SENT`, `isSent`, `sentAt` ; lignes avec `ingredientSlug`, `unitSlug` |
| (existant, modifié) `POST/PUT /units` | Repas | corps avec `dimension`, `baseFactor` |
| `GET /drive/settings` | Drive | `{enabled, retailer, store: {id,label,baseUrl}|null}` (200 même désactivé : `enabled:false`) |
| `PUT /drive/settings` | Drive | `204` ; `422` `DRIVE_STORE_INVALID` (hôte hors liste autorisée) ; `404` `DRIVE_DISABLED` |
| `GET /drive/plans/{shoppingListId}` | Drive | plan ci-dessous ; `409` `DRIVE_NOT_CONFIGURED` ou `SHOPPING_LIST_NOT_SENT` |
| `GET /drive/choices?retailer=` | Drive | `{member:[…], totalItems}` pour le magasin courant |
| `PUT /drive/choices` | Drive | `204` ; lot `{retailer, storeId, choices:[{ingredientSlug, kind, product?}]}` |
| `DELETE /drive/choices/{ingredientSlug}?retailer=&storeId=` | Drive | `204` ; `404` |

`DrivePlanDTO` : `{shoppingListId, listVersion, retailer, store:{id,label,baseUrl}, generatedAt, counts:{known,new,haveAtHome}, lines:[{ingredientSlug, ingredientName, needed:{quantity,unitSymbol}, status:'KNOWN'|'NEW'|'HAVE_AT_HOME', product:{id,ean,label,content:{value,unit}|null}|null, packs:int|null, packsStatus:'COMPUTED'|'TO_CHECK'|null, searchQuery}]}`. Un produit connu dont le magasin a changé n'existe pas : la ligne est `NEW`.

### 4.5 Protocole de l'agent (v1)

Transport : fragment d'URL `#repas=<JSON en base64url sans bourrage>` (aller) et `#result=<…>` (retour). Un fragment ne part jamais vers un serveur. Taille visée < 16 Ko. Presse-papiers en secours (DRV-002).

**Message de plan** (front → agent) : `{v:1, type:'plan'|'setup', runId:uuid, retailer:'leclerc', store:{id, host}, returnPath:'/drive/retour', lines:[{ingredientSlug, name, status:'KNOWN'|'NEW'|'HAVE_AT_HOME'|'SKIP', product?:{id,label,content?}, packs?:1..99, packsStatus?, searchQuery?, replaces?:{id,label}}]}`. Le type `setup` ne porte pas de lignes : l'agent lit le magasin courant et le renvoie.

**Validation par l'agent avant toute action** : `v === 1` ; `runId` uuid ; `retailer` autorisé ; hôte courant ∈ liste autorisée **et** magasin du message = magasin de la page (sinon `STORE_MISMATCH`) ; ≤ 100 lignes ; slugs `^[a-z0-9-]{1,100}$` ; identifiants de produit `^[0-9]{1,12}$` ; `packs` entier 1..99 ; chaînes bornées ; tout affichage par `textContent` (jamais de HTML) ; `returnPath` ∈ `['/drive/retour']` et l'origine de retour est **écrite en dur** à la construction du favori.

**Message de résultat** (agent → front) : `{v:1, type:'result', runId, agentVersion, outcome:'completed'|'partial'|'aborted'|'error', store:{id}, error?:{code, message}, lines:[{ingredientSlug, outcome:'ADDED'|'CHOSEN'|'HAVE_AT_HOME'|'SKIPPED'|'UNAVAILABLE'|'FAILED', productId?, quantity?, product?:{id, ean?, label, content?}, error?}]}`. Codes d'erreur : `NOT_LOGGED_IN`, `STORE_MISMATCH`, `BLOCKED`, `CONTRACT_CHANGED`, `NETWORK`, `CANCELLED`, `INVALID_MESSAGE`, `UNSUPPORTED_PAGE`.

**Comportement** : récapitulatif + confirmation ; **cadence** ≥ 800 ms entre requêtes avec gigue jusqu'à +700 ms, jamais en parallèle, arrêt après 2 réponses 403/429 consécutives (`partial` + `BLOCKED`), une seule reprise sur erreur réseau ; maintien de l'écran allumé (Wake Lock) ; état de reprise dans `sessionStorage` (`repas:run:<runId>`) ; plafonds (quantité ≤ stock lu, ≤ 99) ; ne lit ni cookies, ni compte, ni adresse, ni créneau ; ne valide jamais de commande ; interface en Shadow DOM, cibles ≥ 44 px, `aria-live` pour la progression, textes en français ; mode **diagnostic** (auto-test OK/KO copiable, sans donnée personnelle).

**Choix du produit en phase B** : P1 (recommandée) = un `iframe` plein écran vers `recherche.aspx?TexteRecherche=<requête>` du même hôte, notre script reste dans la page parente et repère le produit que l'utilisateur ajoute ; P2 (repli) = notre script interroge `recherche.aspx`, analyse le JSON embarqué et affiche lui-même les candidats ; décision à G1 (DRV-005) selon DRV-002.

### 4.6 Front

Routes : `/drive/reglages` (magasin, favori, produits mémorisés, « j'en ai toujours »), `/drive/envoi/:listId` (plan, ouverture de Leclerc, attente), `/drive/retour` (récapitulatif). Une liste `SENT` s'affiche en lecture seule avec bandeau « Envoyée au drive le … » et les actions « Reprendre l'envoi », « Terminer », « Rouvrir ». `/liste` ne crée pas de liste tout seul s'il n'y a que des listes `SENT` (le bouton « Nouvelle liste » existe déjà).

**Brouillon de passage** : `{runId, listId, startedAt}` dans `localStorage` (durée 24 h), car l'utilisateur quitte Repas pour Leclerc et que le service worker de production recharge la page dès qu'il détecte une nouvelle version. Au retour : si `runId` ne correspond pas au brouillon, le résultat est ignoré avec un message.

**Ouverture de Chrome** : lien d'intention Android (`intent://…#Intent;scheme=https;package=com.android.chrome;end`) quand l'application est installée (`display-mode: standalone`), sinon lien normal ; à valider au spike DRV-002.

**Contraintes du front existant** : pas de logique métier dans les composants (les conditionnements viennent du plan), jeton en mémoire, textes dans `src/i18n/fr.ts`, couleurs par jetons, CSP de production inchangée (aucune image externe : pas de vignettes Leclerc dans Repas en v1), tests Vitest + e2e Pixel 7, seuil de couverture 80 %.

### 4.7 Sécurité et vie privée

**Invariant (ADR 0022) : aucun identifiant, cookie ni jeton d'enseigne ne transite par notre back ni n'y est stocké ; le back ne contacte jamais une enseigne.** Garde-fous testables : aucun champ de ce type dans `docs/api/openapi.json` ; aucune donnée du message dans les logs (le fragment ne part pas au serveur ; l'API n'a ni plan ni choix en query string car Caddy journalise l'URI complète).

L'agent s'exécute avec les droits de l'utilisateur sur leclercdrive.fr ; compromis, il pourrait agir sur son compte. D'où :
- **pas de code distant** : favori autonome, code public, construction reproductible, empreinte publiée ; mise à jour = réinstaller le favori ;
- message **validé et plafonné**, confirmation visible avant d'agir, rien de lu hors des produits que l'utilisateur ajoute dans le cadre ;
- origine de retour **en dur**, jamais lue dans le message ; un lien piégé ne peut au pire que remplir un panier de produits inutiles, sans commande ni paiement ;
- `Product` est alimenté par les agents : validation côté serveur (identifiants numériques, libellé borné, contenu plausible), mise à jour par clé, aucune donnée personnelle ; `Choice` peut révéler régime ou santé : minimisation, purge à la suppression du compte, pas de statistiques tierces ;
- DataDome peut signaler le compte ou l'IP de l'utilisateur : requêtes en série, pauses, pas de moissonnage, information avant usage ; interrupteur de désactivation ;
- rupture du site : détection `CONTRACT_CHANGED`, message clair, repli en mode assisté.

*Si l'on stockait des identifiants (non recommandé, aucun plan)* : chiffrement par enveloppe (libsodium, `ext-sodium` est présent) avec clé hors base ET hors sauvegardes, donc un coffre externe (la machine unique actuelle livre clé et données si elle est compromise ; aujourd'hui aucun chiffrement au repos, secrets en variables d'environnement, `pg_dump` complets sur l'hôte) ; écriture seule, jamais renvoyé ni journalisé ; rotation ; audit ; limitation de débit ; suppression à la demande ; notification CNIL sous 72 h (RGPD art. 33-34) ; test d'intrusion. Et cela ne règle ni DataDome, ni la 2FA, ni les CGU.

### 4.8 Tests et qualité

- Back : définition de fini du skill `repas-dev` (`make test`, `deptrac`, `phpstan`, `api-doc-*`, `openapi-check`, budgets SQL `WriteBudget` pour `SendShoppingList`, `ReopenShoppingList`, `SaveDriveSettings`, `SaveProductChoices`, `ForgetChoice`, budget de `supprimer-compte` revu) ; handlers en unitaire avec doubles en mémoire ; loaders sur base réelle ; controllers avec `MockCommandBus` / `MockQueryBus` ; un parcours de bout en bout par ressource (jeton → handler → base) ; `PackCalculator` en tests à table.
- Agent : tests unitaires sur fixtures **synthétiques** (jamais de HTML Leclerc brut dans un dépôt public) ; **faux site Leclerc** (DRV-309) pour des tests de bout en bout Playwright : message valide/invalide, magasin différent, 403 simulé, cadence, capture d'un produit, renvoi du résultat.
- Front : Vitest, e2e Pixel 7 avec un agent simulé, accessibilité, test visuel Chromium (captures lues).
- Recette sur le téléphone réel (humain, DRV-505) : le faux site ne prouve pas DataDome.

### 4.9 Déploiement et exploitation

- Interrupteur : port `Shared\Application\Interface\DriveFeature::isEnabled()` implémenté dans `Shared\Infrastructure` (lit `%env(bool:DRIVE_ENABLED)%`), utilisé par les contrôleurs `send`, `reopen` et `Drive` (`404 DRIVE_DISABLED` quand coupé) et exposé par `GET /drive/settings`.
- Ordre : (1) back avec `DRIVE_ENABLED=false` ; (2) front ; (3) l'humain active `DRIVE_ENABLED=true` dans `/opt/repas/.env` (variable listée avec `:?` dans `deploy/compose.yaml`, `deploy/.env.example`, `docs/production.md`) après un déploiement stable ; l'approbation du déploiement reste à l'humain (ADR 0017).
- Retour arrière : voir § 4.2 (requête SQL), le schéma reste.
- Panne de Leclerc ou changement du site : interrupteur, runbook (DRV-503), nouvelle version du favori à réinstaller.
- Sauvegardes : les nouvelles tables sont dans les `pg_dump` ; aucun secret d'enseigne n'existe.

### 4.10 Options écartées, avec la raison

- *Serveur + identifiants stockés (navigateur sans tête côté serveur)* : DataDome bloque un serveur, 2FA et captcha ne s'automatisent pas, une fuite engage notre responsabilité (RGPD, CNIL sous 72 h), mots de passe réutilisés, un seul serveur sans chiffrement au repos, partage d'identifiants probablement contraire aux CGU (à vérifier).
- *Cookie de session envoyé au serveur* : même blocage DataDome, et un cookie de session vaut un identifiant.
- *Application Android dédiée (WebView)* : second projet à construire, signer et distribuer ; l'utilisateur taperait ses identifiants dans notre appli.
- *Navigateur distant diffusé* : nous verrions la session et les saisies, sur un serveur de 1 Go.
- *Leclerc affiché dans Repas (cadre intégré)* : refus possible de Leclerc, CSP, cookies de connexion.
- *Sélecteur de produit dans Repas* : un aller-retour par recherche, images bloquées par notre CSP, interface Leclerc dupliquée.
- *Extension Chrome* : n'existe pas sur Chrome Android. *Extension ou gestionnaire de scripts Firefox, extension Safari* : repoussés (autre navigateur, publication publique ou compte Apple payant), gardés pour le backlog.
- *Code distant chargé par le favori* : notre serveur deviendrait une porte vers les comptes Leclerc de tous les utilisateurs.
- *Synchronisation complète du catalogue* : extraction massive, risque CGU, droit des bases de données et DataDome.
- *Scrapers payants (Apify, Piloterr)* : lisent le catalogue mais ne remplissent pas le panier de l'utilisateur ; coût récurrent ; même risque CGU.
- *Open Food Facts / Open Prices* : pas d'identifiants Leclerc ni de panier ; utile plus tard pour l'EAN.
- *Cocher les lignes après remplissage* : « coché » = « dans le caddie », la dernière coche termine la liste.
- *Partenariat façon Jow* : accord commercial, hors de portée en usage familial.
- *Verrou à la fin du passage plutôt qu'au toucher du bouton* : ouvre une fenêtre où la liste change pendant l'envoi ; le verrou au toucher rend le plan stable (la réouverture explicite couvre l'erreur).
- **Gardé en repli permanent** : le mode assisté (lien de recherche Leclerc par ligne, sans automatisation) quand le favori est bloqué ou cassé.

## 5. Correspondance ingrédient → produit et conditionnements

Données du code (back) : `ShoppingListRow` = `ingredientSlug`, quantité (float) **dans l'unité d'achat par défaut de l'ingrédient**, `unitSlug`, `checked`. Sur 256 ingrédients communs : grammes 99, unité 59, paquet 42. Rien sur EAN, marque, prix, produit. `Unit` n'a que `slug`, `name`, `symbol` : la dimension est implicite dans le graphe des conversions. Les 25 unités : `unite, kilo, gramme, litre, millilitre, centilitre, cuillere-a-soupe, cuillere-a-cafe, tasse-a-cafe, verre, bol, plaquette, tablette, tranche, paquet, boite, sachet, bouteille, conserve, cube, buche, brique, pot, gousse, flacon`.

**`Unit` gagne `dimension` et `baseFactor` (DRV-105)**, nullables, amorcés par la migration :
`gramme` WEIGHT 1 · `kilo` WEIGHT 1000 · `millilitre` VOLUME 1 · `centilitre` VOLUME 10 · `litre` VOLUME 1000 · `unite` COUNT 1 · `paquet, boite, sachet, bouteille, conserve, brique, pot, flacon, plaquette, tablette, buche` PACKAGE 1 · les autres (cuillères, tasse, verre, bol, tranche, cube, gousse) restent `null` (conversion par ingrédient, backlog).

**`PackCalculator::packsFor(line, content)` (DRV-205)**
1. Unité de la ligne `PACKAGE` : `packs = ceil(quantité)`, `COMPUTED` (la liste compte déjà des produits).
2. Unité `COUNT` : contenu en pièces → `ceil(quantité / valeur)`, `COMPUTED` ; contenu absent → `ceil(quantité)`, `TO_CHECK`.
3. Unité `WEIGHT` ou `VOLUME` et contenu de même dimension → `ceil(quantité × facteurLigne / (valeur × facteurContenu))`, `COMPUTED`.
4. Autre cas (dimensions différentes, unité sans dimension, contenu inconnu) → `packs = null`, `TO_CHECK` : l'agent demande la quantité à l'utilisateur, 1 par défaut.
5. Arrondi supérieur avec tolérance de 1e-9, minimum 1, plafond 99. Tests à table : chaque règle, bornes, flottants.

**Contenu d'un produit** : extrait par l'agent (adaptateur Leclerc) du libellé de seconde ligne ou de `prix ÷ prix par unité de mesure`, normalisé en `g | ml | pièce`, `null` si incertain (à valider au spike DRV-002/005).

**Suggestion** (backlog DRV-601) : choix précédent d'abord, sinon règles déterministes (similarité du libellé avec le nom — la recherche Jaro-Winkler de l'ADR 0019 est réutilisable au seuil 0,85 —, compatibilité d'unité, produit non transformé, marque de distributeur, prix à l'unité de mesure le plus bas, en stock). Un modèle de langage pour départager reste une expérience facultative.

**Pas de moissonnage du catalogue** : recherches à la demande, par utilisateur, sur les seuls ingrédients sans choix mémorisé.

**Piège `checked`** : il signifie « déjà dans le caddie » et cocher la dernière ligne **termine la liste** ; un envoi au drive ne coche jamais de lignes.

## 6. Tickets

**Format d'un ticket** (`docs/drive/tickets/DRV-xxx-slug.md`, autonome) : en-tête (dépôt, jalon, taille S/M/L, dépend de, statut) · Contexte (pourquoi, renvois vers `architecture.md` et les ADR) · Portée · Hors portée · Spécification technique (classes, fichiers, contrats exacts) · Critères d'acceptation (Given/When/Then) · Tests exigés · Definition of Done (celle du dépôt + points propres) · Pièges connus. Un agent doit pouvoir l'exécuter sans relire la conversation. **Suivi** : une issue GitHub par ticket (titre `DRV-xxx — …`, labels `drive` + type + dépôt, corps = résumé + lien vers le fichier), une issue d'épique par jalon avec sa liste de tâches ; `docs/drive/roadmap.md` relie l'identifiant au numéro d'issue.

**Jalons et tickets** (taille ; dépend de)

*M0 — Découverte (aucun code produit ; décision G1)*
- DRV-001 [front, `drive-agent/lab/`] Banc d'essai du favori : un seul favori de test à menu (sonde d'environnement ; recherche même origine + analyse ; ajout puis retrait d'un produit ; cadre natif `iframe` ; taille d'un fragment et retour vers `https://kantin.eu/` ; Wake Lock ; ouverture dans Chrome complet) + feuille de procédure. M ; —.
- DRV-002 [humain] Exécution du banc d'essai sur le téléphone Android (modèle, version de Chrome) et rapport `docs/drive/spikes/DRV-002-rapport.md` (favori exécuté ? 200 ? panier ? cadre ? identifiants de produit entre deux magasins ? EAN ? contenu déductible ? 30 ingrédients réels : bon produit dans les 3 premiers ? adresse de la page panier ? panier visible dans l'appli ?). S ; 001.
- DRV-003 [humain] Lecture des CGU/CGV Leclerc (mots « robot », « automatisé », « extraction », « script ») et rapport `DRV-003-cgu.md`. S ; —.
- DRV-004 [humain] Relevé des en-têtes de sécurité (CSP, `X-Frame-Options`, COOP) des pages recherche et panier avec les DevTools d'un ordinateur : `DRV-004-entetes.md`. S ; —.
- DRV-005 [assistant] Synthèse et **décision G1** : ADR 0022 `Accepted` ou `Rejected`, choix P1/P2, véhicule (favori Chrome / Firefox / mode assisté), mise à jour de la roadmap. S ; 002, 003, 004.

*M1 — Back `Repas` (après G1)*
- DRV-101 Liste envoyée : statut `SENT`, `sent_at`, transitions, `assertEditable()`, handlers `SendShoppingList` / `ReopenShoppingList`, gardes (§ 4.2), migration, ADR 0023 → `Accepted`. L ; 005.
- DRV-102 API `send` / `reopen`, DTO (`isSent`, `sentAt`, énumération), OpenAPI, tests de contrôleur et parcours bout en bout. M ; 101, 103.
- DRV-103 Interrupteur `DRIVE_ENABLED` (service + variable dans `deploy/` et docs, exposé par `GET /drive/settings`). S ; —.
- DRV-104 `ShoppingListRowDTO` gagne `ingredientSlug` et `unitSlug` (loader, doc, contrat). S ; —.
- DRV-105 `Unit.dimension` et `baseFactor` : domaine, entité, migration + amorçage des 25 unités, fixtures, API d'administration. M ; —.

*M2 — Back `Drive` (après G1)*
- DRV-201 Squelette du contexte : dossiers, couche et règle `Drive: [Shared]` dans `quality-rules/Deptrac/deptrac.bounded-context.yaml`, expression `(Repas|User)` de `no-database-in-handler-tests` étendue, import de routes (`config/routes.yaml`), mapping Doctrine (`config/packages/doctrine.yaml`), exclusion de fixtures (`phpunit.dist.xml`), PHPStan. S ; —.
- DRV-202 Port `Shared` `ShoppingListForDriveLoader` (+ DTO) et son implémentation dans `Repas`. S ; 201, 104, 105.
- DRV-203 Réglages du drive : domaine → API `GET|PUT /drive/settings`, validation d'hôte, drapeau, purge, tests. M ; 201, 103.
- DRV-204 Produits et choix : domaine, dépôts, entités + migration, `SaveProductChoices` (lot), `ForgetChoice`, `ListChoices`, API, tests, `WriteBudget`. L ; 201.
- DRV-205 `PackCalculator` : § 5, tests à table. M ; 201.
- DRV-206 `GetDrivePlan` : requête, DTO, `GET /drive/plans/{shoppingListId}`, droits propriétaire, statuts attendus. M ; 202, 203, 204, 205.
- DRV-207 Purge Drive à la suppression d'un compte : écouteur de `UserDeletedEvent`, `PurgeDriveData`, extension de `UserOwnedDataCoverageTest`, budget de `supprimer-compte`. S ; 203, 204.

*M3 — Agent (front, `drive-agent/`)*
- DRV-301 Squelette : structure, esbuild, générateur de favori, budget de taille, lint/tests/CI, construction reproductible + empreinte. M ; 005.
- DRV-302 Protocole v1 : schémas JSON plan/setup/résultat, codec base64url, validation stricte, tests ; module partagé avec le front. M ; 301.
- DRV-303 Adaptateur Leclerc : lecture du magasin, recherche + analyse du JSON embarqué, extraction du contenu, fixation de la quantité (`panier.aspx?op=1`), taxonomie d'erreurs, cadence. L ; 301, 302.
- DRV-304 Interface superposée : Shadow DOM, écrans (récapitulatif, progression, sélecteur, erreurs, fin), accessibilité, français. L ; 301.
- DRV-305 Orchestrateur phase A (produits connus), reprise, Wake Lock, construction du résultat. M ; 302, 303, 304.
- DRV-306 Phase B choix guidé (P1 cadre natif ou P2 liste, selon G1) : capture du produit ajouté, ajustement de quantité, « Passer cette fois », « J'en ai toujours ». L ; 305.
- DRV-307 Mode configuration (`setup`) : lit le magasin, renvoie `store`. S ; 302, 303.
- DRV-308 Mode diagnostic. S ; 303, 304.
- DRV-309 Faux site Leclerc (données synthétiques) + tests e2e Playwright de l'agent. L ; 303.
- DRV-310 Publication du favori : version, journal des changements, chaîne à copier, empreinte. S ; 306.

*M4 — Front*
- DRV-401 Liste envoyée dans l'UI : statut et libellés, lecture seule, actions « Reprendre l'envoi / Terminer / Rouvrir », `/liste` sans création automatique, contrat régénéré (`npm run api:update`). M ; 102, 104.
- DRV-402 Module `driveBridge` : encodage/décodage, validation par les schémas partagés, ouverture de Chrome (lien d'intention), brouillon de passage. M ; 302.
- DRV-403 Réglages du drive : magasin (via `setup`), installation du favori + version minimale de l'agent, produits mémorisés (oublier, changer), « j'en ai toujours » (réactiver). L ; 203, 204, 307, 402.
- DRV-404 Flux d'envoi : bouton + confirmation, plan, surcharges par ligne (autre produit, passer), ouverture de Leclerc, attente avec brouillon. L ; 102, 206, 402, 401.
- DRV-405 Retour et récapitulatif : lecture du résultat, enregistrement des choix, récapitulatif, actions finales. L ; 204, 402.
- DRV-406 Tests e2e Pixel 7 (agent simulé), accessibilité, test visuel. M ; 403, 404, 405.
- DRV-407 ADR front 0008 + `CLAUDE.md` front + amendement des ADR 0003/0006 (usage téléphone). S ; 404.
- DRV-408 Page d'aide : installer le favori, le lancer sur Chrome Android, dépannage. S ; 403.
- DRV-409 Administration des unités : saisie de `dimension` et `baseFactor`. S ; 105.

*M5 — Intégration et livraison*
- DRV-501 Intégration en développement (back + front + agent contre le faux site) et checklist de recette. M ; M2, M3, M4.
- DRV-502 Déploiement : ordre, interrupteur, migrations compatibles, retour arrière, `docs/production.md`, skill `repas-ops`. S ; 501.
- DRV-503 Runbook « Leclerc a changé » et désactivation. S ; 501.
- DRV-504 Note vie privée (texte pour l'app, registre des traitements). S ; 204.
- DRV-505 [humain] Recette en conditions réelles et activation de l'interrupteur. S ; 502, 503.

*Backlog (non détaillé)* : DRV-601 suggestions automatiques · 602 journal d'envoi · 603 Firefox + gestionnaire de scripts / extension · 604 vignettes dans Repas (exception CSP ou relais) · 605 conversions unité↔poids par ingrédient · 606 reprise des choix au changement de magasin (EAN, libellé) · 607 deuxième enseigne · 608 enregistrement direct par jeton à usage unique · 609 partage de choix entre proches · 610 rapport d'exécution anonyme.

**Chemin critique** : 001 → 002 → 005 (G1) → 301 → 302 → 303 → 304 → 305 → 306 → 309 → 501 ; côté back 101 → 102 en parallèle de 201 → 204/205 → 206 ; côté front 402 → 404/405 → 406. **Peuvent démarrer avant G1** : 001, 003, 004 (pas de code produit). Rien d'autre.

**Première version utilisable** = M0 à M5 sans le backlog.

## 7. Lacunes comblées, décisions par défaut, ce qui reste à l'humain

**Lacunes que ce plan comble** (liste des points qui manquaient pour avancer) : règle de verrou (statuts, opérations, codes, versions, déploiement) · contexte `Drive` (modèle, tables, ports, API, droits) · protocole de l'agent (messages, validation, erreurs, cadence) · calcul des quantités et `dimension` des unités · comment le contexte `Drive` lit la liste sans importer `Repas` (port `Shared`) · brouillon de passage face au rechargement de la PWA · ouverture dans Chrome et non dans l'onglet intégré · réglage du magasin (lu par l'agent, pas saisi) · comportement après un envoi (place de liste active libérée, `/liste`) · indisponibilité d'un produit connu · retraitement d'un envoi partiel (quantités absolues, panier jamais vidé) · surcharges par ligne avant l'envoi · compatibilité de déploiement et interrupteur · purge et vie privée · tests sans accès à Leclerc (faux site) et recette réelle · maintenance quand Leclerc change · tickets autonomes et suivi.

**Décisions par défaut (modifiables avant l'implémentation)**
- D1 Le verrou se pose au toucher d'« Envoyer au drive » ; la réouverture est explicite. D2 Sur `SENT` : lire, terminer, rouvrir, supprimer ; tout le reste `409`. D3 Les listes `SENT` restent jusqu'à `complete` ou suppression. D4 Envoi possible depuis `ACTIVE` seulement, liste non vide.
- D5 Réessayer un envoi se fait sur la liste `SENT` (quantités absolues, rejouable). D6 Repas ne vide ni ne réduit le panier Leclerc.
- D7 Un choix est propre à (utilisateur, enseigne, magasin, ingrédient). D8 Les conditionnements se calculent dans le back (`Drive`).
- D9 L'agent vit dans le dépôt front ; le protocole est partagé. D10 `DRIVE_ENABLED` est coupé par défaut. D11 Pas de vignettes dans Repas en v1. D12 `sentAt` est stocké.
- D13 Un produit connu indisponible repasse en phase B. D14 « J'en ai toujours » = un `Choice` de type `HAVE_AT_HOME`. D15 Libellés : statut API `SENT`, texte « Envoyée au drive ».

**Ce qui reste à l'humain** : lire les CGU (DRV-003) · faire tourner le banc d'essai sur son téléphone et fournir le rapport (DRV-002) · relever les en-têtes avec les DevTools (DRV-004) · approuver les déploiements et activer l'interrupteur en production (DRV-505, ADR 0017) · valider ou corriger les décisions par défaut ci-dessus.

## 8. Inconnues et risques

**Inconnues non levées** : DataDome face au favori sur téléphone · CGU Leclerc · CSP et refus de cadre de Leclerc · lancement du favori dans le Chrome de l'utilisateur · ouverture dans Chrome complet depuis la PWA · repérage fiable du produit ajouté dans le cadre · identifiants stables entre magasins · EAN disponible · contenu net déductible · persistance du panier site → appli · adresse de la page panier · qualité de la recherche texte · stabilité du site dans le temps (cassure certaine à terme) · canal partenaire éventuel (programme d'affiliation Awin « E.Leclerc FR » repéré, périmètre non vérifié).

**Risques principaux** : (1) DataDome bloque le favori sur téléphone → DRV-002 ; (2) CGU interdisent → DRV-003 ; (3) CSP ou refus de cadre → DRV-004 et 002, repli P2, sinon Firefox, sinon mode assisté ; (4) lancement du favori pénible ou cassé par une mise à jour de Chrome → DRV-002 ; (5) le site change → interrupteur, runbook, détection ; (6) mauvaise qualité de correspondance → DRV-002, choix mémorisés, indice de recherche ; (7) onglet suspendu pendant un passage → Wake Lock, reprise ; (8) support des proches (installation du favori) → page d'aide, installation faite une fois pour eux ; (9) maintenance pour une personne → rester mince, contexte supprimable ; (10) dérive multi-enseigne trop tôt → un port, un adaptateur.

## 9. Livrables de documentation (après approbation) et étapes

Aucun code n'est écrit par l'assistant. Il produit uniquement :

0. **Première action, avant tout le reste** : copier ce plan intégralement (fichier `/home/kantin/.claude/plans/je-vais-avoir-un-breezy-lightning.md`, hors dépôt et hors mémoire, donc perdu pour une nouvelle conversation) dans `docs/drive/plan-initial.md`, pour qu'aucune information ne dépende du fichier de plan ni de la conversation. Les autres fichiers en sont dérivés et le complètent.
1. Branche `docs/drive-leclerc` (dépôt back) depuis `main`.
2. `docs/drive/README.md` (point d'entrée des agents d'implémentation : contexte, décisions, glossaire, ordre de lecture, règles du chantier, statut), `recherche.md` (§ 1 et sources), `analyse.md` (§§ 2-3, 5, 7-8), `architecture.md` (§ 4 complet avec exemples de messages), `roadmap.md` (jalons, dépendances, cases à cocher, correspondance avec les issues), `spikes/` (gabarits des rapports DRV-002/003/004), `tickets/DRV-xxx-*.md` (41 fichiers au format du § 6).
3. `docs/adr/0022-remplissage-du-panier-drive.md` et `docs/adr/0023-liste-envoyee-au-drive.md` (gabarit `0000-template.md`, statut **Proposed**) ; lignes ajoutées au tableau de `docs/adr/README.md` ; une ligne dans `CLAUDE.md` (« Source de vérité ») qui renvoie vers `docs/drive/README.md`.
4. Vérifications : liens relatifs, aucune donnée personnelle ni secret, `make quality` si Docker tourne (sinon la CI).
5. Commits courts en français **sans** `Co-Authored-By` ni pied de PR (`CLAUDE.md` prime sur le rappel d'attribution du harnais), push, `gh pr create` (gabarit Quoi / Vérifié / À relire), fusion `--squash --delete-branch` si la CI est verte (autorisé sur le dépôt back depuis 2026-10-16).
6. Une fois fusionné : labels (`drive`, `spike`, `back`, `front`, `agent`, `humain`) et issues dans les deux dépôts (épiques par jalon + une issue par ticket), puis mise à jour de `roadmap.md` avec les numéros (petite PR docs).
7. Dépôt front : une PR docs minimale ajoutant dans son `CLAUDE.md` le renvoi vers `docs/drive/README.md` du dépôt back (fusion autorisée sur ce dépôt).
8. Mémoire de l'assistant : `project-drive-leclerc.md` (décisions, emplacement de la doc, état) et un retour d'expérience « sur le chantier Drive l'assistant est PO/architecte, ne code pas, ne demande pas quand lancer le code, tout est stocké », avec leurs lignes dans `MEMORY.md`.
9. Compte rendu à l'utilisateur : emplacement des fichiers et liens des issues. Pas de question sur le lancement du code.

## Vérification

- Chaque fait de `recherche.md` porte une URL et une date ; les inconnues sont listées comme telles, rien n'est présenté comme vérifié sans l'être.
- Chaque ticket est autonome (contexte, portée, spécification, critères, tests, DoD) et ses dépendances existent ; `roadmap.md` et les issues portent les mêmes identifiants.
- `git diff --stat` : uniquement `docs/`, `docs/adr/README.md` et la ligne de `CLAUDE.md` ; `grep` de l'adresse e-mail de l'utilisateur, de numéros de magasin personnels et de toute clé : aucun résultat.
- Les liens relatifs des nouveaux fichiers existent ; `docs/adr/README.md` liste 0022 et 0023 en `Proposed`.
- CI verte avant fusion.
- Relecture finale contre les décisions du 2026-10-17 (principe, Android, favori, public, flux, verrou, documentation, rôle sans code).

## Annexe — sources (consultées le 2026-10-17)

- Pas d'API publique : [Pepesto – Leclerc](https://www.pepesto.com/supermarkets/leclerc/) · [KFMichael/site-anniversaire PR #18](https://github.com/KFMichael/site-anniversaire/pull/18)
- Routes internes : [skunkobi/mcp-leclerc-drive, docs/api-capture.md](https://github.com/skunkobi/mcp-leclerc-drive/blob/main/docs/api-capture.md) · [ncleton/leclerc-drive-mcp](https://github.com/ncleton-petitmaker/leclerc-drive-mcp) · [konnectors/leclercdrive (Cozy)](https://github.com/konnectors/leclercdrive)
- Scrapers payants : [Apify studio-amba](https://apify.com/studio-amba/leclerc-scraper) · [Apify abotapi](https://apify.com/abotapi/leclerc-fr-scraper/api) · [Piloterr](https://www.piloterr.com/library/eleclerc-search)
- Site : [robots.txt](https://www.leclercdrive.fr/robots.txt) · [Page légale E.Leclerc](https://www.e.leclerc/e/legal) (illisible par automate) · [FAQ drive](https://faq.e.leclerc/hc/fr/articles/26988303348625-Comment-faire-vos-courses-au-E-Leclerc-Drive)
- Autres enseignes : [Intermarché Scraper (Apify)](https://apify.com/studio-amba/intermarche-fr-scraper) · [scrape.do Intermarché](https://scrape.do/industries/ecommerce/intermarche-scraper/) · [Auchan scraper (Spider)](https://spider.cloud/scrapers/auchan-scraper/)
- Prior art : [Jow – App Store](https://apps.apple.com/fr/app/jow-recettes-menus-courses/id1301257625) · [Jow – PC Astuces](https://www.pcastuces.com/apps/jow.htm) · [Jow – signalements](https://www.totalbug.com/jow/) · [Mealz (Welcome to the Jungle)](https://welcometothejungle.com/fr/companies/miam/culture-1) · [MealMe Carts API](https://apis.apievangelist.com/store/mealme-carts-api/)
- Extensions et mobile : [Chrome Android 2026](https://extensionbooster.net/blog/chrome-extensions-android-2026-what-works-guide/) · [Firefox Android](https://kulturegeek.fr/news-301700/firefox-android-supporte-desormais-plus-450-extensions) · [Firefox Android, extension non répertoriée](https://discourse.mozilla.org/t/install-an-unlisted-extension-in-firefox-android/137802) · [Safari](https://developer.apple.com/safari/resources) · [MDN externally_connectable](https://developer.mozilla.org/docs/Mozilla/Add-ons/WebExtensions/manifest.json/externally_connectable) · [Chromium : fetch des scripts de contenu](https://new.chromium.org/Home/chromium-security/extension-content-script-fetches/) · [Chrome Web Store, distribution](https://developer.chrome.com/docs/webstore/cws-dashboard-distribution)
- Favoris JavaScript : [Chrome mobile](https://labnol.org/software/google-chrome-bookmarklets/27894) · [iPhone/iPad](https://www.schieb.de/?p=719396) · [Mozilla bug 866522](https://bugzilla.mozilla.org/show_bug.cgi?id=866522) · [WebKit bug 156106](https://bugs.webkit.org/show_bug.cgi?id=156106)
- Droit : [Ryanair c. PR Aviation (SCL)](https://www.scl.org/3280-the-cjeu-takes-flight-databases-and-air-fares/)
- Données ouvertes (non retenues pour l'instant) : [Open Prices](https://github.com/openfoodfacts/open-prices) · [data.gouv.fr](https://data.gouv.fr/fr/datasets/open-prices)
