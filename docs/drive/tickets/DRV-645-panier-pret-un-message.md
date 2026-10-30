# DRV-645 — Panier prêt : un message, pas la page ordinateur

- **Dépôt** : front (web)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : S
- **Dépend de** : DRV-631
- **Statut** : fait (PR front #162, 2026-10-19). Demande de l'utilisateur : « quand le panier est prêt, au lieu de m'ouvrir la page ordinateur, ce qui est insupportable en termes d'UX, je préfère que tu m'affiches un petit message : « Ton panier est prêt, retrouve-le sur ton appli Drive Leclerc » ».

## Réalisation

Après un envoi réussi, l'écran de retour dit « Ton panier est prêt. Retrouve-le dans l'appli Drive Leclerc. » (nom de l'enseigne en paramètre : `RETAILER_APP_NAME`), sans lien ; « Terminer la liste » devient l'action principale. Partiel : même phrase seulement si au moins un produit est au panier. Repli `CONTRACT_CHANGED` inchangé.

## Suite

- Bouton « Ouvrir l'appli Leclerc Drive » (paquet Android `com.infomil.leclercdrive` relevé sur le téléphone de test, à confirmer) ; nettoyage du « Finaliser » natif devenu inutilisé (Kotlin).

*Texte remplacé le 2026-10-30 (retour d'un utilisateur, front #272) : « Ton panier est rempli. Tu peux finaliser ta commande dans l'appli Leclerc Drive. » (ou « …l'appli Auchan. »), le nom de l'application vient de la table de l'enseigne ; le compteur « Choisis sur {enseigne} » devient « Choisis par toi ».*
