# DRV-653 — Mémoriser la recherche aussi quand le produit est pris « Pas mon habituel »

- **Dépôt** : back (lot 1), front (`drive-agent/` et web, lot 2)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : S (deux lots)
- **Dépend de** : DRV-650 (la recherche mémorisée avec le choix, fait côté back)
- **Statut** : **fait ; lot 1 (back) fait, PR back #230 (2026-10-20)** ; lot 2 (agent + site) fait, PR front #183 (2026-10-20). Demande de l'utilisateur du 2026-10-20. Effort de réalisation : `medium` (lot 1 : `high`, il touche le contrat de `PUT /drive/choices`).
- **Lire d'abord** : [`DRV-650`](DRV-650-memoriser-la-recherche-du-drive.md) (précédent exact : même colonne, même texte, mêmes erreurs, clé absente / `null` / texte), [`DRV-652`](DRV-652-memoriser-la-contenance-en-unite-de-la-liste.md) (même mécanique d'intentions, lot tout ou rien), [`../architecture.md`](../architecture.md) §§ 5.3, 7, [`../note-vie-privee.md`](../note-vie-privee.md), la règle « Ne pas durcir Leclerc » de [`../consignes-agents.md`](../consignes-agents.md) et [`../multi-enseignes.md`](../multi-enseignes.md), skills `repas-drive-back`, `repas-agent-dev`, `repas-front-dev`, `repas-po`.

## Contexte

Dans le sélecteur de produit de l'agent, un article peut être pris **« Pas mon habituel »** (placement `ONCE`) : le produit va au panier cette fois, rien n'est gardé comme produit habituel, et l'article sera redemandé au prochain envoi. Mais la **recherche** qui a fait trouver ce produit (« céréales coussins » pour « Céréale coussin ») reste la bonne recherche pour cet ingrédient. Aujourd'hui (DRV-650) elle n'est mémorisée qu'avec un `ADD_PRODUCT`, donc jamais pour un produit pris « Pas mon habituel » : le prochain envoi cherche de nouveau « Céréale coussin ».

## Règle de fond : la même recherche, la même clé, sans produit

- La recherche reste **propre à l'enseigne** : elle vit sur le `Choice`, sous la clé existante `(utilisateur, enseigne, magasin, ingrédient)`. Aucun repli ni lecture d'une enseigne à l'autre (voir DRV-650).
- Mémoriser une recherche **ne mémorise aucun produit** : ni accepté, ni refusé, ni contenance. Un article « Pas mon habituel » reste un article à redemander.
- Rien de propre à Leclerc : l'intention et le champ sont les mêmes pour toutes les enseignes.

## Portée

**Lot 1 — back (fait)**

1. Nouvelle action `NOTE_SEARCH` dans `PUT /api/v1/drive/choices` : `{ingredientSlug, action: "NOTE_SEARCH", searchQuery}`. `searchQuery` est **obligatoire** et doit être un texte (mêmes règles que DRV-650 : 2 à 80 caractères, normalisation, `422 DRIVE_SEARCH_QUERY_INVALID`). Ni `product` ni `placement` (et donc pas de `packSize`).
2. Effet : la recherche remplace celle qui est mémorisée pour l'ingrédient, **sans toucher aux produits acceptés, refusés ni aux contenances**. Si l'ingrédient n'a pas encore de choix, il est créé (genre `PRODUCTS`, aucun produit).
3. Lecture : `GET /drive/plans/{id}` sert `searchQuery` à la ligne, qui reste `NEW` (jamais `KNOWN` sans produit accepté) ; `ingredientName` ne change pas. `GET /drive/choices` **ne liste pas** un choix sans aucune ligne de produit qui n'est pas « j'en ai toujours » (voir « Décision »).
4. Purge du compte, « Oublier cet article » : le choix part avec sa recherche, comme tout choix (aucune nouvelle colonne utilisateur).
5. Contrat : `docs/api/openapi.json` régénéré, tableau des actions et § 5.3 de `architecture.md`, note de vie privée, glossaire de `principes-produit.md` (§ 5 : « Choisir » / « Pas mon habituel »).

**Lot 2 — agent + site (à faire après la fusion et la release du lot 1)**

1. **Agent** : quand l'article est pris `ONCE` (« Pas mon habituel »), la ligne `CHOSEN` du résultat porte aussi `searchQuery`, la requête qui a fait apparaître le produit (le mot cherché, ou celui que la personne a tapé dans « Chercher autre chose »), **quand elle diffère de celle du plan** (`searchQuery` de la ligne du plan). Clé facultative du protocole v1, rien de nouveau à l'écran. Le placement `PRIMARY` / `FALLBACK` garde le comportement de DRV-650 (la recherche part dans l'`ADD_PRODUCT`).
2. **Site** : il traduit une ligne `CHOSEN` de placement `ONCE` qui porte une `searchQuery` en intention `NOTE_SEARCH`, après l'avoir **nettoyée** comme pour DRV-650 (retirer les caractères de contrôle, de format et les séparateurs, réduire les espaces, couper à 80 caractères par points de code, ne pas l'envoyer s'il reste moins de 2 caractères). **Jamais d'intention vide** : sans texte exploitable, il n'envoie pas d'intention du tout (le back la refuserait, et ce serait tout le lot).
3. **Textes** : aucune phrase de plus (la note de vie privée dit déjà que la recherche est gardée, y compris pour « Pas mon habituel » ; la phrase du front `driveTerms.saves.*` de DRV-650 suffit). Aucun nouveau libellé à l'écran (skill `repas-po`).

## Hors portée

Montrer ou corriger à la main la recherche d'un article sans produit habituel dans « Mes produits » (à décider avec le PO ; voir « Décision ») ; remplir la recherche des articles déjà pris une fois (elle se remplit au prochain choix) ; une recherche par produit ; toute lecture entre enseignes.

## Critères d'acceptation

- Étant donné un article pris « Pas mon habituel » avec la recherche « céréales coussins » (intention `NOTE_SEARCH`), quand la liste suivante est envoyée, alors la ligne du plan porte `searchQuery` « céréales coussins », `status` `NEW`, `products` vide, et `ingredientName` « Céréale coussin ».
- Étant donné un ingrédient déjà mémorisé (produits, refus, contenance), quand `NOTE_SEARCH` arrive, alors seule sa recherche change.
- Étant donné `NOTE_SEARCH` sans `searchQuery`, avec `searchQuery: null`, avec un `product` ou un `placement`, ou sur un ingrédient « j'en ai toujours », alors `422 DRIVE_CHOICE_INVALID` et rien n'est enregistré, même pour les autres intentions du lot.
- Étant donné un texte mal formé, alors `422 DRIVE_SEARCH_QUERY_INVALID` et rien n'est enregistré.
- Étant donné deux enseignes pour un même ingrédient, alors chacune garde sa recherche (garanti par la clé du choix, tests de DRV-650).
- « Mes produits » n'affiche aucune ligne vide pour ces articles.
- Un compte supprimé n'a plus aucune de ces recherches ; « Oublier cet article » non plus.

## Tests exigés

Lot 1 (faits) : handler en unitaire avec doubles en mémoire (choix inexistant créé sans produit, choix existant aux produits gardés intacts, « j'en ai toujours » refusé et lot non enregistré, intentions mal formées refusées avant toute lecture, dernière recherche du lot gagnante, ordre avec `ADD_PRODUCT` et `HAVE_AT_HOME`, autre utilisateur / magasin, aucune lecture ni écriture de produit, plafond de préférences), controller (clés JSON, absente / `null` / texte, 422 `DRIVE_SEARCH_QUERY_INVALID`), chargeurs sur base réelle (plan : ligne `NEW` avec la recherche ; choix : pas de ligne vide), purge, parcours de bout en bout avec les vrais bus (`DrivePlanApiFlowTest::testTheSearchOfAProductTakenOnlyOnceIsWhatThePlanSearchesNextTime` et `testNoteSearchIsRefusedWithoutASearchOnAHaveAtHomeIngredientOrWithAProduct`), budget SQL (`choix-drive-recherche` 6). Lot 2 : agent (la requête retenue est celle du produit pris `ONCE`, y compris après « Chercher autre chose », et n'est mise que si elle diffère de celle du plan), protocole compatible avec un agent ou un site ancien, site (nettoyage, coupe à 80 points de code, aucune intention vide, aucun `NOTE_SEARCH` pour `PRIMARY` / `FALLBACK`).

## Definition of Done

Celle des deux dépôts. Lot 1 : `make test` et `make quality` verts, `make openapi` relu, roadmap cochée par le lot 2 seulement.

## Pièges connus

- **Tout ou rien** : un `NOTE_SEARCH` mal formé fait refuser tout le lot, donc **perdrait aussi les produits choisis** des autres articles. Le site nettoie avant d'envoyer et n'envoie jamais d'intention qu'il sait refusée.
- **Texte obligatoire** : `NOTE_SEARCH` sans clé `searchQuery` ou avec `null` est `422 DRIVE_CHOICE_INVALID`. Pour **oublier** une recherche, c'est toujours `ADD_PRODUCT` avec `searchQuery: null` (DRV-650).
- **Ne pas doubler** : pour un produit retenu (`PRIMARY` / `FALLBACK`), la recherche part dans l'`ADD_PRODUCT` ; n'envoyer `NOTE_SEARCH` que pour un article pris `ONCE`. Un `NOTE_SEARCH` après un `HAVE_AT_HOME` du même article dans un lot est `422` (l'article est « j'en ai toujours ») ; l'agent ne rapporte jamais les deux pour un même article.
- **Pas de produit** : `NOTE_SEARCH` ne mémorise aucun produit ni prix ; il ne compte pas dans le plafond de 5 000 écritures de produits par jour, mais compte comme tout choix créé dans le plafond de 1 000 préférences par magasin.
- **Texte de la personne** : l'afficher comme du texte, ne jamais le mettre dans une adresse (`GET` ou lien) ni dans un journal ou un diagnostic. Un message d'erreur du back est un code, jamais le texte.
- **Ordre de publication** (règle du § 12 de `architecture.md`) : le **back d'abord** (l'action doit exister), puis le site web, puis l'application Android qui embarque le nouvel agent. Un site qui enverrait `NOTE_SEARCH` à un back qui ne la connaît pas ferait refuser tout le lot (`422` de validation de l'énumération) : ne rien envoyer avant la release du lot 1.

## Décision : « Mes produits » ne liste pas ces articles

`GET /drive/choices` écarte un choix **sans aucune ligne de produit** (ni accepté ni refusé) qui n'est pas « j'en ai toujours » : l'écran « Mes produits » du front en ferait une ligne vide (pas de libellé de produit à montrer), et la recherche n'a rien à faire dans une liste de produits tant que la personne n'a rien choisi. Le plan, lui, s'en sert. Un choix dont les lignes de produit existent mais dont le produit est inconnu continue d'être listé comme avant. Si le PO veut un jour montrer ou corriger ces recherches, ce sera un ticket d'écran (lot 2 ou suivant) qui ajoutera un filtre explicite ; le champ `searchQuery` existe déjà dans la réponse.

## Réalisation du lot 1 (back, 2026-10-20)

Rien n'est publié ni activé. Un client ou un agent qui ne connaît pas `NOTE_SEARCH` marche exactement comme avant.

**Contrat** (valeurs inventées ; `docs/api/openapi.json` fait foi)

- `PUT /api/v1/drive/choices` : `action` gagne la valeur `NOTE_SEARCH`. Exemple : `{"ingredientSlug": "cereale", "action": "NOTE_SEARCH", "searchQuery": "céréales coussins"}`. Réponse `204`. Erreurs : `422 DRIVE_SEARCH_QUERY_INVALID` (texte hors de 2 à 80 caractères après réduction des espaces, caractère de contrôle ou invisible) ; `422 DRIVE_CHOICE_INVALID` (clé absente ou `null`, `product` ou `placement` présents, ingrédient « j'en ai toujours » au moment de l'application, autres cas de DRV-650 inchangés) ; un `searchQuery` qui n'est pas du texte est refusé par la validation du corps, `422` générique. Tout ou rien.
- `GET /api/v1/drive/plans/{id}` : aucun champ ne change ; `lines[].searchQuery` reprend la recherche notée, `status` reste `NEW`, `products` vide.
- `GET /api/v1/drive/choices` : aucun champ ne change ; un choix sans aucune ligne de produit qui n'est pas « j'en ai toujours » n'est plus listé.

**Coût SQL** : le handler ne lit plus les produits quand le lot n'en décrit aucun. Scénario `choix-drive-recherche` : **6** requêtes (mesure de `WriteBudget`, pour une préférence existante et deux nouvelles), contre 10 pour `choix-drive` ; `choix-drive*` inchangés.

**Vie privée** : aucune nouvelle donnée ni colonne ; la recherche est celle de DRV-650, sous la même clé, effacée avec le choix et le compte. `note-vie-privee.md` précise qu'elle est gardée aussi pour « Pas mon habituel ».

**Pour le lot 2** : voir « Portée » (lot 2) et « Pièges connus », en particulier : l'agent met `searchQuery` dans la ligne `CHOSEN` `ONCE` seulement quand elle diffère de celle du plan, le site la traduit en `NOTE_SEARCH` nettoyée, jamais d'intention vide, back avant site avant Android.
