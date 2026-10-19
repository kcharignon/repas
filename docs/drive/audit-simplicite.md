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

Les quatre tickets sont fusionnés dans le front (`main`, agent 0.19.0) et pas encore publiés : le front web (v0.5.2) avant l'application (android-v0.1.5). Les constats F1 et F4 à F12 sont traités (F13 pour les libellés), **F2, F3 et F16 restent à DRV-625**, qui attend la vérification humaine sur le téléphone ; F14 et F15 sont au backlog.

| Constat | État | Par |
|---|---|---|
| F1 deux boutons pour le magasin | fait : « Utiliser ce magasin », une seule fois | DRV-623 |
| F4, F5, F12 mots et voix | fait : vouvoiement partout, glossaire gardé par `voice-and-glossary.spec.ts`, « Moi » | DRV-621 |
| F6, F9, F10 récapitulatif, erreurs, textes longs | fait : titre = résultat, compteurs non nuls, erreurs en trois phrases, code sous « Détails » | DRV-622 |
| F7 « Que faites-vous de ce produit ? » | fait : « Toujours prendre ce produit ? », deux boutons | DRV-624 |
| F8 aucun moyen de passer les suivants | fait : « Passer les n suivants » (3 touches pour 4 nouveaux articles, 2 si la confirmation ne s'applique pas) | DRV-624 |
| F11 magasin manquant = message | fait : « Choisissez votre magasin », bouton « Choisir mon magasin » | DRV-622, DRV-623 |
| F13 menu de l'application | fait pour les libellés ; « Diagnostic de l'agent » reste (voir DRV-621) | DRV-621 |

**Touches du parcours heureux après ces tickets** (premier envoi, 6 articles dont 4 nouveaux) : les 8 touches fixes sont inchangées (rien de ce qui précède l'agent ne change avant DRV-625) ; les nouveaux articles passent de « 4 à 8 » à **« 3 à 4 »** (choisir les 4 : 4 ; choisir le premier et passer les 3 suivants : 3 ; une seule réponse à « Toujours prendre ce produit ? » au lieu de trois quand on remplace un produit retenu). Premier enregistrement du magasin : 2 touches au lieu de 3. Deuxième envoi, tout connu : toujours 7 (objectif 5 par DRV-625).

À relever à la prochaine recette réelle (`recette.md`, 6.4, 7.4 et 7.6) : le choix du produit du premier coup, le nombre de touches mesuré, et si « suivants » est compris.
