# DRV-742 — Accepter l'adresse ordinateur d'un magasin Leclerc avec ville ou `.aspx`

- **Dépôt** : front (`src/features/drive/retailers.ts`) ; le back, l'agent et l'application ne changent pas
- **Jalon** : M7 Plusieurs enseignes (suite de la recette réelle)
- **Taille** : XS
- **Dépend de** : DRV-741
- **Statut** : fait (PR front #274)
- **Lire d'abord** : [`DRV-741-adresse-mobile-leclerc.md`](DRV-741-adresse-mobile-leclerc.md) ; [`../principes-produit.md`](../principes-produit.md).

## Contexte

Retour d'un proche du propriétaire (2026-10-30) : copier/coller l'adresse d'un magasin est « trop complexe ». DRV-741 accepte l'adresse mobile ; reste l'adresse que montre le navigateur d'un ordinateur : `https://fd<N>-courses.leclercdrive.fr/magasin-N-N-ville.aspx` (valeurs inventées). La saisie à la main la refusait (point laissé de DRV-741).

## Portée

`readStore` de Leclerc accepte `/magasin-N-N`, avec ou sans suffixe de ville (`[A-Za-z0-9%_-]{1,100}`), avec ou sans `.aspx`, avec ou sans suite de chemin, requête ou `#`, et rend **toujours** l'adresse de base `https://<hôte>/magasin-N-N` (motif du back inchangé : jamais la ville ni `.aspx` enregistrés). L'hôte mobile avec ville ou `.aspx` n'est jamais enregistré (chemin DRV-741). Adresse nue, autre domaine, `http`, port, identifiants, absence de `magasin-N-N` : comme avant.

## Critères d'acceptation

- Tests Vitest : ville, `.aspx`, les deux, suite de chemin et requête ; mauvaises adresses ; adresse nue inchangée ; formulaire (la requête d'enregistrement porte l'adresse de base).
