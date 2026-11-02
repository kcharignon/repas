# DRV-768 — La carte du guide disparaît et revient deux fois par seconde dans l'arbre d'accessibilité

- **Dépôt** : front (`drive-agent/src/ui/fit.ts`, agent ; puis une version de l'application)
- **Jalon** : M7 Plusieurs enseignes (régression trouvée par la suite `make android-e2e` pendant DRV-766, 2026-11-02)
- **Taille** : S à M
- **Dépend de** : [DRV-766](DRV-766-realigner-la-suite-android-e2e.md) fusionné (le scénario 8 de la suite porte déjà le contrôle « RÉGRESSION DE L'AGENT », volontairement rouge, qui deviendra vert avec ce correctif ; l'émulateur est partagé)
- **Statut** : à lancer après la fusion de DRV-766 ; décrit, **non corrigé**
- **Lire d'abord** : [DRV-764](DRV-764-carte-lisible-par-un-lecteur-d-ecran-et-nom-accessible-complet.md) (la carte lisible par un lecteur d'écran), [DRV-761](DRV-761-recette-reelle-de-la-1-6-3.md) (la carte ne couvre plus les champs), [DRV-757](DRV-757-carte-du-guide-plus-legere-et-plus-juste.md), `drive-agent/src/ui/fit.ts` (fonction `check`)

## Constat (trouvé par l'agent de code de DRV-766, relu dans le code par l'assistant)

Dans l'émulateur, devant une carte du guide à l'écran (scénarios 8, 8b et 21 de la suite) :

- `uiautomator dump` répond « could not get idle state » en continu : l'écran n'est jamais au repos ;
- les outils de développement de Chromium montrent, toutes les **500 ms**, l'attribut `hidden` posé puis retiré sur la carte (8 événements en 4 secondes) ;
- avec le JavaScript de la page coupé, la lecture de l'écran réussit : la cause est donc dans le script de la page, pas dans l'application.

## Cause (lue dans le code)

`drive-agent/src/ui/fit.ts`, `check()`, appelée toutes les `FIT_MS` = 500 ms par `setInterval` (et à chaque `resize`, `focusin`, `focusout`, défilement) : la fonction pose `card.hidden = true` « le temps de lire la page » (pour que la carte ne se compte pas elle-même parmi ce qui recouvre la page), mesure, puis `apply(spot)` la remet. Ce motif date de DRV-757 (2026-11-01, application 1.6.0) : **il est en production depuis**. Une mesure qui force la mise en page pendant que la carte est cachée fait sortir la carte de l'arbre d'accessibilité, puis y revenir, à chaque tour.

## Pourquoi c'est un défaut

- Un lecteur d'écran (TalkBack) voit la carte disparaître et revenir deux fois par seconde : lecture coupée, focus perdu. C'est exactement ce que DRV-764 voulait rendre lisible.
- Les outils qui lisent l'écran (uiautomator, tests, assistants) n'obtiennent plus de lecture stable.
- Possible lien avec des observations de recette (« texte absent de l'arbre d'accessibilité », DRV-764) : à vérifier, sans conclure d'avance.

## À faire

1. Reproduire d'abord : un test de l'agent qui compte les basculements de `hidden` (ou les mutations d'attributs de la carte) sur plusieurs tours de `check()` à la page identique, et qui échoue aujourd'hui ; le contrôle « RÉGRESSION DE L'AGENT » du scénario 8 est la preuve dans l'émulateur.
2. Corriger **sans changer ce que la carte fait pour la personne** (mêmes places, mêmes réponses de DRV-761 : la carte ne couvre pas un champ de saisie, ni un bouton de validation, ni le refus d'un mur de cookies) : ne basculer `hidden` que si le résultat de la mesure change, ou mesurer sans retirer la carte de l'arbre (par exemple exclure la carte des boîtes lues plutôt que la cacher). L'agent de code choisit avec preuve, et justifie dans la PR.
3. Aucun nouveau texte, aucune nouvelle action de la carte. Taille de l'agent sous son budget (300 000 octets).
4. Nouvelle version de l'agent et de l'application (`AGENT_VERSION`, `android/agent.js.sha384` régénéré par la construction), puis recette sur le téléphone de test avec Leclerc puis Auchan (sur le vrai site, ou à défaut le faux) : la carte reste stable, `uiautomator dump` réussit du premier coup, TalkBack ne la perd pas si possible.

## Non vérifié

- Si TalkBack perd réellement le focus (l'émulateur et `uiautomator` le montrent ; un essai avec TalkBack reste à faire).
- Si le motif a un lien avec une observation précédente de la recette (« texte absent de l'arbre »).

## Livraison

Front (agent et application) : PR, relecture indépendante, fusion si la CI est verte, étiquette `android-vX.Y.Z`, approbation et `APP_LATEST_VERSION` par le propriétaire, comme pour 1.6.5 et 1.6.6.
