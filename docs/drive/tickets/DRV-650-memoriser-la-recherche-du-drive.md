# DRV-650 — Mémoriser la recherche du drive qui a fait trouver le produit

- **Dépôt** : back (lot 1), front (`drive-agent/` et web, lot 2)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M (deux lots)
- **Dépend de** : DRV-642 (rayon appris, fait), DRV-635 (indisponible n'est pas introuvable, fait)
- **Statut** : **lot 1 (back) fait, PR back #228 (2026-10-20)** ; lot 2 (agent, protocole, web, « Mes produits ») à faire. Demande de l'utilisateur du 2026-10-20. Effort de réalisation : `high`.
- **Lire d'abord** : [`DRV-642`](DRV-642-chercher-dans-le-bon-rayon.md) (précédent exact : une donnée de catalogue apprise du choix, colonnes ajoutées, migration additive), [`../architecture.md`](../architecture.md) §§ 5.1, 5.3, 7, [`../note-vie-privee.md`](../note-vie-privee.md), la règle « Ne pas durcir Leclerc » de [`../consignes-agents.md`](../consignes-agents.md) et [`../multi-enseignes.md`](../multi-enseignes.md), skills `repas-drive-back`, `repas-agent-dev`, `repas-front-dev`, `repas-po`.

## Contexte

L'utilisateur, 2026-10-20 : « On dit "Céréale coussin" car c'est notre terme, mais sur le drive Leclerc ce n'est pas ce nom-là qu'il faut chercher. Quand on choisit un article via la recherche du drive, stocke la recherche (et le rayon) pour que la prochaine fois, si l'article n'est pas disponible, tu puisses faire une pré-recherche plus précise pour trouver un remplaçant. »

Aujourd'hui le plan fait toujours chercher le **nom de l'ingrédient de Repas** (`searchQuery` = `ingredientName`). Le rayon du produit choisi est déjà mémorisé (DRV-642 : `rayonId` / `familyId` du produit). Ce qui manque est **la recherche qui a fait trouver le produit**.

## Règle de fond : la recherche est propre à l'enseigne

La recherche mémorisée **n'est pas commune à tous les drives** : c'est un texte qui marche sur le site d'une enseigne (le drive Auchan aurait sa propre recherche si besoin). Elle vit donc sur le `Choice`, sous la clé existante `(utilisateur, enseigne, magasin, ingrédient)` :

- deux enseignes qui ont le même ingrédient gardent **chacune la leur** ;
- **aucune lecture ni aucun repli** d'une enseigne vers une autre : le plan d'une enseigne ne reprend que les recherches de **ses** choix ;
- un changement de magasin suit la règle actuelle des choix : rien n'est repris d'office pour le nouveau magasin ;
- le champ et le type restent génériques (`searchQuery`, `SearchQuery`) : **rien de propre à Leclerc** dans le domaine ni dans le contrat ; seule la **valeur** est propre à l'enseigne.

Un test de base le garde (deux enseignes, un même utilisateur, un même magasin et un même ingrédient : chacune garde sa recherche ; l'enseigne n'existe qu'en une valeur dans le code, la deuxième est écrite en SQL dans le test) : `ChoiceRepositoryTest::testTheSearchIsKeptPerRetailerStoreAndUser`, `ChoicePostgreSQLLoaderTest::testTheSearchOfAnotherRetailerAnotherUserOrAnOldStoreIsNeverListed`, `DrivePlanPostgreSQLLoaderTest::testTheSearchOfAnotherRetailerAnotherStoreOrAnotherUserIsNeverUsed`.

## Portée

**Lot 1 — back (fait)**

1. `Choice` gagne `searchQuery` (`?SearchQuery`) ; colonne `drive_choice.search_query VARCHAR(80) NULL` (migration additive `Version20261020150000`, compatible avec la version précédente du code). Valeur de domaine `SearchQuery` : espaces de bord retirées, suites d'espaces (insécables comprises) ramenées à une seule, **2 à 80 caractères**, aucun caractère de contrôle, de format ni séparateur de ligne ou de paragraphe ; sinon `422 DRIVE_SEARCH_QUERY_INVALID`. Seule une préférence de **produits** en porte une (jamais « j'en ai toujours »).
2. `PUT /api/v1/drive/choices` : clé **facultative** `searchQuery` par intention, sur `ADD_PRODUCT` seulement. **Clé absente = la recherche mémorisée est gardée** (une ancienne application n'efface rien) ; **`null` = elle est oubliée** ; **texte = elle remplace l'ancienne**. Même lot atomique, aucune requête de plus (budgets `choix-drive` 10 et `choix-drive-lot` 11 inchangés).
3. `GET /api/v1/drive/choices` : `searchQuery` (`string | null`, toujours écrite) par choix, pour l'écran « Mes produits ». `GET /api/v1/drive/plans/{id}` : `searchQuery` d'une ligne = la recherche mémorisée de son choix, sinon le nom de l'ingrédient comme avant ; `ingredientName` ne change pas ; aucun autre champ du plan ne change.
4. Vie privée : texte tapé par la personne, privé, lié au `Choice`, effacé avec lui (donc avec le compte, ADR 0021 : aucune nouvelle colonne utilisateur, `UserOwnedDataCoverageTest` n'a rien à ajouter), jamais dans une URL ni un journal ; [`../note-vie-privee.md`](../note-vie-privee.md) mise à jour.
5. Contrat : `docs/api/openapi.json` et `docs/drive/samples/{choices,plan}.sample.json` régénérés.

**Lot 2 — agent, protocole, web (à faire après la fusion du lot 1)**

1. **Mémoriser** : l'agent retient la requête qui a fait apparaître le produit choisi (le mot cherché, ou celui que la personne a tapé dans « Chercher autre chose ») et l'envoie dans la ligne du résultat (clé **facultative** du protocole v1, rien de nouveau pour la personne). Le site l'enregistre en l'envoyant dans l'intention `ADD_PRODUCT` de `PUT /drive/choices`, après l'avoir **nettoyée** (voir « Pièges »).
2. **Utiliser** : pour un produit connu devenu indisponible, la pré-recherche d'un remplaçant utilise, dans l'ordre : la recherche mémorisée (`searchQuery` de la ligne du plan), le libellé du produit mémorisé, le nom de l'ingrédient ; le rayon appris (`rayonHints`) sert à classer les candidats comme aujourd'hui.
3. **Montrer** : « Mes produits » affiche la recherche mémorisée de chaque article (`searchQuery` de `GET /drive/choices`), sans réglage de plus ; si la personne peut la corriger, c'est une décision de produit à prendre avec le PO (le back ne propose aujourd'hui que le remplacement de produits).
4. **Textes** : la note de vie privée du front (clés `driveTerms.saves.*`) reçoit la phrase ajoutée à [`../note-vie-privee.md`](../note-vie-privee.md) **avant** que le site n'envoie une première recherche.

## Hors portée

Corriger la recherche à la main dans « Mes produits » (à décider, règle 3 de `principes-produit.md` : pas de réglage de plus sans besoin) ; remplir une recherche pour les produits déjà choisis (elle se remplit au prochain choix) ; une recherche par produit (elle est par **ingrédient**, comme le choix) ; toute lecture entre enseignes ; la recherche sur les refus ou sur « j'en ai toujours ».

## Critères d'acceptation

- Étant donné un produit choisi avec la recherche « céréales coussins » pour l'ingrédient « Céréale coussin », quand la liste suivante est envoyée, alors la ligne du plan porte `searchQuery` « céréales coussins » et `ingredientName` « Céréale coussin ».
- Étant donné une application qui n'envoie pas la clé, alors la recherche déjà mémorisée est gardée ; avec `null`, elle est oubliée ; avec un texte, elle est remplacée.
- Étant donné deux enseignes pour un même ingrédient, alors chacune garde sa recherche et aucune ne reprend celle de l'autre.
- Étant donné un texte mal formé, alors `422 DRIVE_SEARCH_QUERY_INVALID` et rien n'est enregistré.
- Étant donné « j'en ai toujours », alors aucune recherche n'est mémorisée ni rendue.
- Un compte supprimé n'a plus aucune recherche.

## Tests exigés

Lot 1 (faits) : valeur de domaine à table (`SearchQueryTest`), `Choice` (`ChoiceTest`), handler en unitaire avec doubles en mémoire (clé absente, `null`, texte, remplacement, dernier de la liste gagne, « j'en ai toujours », autre utilisateur, autre magasin, action refusée, lot sans requête de plus), repository et chargeurs sur base réelle (dont une autre enseigne écrite en SQL), controller (clés JSON, absente / `null` / texte, 422), parcours de bout en bout avec les vrais bus (`DrivePlanApiFlowTest`), budget SQL (`choix-drive*` avec une recherche), exemples de contrat. Lot 2 : agent (la requête retenue est celle du produit choisi, y compris après « Chercher autre chose » ; ordre recherche mémorisée, libellé, nom), protocole compatible avec un agent ou un site ancien, site (nettoyage et coupe à 80 caractères, jamais d'envoi trop court), « Mes produits ».

## Definition of Done

Celle des deux dépôts. Lot 1 : `make test` et `make quality` verts, `make openapi` relu, roadmap cochée par le lot 2 seulement.

## Pièges connus

- **Tout ou rien** : un texte mal formé fait refuser tout le lot, donc **perdrait aussi les produits choisis**. Le site doit nettoyer avant d'envoyer et ne jamais envoyer un texte qu'il sait refusé : retirer les caractères de contrôle, de format et les séparateurs (catégories Unicode `Cc`, `Cf`, `Zl`, `Zp`), ramener les suites d'espaces à une seule, retirer les espaces de bord, **couper à 80 caractères par points de code** (pas par unités UTF-16 : une coupe au milieu d'une paire de substitution fait un JSON que le back rejette), retirer les espaces de bord laissées par la coupe, et ne pas envoyer le texte s'il reste moins de 2 caractères (envoyer plutôt rien : la clé absente garde l'ancienne recherche, `null` l'efface).
- **Clé absente ≠ `null`** : un client qui enverrait `searchQuery: null` partout effacerait les recherches ; ne l'envoyer à `null` que pour oublier volontairement. Le schéma OpenAPI écrit `default: null` pour cette clé, c'est un artefact du générateur de documentation (une clé absente n'est pas `null`) : un client généré ne doit pas remplir ce défaut.
- **Texte de la personne** : l'afficher comme du texte (jamais comme du HTML), ne jamais le mettre dans une adresse (`GET` ou lien : Caddy journalise l'URI) ni dans un journal ou un diagnostic. Un message d'erreur du back est un code, jamais le texte.
- La recherche est **par ingrédient** : plusieurs `ADD_PRODUCT` d'un même ingrédient dans un lot, la dernière recherche reçue gagne. Envoyer la recherche du produit placé en `PRIMARY`, pas celle d'un produit de secours.
- Un lot ne mentionne `searchQuery` que sur `ADD_PRODUCT` : sur un refus, « j'en ai toujours » ou un rafraîchissement de prix, même `null` est `422 DRIVE_CHOICE_INVALID`.
- `PUT /drive/choices/{ingredientSlug}` (écran de gestion) garde la recherche, sauf pour « j'en ai toujours » ; une préférence qui devient vide est supprimée avec sa recherche ; « Oublier cet article » l'efface aussi.
- Ordre de publication du protocole (règle du § 12 de `architecture.md`) : si l'agent ou le plan gagnent une clé, **le site web avant l'application Android** qui embarque le nouvel agent. Côté back, rien à attendre : la clé est facultative et le plan l'écrit déjà.
- Le plan peut porter une recherche de 80 caractères : `plan.schema.json` accepte 1 à 255 caractères pour `searchQuery`, aucune limite à relever.

## Réalisation du lot 1 (back, 2026-10-20)

Rien n'est publié ni activé. Un client ou un agent qui ne connaît pas la recherche mémorisée marche exactement comme avant (le plan cherche le nom de l'ingrédient tant qu'aucune recherche n'est mémorisée).

**Contrat** (valeurs inventées ; `docs/api/openapi.json` et `docs/drive/samples/*.sample.json` font foi)

- `PUT /api/v1/drive/choices` : l'intention gagne `searchQuery`, texte ou `null`, **facultatif**. Exemple : `{"ingredientSlug": "cereale", "action": "ADD_PRODUCT", "placement": "PRIMARY", "product": {"retailerProductId": "555101", "label": "Céréales coussins chocolat 500 g"}, "searchQuery": "céréales coussins"}`. Erreurs : `422 DRIVE_SEARCH_QUERY_INVALID` (moins de 2 ou plus de 80 caractères après réduction des espaces, caractère de contrôle, de format ou séparateur) ; `422 DRIVE_CHOICE_INVALID` (la clé est mentionnée, même à `null`, sur autre chose qu'`ADD_PRODUCT`) ; un `searchQuery` qui n'est pas du texte (nombre, booléen, liste, objet) est refusé par la validation du corps, `422` générique `{error:{message}}`.
- `GET /api/v1/drive/choices` : chaque élément gagne `searchQuery` (`string | null`, **toujours écrite**), à la fin de l'objet. `null` quand rien n'est mémorisé et pour « j'en ai toujours ».
- `GET /api/v1/drive/plans/{shoppingListId}` : la clé `searchQuery` de chaque ligne existait ; sa valeur est maintenant la recherche mémorisée du choix de la ligne (pour ce magasin, cette enseigne et cet utilisateur), sinon le nom de l'ingrédient. Une ligne `NEW` dont le choix ne garde que des refus et une recherche la reprend ; une ligne « j'en ai toujours » cherche le nom. Aucun autre champ ne change.

**Modèle** : `drive_choice.search_query` (VARCHAR(80), nullable) ; migration `Version20261020150000` additive, compatible avec la version précédente du code (ADR 0017 règle 6 : son `INSERT … ON CONFLICT` ne touche pas la colonne, une valeur déjà écrite reste ; le retour arrière du code ne défait pas le schéma). Une ligne « j'en ai toujours » qu'une version plus ancienne aurait laissée avec une recherche est lue sans elle (`Choice::load()`, chargeurs). `Choice::rememberSearch()` / `forgetSearch()` ; `markHaveAtHome()` et `replace(haveAtHome: true)` l'effacent ; `replace()` sinon la garde.

**Coût SQL** : aucune requête de plus (la colonne voyage avec la préférence) : `PUT /drive/choices` garde ses budgets `choix-drive` 10 et `choix-drive-lot` 11 (les scénarios portent désormais une recherche) ; le plan reste à 5 requêtes au plus.

**Vie privée** : pas de colonne utilisateur nouvelle, la recherche part avec la ligne du choix (`deleteByUser()`) ; le back ne journalise pas les corps de requête et ses messages d'erreur sont des codes ; la note de vie privée a une phrase de plus (« À garder vrai » et registre à jour).

**Pour le lot 2** : voir « Pièges connus » ci-dessus, en particulier le nettoyage côté site, la clé absente contre `null`, la coupe par points de code et l'ordre de publication.
