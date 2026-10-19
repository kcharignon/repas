# DRV-642 — Chercher dans le bon rayon (produits frais)

- **Dépôt** : back (plan) + front (`drive-agent/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M à L (en deux lots)
- **Dépend de** : —
- **Statut** : à faire (demande de l'utilisateur du 2026-10-19 : « quand on veut des produits frais on ne voit jamais les bons produits ; chercher dans le bon rayon, fruits et légumes par exemple, devrait aider »). Effort de réalisation : `high`.
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
