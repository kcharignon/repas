# DRV-726 — Rapport de la recette réelle d'Auchan (2026-10-30)

Recette menée **en direct** par le propriétaire et l'assistant, sur le téléphone de test, avec un compte Auchan de test (hors dépôt), l'APK de **débogage** (agent 0.51.2 puis 0.51.3), le back `v1.8.0` et le site `v1.9.0` **en production**. `DRIVE_RETAILERS=leclerc,auchan` a été allumé par le propriétaire (script `ecrire-drive-retailers.sh`, première exécution en production : il marche) pendant **deux fenêtres courtes** (09:35–09:53 et 10:28–10:34 UTC+1), puis remis à `leclerc`. Un vrai envoi de six articles depuis Repas. Aucun identifiant réel (magasin, adresse, e-mail, cookie, UUID) dans ce rapport.

## Portée : observé / non observé

| # | Ligne de la portée | Résultat |
|---|---|---|
| 1 | Connexion dans la WebView | **Observé.** Le formulaire s'affiche, e-mail et mot de passe passent, le reCAPTCHA invisible ne bloque pas. Sur un appareil inconnu, Auchan demande un **code de vérification à 6 chiffres envoyé par e-mail** (saisi par le propriétaire). Le pont est absent de `compte.auchan.fr`, présent sur `www.auchan.fr`. Connexion Apple et Google : non essayées. |
| 2 | Magasin | **Observé.** « Choisir mon magasin Auchan » (réglages) lit le drive (`GET /journey`), le libellé s'affiche, « Utiliser ce magasin » l'enregistre (identifiant UUID de 36 caractères dans la colonne élargie à 40). La session et le magasin survivent à un arrêt forcé de l'application et à la réinstallation de l'APK (`install -r`). Après une heure, après un jour, effet d'une déconnexion : **non observé** (A-7 reste `I`). |
| 3 | Un envoi réel | **Observé, deux essais.** Essai 1 (agent 0.51.2) : quatre articles écrits au panier (`200`), puis arrêt « Auchan a changé son site » à l'article 5 : la page de résultats ne portait qu'un produit à trois offres `ONLINE` (hors drive), jugé illisible, d'où **DRV-739**. Essai 2 (agent 0.51.3, même liste) : les quatre produits mémorisés déjà au panier ne sont pas réécrits (C-12), l'article 5 retombe sur une recherche plus courte et propose de vrais produits, l'article 6 aussi ; deux écritures `200`, issue complète « Le panier est rempli », compteurs « 4 ajoutés au panier » et « 2 choisis ». Un plan d'essai de débogage (DRV-735) avait aussi écrit quatre lignes, dont une à quantité 2, relues à l'euro près. **Rien n'est commandé, aucun créneau, rien n'est payé.** |
| 4 | Anti-robot | **Aucun défi** sur une centaine de requêtes au rythme de l'agent (3 à 6 s), sur trois sessions. Un défi n'a pas été provoqué. La reprise après un défi reste non observée. |
| 5 | Visiteur et fusion | **Non observé** de bout en bout. Un visiteur qui lance un envoi reçoit « Tu n'es pas connecté à Auchan » (règle prudente, DRV-725). La fusion du panier du visiteur à la connexion et le cas du même produit dans les deux paniers (C-8) restent `I`. |
| 6 | Sous-ressources en WebView | **Observé.** Les images (`cdn.auchan.fr`) s'affichent dans l'écran de choix ; aucune ressource bloquée par la politique de navigation. |
| 7 | Valeurs encore inconnues | Plafond de quantité : `maxQuantity` lu (de quelques dizaines à plusieurs centaines selon le produit). Produit en vente en ligne seulement : **mesuré** (B-9), géré par DRV-739. Produit indisponible au drive, messages d'erreur du site (`401`, `99001` à `99007`) : **non observés**. |

## Ce que la recette a fait corriger

- **DRV-734** (recherche redirigée vers un rayon, page « aucun résultat ») : validé sur le vrai site (12 recherches sur 12).
- **DRV-735** (plan d'essai de débogage par enseigne) : a permis la première écriture réelle de l'adaptateur.
- **DRV-739** (offres en ligne seulement) : défaut trouvé à la recette (un seul article sans offre du drive arrêtait tout l'envoi), corrigé, rejoué avec succès.
- Règle produit du propriétaire (DRV-736, DRV-737) : aucun drive configuré = pas de bouton « Envoyer au drive » et « Configurer un drive » dans le menu « ⋮ » ; un drive = aucune question ; plusieurs = la question à chaque envoi, le drive habituel coché d'avance. Vue en situation : la question « Où envoyer ta liste ? » et le texte qui suit le drive choisi.
- Constat de présentation : tant que l'application publiée n'annonce pas Auchan, allumer `DRIVE_RETAILERS=leclerc,auchan` montre à **tous** une ligne « mets à jour l'application » dans « Mes drives » : l'application doit sortir **avant** l'allumage définitif (DRV-738).

## Non-régression de Leclerc (même jour, vrai site)

Test automatique de l'APK de débogage sur un compte Leclerc connecté, panier réel non vide : diagnostic, lecture du magasin, 12 recherches sur 12 et essai d'écriture au panier **OK** ; seule la comparaison « panier avant/après » est KO (défaut de lecture préexistant du total de la page, sans lien avec le chantier ; voir `DRV-738`).

## Décision

**Allumer Auchan**, dans cet ordre (ADR 0027) : le texte des conditions d'utilisation d'Auchan est **validé par le propriétaire (2026-10-30)** ; DRV-738 est fusionné (l'application publiée sert Auchan) ; release de l'application (`android-vX.Y.Z`), puis `APP_LATEST_VERSION` écrit par le propriétaire ; **le propriétaire allume** `DRIVE_RETAILERS=leclerc,auchan` (retour arrière : `bash -s -- leclerc`).

## Réserves gardées ouvertes

- Le visiteur Auchan (l'envoi s'arrête) : à décider avec l'usage.
- Persistance du magasin après un jour, fusion des paniers (C-8), messages d'erreur du site : à relever à l'usage (runbook « Auchan a changé »).
- Produit à la fois en ligne et au drive (B-9) : prix et disponibilité lus sur la carte, à surveiller.
- La variante par enseigne de la note de vie privée (`note-vie-privee.md` § 5 bis) reste une **proposition** à valider par le propriétaire.
