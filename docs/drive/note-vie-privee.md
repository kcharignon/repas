# Note de vie privée : l'envoi de la liste au drive

Texte du front (ticket DRV-504 : **partie back** = le texte et le registre, ici ; **partie front** faite, repas-front#101, #105 et #106, clés `drivePrivacy.*` de `src/i18n/fr.ts`). Depuis DRV-628 (2026-10-19), il n'est plus dans le parcours : c'est la page **`/drive/conditions`** (« Conditions d'utilisation du drive », clés `driveTerms.*` de `src/i18n/fr.ts`), atteinte par un lien discret « Conditions d'utilisation » au pied de la rubrique Drive de « Moi » et de l'aide ; les écrans du parcours n'ont plus ni résumé, ni avertissement. Français simple, au **tutoiement** comme tout le produit (décision du PO du 2026-10-19, DRV-627, [`principes-produit.md`](principes-produit.md)) : le texte complet ci-dessous reprend les clés `driveTerms.*` du front, mot pour mot. Aucune image externe.

- Dernière mise à jour du texte : **2026-10-20** (contenance d'un produit dans l'unité de ta liste, DRV-652 ; recherche mémorisée, DRV-650 ; avant : 2026-10-19, tutoiement DRV-627, page des conditions DRV-628, glossaire DRV-621). Le relire à chaque changement de ce que la fonction enregistre (voir « À garder vrai » plus bas).
- Les mots entre « » sont ceux de l'interface actuelle de l'application et du front.

---

## 1. Texte court (retiré des écrans par DRV-628, gardé pour mémoire)

*Texte d'origine, tel qu'il s'affichait (au vouvoiement de DRV-621) : il n'est plus à l'écran, donc il n'a pas été repris au tutoiement.*

> **Votre vie privée.** Repas retient votre magasin, les produits que vous choisissez (avec leur prix vu) et la date d'envoi de vos listes. Il ne voit jamais votre identifiant, votre mot de passe, vos cookies, votre panier Leclerc ni vos commandes : l'envoi se fait dans l'application, avec votre propre connexion à Leclerc. Attention : Leclerc peut suspendre un compte qu'il juge utilisé de façon abusive. **Lisez la note complète.**

(Ce résumé et son lien « Lisez la note complète » ne sont plus affichés : l'écran sert l'action en cours, règle 10 de `principes-produit.md`.)

---

## 2. Texte complet (page « Conditions d'utilisation du drive », `/drive/conditions`)

### Conditions d'utilisation du drive

Envoyer ta liste au drive, c'est Repas qui prépare la liste et l'application qui la met dans ton panier Leclerc, avec ta connexion à toi. Voici comment cela se passe, ce que Repas garde, ce qu'il ne voit pas, les risques, et comment tout effacer.

### Comment ça se passe

- Quand tu touches « Envoyer au drive », Repas prépare ta liste et la donne à l'application, sur ton téléphone. Rien n'est mis au panier tant que tu n'as pas touché « Commencer ».
- L'application ouvre Leclerc dans sa propre fenêtre. C'est **toi** qui te connectes à Leclerc, comme sur le site. Ensuite elle cherche les produits et les met dans ton panier **depuis ta session Leclerc**, comme si tu le faisais toi-même : un produit à la fois, avec des pauses, pour ne pas gêner Leclerc.
- Tout cela se passe entre ton téléphone et Leclerc. **Rien de cela ne passe par les serveurs de Repas.** Comme dans un navigateur, les pages de Leclerc font appel à leurs propres services (protection contre les robots, mesure d'audience) : Repas n'y a pas accès et ne les choisit pas. À la fin, l'application ne rend à Repas que ce qu'il doit retenir : les produits que tu as choisis, et ton magasin quand tu le lui fais lire. Rien d'autre de ton compte Leclerc ne quitte la page.
- Si l'envoi s'arrête, tu le reprends là où il en était : l'avancement est gardé et les articles déjà faits ne sont pas refaits.
- Tu finalises ta commande toi-même sur Leclerc. Repas ne valide jamais de commande, ne choisit pas de créneau, ne paie rien et ne vide jamais ton panier. Il ne réduit jamais une quantité déjà au panier : un article déjà présent en plus grande quantité reste tel quel.

### Ce que Repas enregistre

- **Ton magasin Leclerc** : son numéro, son adresse sur le site de Leclerc, et le nom que tu lui as donné si tu en as mis un.
- **Les produits dont Repas se souvient, article par article** : ton produit habituel, tes produits de remplacement (5 au plus), les produits à ne plus proposer (20 au plus) et les articles « J'en ai toujours ». Ces produits sont propres à ton magasin.
- **Pour chaque produit** : son numéro chez Leclerc, son nom, son contenu (par exemple 1 kg), et son prix avec la date où il a été vu.
- **La recherche qui t'a fait trouver un produit** : le texte que tu as tapé pour le trouver sur le site (par exemple « céréales coussins »), pour chercher un remplaçant avec ce texte quand le produit n'est plus disponible. Elle est gardée avec l'article, pour ton magasin Leclerc seulement. Elle n'est jamais envoyée ailleurs et personne d'autre ne la voit. Elle est gardée aussi quand tu prends un produit « Pas mon habituel » : le produit n'est pas retenu, mais le texte qui l'a fait trouver l'est (DRV-653, même phrase, rien à ajouter).
  (Phrase à recopier dans « driveTerms.saves » du front avant que le site envoie une recherche : DRV-650, lot 2. Le back la garde déjà si on la lui envoie.)
- **Combien il y en a dans un produit, quand ta liste compte autrement qu'en poids ou en volume** : si ta liste dit « 15 tranches », Repas ne sait pas combien de tranches tient dans un produit. Il te le demande une fois, et retient ta réponse (par exemple « 20 tranches ») pour calculer le nombre de produits la fois suivante, sans te le redemander. Elle est gardée avec ce produit-là, dans ton article, pour ton magasin Leclerc seulement. Elle n'est jamais envoyée ailleurs et personne d'autre ne la voit.
  (Phrase à recopier dans « driveTerms.saves » du front avant que le site envoie une contenance : DRV-652, lot 2. Le back la garde déjà si on la lui envoie.)
- Les prix servent à estimer le coût d'une liste. L'estimation est indicative : elle est faite d'après les derniers prix vus, avec leur date, et le vrai prix est toujours celui de Leclerc. Elle ne compte que les articles dont le prix est connu, elle est peu fiable quand moins de la moitié en ont un, et Repas ne devine jamais un prix.
- **Pour chaque liste** : le fait qu'elle a été « envoyée au drive » et la date de l'envoi.
- **La version de l'application** : le numéro de version de l'application Repas de ton téléphone, avec la date où il a été vu, pour te dire quand une nouvelle version est prête. Seule la version est retenue : aucun identifiant de ton téléphone, aucun modèle de téléphone.
  (Phrase recopiée de `driveTerms.saves.appVersion` du front, PR front #173, 2026-10-20 ; elle doit rester identique au fond dans les deux dépôts. [DRV-637](tickets/DRV-637-version-de-l-application-connue-du-serveur.md).)

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
- Sur ton téléphone, l'application garde ta connexion à Leclerc, comme le ferait un navigateur. Elle ne la partage ni avec Repas ni avec personne d'autre que Leclerc et ses propres services. Tu peux l'effacer à tout moment : menu de l'application, « Se déconnecter de Leclerc ».
- Les descriptions de produits (nom, contenu, prix vu, magasin) sont communes à tous les utilisateurs de Repas et ne disent **pas** qui les a vues. Chacun ne retrouve que les produits dont Repas se souvient pour lui. La première personne qui décrit un produit fixe sa description (nom, contenu, code-barres) : les suivantes ne la réécrivent pas, elles ne font que la compléter si elle est incomplète ; le prix vu, lui, est rafraîchi par celles qui choisissent ce produit. Ces descriptions restent quand un compte est supprimé, mais sans aucun lien avec la personne.

### Comment tout effacer

- **Un produit, ou un article** : dans « Moi », « Mes produits du drive » : retire un produit, choisis « Ne plus proposer » pour un autre, ou touche « Oublier cet article » pour effacer tout ce que Repas retient sur lui.
- **Un changement de magasin** : si tu changes de magasin, les produits dont Repas se souvient pour l'ancien magasin ne sont plus utilisés, mais ils restent enregistrés jusqu'à la suppression de ton compte.
- **Tout, d'un coup** : demande la suppression de ton compte à la personne qui administre Repas. Elle efface tes données du drive (magasin, produits dont Repas se souvient), tes listes de courses avec leur statut d'envoi, et le reste de ton compte.
- Pour exercer tes droits (savoir ce que Repas a de toi, corriger, effacer), écris à la personne qui t'a donné accès à Repas.

### Avant d'utiliser l'envoi : ce que tu dois savoir

- L'envoi agit **dans ton propre compte Leclerc**, avec ta propre connexion, comme si tu faisais les recherches et les ajouts toi-même. Repas ne voit jamais tes identifiants.
- Les conditions d'utilisation de Leclerc permettent à l'enseigne de **suspendre un compte E.Leclerc** (et la cagnotte de Tickets E.Leclerc qui va avec) si elle juge l'usage abusif. Repas limite ce risque : une requête à la fois, avec des pauses, seulement quand tu le demandes, et seulement pour ta liste. Mais le risque existe et il est pour ton compte.
- Si Leclerc ralentit les requêtes, l'application s'arrête d'elle-même. Attends plusieurs heures avant de reprendre l'envoi, sans le relancer en boucle. Si ton compte Leclerc pose un problème, contacte le service client de Leclerc : Repas n'a aucun accès à ton compte et ne contacte jamais Leclerc.
- Tu peux t'en passer à tout moment : la liste de Repas reste utilisable pour faire tes courses à la main sur Leclerc.

---

## 3. À garder vrai (pour qui modifie la fonction)

Chaque phrase du texte a sa source. Si l'une change, mettre à jour ce texte **et** le front dans la même PR.

| Ce que le texte affirme | D'où ça vient |
|---|---|
| Aucun identifiant, cookie ni jeton Leclerc chez Repas ; le back ne contacte jamais Leclerc | ADR 0022 règles 1 et 2 ; [`architecture.md`](architecture.md) § 2 ; aucun champ de ce type dans `docs/api/openapi.json` |
| L'envoi agit dans la session de l'utilisateur, dans l'application, sans passer par le serveur | ADR 0022 règle 3 et mise à jour G1 ; [`pivot-application-android.md`](pivot-application-android.md) ; le plan voyage par le pont natif, jamais par une route du back |
| Repas ne valide pas la commande, ne choisit pas de créneau, ne paie pas, ne vide pas le panier ; il ne coche dans ta liste que les articles que ton drive a réellement mis au panier, jamais les autres | ADR 0022 règles 4 et 9 (amendée le 2026-10-31) ; ADR 0023 décision 3 (amendée) ; `architecture.md` § 2 invariants 4, 6, 7 |
| Magasin : numéro, adresse, nom facultatif | table `drive_settings` (`store_id`, `base_url`, `store_label`), une ligne par utilisateur et enseigne |
| Produits dont Repas se souvient : habituel, remplacement (5), à ne plus proposer (20), « J'en ai toujours » (dans le code : préféré, secours, refusés, `HAVE_AT_HOME`) | ADR 0022 règle 7 ; tables `drive_choice`, `drive_choice_product` |
| Description d'un produit : numéro, nom, contenu, prix et date de vue ; pas de photo. Le nom et le prix que tu vois sont ceux que tu as toi-même lus : ils sont gardés avec ton article, jamais partagés avec les autres comptes (la description commune ne sert que de repli) | table `drive_product` (D22, `analyse.md`) ; `drive_product` n'a pas de colonne utilisateur ; colonnes `drive_choice_product.label`, `price_cents` et `price_seen_at` (ADR 0032) : sur la ligne du produit de ton choix, donc sous la clé du choix `(user_id, retailer, store_id, ingredient_slug)`, effacées avec la ligne (aucune colonne utilisateur de plus) |
| La recherche qui a fait trouver un produit est gardée avec l'article, pour ton magasin seulement ; elle n'est jamais envoyée ailleurs | colonne `drive_choice.search_query` (DRV-650, aussi pour un article sans produit habituel : `NOTE_SEARCH`, DRV-653, qui crée alors le choix sans aucune ligne de produit) : sous la clé du choix `(user_id, retailer, store_id, ingredient_slug)`, donc privée, par enseigne et par magasin ; rendue seulement par « GET /drive/choices » et « GET /drive/plans/{id} » de son propriétaire ; jamais dans une URL ni un journal ; 80 caractères au plus, sans caractère de contrôle |
| Combien d'unités de la liste (tranches, cuillères…) tiennent dans un produit est une réponse de la personne, gardée avec ce produit dans son article, pour son magasin seulement ; elle n'est jamais envoyée ailleurs | colonnes `drive_choice_product.pack_size_value` et `pack_size_unit_slug` (DRV-652) : sur la ligne du produit accepté de la préférence, donc sous la clé du choix `(user_id, retailer, store_id, ingredient_slug)` ET propre à ce produit (jamais sur la fiche commune `drive_product`) ; rendue seulement par « GET /drive/choices » et « GET /drive/plans/{id} » de son propriétaire ; jamais dans une URL ni un journal ; valeur de 0,001 à 9 999, unité qui existe dans Repas ; perdue quand le produit n'est plus accepté |
| Statut « envoyée » et date d'envoi | `shopping_list.status` et `sent_at` (ADR 0023) |
| L'estimation est indicative et datée, ne compte que les articles dont le prix est connu, est peu fiable quand moins de la moitié en ont un, et ne devine jamais un prix | [`DRV-611`](tickets/DRV-611-estimation-cout-liste.md) (`ListCostEstimator`, couverture) ; [`DRV-612`](tickets/DRV-612-ecran-estimation-cout.md) |
| Rien d'autre n'est lu : adresse, créneau, paiement, panier, commandes | l'agent ne fait que lire le magasin, chercher, fixer une quantité, lire le produit ajouté (ADR 0022 règle 4) |
| Pas de statistiques tierces, pas de partage | `architecture.md` § 10 ; aucune route ne liste les préférences d'un autre utilisateur |
| Effacé avec le compte, sauf les descriptions de produits | `PurgeUserDriveDataHandler` (réglages, lignes de produits avec leur contenance, leur libellé et leur prix, préférences avec la recherche mémorisée qui est dans leur ligne) ; `UserOwnedDataCoverageTest` ; ADR 0021. **Pas d'auto-suppression** : seul un administrateur supprime un compte |
| Repas retient la date de la dernière connexion de chaque compte, vue de l'administrateur seul (§ 4 bis ; pas dans le texte des conditions du drive, ce n'est pas une donnée du drive) | colonne `user.last_login_at` (ADR 0021, amendement du 2026-10-20) ; écrite par `RecordUserLogin` au plus toutes les 10 minutes, après une authentification réussie seulement ; lue par `GET /api/v1/users` (administrateur), jamais par `GET /api/v1/me` ; sur la ligne du compte, donc effacée avec lui |
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
| Données | Magasin (numéro, adresse web, nom facultatif) ; pour chaque article : produits acceptés (ordre), à ne plus proposer, « J'en ai toujours », recherche qui a fait trouver le produit choisi (texte tapé, 80 caractères au plus), pour chaque produit accepté la contenance donnée par la personne dans l'unité de sa liste (un nombre et l'unité, par exemple 20 tranches), date de mise à jour ; description d'un produit (numéro de l'enseigne, nom, contenu, prix, date de vue) ; statut et date d'envoi d'une liste ; dernière version vue de l'application Android (trois nombres) et date. Les choix peuvent révéler un régime alimentaire ou un état de santé : minimisation, aucune diffusion. |
| Données jamais collectées | Identifiants, mots de passe, codes, cookies et jetons de l'enseigne ; adresse, créneau, paiement ; contenu du panier de l'enseigne, commandes ; photos |
| Base juridique | À qualifier par le propriétaire du service (usage familial sur demande de l'utilisateur) |
| Destinataires | Aucun. Hébergeur du serveur : DigitalOcean (région non documentée dans le dépôt : à confirmer). L'enseigne n'est jamais destinataire de données par Repas : l'application agit dans la session de l'utilisateur |
| Durée | Tant que le compte existe ou jusqu'à l'oubli par l'utilisateur ; descriptions de produits sans propriétaire conservées ; copies de sauvegarde : 14 dernières, environ deux semaines |
| Suppression | Retrait d'un produit (efface sa contenance) ou « Oublier cet article » (efface aussi la recherche mémorisée et les contenances) ; suppression du compte par un administrateur (réglages, produits dont Repas se souvient, listes) en une transaction (ADR 0021) ; changement de magasin : anciens choix (et leurs recherches et contenances) conservés jusqu'à la suppression du compte, mais jamais repris pour le nouveau magasin |
| Sécurité | Accès par jeton, l'utilisateur vient toujours du jeton et jamais du corps de la requête ; aucune route ne liste les préférences d'autrui ; entrées bornées et validées ; sauvegardes locales en mode `700` ; pas de chiffrement de la base au repos ([`analyse.md`](analyse.md) § 6) |
| Droits | Accès, rectification, effacement : demande à l'administrateur du service |

### 4 bis. Suivi de la dernière connexion (administration des comptes)

Deuxième entrée du registre, hors du drive : ce traitement concerne **tous** les comptes de Repas, qu'ils utilisent le drive ou non.

| Rubrique | Contenu |
|---|---|
| Traitement | Date de la dernière connexion de chaque compte, vue de l'administrateur (ADR 0021, amendement du 2026-10-20) |
| Finalité | Permettre à l'administrateur de savoir quels comptes sont utilisés (gérer, désactiver ou supprimer un compte en connaissance de cause) |
| Personnes concernées | Les utilisateurs de Repas (usage familial) |
| Données | Une date par compte (`user.last_login_at`, UTC) : celle de la dernière authentification réussie, par mot de passe ou par renouvellement de la session ouverte. Rien d'autre : ni adresse IP, ni appareil, ni historique des connexions, ni lieu. Retenue au plus toutes les 10 minutes. Pour les comptes existants à la mise en place, rattrapée depuis la date du jeton de session le plus récent encore présent (jamais plus récente que la vraie), sinon vide |
| Données jamais collectées | Historique des connexions (une seule date, remplacée), adresse IP, navigateur, appareil, position |
| Base juridique | À qualifier par le propriétaire du service (usage familial, administration du compte) |
| Destinataires | L'administrateur de Repas, seul (écran d'administration des comptes). Aucun autre utilisateur ne la voit, elle n'est dans aucune autre réponse de l'API (`GET /api/v1/me` compris). Hébergeur du serveur : DigitalOcean |
| Durée | Tant que le compte existe (la date est remplacée à chaque nouvelle connexion) ; copies de sauvegarde : 14 dernières, environ deux semaines |
| Suppression | Avec le compte : la date est sur la ligne du compte, la suppression par un administrateur (ADR 0021) l'efface en même temps, rien à ajouter à la purge |
| Sécurité | Lecture réservée au rôle administrateur ; écriture par une instruction qui ne touche que cette colonne ; aucun avertissement ni écran pour l'utilisateur |
| Droits | Accès, rectification, effacement : demande à l'administrateur du service |

---

## 5. Pour l'intégration dans le front (autre ticket)

1. (Remplacé par DRV-628) Plus de texte court dans les réglages : un lien discret « Conditions d'utilisation » au pied de la rubrique Drive de « Moi ».
2. Le texte complet sur la page `/drive/conditions` (l'ancre `/drive/aide#vie-privee` n'existe plus). Textes dans `src/i18n/fr.ts`, couleurs par jetons, cibles de 44 px, lisible sur 360 px.
3. Ne rien ajouter qui ne soit pas dans « À garder vrai ». Si l'écran « Mes produits » (DRV-410) porte un autre nom, adapter la phrase « Comment tout effacer ».
4. ~~Afficher la note avant la première activation de l'envoi~~ : décision de l'utilisateur du 2026-10-19, aucune acceptation ni fenêtre dans le parcours ; la page reste accessible. Motif d'origine : les conditions de Leclerc concernent le compte de chaque utilisateur (constat DRV-003).
5. Test : le lien existe depuis « Moi » et l'aide et mène à la page ; un test vérifie que tout ce qui est retiré des écrans figure sur la page.

---

## 5 bis. Variante par enseigne du texte des conditions (DRV-711) — **proposition, à valider par le propriétaire**

> **Ce texte est destiné aux utilisateurs. Il n'est pas définitif.** Il est proposé ici pour que le propriétaire le relise avant tout passage dans le front (clés `driveTerms.*` : le texte commun, puis une **section par enseigne**, `termsSection` du registre). Rien de ce qui suit n'est affirmé sur le site d'une enseigne sans mesure : les phrases propres à Auchan sont marquées « à compléter ».

**Principe.** Le texte du § 2 reste vrai pour Leclerc. Avec plusieurs enseignes, il devient **un tronc commun** (ce que Repas fait et ne fait pas, quelle que soit l'enseigne) plus **un bloc par enseigne** (ce qui dépend d'elle). Le nom est un paramètre (`{enseigne}`, « de {enseigne} » avec élision : « d'Auchan »). Tutoiement, phrases de 15 mots au plus, aucun avertissement hors de ces conditions ([`principes-produit.md`](principes-produit.md)).

**Tronc commun (proposé, à partir du § 2).**

- Envoyer ta liste au drive, c'est Repas qui prépare la liste et l'application qui la met dans ton panier, avec ta connexion à toi, chez le drive que tu choisis.
- Rien de cela ne passe par les serveurs de Repas. Repas ne voit jamais tes identifiants, tes cookies ni ton panier. Il ne valide jamais de commande, ne choisit pas de créneau, ne paie rien, ne vide jamais ton panier et ne réduit jamais une quantité.
- **Ce que Repas enregistre, pour chaque drive que tu as réglé** : ton magasin (son identifiant et son nom), les produits dont Repas se souvient pour ce drive, leur nom, leur contenu et le prix que tu as vu, avec sa date. Les produits d'un drive ne sont jamais utilisés pour un autre.
- **Ton drive habituel et le dernier drive ouvert** sont retenus avec ton compte, pour ne plus te poser la question.
- Tout s'efface avec ton compte, ou article par article dans « Mes produits du drive » ; « Se déconnecter de {enseigne} » efface seulement la connexion de ce drive sur ton téléphone.

**Bloc propre à chaque enseigne (modèle).**

- Comment tu te connectes chez {enseigne} (sur la page de {enseigne}, jamais dans Repas).
- Ce que ton magasin désigne chez {enseigne} (par exemple le point de retrait lu sur le site).
- **Le risque de suspension de ton compte {enseigne}** : phrase à écrire d'après les conditions de l'enseigne, **relues par le propriétaire** ; ne rien affirmer qui ne soit pas lu à la source (chez Leclerc : [`spikes/DRV-003-cgu.md`](spikes/DRV-003-cgu.md), décision D23).

**Bloc d'Auchan : à compléter par DRV-726.** On sait seulement que la connexion passe par `compte.auchan.fr` (mesuré, [`hypotheses-site-reel-auchan.md`](hypotheses-site-reel-auchan.md) D-5) ; la connexion dans l'application, les conditions de l'enseigne et le risque pour le compte ne sont pas établis. Aucune phrase sur Auchan n'est donc écrite ici.

**Ce que le registre des traitements (§ 4) gagne** : la colonne `retailer` des tables du drive est déjà dans la fiche ; ajouter `drive_retailer_preference` (drive habituel et dernière enseigne ouverte, une ligne par utilisateur, effacée avec le compte) à la liste des données du drive, et, pour une enseigne qui en a, l'identifiant d'offre d'un produit (`drive_product.retailer_offer_id`, fait public sans propriétaire). Ces lignes sont à ajouter au § 4 **quand le propriétaire aura validé** la variante.
