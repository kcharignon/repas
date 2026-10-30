# DRV-743 — Trier la liste des produits proposés pendant l'envoi au drive

- **Dépôt** : front (`drive-agent/src/ui/`, `drive-agent/src/flow/`)
- **Jalon** : M7 Plusieurs enseignes (retour d'utilisateur, hors ordre)
- **Taille** : S
- **Dépend de** : DRV-306, DRV-643 (la liste par lots), DRV-647 (la rangée de rayons)
- **Statut** : fait (PR front #276 fusionnée, agent 0.52.0, 2026-10-30) ; à publier avec l'application Android qui embarque le nouvel agent. Aucun changement du protocole ni du back.
- **Lire d'abord** : [`../principes-produit.md`](../principes-produit.md) ; [`../consignes-agents.md`](../consignes-agents.md) ; `drive-agent/README.md` du front, « Trier les produits proposés ».

## Contexte

Retour d'un utilisateur du propriétaire (2026-10-30) : « quand j'exporte ma liste au drive, il me propose plein de produits (par exemple plein de beurre) ; je voudrais pouvoir les classer par prix, prix au kilo ou alphabétique ».

Maquette validée par le propriétaire (Claude Design, planche « 2 · Drive : trier les produits proposés ») : sous « Il te faut : 250 g. », un bloc « Trier » de quatre boutons en pastille (44 px au moins) : **Pertinence** (l'ordre du site, par défaut), **Prix**, **Prix au kilo**, **A → Z** ; le bouton actif est plein (vert de l'écran de l'agent) et porte une flèche ↑ ou ↓ qui dit le sens ; une ligne d'aide en dessous (« Du moins cher au plus cher. Touche encore pour inverser. ») ; puis « Résultats pour « beurre » » et les cartes dans l'ordre trié.

## Portée

1. **Purement local** : aucune requête de plus vers le site ; ce que l'agent propose, la mémoire des produits (« Produit habituel », rejets) et le résultat envoyé à Repas ne changent pas. Le tri ne change que l'ordre d'affichage (`sortItems`, fonction pure, appliquée à l'affichage, après le filtre par rayon).
2. **Pertinence** = l'ordre du site, sans tri. **Prix** = le prix du produit, croissant d'abord. **Prix au kilo** = le prix à l'unité de mesure que la fiche montre déjà (€/kg, €/L, €/pièce). **A → Z** = par libellé, en français, sans tenir compte de la casse ni des accents.
3. Toucher le bouton actif **inverse** le sens (↑ croissant, ↓ décroissant) ; la ligne d'aide dit le sens en une phrase. Toucher « Pertinence » rend l'ordre du site ; la retoucher ne fait rien.
4. Un produit **sans** la valeur du tri passe **en dernier**, quel que soit le sens ; entre égaux, l'ordre du site reste (tri stable).
5. **Kilos et litres ne se comparent jamais.** Décision (la plus simple et sûre) : « Prix au kilo » trie sur l'unité la plus fréquente de la liste (à égalité kg, puis L, puis pièce) ; le bouton le dit (« Prix au litre », « Prix à la pièce ») ; un produit d'une autre unité passe avec ceux qui n'ont pas de prix à l'unité, en dernier. Le bouton est toujours là.
6. Le choix est **gardé pour le reste de l'envoi** (l'article suivant s'ouvre déjà dans cet ordre, comme l'écran qui revient de « Vérifie la quantité »), puis oublié à la fin ; il n'est pas écrit dans `sessionStorage` (après un rechargement de la page, l'écran repart de la pertinence). Il s'applique aux résultats d'une **nouvelle recherche** (« Chercher autre chose »).
7. Accessibilité : de vrais `<button>` avec `aria-pressed`, 48 px, nom accessible du bouton actif qui dit le sens (« Prix, croissant »), la ligne d'aide en `aria-live="polite"`, texte et fond de l'écran de l'agent (contraste AA clair et sombre), pas d'emoji. Les boutons ne sont jamais refaits : le focus reste sur celui qui a été touché.
8. Aucun `if` sur le nom d'une enseigne : Leclerc et Auchan partagent l'écran.

## Hors périmètre

- **Pas de filtre** (bio, marque, promotion) : un ticket à part, plus tard.
- Pas de nombre de résultats dans le titre (« Résultats pour « beurre » : 24 produits » de la maquette) : le titre reste « Résultats pour « beurre » », la rangée « Tous (N) » dit déjà le nombre quand il y a des rayons.

## Critères d'acceptation

- Tests unitaires de l'agent : chaque tri et son sens, les valeurs manquantes en dernier dans les deux sens, le tri stable, kilos et litres, le tri gardé d'un article à l'autre, la nouvelle recherche (`test/sort.spec.ts`, `test/picker-sort.spec.ts`, `test/phase-b.spec.ts`).
- Scénario de bout en bout de l'agent contre le faux Leclerc : produits triés par prix puis par prix au kilo, **le choix du premier produit trié écrit ce produit au panier** (`e2e/tri.spec.ts`).
- Version de l'agent augmentée (`src/version.ts`, `android/agent.js.sha384`) ; `make check`, `make agent-verify`, `make agent-e2e` et `make android-e2e` verts.
