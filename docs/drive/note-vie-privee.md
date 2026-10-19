# Note de vie privée : l'envoi de la liste au drive

Texte du front (ticket DRV-504 : **partie back** = le texte et le registre, ici ; **partie front** faite, repas-front#101, #105 et #106, clés `drivePrivacy.*` de `src/i18n/fr.ts`). Depuis DRV-628 (2026-10-19), il n'est plus dans le parcours : c'est la page **`/drive/conditions`** (« Conditions d'utilisation du drive », clés `driveTerms.*` de `src/i18n/fr.ts`), atteinte par un lien discret « Conditions d'utilisation » au pied de la rubrique Drive de « Moi » et de l'aide ; les écrans du parcours n'ont plus ni résumé, ni avertissement. Français simple, au **vouvoiement** comme tout le produit (décision du PO du 2026-10-19, DRV-621, [`principes-produit.md`](principes-produit.md)) : ce fichier reprend les clés `drivePrivacy.*` du front. Aucune image externe.

- Dernière mise à jour du texte : **2026-10-19** (vouvoiement et glossaire, DRV-621). Le relire à chaque changement de ce que la fonction enregistre (voir « À garder vrai » plus bas).
- Les mots entre « » sont ceux de l'interface actuelle de l'application et du front.

---

## 1. Texte court (retiré des écrans par DRV-628, gardé pour mémoire)

> **Votre vie privée.** Repas retient votre magasin, les produits que vous choisissez (avec leur prix vu) et la date d'envoi de vos listes. Il ne voit jamais votre identifiant, votre mot de passe, vos cookies, votre panier Leclerc ni vos commandes : l'envoi se fait dans l'application, avec votre propre connexion à Leclerc. Attention : Leclerc peut suspendre un compte qu'il juge utilisé de façon abusive. **Lisez la note complète.**

(Ce résumé et son lien « Lisez la note complète » ne sont plus affichés : l'écran sert l'action en cours, règle 10 de `principes-produit.md`.)

---

## 2. Texte complet (page « Conditions d'utilisation du drive », `/drive/conditions`)

### Votre vie privée avec le drive

Envoyer votre liste au drive, c'est Repas qui prépare la liste et l'application qui la met dans votre panier Leclerc, avec votre connexion à vous. Voici ce que Repas garde, ce qu'il ne voit jamais, et comment tout effacer.

### Comment ça se passe

- Quand vous touchez « Envoyer au drive », Repas prépare votre liste et la donne à l'application, sur votre téléphone. Rien n'est mis au panier tant que vous n'avez pas touché « Commencer ».
- L'application ouvre Leclerc dans sa propre fenêtre. C'est **vous** qui vous connectez à Leclerc, comme sur le site. Ensuite elle cherche les produits et les met dans votre panier **depuis votre session Leclerc**, comme si vous le faisiez vous-même. Un produit à la fois, avec des pauses, pour ne pas gêner Leclerc.
- Tout cela se passe entre votre téléphone et Leclerc. **Rien de cela ne passe par les serveurs de Repas.** Comme dans un navigateur, les pages de Leclerc font appel à leurs propres services (protection contre les robots, mesure d'audience) : Repas n'y a pas accès et ne les choisit pas. À la fin, l'application ne rend à Repas que ce qu'il doit retenir : les produits que vous avez choisis.
- Vous finalisez votre commande vous-même sur Leclerc. Repas ne valide jamais de commande, ne choisit pas de créneau, ne paie rien et ne vide jamais votre panier. Il ne réduit jamais une quantité déjà au panier : un article déjà présent en plus grande quantité reste tel quel.

### Ce que Repas enregistre

- **Votre magasin Leclerc** : son numéro, son adresse sur le site de Leclerc, et le nom que vous lui avez donné si vous en avez mis un.
- **Les produits dont Repas se souvient, article par article** : votre produit habituel, vos produits de remplacement (5 au plus), les produits à ne plus proposer (20 au plus) et les articles « J'en ai toujours ».
- **Pour chaque produit** : son numéro chez Leclerc, son nom, son contenu (par exemple 1 kg), et son prix avec la date où il a été vu. Les prix servent à estimer le coût d'une liste ; l'estimation est datée et le vrai prix est toujours celui de Leclerc.
- **Pour chaque liste** : le fait qu'elle a été « envoyée au drive » et la date de l'envoi.

### Ce que Repas ne voit et n'enregistre jamais

- Votre identifiant et votre mot de passe Leclerc, et le code que Leclerc vous envoie par e-mail.
- Vos cookies et vos jetons de connexion à Leclerc.
- Votre adresse, votre créneau de retrait, votre moyen de paiement.
- Le contenu réel de votre panier Leclerc, vos commandes, vos achats passés.
- Les photos des produits.

### Où, et combien de temps

- Les données sont dans la base de Repas, sur le serveur de Repas (hébergé chez DigitalOcean). Repas ne les donne à personne, ne les vend pas, ne fait pas de publicité et n'envoie pas de statistiques à des tiers.
- Elles restent **tant que votre compte existe**, ou jusqu'à ce que vous les oubliez (voir plus bas).
- Le serveur fait une copie de sauvegarde chaque nuit et garde les 14 dernières : une donnée effacée peut rester dans ces copies **environ deux semaines au plus**, puis elles sont remplacées.
- Sur votre téléphone, l'application garde votre connexion à Leclerc, comme le ferait un navigateur. Elle ne la partage ni avec Repas ni avec personne d'autre que Leclerc et ses propres services. Vous pouvez l'effacer à tout moment : menu de l'application, « Se déconnecter de Leclerc ».
- Les descriptions de produits (nom, contenu, prix vu, magasin) sont communes à tous les utilisateurs de Repas et ne disent **pas** qui les a vues. Chacun ne retrouve que les produits dont Repas se souvient pour lui. La première personne qui décrit un produit fixe sa description (nom, contenu, code-barres) : les suivantes ne la réécrivent pas, elles ne font que la compléter si elle est incomplète ; le prix vu, lui, est rafraîchi par celles qui choisissent ce produit. Ces descriptions restent quand un compte est supprimé, mais sans aucun lien avec la personne.

### Comment tout effacer

- **Un produit, ou un article** : dans « Moi », « Mes produits du drive » : retirez un produit, choisissez « Ne plus proposer » pour un autre, ou touchez « Oublier cet article » pour effacer tout ce que Repas retient sur lui.
- **Un changement de magasin** : si vous changez de magasin, les produits dont Repas se souvient pour l'ancien magasin ne sont plus utilisés, mais ils restent enregistrés jusqu'à la suppression de votre compte.
- **Tout, d'un coup** : demandez la suppression de votre compte à la personne qui administre Repas. Elle efface vos données du drive (magasin, produits dont Repas se souvient), vos listes de courses avec leur statut d'envoi, et le reste de votre compte.
- Pour exercer vos droits (savoir ce que Repas a de vous, corriger, effacer), écrivez à la personne qui vous a donné accès à Repas.

### Avant d'utiliser l'envoi : ce que vous devez savoir

- L'envoi agit **dans votre propre compte Leclerc**, avec votre propre connexion, comme si vous faisiez les recherches et les ajouts vous-même. Repas ne voit jamais vos identifiants.
- Les conditions d'utilisation de Leclerc permettent à l'enseigne de **suspendre un compte E.Leclerc** (et la cagnotte de Tickets E.Leclerc qui va avec) si elle juge l'usage abusif. Repas limite ce risque : une requête à la fois, avec des pauses, seulement quand vous le demandez, et seulement pour votre liste. Mais le risque existe et il est pour votre compte.
- Si l'envoi s'arrête, vous le reprenez là où il en était : l'avancement est gardé et les articles déjà faits ne sont pas refaits. Si Leclerc ralentit les requêtes, l'application s'arrête d'elle-même : attendez plusieurs heures avant de relancer, sans insister en boucle. Si votre compte Leclerc pose un problème, contactez le service client de Leclerc : Repas n'a aucun accès à votre compte et ne contacte jamais Leclerc.
- Vous pouvez vous en passer à tout moment : la liste de Repas reste utilisable pour faire vos courses à la main sur Leclerc.

---

## 3. À garder vrai (pour qui modifie la fonction)

Chaque phrase du texte a sa source. Si l'une change, mettre à jour ce texte **et** le front dans la même PR.

| Ce que le texte affirme | D'où ça vient |
|---|---|
| Aucun identifiant, cookie ni jeton Leclerc chez Repas ; le back ne contacte jamais Leclerc | ADR 0022 règles 1 et 2 ; [`architecture.md`](architecture.md) § 2 ; aucun champ de ce type dans `docs/api/openapi.json` |
| L'envoi agit dans la session de l'utilisateur, dans l'application, sans passer par le serveur | ADR 0022 règle 3 et mise à jour G1 ; [`pivot-application-android.md`](pivot-application-android.md) ; le plan voyage par le pont natif, jamais par une route du back |
| Repas ne valide pas la commande, ne choisit pas de créneau, ne paie pas, ne vide pas le panier, ne coche rien | ADR 0022 règles 4 et 9 ; `architecture.md` § 2 invariants 4, 6, 7 |
| Magasin : numéro, adresse, nom facultatif | table `drive_settings` (`store_id`, `base_url`, `store_label`), une ligne par utilisateur et enseigne |
| Produits dont Repas se souvient : habituel, remplacement (5), à ne plus proposer (20), « J'en ai toujours » (dans le code : préféré, secours, refusés, `HAVE_AT_HOME`) | ADR 0022 règle 7 ; tables `drive_choice`, `drive_choice_product` |
| Description d'un produit : numéro, nom, contenu, prix et date de vue ; pas de photo | table `drive_product` (D22, `analyse.md`) ; `drive_product` n'a pas de colonne utilisateur |
| Statut « envoyée » et date d'envoi | `shopping_list.status` et `sent_at` (ADR 0023) |
| Rien d'autre n'est lu : adresse, créneau, paiement, panier, commandes | l'agent ne fait que lire le magasin, chercher, fixer une quantité, lire le produit ajouté (ADR 0022 règle 4) |
| Pas de statistiques tierces, pas de partage | `architecture.md` § 10 ; aucune route ne liste les préférences d'un autre utilisateur |
| Effacé avec le compte, sauf les descriptions de produits | `PurgeUserDriveDataHandler` (réglages, lignes de produits, préférences) ; `UserOwnedDataCoverageTest` ; ADR 0021. **Pas d'auto-suppression** : seul un administrateur supprime un compte |
| Changer de magasin ne supprime pas les anciens choix | `SaveDriveSettingsHandler` remplace le magasin sans purge ; les choix portent leur `store_id` |
| Sauvegardes : 14 conservées, une par nuit | `deploy/backup.sh` ; [`../production.md`](../production.md) |
| Les pages de Leclerc appellent leurs propres services (anti-robot, audience) ; Repas ne les voit pas | comportement d'un navigateur : la WebView du drive accepte les cookies de ces services (voulu, pour le défi anti-robot), celle de Repas non ; le cookie anti-robot est visible dans la WebView ([`spikes/DRV-011-rapport.md`](spikes/DRV-011-rapport.md)) ; phrase ajoutée à la note du front par repas-front#108 (revue de sécurité du front, [`revue-securite-drive.md`](https://github.com/kcharignon/repas-front/blob/main/docs/revue-securite-drive.md) constat D3), reportée ici |
| Cadence lente, en série, à la demande | `architecture.md` § 8 (cadence), DRV-003 (constat CGU) ; l'agent s'arrête après deux refus |
| Risque de suspension du compte E.Leclerc | [`spikes/DRV-003-cgu.md`](spikes/DRV-003-cgu.md) ; décision D23 de l'utilisateur |

---

## 4. Registre des traitements

Il n'existe pas de registre des traitements dans ce dépôt. Cette fiche en tient lieu pour la fonction « envoi au drive » ; elle peut devenir la première entrée d'un registre commun.

| Rubrique | Contenu |
|---|---|
| Traitement | Mémorisation des préférences d'achat au drive (magasin, produits choisis, produits à ne plus proposer, articles « J'en ai toujours », prix vus) et du statut d'envoi des listes |
| Finalité | Remplir plus vite le panier du drive de l'utilisateur à partir de ses listes de courses ; estimer le coût d'une liste (estimation datée) |
| Personnes concernées | Les utilisateurs de Repas qui activent la fonction (usage familial) |
| Données | Magasin (numéro, adresse web, nom facultatif) ; pour chaque article : produits acceptés (ordre), à ne plus proposer, « J'en ai toujours », date de mise à jour ; description d'un produit (numéro de l'enseigne, nom, contenu, prix, date de vue) ; statut et date d'envoi d'une liste. Les choix peuvent révéler un régime alimentaire ou un état de santé : minimisation, aucune diffusion. |
| Données jamais collectées | Identifiants, mots de passe, codes, cookies et jetons de l'enseigne ; adresse, créneau, paiement ; contenu du panier de l'enseigne, commandes ; photos |
| Base juridique | À qualifier par le propriétaire du service (usage familial sur demande de l'utilisateur) |
| Destinataires | Aucun. Hébergeur du serveur : DigitalOcean (région non documentée dans le dépôt : à confirmer). L'enseigne n'est jamais destinataire de données par Repas : l'application agit dans la session de l'utilisateur |
| Durée | Tant que le compte existe ou jusqu'à l'oubli par l'utilisateur ; descriptions de produits sans propriétaire conservées ; copies de sauvegarde : 14 dernières, environ deux semaines |
| Suppression | Retrait ou « Oublier cet article » ; suppression du compte par un administrateur (réglages, produits dont Repas se souvient, listes) en une transaction (ADR 0021) ; changement de magasin : anciens choix conservés jusqu'à la suppression du compte |
| Sécurité | Accès par jeton, l'utilisateur vient toujours du jeton et jamais du corps de la requête ; aucune route ne liste les préférences d'autrui ; entrées bornées et validées ; sauvegardes locales en mode `700` ; pas de chiffrement de la base au repos ([`analyse.md`](analyse.md) § 6) |
| Droits | Accès, rectification, effacement : demande à l'administrateur du service |

---

## 5. Pour l'intégration dans le front (autre ticket)

1. (Remplacé par DRV-628) Plus de texte court dans les réglages : un lien discret « Conditions d'utilisation » au pied de la rubrique Drive de « Moi ».
2. Le texte complet sur la page `/drive/conditions` (l'ancre `/drive/aide#vie-privee` n'existe plus). Textes dans `src/i18n/fr.ts`, couleurs par jetons, cibles de 44 px, lisible sur 360 px.
3. Ne rien ajouter qui ne soit pas dans « À garder vrai ». Si l'écran « Mes produits » (DRV-410) porte un autre nom, adapter la phrase « Comment tout effacer ».
4. ~~Afficher la note avant la première activation de l'envoi~~ : décision de l'utilisateur du 2026-10-19, aucune acceptation ni fenêtre dans le parcours ; la page reste accessible. Motif d'origine : les conditions de Leclerc concernent le compte de chaque utilisateur (constat DRV-003).
5. Test : le lien existe depuis « Moi » et l'aide et mène à la page ; un test vérifie que tout ce qui est retiré des écrans figure sur la page.
