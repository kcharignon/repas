# Audit de simplicité du parcours Drive (2026-10-19)

Audit du PO sur le flux et les textes de l'envoi au drive, avec les principes de [`principes-produit.md`](principes-produit.md). Sources : lecture des textes du front web (`src/i18n/fr.ts`), de l'agent (`drive-agent/src/ui/strings.ts`) et de l'application Android (`res/values/strings.xml`), et recette réelle des 18 et 19 octobre sur un téléphone. Aucune capture de site réel n'est reproduite ici.

## 1. Le parcours aujourd'hui (touches du parcours heureux)

**Premier envoi, 6 articles dont 4 nouveaux** (vécu à la recette) :

1. Ouvrir la liste, **Envoyer au drive**. 2. Confirmer dans la fenêtre « Envoyer la liste au drive ? ». 3. **Ouvrir le drive** (écran de l'envoi). 4. Sur le site de Leclerc : toucher **Commencer mes courses**, *à la main*, guidé par un bandeau de 30 mots. 5. L'agent affiche « Prêt à remplir ton panier » : **Commencer**. 6. Pour chaque nouvel article (4) : choisir un produit, ou « Passer cette fois » / « J'en ai toujours » ; si le produit choisi n'est pas le produit retenu : une fenêtre de plus à trois options. 7. L'agent finit : **Retourner dans Repas**. 8. Le récapitulatif : **Finaliser sur Leclerc**.

Total : **8 touches fixes + 4 à 8 pour les nouveaux articles**, soit 12 à 16 touches, sur trois univers visuels (Repas, Leclerc, la couche de l'agent), dans deux voix (« vous » puis « tu »).

**Deuxième envoi, tout connu** : 1, 2, 3, 4, 5, 7, 8 = **7 touches** alors que rien n’est à décider.

**Objectif PO** : deuxième envoi en **5 touches** : Envoyer au drive, Confirmer, Ouvrir le drive, Commencer, puis Finaliser sur Leclerc (le site de Leclerc s’ouvre tout seul sur le magasin et le retour dans Repas se fait tout seul) ; premier envoi : 5 + 1 par nouvel article.

## 2. Constats

Gravité : **A** = un proche reste bloqué ou se trompe ; **B** = gênant, ralentit, fait douter ; **C** = cosmétique.

| # | Gravité | Constat | Preuve |
|---|---|---|---|
| F1 | A | **Deux boutons pour enregistrer le magasin** : « Enregistrer » dans l'agent, puis « Enregistrer mon magasin » dans Repas. Le premier ne suffit pas et rien ne le dit. | Vécu le 18/10 : le magasin n'était pas enregistré côté serveur. |
| F2 | A | **« Commencer mes courses » se touche à la main** sur le site de Leclerc, guidé par un long bandeau. Avant la correction, « Page non prise en charge ». | Vécu le 18/10 (« Page non prise en charge »). |
| F3 | B | **Trois étapes de confirmation avant d'écrire** : la fenêtre « Envoyer ? », l'écran de l'envoi (« Ouvrir le drive »), « Prêt à remplir : Commencer ». L'agent répète les comptes déjà montrés par Repas. | Lecture de `fr.ts` et `strings.ts`. |
| F4 | B | **Un mot différent pour la même chose** : envoi, passage, plan, remplissage du panier ; récapitulatif, résumé ; « Déjà à la maison », « J'en ai toujours », « Vous en avez déjà » ; « Passer cette fois », « Passés », « Ignoré » ; « refusé », « ne plus jamais prendre » ; préféré, secours. | Comptes : voir `principes-produit.md` § 5. |
| F5 | B | **Deux voix** : « vous » dans Repas (111 occurrences, aucun « tu »), « tu » dans l'agent et l'application Android, dans le même parcours. | `grep` sur les trois fichiers. |
| F6 | B | **Récapitulatif trop technique** : sept compteurs (dont « Prix lus » et « Échecs »), même à zéro ; titres « Passage arrêté », « Le passage a échoué » incompris. | Vécu le 18/10 : « Passage arrêté… je dois faire quoi ? » |
| F7 | B | **« Que faites-vous de ce produit ? »** expose l'ordre de préférence (préféré / secours / une fois) : trois choix et un retour pour une question que l'utilisateur ne se pose pas. | `strings.ts` (`placement`). |
| F8 | B | **Aucune proposition par défaut** pour un nouvel article : chaque choix est un effort. Pas de moyen de dire « passe tous les suivants ». (Les suggestions automatiques sont au backlog, DRV-601.) | `strings.ts` (`chooser`). |
| F9 | B | **Erreurs avec jargon ou code** : « Code : {code} », « Réponse incomprise », « lignes inattendues ignorées », « L'application ne répond pas comme prévu ». | `fr.ts`. |
| F10 | B | **Textes d'introduction longs** : l'introduction de l'écran de l'envoi fait 37 mots, le bandeau d'ouverture 30. | `fr.ts`, `strings.xml`. |
| F11 | B | **Magasin manquant = message, pas bouton** : « Choisissez d'abord votre magasin dans les réglages du drive. » (à vérifier : renvoie-t-il directement à l'écran qui le lit ?) | `fr.ts` (`driveNotConfigured`). |
| F12 | C | **Nom de l'écran** : l'onglet s'appelle « Moi », la page « Mon compte », les textes d'aide disent « Mon compte » ; l'utilisateur a relevé l'écart. | `fr.ts` lignes 12, 243, 601, 867. |
| F13 | C | **Menu de l'application trop long** : « Se déconnecter de Leclerc et effacer les données du drive » (10 mots) ; « Ouvrir dans le navigateur » (inutile pour un proche). Les entrées de débogage ne doivent pas apparaître dans l'application signée (à vérifier). | `strings.xml`. |
| F14 | C | **Mise à jour de l'application** : téléchargement depuis GitHub, avertissement de Play Protect, aucune vérification dans l'application. Pas de remède simple avant la vérification des développeurs Android ; au backlog (« nouvelle version disponible »). | Vécu le 18/10. |
| F15 | C | **Lisibilité dans le site de Leclerc en mode ordinateur** : texte et boutons minuscules (corrigé en partie, agrandissement ×2,38 ; à confirmer à l'essai d'un proche). | Vécu le 18/10. |
| F16 | A | **« Finaliser sur Leclerc » mène à une page inexistante, dans le mauvais navigateur** : `BASKET_PATH` est vide (`src/features/drive/leclerc.ts`), donc le lien pointe la racine du magasin, qui est une page 404 sur le vrai site (mesure du 18/10) ; et il s’ouvre dans le navigateur du téléphone (`finalizeHelp`), où l’utilisateur n’est pas connecté à Leclerc (sa connexion est dans l’application). L’adresse de la page du panier reste à relever sur le téléphone. | Lecture de `leclerc.ts` et `fr.ts` ; non encore essayé en recette. |

## 3. Tickets (jalon M6 — Simplicité d'usage)

Ordre conseillé : 621 d'abord (aucun risque, tout le reste en dépend pour les textes), 623 et 622 ensuite, 624, puis 625 après vérification humaine sur le téléphone.

| Ticket | Contenu | Corrige | Taille |
|---|---|---|---|
| [DRV-621](tickets/DRV-621-voix-et-vocabulaire-uniques.md) | Voix unique (vouvoiement) et glossaire appliqués au web, à l'agent, à l'application et à l'aide ; test qui interdit les mots du glossaire | F4, F5, F12, F13 | M |
| [DRV-622](tickets/DRV-622-ecrans-envoi-et-recapitulatif-simples.md) | Écran de l'envoi et récapitulatif en une phrase, compteurs non nuls, une action principale, erreurs en trois phrases sans code, magasin manquant = bouton | F6, F9, F10, F11 | M |
| [DRV-623](tickets/DRV-623-un-seul-geste-pour-le-magasin.md) | Un seul geste pour enregistrer le magasin | F1 | S |
| [DRV-624](tickets/DRV-624-choix-de-produit-sans-jargon.md) | Choix du produit sans jargon, une seule question, « passer les suivants » | F7, F8 | M |
| [DRV-625](tickets/DRV-625-entrer-dans-le-magasin-sans-geste.md) | Entrer dans le magasin sans geste (« Commencer mes courses »), revenir dans Repas sans geste, « Finaliser » dans l’application (panier Leclerc connecté) | F2, F3, F16 | M (gardé par une vérification humaine) |
| [DRV-626](tickets/DRV-626-balayage-apres-revue-de-parcours.md) | Balayage après la revue de parcours sur captures (compteurs à zéro, « Passer ces n articles », estimation sans prix, récapitulatif sans impasse, réglages) | F17 à F27 | M |

## 4. Décisions du PO (2026-10-19, réversibles)

- **Vouvoiement partout** (voir `principes-produit.md` § 3).
- **Le magasin lu par l'agent est enregistré par ce seul geste** (DRV-623) : la même validation côté serveur (hôte autorisé) vaut qu'on saisisse l'adresse à la main ou qu'on la lise ; un résultat falsifié par la page ne peut qu'enregistrer un autre magasin Leclerc, ce que l'utilisateur voit et corrige dans « Moi ».
- **« Passer les n suivants » demande une ligne de confirmation** quand un des articles passés n’a encore aucun produit retenu (DRV-624) : passer un article jamais choisi le retire de la commande, et un toucher de travers sur un bouton voisin suffirait. Un envoi de 4 nouveaux articles coûte donc 3 touches (choisir, passer, confirmer), pas 2. Retirer la confirmation : une ligne (`confirmRest`, voir le ticket). Réversible.
- **La notion de produit « de secours » reste dans le serveur** (ordre de préférence) ; elle disparaît des écrans : un nouveau produit « toujours » passe en tête, les anciens suivent en remplacement.
- **Sous 50 % de couverture, l'estimation du total reste masquée** (« Estimation peu fiable ») tant que l'utilisateur ne décide pas autrement ; ce n'est pas un enjeu de simplicité.

## 5. À mesurer avant d'aller plus loin

- Parcours de 7 touches du deuxième envoi (objectif 5) : à recompter après DRV-625 (recette réelle, partie 6).
- Essai d'un proche, seul, sans explication (voir `principes-produit.md` § 7) : ce qui le fait hésiter s'ajoute au tableau des constats.
- Premier envoi d'un proche : le magasin à choisir et la connexion à Leclerc restent les deux seuls gestes préalables ; vérifier qu'aucun ne demande de réglage dans « Moi » (règle 6).

## 6. État après DRV-621 à 624 (2026-10-19)

*Les textes cités dans les constats et les comptes rendus de cette section et de la suivante sont ceux d'alors, au vouvoiement ; la colonne « État » du tableau suit les écrans actuels. DRV-627 (2026-10-19) a tout passé au tutoiement, sans changer un mot du glossaire (voir « Après DRV-627 » plus bas).*

Les quatre tickets sont fusionnés dans le front (`main`, agent 0.19.0 ; 0.20.0 avec DRV-626, voir plus bas) et pas encore publiés : le front web (v0.5.2) avant l'application (android-v0.1.5). Les constats F1 et F4 à F12 sont traités (F13 pour les libellés), **F2, F3 et F16 sont traités par DRV-625** (fait le 2026-10-19, voir plus bas) ; F14 et F15 sont au backlog.

| Constat | État | Par |
|---|---|---|
| F1 deux boutons pour le magasin | fait : « Utiliser ce magasin », une seule fois | DRV-623 |
| F4, F5, F12 mots et voix | fait : une seule voix (le vouvoiement de DRV-621, remplacé par le tutoiement de DRV-627 le 2026-10-19), glossaire gardé par `voice-and-glossary.spec.ts`, « Moi » | DRV-621, DRV-627 |
| F6, F9, F10 récapitulatif, erreurs, textes longs | fait : titre = résultat, compteurs non nuls, erreurs en trois phrases, code sous « Détails » | DRV-622 |
| F7 « Que faites-vous de ce produit ? » | fait : « Toujours prendre ce produit ? », deux boutons | DRV-624 |
| F8 aucun moyen de passer les suivants | fait : « Passer ces n articles » (nommé « Passer les n suivants » par DRV-624 ; 3 touches pour 4 nouveaux articles, 2 si la confirmation ne s'applique pas) | DRV-624, DRV-626 |
| F11 magasin manquant = message | fait : « Choisis ton magasin », bouton « Choisir mon magasin » | DRV-622, DRV-623, DRV-627 |
| F13 menu de l'application | fait pour les libellés ; « Diagnostic de l'agent » reste (voir DRV-621) | DRV-621 |

**Touches du parcours heureux après ces tickets** (premier envoi, 6 articles dont 4 nouveaux ; recompte de la revue du § 7 : 11 touches = 7 fixes + 4 choix) : les touches fixes ne changent pas avant DRV-625 ; les nouveaux articles passent de « 4 à 8 » à **« 3 à 4 »** (choisir les 4 : 4 ; choisir le premier et passer les 3 suivants : 3 ; une seule réponse à « Toujours prendre ce produit ? » au lieu de trois quand on remplace un produit retenu). Premier enregistrement du magasin : 2 touches au lieu de 3. Deuxième envoi, tout connu : toujours 7 (objectif 5 par DRV-625).

**Après DRV-629** (front `main` `35c3532`, agent 0.24.0, publié dans `v0.5.5` et `android-v0.1.8`) : « Commencer mes courses » est cliqué par l'agent (c'est un bouton sans adresse) et « Finaliser sur Leclerc » ouvre directement la page du panier (`detail-panier.aspx`, ville retenue côté application) ; reste à vérifier sur le vrai site que le clic programmé est accepté.

**Après DRV-625** (front `main` `0cb6437`, agent 0.22.0, publié dans `v0.5.4` et `android-v0.1.7`) : F2 (« Commencer mes courses » suivi par l'agent, repli sur le bandeau court), F3 (retour sans écran de fin) et F16 (« Finaliser sur Leclerc » ouvre le drive dans l'application, connecté) sont traités. **Deuxième envoi, tout connu : 5 touches** (7 avant) : Envoyer au drive, Confirmer, Ouvrir le drive, Commencer, Finaliser. Reste `BASKET_PATH` (page du panier) : les sondes du diagnostic en relèveront la forme.

À relever à la prochaine recette réelle (`recette.md`, 6.4, 7.4 et 7.6) : le choix du produit du premier coup, le nombre de touches mesuré, et si « Passer ces n articles » est compris.

**Après DRV-626** (front `main` e32d9ce, agent 0.20.0, toujours non publié) : les constats F17 à F27 de la revue (§ 7) sont traités, sans touche ajoutée. Touches mesurées par l'agent d'implémentation : premier envoi de 4 nouveaux articles 3 / 3 (inchangé) ; deuxième envoi inchangé ; « Vérifiez la quantité » une suppression de moins (le « 1 » est sélectionné) ; réglages du drive un défilement de moins ; « Aucun récapitulatif à afficher » : de l'impasse à 1 touche vers « Mes listes ». Le recompte global reste celui de DRV-624 (11 touches au premier envoi avec 4 nouveaux articles, 10 avec « Passer ces n articles », 7 au deuxième) jusqu'à DRV-625.

**Après DRV-627** (front `main` `97eb0e0`, PR #150, agent 0.23.0, fusionné le 2026-10-19, pas encore publié) : toute la voix de Repas est au tutoiement (web, agent, application Android, aide, conditions d'utilisation, erreurs). Ni le glossaire ni la structure des écrans ne changent (hors portée du ticket). Le test de voix interdit « vous », « votre », « vos » et les impératifs en « -ez ». Les constats F4 et F5 (deux voix) restent clos. Reste au vouvoiement : les deux e-mails et un message de connexion du back ([DRV-630](tickets/DRV-630-emails-du-back-au-tutoiement.md)).

## 7. Revue de parcours sur captures (2026-10-19)

Le PO a fait rejouer le chemin heureux à 360 px par un agent de revue (lecture seule : web de Repas simulé, agent réel dans l'émulateur contre le faux site, 80 écrans en clair et en sombre, aucun défilement horizontal, aucune violation d'accessibilité) et a lu les écrans. Les captures et les outils de rejeu sont dans le dossier de travail de la séance (jetables) ; les constats qui comptent sont ici.

**Touches comptées sur les captures** : premier envoi avec 4 nouveaux articles : **11** (Envoyer au drive, confirmer, Ouvrir le drive, « Commencer mes courses » à la main, « Commencer », 4 × « Choisir », « Retourner dans Repas », « Finaliser sur Leclerc »), **10** avec « Passer ces n articles » après le premier choix ; chaque produit remplacé ajoute 1 touche, chaque quantité à vérifier 1 touche. Deuxième envoi, tout connu : **7** (depuis une liste déjà envoyée : 6, « Reprendre l'envoi » remplace les deux premières). Premier réglage du magasin : **5** (envoyer, « Choisir mon magasin », « Commencer mes courses » sur Leclerc, « Utiliser ce magasin », puis la fenêtre d'envoi qui se rouvre). Les deux touches manuelles restantes (« Commencer mes courses », « Retourner dans Repas ») sont celles de DRV-625.

| # | Gravité | Constat (capture lue) | Traité par |
|---|---|---|---|
| F17 | B | « Prêt à remplir » de l'agent : compteurs à zéro, « produits » pour des articles | DRV-626 point 1 |
| F18 | B | « Passer les n suivants » compte l'article affiché ; la confirmation laisse la liste de produits sous elle (deux décisions) | DRV-626 point 2 : « Passer ces n articles », confirmation à deux boutons |
| F19 | C | Fiche produit : la taille écrite trois fois ; « (1 sur 1) » | DRV-626 points 3 et 4 |
| F20 | B | « Vérifiez la quantité » : champ prérempli non sélectionné (« 1 » + 4 = « 14 ») | DRV-626 point 5 |
| F21 | B | « Le panier est rempli en partie » alors que rien n'est au panier | DRV-626 point 6 |
| F22 | B | Liste envoyée : « 0 sur 6 articles dans le panier » (cases cochées) sous « Le panier est rempli » | DRV-626 point 7 |
| F23 | B | Estimation du coût sans aucun prix : quatre messages empilés qui repoussent les articles | DRV-626 point 8 |
| F24 | B | « Produits retenus, par ordre de préférence » : le mot que le glossaire écarte | DRV-626 point 9 |
| F25 | B | « Aucun récapitulatif à afficher » : impasse sans bouton | DRV-626 point 10 |
| F26 | B | Réglages du drive : la note de vie privée remplit l'écran, le magasin est sous la ligne de flottaison | DRV-626 point 11 |
| F27 | B | « Vos choix sont enregistrés… » sur des erreurs sans choix (à vérifier hors simulation) | DRV-626 point 12 : le défaut existait hors simulation, corrigé |

**État (DRV-626 fait, PR front #147)** : F17 à F27 traités. **Vu pendant le balayage et laissé** : l'écran « Ce récapitulatif ne correspond pas à votre dernier envoi » n'a pas de bouton non plus ; un envoi terminé où tout est passé (rien au panier) dit encore « Le panier est rempli » ; si l'enregistrement de simples prix échoue, le texte parle de « choix » ; la liste de l'écran d'envoi garde ses rangs « 1. », « 2. » (l'ordre est visible).

**Constats laissés (décision du PO : pas maintenant)** :

- « Vous n'êtes pas connecté » : le bouton principal de l'agent est « Retourner dans Repas » alors que l'action est de se connecter sur Leclerc ; la reprise passe par Repas. À traiter avec DRV-625 (entrée et retour sans geste).
- Mêmes erreurs, deux textes (autre magasin, « Leclerc a ralenti les demandes » : l'agent ajoute « rechargez la page » ; titres différents) ; « Magasin n° 000001 » dans l'agent contre « Magasin …456 » dans Repas. Balayage cosmétique à grouper avec DRV-625.
- « Android System WebView » à l'écran quand le composant est absent : c'est le nom de ce qu'il faut mettre à jour, gardé.
- Espaces insécables dans les guillemets (un « ». » seul en début de ligne à 360 px), phrases de vie privée de plus de 15 mots hors parcours, cibles tactiles de Repas sous 44 px hors parcours Drive (cases des articles, liens « Mes listes » et « Moi ») : au backlog.
- Le bandeau de lecture du magasin ne dit pas de toucher « Commencer mes courses » (l'agent n'affiche « Utiliser ce magasin ? » que sur une page de magasin) : relevé de la simulation, à vérifier sur le vrai site (DRV-625).

## 8. Plusieurs enseignes : relevé de touches (DRV-711, à confirmer à la recette d'Auchan)

L'audit ci-dessus compte le parcours d'**une** enseigne. Avec plusieurs (règle de choix de [`multi-enseignes.md`](multi-enseignes.md) § 5.1), le relevé attendu est le suivant ; c'est un **objectif de conception**, mesuré seulement après la recette réelle d'Auchan (DRV-726), sur le même parcours que le § 1.

| Situation | Touches par rapport au § 1 | Pourquoi |
|---|---|---|
| **Un drive** configuré (tous les utilisateurs aujourd'hui, Auchan éteinte) | **inchangé** (0 en plus) | R1 et R2 : aucune question, aucun écran de plus ; critère de réussite du découplage |
| **Deux drives, un drive habituel** choisi dans « Moi » | inchangé ; **+1** seulement pour « Envoyer ailleurs » | R3 : le drive habituel est utilisé sans rien demander |
| **Deux drives, pas de drive habituel** | **+1** : « Ouvrir {nom} » à l'écran d'envoi, après la confirmation d'envoi, **si la présélection convient** (dernière enseigne utilisée) ; **+2** pour en changer (toucher l'autre tuile, puis « Ouvrir {nom} ») | R4 : la présélection coûte une touche de confirmation, jamais une question à deux étapes |
| Première configuration avec plusieurs enseignes proposées | **+1** (« Quel drive utilises-tu ? », une tuile par enseigne), puis la lecture du magasin | R1 : seulement quand aucune n'est configurée et que plusieurs sont allumées |

À vérifier à la recette : que le nom de l'enseigne est toujours un paramètre de texte (jamais écrit à la main), que le mot « enseigne » n'apparaît nulle part à l'écran (on dit « drive »), et que le **drive habituel** supprime bien la touche en trop. Si une personne à deux drives sans drive habituel y voit une question à chaque envoi, proposer le drive habituel à la deuxième fois, jamais avant (règle 3 des principes : ne pas demander ce qu'on sait déjà).
