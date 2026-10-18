# Note de vie privée : l'envoi de la liste au drive

Texte prêt à intégrer dans le front (ticket DRV-504, **partie back** : le texte et le registre ; l'affichage est la partie front). Il doit être accessible depuis **`/drive/reglages`** (résumé et lien) et **`/drive/aide`** (texte complet). Français simple, tutoiement, comme le reste de l'interface ; chaque bloc ci-dessous peut devenir une clé de `src/i18n/fr.ts` (par exemple `drive.privacy.*`). Aucune image externe.

- Dernière mise à jour du texte : **2026-10-18**. Le relire à chaque changement de ce que la fonction enregistre (voir « À garder vrai » plus bas).
- Les mots entre « » sont ceux de l'interface actuelle de l'application et du front.

---

## 1. Texte court (réglages du drive)

> **Ta vie privée.** Repas retient ton magasin, les produits que tu choisis (avec leur prix vu) et la date d'envoi de tes listes. Il ne voit jamais ton identifiant, ton mot de passe, tes cookies, ton panier Leclerc ni tes commandes : l'envoi se fait dans l'application, avec ta propre connexion à Leclerc. Attention : Leclerc peut suspendre un compte qu'il juge utilisé de façon abusive. **Lis la note complète.**

(Le lien « Lis la note complète » mène à `/drive/aide`, ancre de la note.)

---

## 2. Texte complet (aide du drive)

### Ta vie privée avec le drive

Envoyer ta liste au drive, c'est Repas qui prépare la liste et l'application qui la met dans ton panier Leclerc, avec ta connexion à toi. Voici ce que Repas garde, ce qu'il ne voit jamais, et comment tout effacer.

### Comment ça se passe

- Quand tu touches « Envoyer au drive », Repas prépare ta liste et la donne à l'application, sur ton téléphone.
- L'application ouvre Leclerc dans sa propre fenêtre. C'est **toi** qui te connectes à Leclerc, comme sur le site. Ensuite elle cherche les produits et les met dans ton panier **depuis ta session Leclerc**, comme si tu le faisais toi-même.
- Tout cela se passe entre ton téléphone et Leclerc. **Rien de cela ne passe par les serveurs de Repas.** À la fin, l'application ne rend à Repas que ce qu'il doit retenir : les produits que tu as choisis.
- Tu finalises ta commande toi-même sur Leclerc. Repas ne valide jamais de commande, ne choisit pas de créneau, ne paie rien et ne vide jamais ton panier.

### Ce que Repas enregistre

- **Ton magasin Leclerc** : son numéro, son adresse sur le site de Leclerc, et le nom que tu lui as donné si tu en as mis un.
- **Tes produits mémorisés, ingrédient par ingrédient** : ton produit préféré, tes produits de secours (5 au plus), tes produits refusés (20 au plus) et les ingrédients que tu as « toujours chez toi ».
- **Pour chaque produit** : son numéro chez Leclerc, son nom, son contenu (par exemple 1 kg), et son prix avec la date où il a été vu. Les prix servent à estimer le coût d'une liste ; l'estimation est datée et le vrai prix est toujours celui de Leclerc.
- **Pour chaque liste** : le fait qu'elle a été « envoyée au drive » et la date de l'envoi.

### Ce que Repas ne voit et n'enregistre jamais

- Ton identifiant et ton mot de passe Leclerc, et le code que Leclerc t'envoie par e-mail.
- Tes cookies et tes jetons de connexion à Leclerc.
- Ton adresse, ton créneau de retrait, ton moyen de paiement.
- Le contenu réel de ton panier Leclerc, tes commandes, tes achats passés.
- Les photos des produits.

### Où, et combien de temps

- Les données sont dans la base de Repas, sur le serveur de Repas (hébergé chez DigitalOcean). Repas ne les donne à personne, ne les vend pas, ne fait pas de publicité et n'envoie pas de statistiques à des tiers.
- Elles restent **tant que ton compte existe**, ou jusqu'à ce que tu les oublies (voir plus bas).
- Le serveur fait une copie de sauvegarde chaque nuit et garde les 14 dernières : une donnée effacée peut rester dans ces copies **environ deux semaines au plus**, puis elles sont remplacées.
- Sur ton téléphone, l'application garde ta connexion à Leclerc, comme le ferait un navigateur. Elle ne la partage ni avec Repas ni avec personne. Tu peux l'effacer à tout moment : menu de l'application, « Se déconnecter de Leclerc et effacer les données du drive ».
- Les fiches de produits (nom, contenu, prix vu, magasin) sont communes à tous les utilisateurs de Repas et ne disent **pas** qui les a vues. Chacun ne retrouve que les produits qu'il a lui-même mémorisés. La première personne qui décrit un produit fixe sa fiche (nom, contenu, code-barres) : les suivantes ne la réécrivent pas, elles ne font que la compléter si elle est incomplète ; le prix vu, lui, est rafraîchi par celles qui choisissent ce produit. Ces fiches restent quand un compte est supprimé, mais sans aucun lien avec la personne.

### Comment tout effacer

- **Un produit, ou un ingrédient** : dans les réglages du drive, « Mes produits » : retire un produit, refuses-en un, ou touche « Oublier » pour effacer tout ce que Repas retient sur un ingrédient.
- **Un changement de magasin** : si tu changes de magasin, les produits mémorisés pour l'ancien magasin ne sont plus utilisés, mais ils restent enregistrés jusqu'à la suppression de ton compte.
- **Tout, d'un coup** : demande la suppression de ton compte à la personne qui administre Repas. Elle efface tes données du drive (magasin, produits mémorisés), tes listes de courses avec leur statut d'envoi, et le reste de ton compte.
- Pour exercer tes droits (savoir ce que Repas a de toi, corriger, effacer), écris à la personne qui t'a donné accès à Repas.

### Avant d'utiliser l'envoi : ce que tu dois savoir

- L'envoi agit **dans ton propre compte Leclerc**, avec ta propre connexion, comme si tu faisais les recherches et les ajouts toi-même. Repas ne voit jamais tes identifiants.
- Les conditions d'utilisation de Leclerc permettent à l'enseigne de **suspendre un compte E.Leclerc** (et la cagnotte de Tickets E.Leclerc qui va avec) si elle juge l'usage abusif. Repas limite ce risque : une requête à la fois, avec des pauses, seulement quand tu le demandes, et seulement pour ta liste. Mais le risque existe et il est pour ton compte.
- Si Leclerc ralentit les requêtes, l'application s'arrête d'elle-même. Si ton compte Leclerc pose un problème, contacte le service client de Leclerc : Repas n'a aucun accès à ton compte et ne contacte jamais Leclerc.
- Tu peux t'en passer à tout moment : la liste de Repas reste utilisable pour faire tes courses à la main sur Leclerc.

---

## 3. À garder vrai (pour qui modifie la fonction)

Chaque phrase du texte a sa source. Si l'une change, mettre à jour ce texte **et** le front dans la même PR.

| Ce que le texte affirme | D'où ça vient |
|---|---|
| Aucun identifiant, cookie ni jeton Leclerc chez Repas ; le back ne contacte jamais Leclerc | ADR 0022 règles 1 et 2 ; [`architecture.md`](architecture.md) § 2 ; aucun champ de ce type dans `docs/api/openapi.json` |
| L'envoi agit dans la session de l'utilisateur, dans l'application, sans passer par le serveur | ADR 0022 règle 3 et mise à jour G1 ; [`pivot-application-android.md`](pivot-application-android.md) ; le plan voyage par le pont natif, jamais par une route du back |
| Repas ne valide pas la commande, ne choisit pas de créneau, ne paie pas, ne vide pas le panier, ne coche rien | ADR 0022 règles 4 et 9 ; `architecture.md` § 2 invariants 4, 6, 7 |
| Magasin : numéro, adresse, nom facultatif | table `drive_settings` (`store_id`, `base_url`, `store_label`), une ligne par utilisateur et enseigne |
| Produits mémorisés : préféré, secours (5), refusés (20), « toujours chez moi » | ADR 0022 règle 7 ; tables `drive_choice`, `drive_choice_product` |
| Fiche de produit : numéro, nom, contenu, prix et date de vue ; pas de photo | table `drive_product` (D22, `analyse.md`) ; `drive_product` n'a pas de colonne utilisateur |
| Statut « envoyée » et date d'envoi | `shopping_list.status` et `sent_at` (ADR 0023) |
| Rien d'autre n'est lu : adresse, créneau, paiement, panier, commandes | l'agent ne fait que lire le magasin, chercher, fixer une quantité, lire le produit ajouté (ADR 0022 règle 4) |
| Pas de statistiques tierces, pas de partage | `architecture.md` § 10 ; aucune route ne liste les préférences d'un autre utilisateur |
| Effacé avec le compte, sauf les fiches de produits | `PurgeUserDriveDataHandler` (réglages, lignes de produits, préférences) ; `UserOwnedDataCoverageTest` ; ADR 0021. **Pas d'auto-suppression** : seul un administrateur supprime un compte |
| Changer de magasin ne supprime pas les anciens choix | `SaveDriveSettingsHandler` remplace le magasin sans purge ; les choix portent leur `store_id` |
| Sauvegardes : 14 conservées, une par nuit | `deploy/backup.sh` ; [`../production.md`](../production.md) |
| Cadence lente, en série, à la demande | `architecture.md` § 8 (cadence), DRV-003 (constat CGU) ; l'agent s'arrête après deux refus |
| Risque de suspension du compte E.Leclerc | [`spikes/DRV-003-cgu.md`](spikes/DRV-003-cgu.md) ; décision D23 de l'utilisateur |

---

## 4. Registre des traitements

Il n'existe pas de registre des traitements dans ce dépôt. Cette fiche en tient lieu pour la fonction « envoi au drive » ; elle peut devenir la première entrée d'un registre commun.

| Rubrique | Contenu |
|---|---|
| Traitement | Mémorisation des préférences d'achat au drive (magasin, produits choisis, produits refusés, ingrédients « toujours chez moi », prix vus) et du statut d'envoi des listes |
| Finalité | Remplir plus vite le panier du drive de l'utilisateur à partir de ses listes de courses ; estimer le coût d'une liste (estimation datée) |
| Personnes concernées | Les utilisateurs de Repas qui activent la fonction (usage familial) |
| Données | Magasin (numéro, adresse web, nom facultatif) ; pour chaque ingrédient : produits acceptés (ordre), refusés, « toujours chez moi », date de mise à jour ; fiche de produit (numéro de l'enseigne, nom, contenu, prix, date de vue) ; statut et date d'envoi d'une liste. Les choix peuvent révéler un régime alimentaire ou un état de santé : minimisation, aucune diffusion. |
| Données jamais collectées | Identifiants, mots de passe, codes, cookies et jetons de l'enseigne ; adresse, créneau, paiement ; contenu du panier de l'enseigne, commandes ; photos |
| Base juridique | À qualifier par le propriétaire du service (usage familial sur demande de l'utilisateur) |
| Destinataires | Aucun. Hébergeur du serveur : DigitalOcean (région non documentée dans le dépôt : à confirmer). L'enseigne n'est jamais destinataire de données par Repas : l'application agit dans la session de l'utilisateur |
| Durée | Tant que le compte existe ou jusqu'à l'oubli par l'utilisateur ; fiches de produits sans propriétaire conservées ; copies de sauvegarde : 14 dernières, environ deux semaines |
| Suppression | Retrait ou « Oublier » par ingrédient ; suppression du compte par un administrateur (réglages, produits mémorisés, listes) en une transaction (ADR 0021) ; changement de magasin : anciens choix conservés jusqu'à la suppression du compte |
| Sécurité | Accès par jeton, l'utilisateur vient toujours du jeton et jamais du corps de la requête ; aucune route ne liste les préférences d'autrui ; entrées bornées et validées ; sauvegardes locales en mode `700` ; pas de chiffrement de la base au repos ([`analyse.md`](analyse.md) § 6) |
| Droits | Accès, rectification, effacement : demande à l'administrateur du service |

---

## 5. Pour l'intégration dans le front (autre ticket)

1. Le texte court dans `/drive/reglages` (au-dessus de l'action « Enregistrer mon magasin »), avec le lien vers la note complète.
2. Le texte complet dans `/drive/aide`, avec une ancre (par exemple `#vie-privee`). Textes dans `src/i18n/fr.ts`, couleurs par jetons, cibles de 44 px, lisible sur 360 px.
3. Ne rien ajouter qui ne soit pas dans « À garder vrai ». Si l'écran « Mes produits » (DRV-410) porte un autre nom, adapter la phrase « Comment tout effacer ».
4. Afficher la note **avant** la première activation de l'envoi (par exemple lors de l'enregistrement du magasin) : les conditions de Leclerc concernent le compte de chaque utilisateur (constat DRV-003).
5. Test : le lien existe depuis les deux écrans et mène au texte.
