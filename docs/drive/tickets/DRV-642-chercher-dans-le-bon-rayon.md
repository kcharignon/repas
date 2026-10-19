# DRV-642 — Chercher dans le bon rayon (produits frais)

- **Dépôt** : back (plan) + front (`drive-agent/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M à L (en deux lots)
- **Dépend de** : —
- **Statut** : **lot 1a (back) fait, PR #217 ; lot 1b (agent, protocole et front) à faire** : voir « Réalisation du lot 1a ». Conception retenue (lot 1, voir plus bas) (demande de l'utilisateur du 2026-10-19 : « quand on veut des produits frais on ne voit jamais les bons produits ; chercher dans le bon rayon, fruits et légumes par exemple, devrait aider »). Effort de réalisation : `high`.
- **Lire d'abord** : `docs/drive/recherche.md` (champs `iIdRayon`, `iIdFamille`, `niIdSousFamille` de chaque produit de la recherche), `docs/drive/hypotheses-site-reel.md`, ADR 0022, skills `repas-drive-back`, `repas-agent-dev`, `repas-po` (règle 3 : pas de réglage de plus).

## Contexte

La recherche du site (`recherche.aspx?TexteRecherche=…`) renvoie tous les produits dont le nom contient les mots : « tomate » ramène sauces et conserves avant les tomates fraîches. Chaque produit porte pourtant le rayon (`iIdRayon`), la famille et la sous-famille de Leclerc, et un ingrédient de Repas appartient déjà à un rayon (`Department`, utilisé pour grouper la liste de courses).

## Portée

**Lot 1 — classer, sans filtrer (agent seul)** : l'agent reçoit, pour chaque ligne du plan, le rayon de Repas (clé optionnelle et additive du protocole, `department`: slug) ; il place d'abord les candidats dont le rayon Leclerc est celui où l'on achète d'habitude cet ingrédient, puis les autres, sans en cacher aucun. L'association « rayon de Repas → rayon(s) Leclerc » s'**apprend** des choix : à chaque produit choisi, l'agent renvoie `iIdRayon`/`iIdFamille` du produit (clés optionnelles du résultat) ; le back garde pour chaque (utilisateur, enseigne, rayon de Repas) les rayons Leclerc les plus choisis. Aucun identifiant de rayon écrit en dur.

**Lot 2 — filtrer par le site (après mesure)** : si le site accepte un filtre de rayon dans l'adresse de recherche, l'utiliser comme première tentative (puis repli sans filtre) ; à mesurer d'abord au diagnostic sur un téléphone (jamais depuis le PC : DataDome).

## Hors portée

Liste de rayons Leclerc écrite à la main, filtrage qui cache des produits, réglage visible par l'utilisateur.

## Critères d'acceptation

- Étant donné un ingrédient du rayon « Fruits et légumes » dont le choix précédent est un produit du rayon frais, « tomate » montre d'abord les tomates fraîches.
- Étant donné un rayon jamais appris, l'ordre est celui du site.
- Le résultat envoyé à Repas ne gagne que des clés optionnelles ; un agent ancien reste compatible.

## Tests exigés

Back : loader du plan (rayon de la ligne), apprentissage (handler), contrat OpenAPI ; agent : classement sur le faux site (produits de deux rayons), protocole compatible ; recette sur le téléphone de test avec « tomate », « pomme », « courgette ».

## Definition of Done

Celle des deux dépôts ; `docs/drive/recherche.md` complété par la mesure.

## Pièges connus

- Un seul agent à la fois sur le back.
- Les identifiants de rayon peuvent différer d'un magasin à l'autre : les apprendre par magasin.
- Respecter la règle 10 : aucun message nouveau pour la personne.

## Constats réels (2026-10-19, téléphone de test, agent 0.29.0, site ordinateur, magasin de l'utilisateur)

- **Menu « Rayons » du site** : une vingtaine de rayons, dont « Ma soirée parfaite », « Foire aux vins », « Mon marché frais » (Mon boucher, Mon poissonnier, Mon primeur, Mon boulanger pâtissier, Mon fromager, Mon charcutier, Mon Traiteur), « Viandes Poissons », « Fruits Légumes », « Pains Pâtisseries », « Laitier Oeufs Végétal », « Charcuterie Traiteur », « Surgelés », « Epicerie salée », « Epicerie sucrée », « Boissons », « Saveurs du monde », « Bébé », « Bio », « Parapharmacie », « Hygiène Beauté », « Entretien Nettoyage », « Animalerie », « Maison Loisirs », « Traiteur ».
- **Deux niveaux** sous chaque rayon : les **familles** (ex. « Fruits Légumes » : Légumes, Fruits, Fruits secs et graines, Jus de fruits et légumes frais ; « Laitier Oeufs Végétal » : Laits et Oeufs, Crèmerie, Fromages, Yaourts Skyrs Fromages Blancs, Desserts, Compotes fraîches, Végétal, Laitier Oeufs Bio). Ces deux niveaux correspondent aux `iIdRayon` et `iIdFamille` de chaque produit de la recherche.
- **« Mon marché frais »** est un raccourci commercial (boucher, poissonnier, primeur…) qui double d'autres rayons : à ne pas prendre pour un rayon de classement.
- **Libellés** : l'application officielle et le site montrent des noms de rayons, mais **le JSON de la recherche ne porte que des identifiants** (aucun champ de libellé trouvé par DRV-646) : l'association identifiant → nom reste à lire sur le site ou à apprendre.
- **Taille d'une réponse** : la première réponse de la recherche « sel » contient **203 produits** (diagnostic réel). Le plafond de 8 candidats était donc très en dessous de ce que le site renvoie ; DRV-643 affiche tout en défilant.
- **Diagnostic des rayons** (DRV-646) : les lignes « Rayons vus pour tomate / pomme / courgette » n'apparaissent pas dans le rapport du « Diagnostic de l'agent » (agent 0.29.0) ; correction en cours (rendre les rayons visibles, rapport plus court).

## Mesure réelle des rayons (2026-10-19, agent 0.30.0, site ordinateur)

Diagnostic de l'agent sur le vrai site (identifiants du catalogue, aucune donnée personnelle) :

| Recherche | Produits lus | Rayons (`iIdRayon`:nombre) |
|---|---|---|
| tomate | 181 | 284319:130, 315989:12, 284311:11 |
| pomme | 196 | 284320:78, 284310:52, 284311:44 |
| courgette | 33 | 284311:12, 284246:6, 284320:5 |

Lecture : le rayon **284311** revient pour les trois produits frais (probablement « Fruits Légumes ») mais il est **minoritaire** (11 produits sur 181 pour « tomate », noyés parmi 130 produits d'un autre rayon) : c'est exactement ce qui fait qu'on ne voit « jamais les bons produits » en frais. Classer ces candidats en premier règle le problème sans en cacher aucun. Les noms des rayons ne figurent pas dans le JSON ; l'association se fait par identifiant, apprise.

## Conception retenue du lot 1 (contrat)

1. **Le produit retient son rayon** (fait public du catalogue, pas de donnée de personne) : le résultat de l'agent porte, pour un produit choisi, `rayonId` et `familyId` (entiers positifs, **facultatifs**, ajouts au protocole v1 sans changer sa version) ; `PUT /drive/choices` les accepte (`product.rayonId`, `product.familyId`) et le produit les mémorise (`drive_product.rayon_id`, `family_id`, nullables, migration additive compatible avec le code précédent).
2. **Le rayon de Repas de chaque ligne** : le port `Shared` `ShoppingListForDriveLoader` ajoute `departmentSlug` à chaque ligne (implémenté dans `Repas`), et un port `Shared` permet à `Drive` de connaître le rayon de Repas d'un ingrédient au moment d'enregistrer un choix.
3. **Apprentissage** : table `drive_department_rayon(retailer, department_slug, rayon_id, chosen_count)` (agrégat par enseigne, sans utilisateur ni magasin : ce sont des faits de catalogue) incrémentée à chaque choix enregistré avec un `rayonId` ; une enseigne = un jeu de rayons.
4. **Plan** : chaque ligne du plan gagne `rayonHints` (jusqu'à 3 identifiants de rayon, du plus choisi au moins choisi, pour le rayon de Repas de la ligne ; absent ou vide si rien n'est appris) ; clé facultative.
5. **Agent** (lot 1b, après le lot back) : en phase B et pour « Chercher autre chose », les candidats dont `iIdRayon` ∈ `rayonHints` passent d'abord (ordre du site conservé à l'intérieur de chaque groupe), puis les autres, sans en cacher aucun ; si `rayonHints` est absent, ordre du site ; renvoie `rayonId`/`familyId` du produit choisi.
4. **Démarrage à froid** : l'apprentissage démarre vide. Une **graine** facultative (migration de données) peut associer « fruits et légumes » au rayon 284311 pour Leclerc ; à ne poser qu'après vérification sur un second magasin (les identifiants de rayon peuvent différer) et marquée comme graine, jamais comme règle codée en dur.

## Réalisation du lot 1a (back, 2026-10-19)

Fait dans le back seulement ; rien n'est publié ni activé (aucune graine, aucun changement de `deploy/`). Un client ou un agent qui ne connaît pas les rayons marche exactement comme avant.

**Contrat** (valeurs inventées dans les exemples ; `docs/api/openapi.json` et `docs/drive/samples/plan.sample.json` font foi)

- `PUT /api/v1/drive/choices` : le `product` d'une intention gagne `rayonId` et `familyId`, **entiers de 1 à 2147483647, facultatifs, `null` accepté**. Absents ou `null` : la fiche du produit n'est pas modifiée ; une valeur déjà connue **n'est jamais remplacée** (premier écrivain gagnant, comme le code-barres) ni effacée ; hors bornes ou pas un entier : `422`, rien n'est enregistré. Exemple : `{"ingredientSlug": "farine", "action": "ADD_PRODUCT", "placement": "PRIMARY", "product": {"retailerProductId": "555101", "label": "Farine de blé T55 1 kg", "rayonId": 4001, "familyId": 4101}}`.
- `GET /api/v1/drive/plans/{shoppingListId}` : chaque ligne gagne **`rayonHints`**, tableau de **3 entiers au plus** (identifiants de rayon de l'enseigne, du plus choisi au moins choisi, à égalité le plus petit identifiant d'abord), `[]` si rien n'est appris pour le rayon de Repas de la ligne ou si la ligne n'en a pas. La clé est toujours écrite par le back et reste **facultative pour le lecteur**. Exemple de ligne : `{"ingredientSlug": "farine", …, "searchQuery": "Farine", "rayonHints": [4001, 4002]}`.
- `GET /api/v1/drive/choices` ne change pas (il ne rend pas le rayon).

**Modèle** : `drive_product.rayon_id` et `family_id` (INT, nullables) ; table `drive_department_rayon(retailer, department_slug, rayon_id, chosen_count)`, clé primaire `(retailer, department_slug, rayon_id)`, **sans utilisateur ni magasin** : rien à purger avec un compte, `UserOwnedDataCoverageTest` n'a pas à la lister. Migrations additives `Version20261019200000` (colonnes) et `Version20261019200100` (table), compatibles avec la version précédente du code (ADR 0017 règle 6) : le retour arrière du code ne défait pas le schéma.

**Apprentissage** : chaque `ADD_PRODUCT` (`PRIMARY` ou `FALLBACK`) dont le produit connaît son rayon compte un choix pour `(enseigne, rayon de Repas de l'ingrédient, rayon de l'enseigne)`. Le rayon pris est **celui de la fiche** (qui fait foi) et non celui de l'intention, pour qu'un agent qui se trompe ne fausse pas un produit déjà connu. Ni `REJECT_PRODUCT`, ni `REFRESH_PRICE`, ni `HAVE_AT_HOME`, ni un ingrédient sans rayon de Repas n'apprennent. Le rayon de Repas vient des ports `Shared` : `ShoppingListLineForDrive::departmentSlug` (lecture du plan) et `IngredientDepartmentResolver` (écriture du choix ; il ne s'appelle pas `…Loader` parce qu'un handler de commande n'appelle pas de `Loader`).

**Coût SQL** : `PUT /drive/choices` +2 requêtes seulement quand un choix porte un rayon (lecture des rayons de Repas, écriture des compteurs, constantes quelle que soit la taille du lot) : budgets `choix-drive` 8 → 10 et `choix-drive-lot` 9 → 11. `GET /drive/plans/{id}` : 5 requêtes au plus au lieu de 4 (aucune de plus si aucune ligne n'a de rayon de Repas).

**Pour le lot 1b (agent, protocole, front)** — à ne pas faire dans le back :
1. Protocole v1, ajouts **facultatifs** : `rayonHints` (entiers, 3 au plus) dans chaque ligne du message `plan` (le front le recopie depuis le plan du back), et `rayonId`/`familyId` (entiers de 1 à 2147483647) dans le `product` d'une ligne `CHOSEN` du résultat. Règle de déploiement du § 12 de `architecture.md` : un récepteur qui ne connaît pas un champ rejette le message entier, donc **le site web (validateur du protocole) avant l'application Android qui embarque le nouvel agent**.
2. Le front envoie `rayonId`/`familyId` dans `PUT /drive/choices` pour les produits choisis (le back accepte déjà).
3. L'agent met d'abord les candidats dont `iIdRayon` est dans `rayonHints`, puis les autres, sans en cacher aucun ; sans `rayonHints`, l'ordre du site.
4. `docs/drive/samples/plan.sample.json` est régénéré (`make drive-samples`) : le front le recopie (`make samples-update`) ; sa ligne `farine` porte `rayonHints: [4001]`.

**Écarts et points ouverts**
- L'apprentissage est **par enseigne**, pas par magasin (conception du lot 1, point 3 : « une enseigne = un jeu de rayons »), alors que « Pièges connus » dit que les identifiants peuvent différer d'un magasin à l'autre. Si la recette sur un second magasin le montre, ajouter une colonne `store_id` à la clé primaire (migration, puis lecture par magasin courant) ; les rayons ne sont qu'un classement, jamais un filtre, donc une erreur ne cache aucun produit.
- Pas de graine de départ (« fruits et légumes » ↔ rayon des fruits et légumes) : l'apprentissage démarre vide, comme le ticket le demande avant vérification sur un second magasin.
- L'apprentissage n'a pas d'autre borne par utilisateur que les plafonds existants (5 000 écritures de produits par jour, 60 écritures par minute) : une personne mal intentionnée peut peser sur le classement d'un rayon de Repas ; le seul effet est l'ordre des candidats proposés, jamais leur présence.
