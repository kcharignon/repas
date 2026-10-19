# DRV-635 — « Introuvable à la recherche » n'est pas « indisponible »

- **Dépôt** : front (`drive-agent/src/flow/phase-a.ts`, `drive-agent/src/leclerc/` : `findKnownProduct`, requêtes de recherche, `drive-agent/src/ui/strings.ts`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M
- **Dépend de** : —
- **Statut** : à faire (constat de l'utilisateur du 2026-10-19 : « certains produits sont affichés comme non disponibles sur Repas mais sont disponibles et cliquables sur le site du drive »)
- **Lire d'abord** : [`principes-produit.md`](../principes-produit.md) (règles 7 et 10), [`hypotheses-site-reel.md`](../hypotheses-site-reel.md) § 8 et § 12, skills `repas-agent-dev`, `repas-po`.

## Contexte

Pour un produit habituel (`KNOWN`), `settleKnownLine` (`phase-a.ts`) le **retrouve par la recherche** du libellé (`findKnownProduct`, jusqu'à 4 requêtes de formes dérivées) et déclare **indisponible** un produit « introuvable après cela, à stock nul, ou que le panier refuse ». Trois causes très différentes aboutissent donc au même message (« Ces produits ne sont plus disponibles… ») et au même traitement (choisir un autre produit) : (1) **rupture**, dite par le site ; (2) **introuvable** : la recherche ne le renvoie pas ; (3) **refus du panier**. Seule la première prouve une indisponibilité.

## Faits mesurés sur le vrai site (2026-10-19, Pixel 6, recherche du site, produit « Oeufs C'est Qui Le Patron Plein Air x6 »)

| Recherche saisie | Résultat |
|---|---|
| `oeufs c'est qui le patron` (apostrophe) | **1 produit** trouvé |
| `oeufs cest qui le patron` (apostrophe retirée, sans espace) | **1 produit** trouvé |
| `oeufs c est qui le patron` (apostrophe remplacée par une **espace**) | **aucun résultat** |
| `oeufs patron` | **1 produit** trouvé |

Ce produit précis est réellement « Bientôt disponible » (pas de bouton d'ajout, « Produits similaires ») : le cas était donc une vraie rupture. Mais le tableau montre qu'une **mise en forme de la requête qui remplace l'apostrophe par une espace fait perdre un produit disponible** : c'est la cause probable du constat de l'utilisateur pour les libellés qui portent une apostrophe, un trait d'union, une barre oblique, un « & », un pourcentage ou des signes que le site ne tokenise pas comme nous.

## Portée

1. **Reproduire** dans le faux site (`fake-leclerc/`) un mode de recherche qui, comme le vrai site, **ne trouve pas** `c est` pour `C'est` ; vérifier ce que fait `findKnownProduct` aujourd'hui (formes dérivées essayées, normalisation des apostrophes, des tirets, des accents, de la casse, de la ponctuation) et **garder toujours en premier la forme du libellé telle quelle** (apostrophe comprise), puis des formes dérivées qui **retirent** les signes sans les remplacer par une espace (`cest`), puis les mots-clés les plus longs seuls (marque + mot principal).
2. **Trois états distincts** dans le résultat interne de la ligne : `OUT_OF_STOCK` (le site la dit en rupture : stock nul ou « Bientôt disponible »), `NOT_FOUND` (introuvable après toutes les formes), `CART_REFUSED` (le panier n'a rien accepté). Seul `OUT_OF_STOCK` s'affiche comme « n'est plus disponible ».
3. **Textes** (tutoiement, règle 10, 15 mots au plus) : `NOT_FOUND` → « Je ne retrouve pas « … » : cherche-le ou choisis-en un autre. » avec la **recherche préremplie** de la forme la plus simple ; `CART_REFUSED` → « Leclerc n'a pas pu mettre « … » au panier. Choisis-en un autre. » (texte qui existe déjà : le réutiliser) ; `OUT_OF_STOCK` → inchangé.
4. **Ne pas retirer le produit de la mémoire de Repas** pour un `NOT_FOUND` ou un `CART_REFUSED` (« Ne plus proposer » n'est coché par défaut pour aucun des deux) : un produit habituel introuvable ce jour-là redevient proposé au prochain envoi.
5. **Voir la raison** : le récapitulatif de l'agent (« Détails ») et le diagnostic indiquent, par ligne, la raison (`en rupture`, `introuvable`, `refusé par le panier`) et le nombre de formes essayées, **sans libellé ni adresse** dans le diagnostic.
6. Journal de recherche borné : jamais plus de 5 requêtes par produit habituel, au rythme du `pacer` (aucune rafale).

## Hors portée

Le protocole (aucune clé nouvelle dans le résultat : les issues `UNAVAILABLE` existantes suffisent ; la distinction vit dans les détails de l'agent et dans son écran), les photos (DRV-633), la recherche par identifiant (aucune page produit par identifiant n'est connue).

## Critères d'acceptation

- Étant donné un produit habituel dont le libellé porte une apostrophe et dont le faux site ne trouve pas la forme « espace » : quand l'agent le cherche, alors il le retrouve (forme telle quelle ou `cest`) et le met au panier sans rien demander.
- Étant donné un produit introuvable sous toutes les formes : alors l'écran dit « Je ne retrouve pas… », propose la recherche préremplie, et le produit reste dans la mémoire de Repas.
- Étant donné une vraie rupture (`stock` 0 ou « Bientôt disponible ») : alors le texte « n'est plus disponible » comme aujourd'hui.
- Le résultat renvoyé à Repas ne contient aucune clé nouvelle (test).

## Tests exigés

Tests unitaires de la génération des formes de requête (tableau : apostrophe droite et typographique, tiret, barre oblique, « & », « % », accents, majuscules, nombres, libellé très court), jsdom (trois états, textes, recherche préremplie), faux site (mode « apostrophe stricte »), `make agent-verify`, `make agent-e2e`, captures lues.

## Definition of Done

Celle du dépôt front ; agent incrémenté, empreinte recalculée ; **à confirmer sur le vrai site** (le PO fait la recette avec les produits cités par l'utilisateur). Aucun changement de protocole : pas d'ordre de publication site / application.

## Pièges connus

- Le site réagit aux signes de ponctuation autrement qu'un moteur de recherche ordinaire : ne **jamais** conclure « indisponible » d'un simple « pas de résultat ».
- Toute requête de plus est une requête de plus chez l'enseigne : 5 au plus, en série, au rythme habituel.
