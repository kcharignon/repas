# DRV-646 — Diagnostic des rayons et familles vus par la recherche

- **Dépôt** : front (`drive-agent/src/diagnostic/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : S
- **Dépend de** : —
- **Statut** : fait (PR front #164 puis correctif #165, fusionnées le 2026-10-19)
- **Lire d'abord** : [`DRV-642`](DRV-642-chercher-dans-le-bon-rayon.md) (« Mesure réelle des rayons »), `docs/drive/recherche.md`, skill `repas-agent-dev`.

## Contexte

Pour chercher dans le bon rayon (DRV-642), il faut savoir quels rayons (`iIdRayon`) et familles (`iIdFamille`) la recherche du site renvoie pour des produits frais ordinaires, et si la réponse annonce une page suivante. Le JSON ne porte aucun libellé de rayon ni indice de pagination connu : la mesure se fait par identifiants, sur un téléphone, depuis le « Diagnostic de l'agent ».

## Portée

- Le test automatique (débogage) de l'agent ajoute trois recherches ordinaires (« tomate », « pomme », « courgette »), en série, à la cadence du `Pacer`.
- Pour chaque recherche, une ligne donne les rayons et familles de chaque produit lu : `id:nombre`, triés par nombre décroissant, 8 groupes au plus, avec le nombre de produits. Pour la première recherche, le nombre de produits de la réponse et « page suivante : inconnue ».
- Aucune donnée personnelle : ni nom de produit, ni prix, ni magasin, ni ville dans les lignes (un test le garde). Un blocage arrête la série. Protocole inchangé.
- Les identifiants `aisle` et `family` entrent dans `Candidate` (lus, jamais envoyés à Repas). Le test automatique fait 3 requêtes de plus (28 au lieu de 25).

## Résultat

- PR front #164 (agent 0.29.0) : les trois recherches et leurs lignes de rayons ; faux site avec des produits frais dans plusieurs rayons.
- PR front #165 (agent 0.30.0) : sur le vrai site, le rapport n'avait aucune ligne de rayons, la série étant sautée quand le mot absurde (sonde voulue) échouait en HTTP. Désormais seuls un blocage, une session perdue, une page hors magasin ou l'échec de la recherche « sel » l'empêchent, et le motif est écrit (« Rayons : non lus (motif) »). Nouveau contrôle « Rayons vus » dans la liste du haut. Listes de noms bornées à 40. La cause exacte sur le vrai site n'avait pas été observée au moment de la PR.
- Les mesures obtenues alimentent la conception du lot 1 de DRV-642.
