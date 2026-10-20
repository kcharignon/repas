# DRV-652 — Mémoriser la contenance d'un produit dans l'unité de la liste

- **Dépôt** : back (lot 1), front (`drive-agent/` et web, lot 2)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M (deux lots)
- **Dépend de** : DRV-205 (`PackCalculator`, fait), DRV-650 (précédent exact : donnée privée mémorisée avec le choix, clé facultative de `PUT /drive/choices`, fait côté back)
- **Statut** : **lot 1 (back) fait, PR back #229 (2026-10-20)** ; lot 2 (agent, protocole, web, « Mes produits ») à faire. Demande de l'utilisateur du 2026-10-20. Effort de réalisation : `high`.
- **Lire d'abord** : [`DRV-650`](DRV-650-memoriser-la-recherche-du-drive.md) (même mécanique : migration additive, clé facultative, absente = gardée, `null` = effacée), [`DRV-205`](DRV-205-pack-calculator.md) (`PackCalculator`), [`../architecture.md`](../architecture.md) §§ 5.1, 5.3, 6, 7, [`../note-vie-privee.md`](../note-vie-privee.md), la règle « Ne pas durcir Leclerc » de [`../consignes-agents.md`](../consignes-agents.md) et [`../multi-enseignes.md`](../multi-enseignes.md), skills `repas-drive-back`, `repas-agent-dev`, `repas-front-dev`, `repas-po`.

## Contexte

L'utilisateur, 2026-10-20, après avoir lu l'écran « Vérifie la quantité » de l'agent : sa liste dit « cheddar en tranche : 15 tranche », le produit choisi s'appelle « Fromage Cheddar Burger Les Croisés 20 Tranches - 340g », et l'agent demande « Combien de produits (de 1 à 99) ? » **à chaque envoi**. Sa demande : « demande-moi combien il y a de tranches DANS le produit plutôt que combien de paquets, comme ça la prochaine fois tu ne me redemandes pas : tu connais déjà sa contenance en tranches. »

Pourquoi l'agent demande : la tranche n'a ni poids ni volume (`dimension` nulle), donc `PackCalculator` ne sait pas passer de « 15 tranches » au contenu net du produit (340 g) et le plan sort `packs = null`, `packsStatus = TO_CHECK`. Or **le nombre de paquets dépend de la liste, la contenance du produit non** : on mémorise la contenance, on calcule les paquets.

## Règle de fond : une réponse de la personne, privée, propre au produit

- La contenance est comptée **dans l'unité de la liste** (`{value: 20, unitSlug: "tranche"}`), pas en g, ml ou pièces : ce n'est **pas** le contenu net public de la fiche du produit (`drive_product`). Elle reste **privée** à l'utilisateur, effacée avec le compte.
- Elle est rattachée au **couple (préférence, produit)** : sur la ligne `drive_choice_product` du produit accepté, jamais sur la préférence entière. Chaque produit d'une préférence a la sienne ; **changer de produit ne réutilise jamais la contenance de l'ancien** (un nouveau produit préféré n'en a pas, l'ancien garde la sienne). Un produit qui cesse d'être accepté (refusé, retiré, remplacé hors de la liste) la perd, et l'accepter de nouveau ne la ramène pas.
- Elle ne vaut que pour **l'unité exacte** de la ligne (`unitSlug` égal) : si la liste passe de « tranche » à « gramme », elle est ignorée par le calcul (et reste renvoyée).
- Rien de propre à Leclerc : `packSize` et `unitSlug` sont des slugs d'unités de Repas, le champ est le même pour toutes les enseignes, qui gardent chacune leurs choix (clé du choix : utilisateur, enseigne, magasin, ingrédient).

## Portée

**Lot 1 — back (fait)**

1. `drive_choice_product` gagne `pack_size_value NUMERIC(7,3) NULL` et `pack_size_unit_slug VARCHAR(255) NULL` (migration additive `Version20261020180000`, compatible avec la version précédente du code). Valeur de domaine `PackSize` : `value` de 0,001 à 9 999 avec trois décimales au plus, `unitSlug` au motif `^[a-z0-9-]{1,255}$` et **désignant une unité qui existe dans Repas** ; sinon `422 DRIVE_PACK_SIZE_INVALID`. `Choice` porte une contenance par produit accepté (`rememberPackSize`, `forgetPackSize`, `getPackSize`, élaguées par chaque méthode qui retire un produit des acceptés).
2. `PUT /api/v1/drive/choices` : le `product` d'une intention `ADD_PRODUCT` accepte `packSize: {value, unitSlug}` ou `null`, **facultatif**. **Clé absente = la contenance déjà mémorisée pour le même produit est gardée** (une ancienne application n'efface rien ; un produit nouveau n'en a pas) ; **`null` = elle est oubliée** ; **objet = elle remplace l'ancienne**. Sur une autre action qu'`ADD_PRODUCT`, la mentionner (même `null`) est `422 DRIVE_CHOICE_INVALID`. Lot tout ou rien.
3. `PackCalculator::packsFor(LineQuantity, ?ProductContent, ?PackSize)` : règle prioritaire sur les règles 2 à 4 (après `PACKAGE`) : contenance dans l'unité exacte de la ligne → `packs = ceil(quantité / value)`, `COMPUTED` (tolérance 1e-9, minimum 1, plafond 99 comme les autres règles). `LineQuantity` gagne `unitSlug`.
4. Lecture : `GET /drive/plans/{id}` : `needed.unitSlug` et `needed.unitName` (nom de l'unité au singulier, pour écrire « Combien de tranches… ») ajoutés à `unitSymbol` ; `products[].packSize` (`{value, unitSlug}` ou `null`, toujours écrite) ; `packs` et `packsStatus` reflètent la règle. `GET /drive/choices` : `product.packSize` par produit (accepté ou refusé, `null` pour un refusé). L'estimation du coût (`GET /drive/estimates/{id}`) utilise la même règle (une ligne en tranches devient estimable), sans changer sa forme.
5. Vie privée : [`../note-vie-privee.md`](../note-vie-privee.md) (texte, « À garder vrai », registre) et [`../architecture.md`](../architecture.md) mis à jour ; `docs/api/openapi.json` et `docs/drive/samples/{choices,plan}.sample.json` régénérés (le sel y a un produit de secours compté en cuillères).

**Lot 2 — agent, protocole, web (à faire après la fusion du lot 1)**

1. **Poser la bonne question.** L'écran « Vérifie la quantité » ne demande plus « Combien de produits (de 1 à 99) ? » mais **« Combien de {unités} y a-t-il dans « {produit} » ? »** : champ numérique, aperçu **« Il te faut 15 tranches : 1 produit »** (même arrondi que le back : `ceil(quantité / valeur)`, tolérance 1e-9, minimum 1, plafond 99), boutons **Valider** et **Pas cette fois**. Le champ est **prérempli** quand le libellé du produit dit « N {unité} » (ex. « 20 Tranches » avec l'unité de la ligne, comparaison sans casse ni accent, pluriel compris), sinon vide. Le nom de l'unité est `needed.unitName` (au singulier ; l'agent met au pluriel selon la quantité). Le champ « Nombre de produits » reste en **dernier recours** quand l'unité n'est pas connue (plan d'un back ancien sans `unitSlug`).
2. **Mémoriser.** La réponse part dans la ligne du résultat de l'agent (clé **facultative** du protocole v1, `packSize: {value, unitSlug}` avec `unitSlug` = `needed.unitSlug`, jamais deviné depuis le symbole) ; le site l'enregistre dans le `product` de l'intention `ADD_PRODUCT` de `PUT /drive/choices`. « Pas cette fois » n'envoie rien (le produit non plus).
3. **Ne plus redemander.** L'envoi suivant reçoit `packs` `COMPUTED` pour ce produit : l'écran « Vérifie la quantité » n'apparaît plus.
4. **Montrer.** « Mes produits » affiche la contenance mémorisée de chaque produit (« 20 tranches »), sans réglage de plus ; la corriger se fait en redonnant la réponse au prochain envoi (à décider avec le PO s'il faut un champ à corriger ici, règle 3 de `principes-produit.md`).
5. **Textes.** La note de vie privée du front (clés `driveTerms.saves.*`) reçoit la phrase ajoutée à [`../note-vie-privee.md`](../note-vie-privee.md) **avant** que le site n'envoie une première contenance. Tutoiement, phrases courtes, aucun avertissement (skill `repas-po`).

## Hors portée

Remplir la contenance des produits déjà choisis (elle se remplit au prochain envoi qui pose la question) ; deviner la contenance depuis le libellé sans la confirmer de la personne (le libellé ne fait que **préremplir** le champ) ; une contenance pour les lignes en `PACKAGE` (« 2 paquets » donne 2, la règle 1 passe avant) ; convertir entre unités (« 2 cuillères » en grammes : backlog DRV-605) ; toute lecture entre enseignes ou entre utilisateurs.

## Critères d'acceptation

- Étant donné une liste « cheddar : 15 tranches » et un produit « 20 Tranches - 340 g » choisi avec `packSize {20, tranche}`, quand le plan est lu, alors le produit porte `packs: 1`, `packsStatus: COMPUTED` et `packSize`, et la ligne `needed.unitSlug` vaut `tranche`.
- 25 tranches pour 20 par produit donnent 2 ; 20 donnent 1 ; 2 000 donnent 99 à vérifier ; une contenance dans une autre unité que celle de la ligne est ignorée par le calcul.
- Étant donné un envoi qui ne parle pas de contenance (ancienne application), alors celle qui est mémorisée pour le même produit est gardée ; avec `null`, elle est oubliée ; avec un objet, elle est remplacée.
- Étant donné deux produits dans une préférence, alors chacun garde sa contenance, et un nouveau produit n'a jamais celle de l'ancien.
- Étant donné une unité inconnue, une valeur hors de 0,001 à 9 999 ou avec plus de trois décimales, alors `422 DRIVE_PACK_SIZE_INVALID` et rien n'est enregistré, même pour les autres intentions du lot.
- Étant donné une contenance sur un refus, « j'en ai toujours » ou un rafraîchissement de prix, alors `422 DRIVE_CHOICE_INVALID`.
- Un compte supprimé n'a plus aucune contenance ; « Oublier cet article » et le retrait d'un produit l'effacent.
- Deux utilisateurs qui choisissent le même produit ont chacun leur contenance (ou aucune) : rien ne passe de l'un à l'autre.

## Tests exigés

Lot 1 (faits) : valeur de domaine à table (`PackSizeTest`), `PackCalculator` à table (15, 25, 20, 21, 40 tranches pour 20, flottants, plafond, autre unité ignorée, priorité sur le contenu net en g, ml et pièces, quantité invalide, contenance inutilisable), `Choice` (une contenance par produit, élagage au refus, au retrait, au remplacement, à « j'en ai toujours », méthode qui échoue), handlers en unitaire avec doubles en mémoire (clé absente / `null` / objet, même produit / produit changé, dernier du lot gagnant, autre utilisateur / magasin / ingrédient, unité inconnue, action refusée, une seule lecture des unités, tout ou rien), repository et chargeurs sur base réelle (plan, choix, estimation, purge, requêtes comptées), controller (clés JSON, absente / `null` / objet, 422 avec son code), parcours de bout en bout avec les vrais bus (`DrivePlanApiFlowTest::testTheSizeInSlicesMakesThePlanComputeThePacksWithoutAQuestion`), budget SQL (`choix-drive-contenance` 11 ; `choix-drive*` inchangés), exemples de contrat. Lot 2 : agent (question, préremplissage depuis le libellé, aperçu, « Pas cette fois », repli « Nombre de produits » sans `unitSlug`), protocole compatible avec un agent ou un site ancien, site (validation avant envoi, jamais d'envoi hors bornes), « Mes produits ».

## Definition of Done

Celle des deux dépôts. Lot 1 : `make test` et `make quality` verts, `make openapi` relu, roadmap cochée par le lot 2 seulement.

## Pièges connus

- **Tout ou rien** : une contenance refusée (valeur hors bornes, unité qui n'existe pas) fait refuser tout le lot, donc **perdrait aussi les produits choisis**. Le site valide avant d'envoyer : `value` de 0,001 à 9 999, trois décimales au plus, `unitSlug` = le `needed.unitSlug` de la ligne (toujours une unité qui existe), jamais un texte déduit du symbole ou du libellé. Un champ numérique à virgule française (« 12,5 ») se convertit avant l'envoi.
- **Clé absente ≠ `null`** : un client qui enverrait `packSize: null` partout effacerait les contenances ; ne l'envoyer à `null` que pour oublier volontairement. La clé est **dans `product`**, pas dans l'intention (contrairement à `searchQuery` de DRV-650) : elle dépend du produit, pas de l'ingrédient. Le schéma OpenAPI écrit `default: null` pour cette clé, c'est un artefact du générateur (une clé absente n'est pas `null`) : un client généré ne doit pas remplir ce défaut.
- **Par produit** : plusieurs `ADD_PRODUCT` d'un même ingrédient dans un lot, chaque produit a sa contenance ; pour un même produit, la dernière reçue gagne. Envoyer la contenance du produit sur lequel la personne a répondu, pas d'un autre.
- **Ne rien envoyer pour « Pas cette fois »** (le produit reste hors des préférences, comme aujourd'hui), ni pour un refus (`REJECT_PRODUCT` avec `packSize` est `422`).
- **Le plan gagne des clés** (`needed.unitSlug`, `needed.unitName`, `products[].packSize`) : `plan.schema.json` du front doit les accepter **avant** que l'agent s'en serve, et l'agent doit marcher sans elles (back ancien). Ordre de publication du protocole (règle du § 12 de `architecture.md`) : le **site web avant l'application Android** qui embarque le nouvel agent. Côté back, rien à attendre : tout est facultatif.
- **`needed.unitName` est au singulier** (« tranche ») : l'agent écrit « tranches » selon la quantité ; ne pas reprendre `unitSymbol` (qui peut être un symbole court, voire le nom coupé à 32 caractères).
- **Priorité du calcul** : la contenance passe avant le contenu net, mais seulement pour la même unité. Une ligne en grammes avec un produit au contenu net connu garde son calcul ; une ligne en `PACKAGE` ne s'en sert jamais.
- **Texte de la personne** : un nombre et une unité, rien d'autre ; jamais dans une adresse ni un journal (Caddy journalise l'URI).

## Réalisation du lot 1 (back, 2026-10-20)

Rien n'est publié ni activé. Un client ou un agent qui ne connaît pas la contenance marche exactement comme avant (le plan garde `packs: null` / `TO_CHECK` pour une ligne sans dimension tant qu'aucune contenance n'est mémorisée).

**Contrat** (valeurs inventées ; `docs/api/openapi.json` et `docs/drive/samples/*.sample.json` font foi)

- `PUT /api/v1/drive/choices` : le `product` d'une intention `ADD_PRODUCT` gagne `packSize`, objet `{value, unitSlug}` ou `null`, **facultatif**. Exemple : `{"ingredientSlug": "cheddar", "action": "ADD_PRODUCT", "placement": "PRIMARY", "product": {"retailerProductId": "555701", "label": "Fromage Cheddar Burger 20 Tranches - 340g", "packSize": {"value": 20, "unitSlug": "tranche"}}}`. Erreurs : `422 DRIVE_PACK_SIZE_INVALID` (valeur hors de 0,001 à 9 999, plus de trois décimales, `unitSlug` mal formé ou qui n'est pas une unité de Repas) ; `422 DRIVE_CHOICE_INVALID` (la clé est mentionnée, même à `null`, sur autre chose qu'`ADD_PRODUCT`) ; un `packSize` qui n'est pas un objet `{value: nombre, unitSlug: texte}` est refusé par la validation du corps, `422` générique `{error:{message}}`.
- `GET /api/v1/drive/choices` : chaque `product` (accepté ou refusé) gagne `packSize` (`{value, unitSlug}` ou `null`, **toujours écrite**, à la fin de l'objet).
- `GET /api/v1/drive/plans/{shoppingListId}` : `lines[].needed` gagne `unitSlug` et `unitName` (à la fin de l'objet) ; `lines[].products[]` gagne `packSize` (même forme, `null` sans réponse, à la fin de l'objet). Les valeurs de `packs` et `packsStatus` suivent la règle (contenance dans l'unité de la ligne : `COMPUTED`). Aucun champ existant ne change ni ne disparaît.

**Modèle** : `drive_choice_product.pack_size_value` (NUMERIC(7,3), nullable) et `pack_size_unit_slug` (VARCHAR(255), nullable) ; migration `Version20261020180000` additive, compatible avec la version précédente du code (ADR 0017 règle 6) ; le retour arrière du code ne défait pas le schéma, mais une préférence réécrite par l'ancienne version perd ses contenances (l'agent redemande alors le nombre de produits, comme avant). Valeur de domaine `PackSize` ; `AcceptedProduct` porte la contenance de sa ligne ; `Choice` en garde une par produit accepté et l'élague dans `reject`, `remove`, `replace` et `markHaveAtHome`. `ReplaceIngredientPreference` garde celles des produits qui restent acceptés.

**Existence de l'unité** : port `Shared` `UnitSlugResolver::existingSlugs()` (implémenté dans `Repas\Infrastructure\Loader\UnitSlugPostgreSQLResolver`, nommé `…Resolver` et non `…Loader` pour la même raison que `IngredientDepartmentResolver` : un `CommandHandler` n'appelle pas de `Loader`). Une requête pour tout le lot, aucune quand le lot ne donne pas de contenance (clé absente ou `null`).

**Coût SQL** : la contenance voyage dans la réécriture des lignes de produits (aucune requête de plus pour la porter). Seule la lecture des unités ajoute **une requête, et seulement pour un lot qui donne une contenance** : `choix-drive` 10 et `choix-drive-lot` 11 sont **inchangés** (leurs scénarios n'en donnent pas), le nouveau scénario `choix-drive-contenance` mesure le cas avec contenance : **11** (10 + `SELECT unit`). Le plan reste à 5 requêtes au plus, l'estimation à 2 (mêmes colonnes, mêmes jointures).

**Vie privée** : pas de colonne utilisateur nouvelle (la contenance part avec la ligne du produit : `deleteProductsByUser()`, `deleteByUser()`, « Oublier cet article »), `UserOwnedDataCoverageTest` n'a rien à ajouter ; le back ne journalise pas les corps de requête ; la note de vie privée a une phrase de plus (texte, « À garder vrai » et registre).

**Pour le lot 2** : voir « Pièges connus » ci-dessus, en particulier la validation côté site avant envoi (tout ou rien), la clé dans `product`, absente contre `null`, le singulier de `unitName` et l'ordre de publication.
