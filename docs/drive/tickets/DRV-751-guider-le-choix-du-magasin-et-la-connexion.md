# DRV-751 — Guider pas à pas le choix du magasin et la connexion

- **Dépôt** : front (`drive-agent/` et `android/`) ; la doc de l'agent est dans le back (`docs/drive/architecture.md`)
- **Jalon** : M7 Plusieurs enseignes (retour du propriétaire, hors ordre)
- **Taille** : M
- **Dépend de** : DRV-625 / DRV-629 (l'agent suit « Commencer mes courses »), DRV-733 (bandeaux par enseigne), DRV-740 (première configuration), DRV-748 (entrée après la connexion)
- **Statut** : à faire. Effort recommandé : `high`. Texte et boutons avec le skill `repas-po`.
- **Lire d'abord** : skills `repas-po`, `repas-agent-dev`, `repas-android-dev` ; [`../principes-produit.md`](../principes-produit.md) ; `android/README.md` et `android/ARCHITECTURE.md` (`StoreBanner`, le panneau d'incident, DRV-733) ; `drive-agent/` (les écrans de l'agent : `STORE_NOT_CHOSEN`, « pas connecté ») ; `android/emulator/e2e.sh` (scénarios 1046 à 1066 : « Aucun magasin n'est choisi »).

## Contexte

Retour du propriétaire, recette du 2026-10-31 sur Auchan (téléphone de test, application 1.3.1, première utilisation : aucun magasin choisi, aucune connexion). Il se met à la place d'un proche qui ne connaît ni le drive ni l'application et le dit : **« c'est hyper contre-intuitif, hyper anxiogène, il faut guider »**. Ce qu'il a vécu :

1. L'agent s'arrête sur « Choisis ton magasin » (`STORE_NOT_CHOSEN`) et affiche un **panneau d'incident** : un titre, une phrase, et **deux gros boutons, « Retourner dans Repas » et « Fermer »**. Ce panneau occupe le haut de l'écran, **au même endroit que la petite barre d'Auchan « Choisir mon mode de livraison »**, la seule chose à toucher : il ne peut pas la toucher, elle est trop petite à côté des deux gros boutons et en partie cachée. Il ne sait pas quoi faire.
2. « Fermer » fait peur : on ne sait pas si cela ferme l'application, la liste, l'envoi.
3. Quand il a touché la page d'accueil d'Auchan, **le message « magasin introuvable » est revenu**, alors qu'il était en train de faire ce qu'on lui demandait : anxiogène.
4. Quand il a enfin choisi son magasin, **rien ne s'est passé** : l'agent s'était arrêté, le message dit « puis reprends l'envoi depuis Repas ». Il doit comprendre tout seul qu'il faut quitter la page, revenir dans Repas et toucher « Reprendre l'envoi ».
5. Même panneau pour « Tu n'es pas connecté à Auchan » : deux gros boutons, aucune indication sur où se connecter.

Ce n'est pas une erreur : c'est l'**étape normale** d'une première utilisation. Elle doit se présenter comme une étape, pas comme un incident.

## À faire

1. **Un parcours guidé en deux étapes courtes, sans quitter la page de l'enseigne** : « 1 sur 2 : choisis ton magasin », puis « 2 sur 2 : connecte-toi » (seulement les étapes qui manquent ; une personne déjà connectée avec un magasin choisi ne voit rien de nouveau). Chaque étape dit **une seule chose à faire**, en mots simples, et **où** (le bouton ou la barre de la page à toucher, avec un repère visuel qui la désigne : flèche, cadre, ou, mieux, l'agent met lui-même l'élément en évidence ou fait défiler jusqu'à lui). Le message ne cache **jamais** le contrôle qu'il demande de toucher.
2. **L'agent attend** pendant que la personne choisit son magasin ou se connecte : il ne met pas fin à l'envoi, il ne demande pas de « reprendre l'envoi depuis Repas ». **Dès que le magasin est lu et la session ouverte, il continue tout seul** et le dit (« Ton magasin est choisi. Je remplis ton panier. »). Aucun retour dans Repas à faire. Garder le délai maximum d'attente et la sortie existante (la personne arrête quand elle veut).
3. **Un ton de guide, pas d'incident** : pas de mot d'erreur (« introuvable », « pas connecté », « incident ») pour un état attendu, pas de panneau plein écran, pas de couleur d'alerte. Le message ne réapparaît pas en rouge ou en plus grand quand la personne navigue dans la page : il n'est affiché que tant que l'étape n'est pas faite, et passe à l'étape suivante, ou disparaît, quand elle l'est.
4. **Les boutons disent ce qui va se passer et rassurent**, jamais « Fermer », « Retourner dans Repas », « Annuler » seuls. Une seule action secondaire, qui dit clairement qu'**on arrête l'envoi, que la liste est gardée et que rien n'est perdu** (formulation proposée à `repas-po`, par exemple « Arrêter pour l'instant — ta liste est gardée »). Aucun bouton qu'on pourrait croire qui ferme l'application ou efface quelque chose. Revoir, avec la même règle, **les autres boutons de ce panneau d'incident et de ses variantes** (page refusée, trop lente…) : un bouton = une conséquence claire.
5. **Même parcours pour les deux enseignes**, par le registre de l'enseigne (`RetailerSite`, DRV-733) : Leclerc (page de magasin) et Auchan (magasin lu dans la page). **Ne pas durcir Leclerc** : aucun nom d'enseigne en dur hors du registre ; chaque enseigne dit où se trouve l'élément à désigner et comment on lit « magasin choisi » et « connecté ».
6. Le cas où le magasin choisi n'est pas celui de la liste (`STORE_MISMATCH`) et les autres arrêts de l'agent gardent leur sens ; ne toucher que ce qui est un état d'attente normal de première utilisation.
7. Tests : l'agent (faux sites Leclerc et Auchan : magasin absent puis choisi, connexion absente puis faite, reprise automatique, aucun message revenu en navigant, aucun double envoi), l'application Android (le message ne chevauche pas la zone à toucher, les boutons mesurent 44 dp au moins, textes lus), l'e2e émulateur (`android/emulator/e2e.sh` : les scénarios « Aucun magasin n'est choisi » changent), la capture de l'écran lue à 360 px en clair et en sombre.
8. `AGENT_VERSION` suivante, empreinte `android/agent.js.sha384` régénérée, docs de l'agent et de l'application dans le même commit (`android/README.md`, `android/ARCHITECTURE.md`, `docs/drive/architecture.md` du back : à mettre à jour à part par l'assistant principal), skills du front que [`../consignes-agents.md`](../consignes-agents.md) liste s'ils parlent de ces boutons.

## Ordre de livraison

L'agent change : **application Android** (nouvelle version de l'agent embarquée), et le site seulement si un texte de Repas change. Recette réelle sur le téléphone de test, **installation neuve** : Leclerc puis Auchan, première utilisation (sans magasin ni connexion), puis avec un magasin déjà choisi et une session ouverte (rien de nouveau à l'écran).

## Hors périmètre

Le mot de passe se tape toujours à la main, jamais par l'agent. Le choix du magasin reste celui de la personne : l'agent le désigne, il ne le choisit pas.
