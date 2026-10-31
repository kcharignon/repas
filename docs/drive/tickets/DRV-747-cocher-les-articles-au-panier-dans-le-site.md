# DRV-747 — Le site coche les articles mis au panier et n'envoie plus les lignes cochées

- **Dépôt** : front (web)
- **Jalon** : M7 Plusieurs enseignes (retour du propriétaire, hors ordre)
- **Taille** : M
- **Dépend de** : DRV-745 (route et plan), DRV-746 (clé `notInCart`)
- **Statut** : fait (front PR #291, 2026-10-31). Effort recommandé : `high`, texte relu avec `repas-po`.
- **Lire d'abord** : [DRV-745](DRV-745-lignes-cochees-et-articles-au-panier.md), [`../architecture.md`](../architecture.md) § 7 et § 8, [ADR 0022](../../adr/0022-remplissage-du-panier-drive.md) (amendement du 2026-10-31).

## Livré

- Après le passage, un seul appel à `POST /drive/plans/{id}/basket-lines` avec les articles `ADDED` et les `CHOSEN` sans `notInCart` du résultat déjà recoupé avec le plan (`restrictToPlan`). Jamais bloquant, rien n'est dit s'il échoue, aucun appel sans article, 1 000 slugs au plus par appel. Les listes sont relues ensuite, jamais le plan du récapitulatif (qui garde son plan lu au montage).
- Envoi : « Envoyer au drive » et « Reprendre l'envoi » comptent les articles non cochés ; un plan sans ligne dit « Tous tes articles sont cochés. » ; `422 SHOPPING_LIST_EMPTY` a un texte lisible ; `DriveSendView` n'utilise qu'un plan lu après son ouverture (un plan périmé renverrait au drive des articles déjà au panier).
- La barre « N sur M articles dans le panier » s'affiche aussi sur une liste envoyée ; le récapitulatif dit que les articles ajoutés sont cochés.
- ADR front 0008 § 16, revue de sécurité C1, `CLAUDE.md` et skill du front.

## Reste

Publier l'application Android 0.54.0, puis écrire `APP_LATEST_VERSION` (script du propriétaire). Recette réelle sur le téléphone de test (`docs/drive/recette.md`).
