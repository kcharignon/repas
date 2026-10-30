# DRV-734 — Recherche d'Auchan : lire la page de rayon vers laquelle un terme redirige, reconnaître « aucun résultat »

- **Dépôt** : front (`drive-agent/src/auchan/`, `drive-agent/fake-auchan/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : S
- **Dépend de** : DRV-724
- **Statut** : fait (PR front #264, 2026-10-30, agent 0.51.2).
- **Lire d'abord** : [`../hypotheses-site-reel-auchan.md`](../hypotheses-site-reel-auchan.md) B-7b, B-8 ; l'essai réel du 2026-10-30 ([issue #322](https://github.com/kcharignon/repas/issues/322)).

## Contexte

L'essai réel dans la WebView a montré que les termes courants (riz, sucre, oeufs, poivre) **redirigent vers une page de rayon** qui contient les produits avec les mêmes cartes ; l'adaptateur de DRV-724 les lisait comme « zéro produit » et le test automatique s'arrêtait. La page « aucun résultat » (mesurée) était lue comme une page inconnue.

## Réalisation

Une adresse finale `/<rayon>/…/ca-n<chiffres>` dont le contenu le confirme est lue comme une page de résultats (première page, mêmes sélecteurs et garde-fous ; `SearchFacts.landing = 'AISLE'`, consigné au diagnostic sans l'adresse) ; une adresse de rayon sans le bon contenu, ou l'inverse : `SEARCH_REDIRECTED` ; un rayon sans carte : `SEARCH_UNKNOWN_PAGE` ; « aucun résultat » (`200`, titre et `h1` « Votre recherche… », la phrase, aucune carte) : zéro produit sans erreur ; une fiche produit redirigée reste non lue. Le faux site rejoue ces formes (données inventées).
