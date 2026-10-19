# DRV-642 — Chercher dans le bon rayon (produits frais)

- **Dépôt** : back (plan) + front (`drive-agent/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M à L (en deux lots)
- **Dépend de** : —
- **Statut** : conception retenue (lot 1, voir plus bas), à faire (demande de l'utilisateur du 2026-10-19 : « quand on veut des produits frais on ne voit jamais les bons produits ; chercher dans le bon rayon, fruits et légumes par exemple, devrait aider »). Effort de réalisation : `high`.
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
