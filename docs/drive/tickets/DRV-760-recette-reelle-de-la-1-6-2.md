# DRV-760 — Recette réelle de 1.6.2 : la carte que la personne ne voit pas

- **Dépôt** : front (`drive-agent/`, `android/`)
- **Jalon** : M7 Plusieurs enseignes (recette réelle du 2026-11-02, application 1.6.2)
- **Taille** : M
- **Dépend de** : DRV-759
- **Statut** : fait côté code (front PR #308 fusionnée le 2026-11-02, agent 0.62.5, relue par deux agents indépendants, puis son dernier delta par l'assistant principal) ; application `android-v1.6.3` : étiquette posée, run en attente de l'approbation du propriétaire ; la recette réelle de 1.6.3 reste à faire (elle tranche la cause du mur de Leclerc)
- **Lire d'abord** : [DRV-759](DRV-759-carte-du-guide-suite-de-la-recette-1-6-0.md), `drive-agent/src/ui/fit.ts` et `ui.ts`, `drive-agent/src/diagnostic/foreground.ts`, `drive-agent/protocol/README.md` (message `log`), `android/` (`CardWatch.kt`, `DrivePass.kt`), [`architecture.md`](../architecture.md) § 8 (message `log`)

## Contexte

Recette réelle de 1.6.2 sur le téléphone de test (compte de démo), Leclerc puis Auchan, en lisant chaque étape comme une personne novice. Le parcours va jusqu'au bout chez les deux (« Le panier est rempli »), la page de connexion de Leclerc dit « Étape 2 sur 2 · Connecte-toi » et la carte reste sur le panneau « Choisir un drive » d'Auchan : les défauts de DRV-759 sont corrigés. Il reste un défaut grave et des écarts.

## Constats (mesurés, pas conclus)

1. **Mur de cookies de Leclerc : aucune carte pendant plus de 20 secondes**, alors que le diagnostic natif dit « agent annoncé 1 fois, passage : plan, agent : aucune ligne reçue ». Le mur n'apparaît que dans la WebView de l'application (Chrome ne le montre pas après l'effacement des cookies). Sur la page d'accueil réelle, Chrome relève huit `div.modal` en `position: fixed`, plein écran, `z-index: -1`, décalés hors écran à droite, plus un en-tête collant à `z-index: 21`. **La nature du mur n'est pas connue.**
2. **Liste des magasins** : la barre se posait sur la première carte de magasin, et le repère « Touche ici » était trop haut.
3. **Auchan** : la carte cite « Choisir vos courses » alors que le nom accessible du bouton est « Choisir mon mode de livraison » ; elle cite « Me connecter » pour un contrôle sans nom ; le bandeau natif de la page du code à 6 chiffres ne parle pas du code.
4. **Diagnostic** : « Agent : aucune ligne reçue » même quand l'agent est annoncé (le diagnostic ne répondait pas pendant un passage).

## Ce que la relecture indépendante a montré

La première version de la PR posait un repli natif (bandeau « Continue dans la page. » si l'agent ne montre aucun écran 6 s après l'envoi du plan). Un relecteur a reproduit, en Chromium réel, qu'il **ne se déclenche pas** quand la page pose un `<dialog>` modal par-dessus : l'agent croit montrer sa carte (`card: true`), `document.elementFromPoint` au centre de la carte rend le mur, et la carte n'est pas visible. Une couche supérieure passe devant n'importe quel `z-index` et devant un conteneur remis en dernier enfant du document. C'est une **hypothèse sur le vrai mur** (non prouvée : en 1.6.0 la carte s'affichait devant le même mur, ce qui ne tient que si le mur s'ouvre après elle).

## À faire (front PR #308)

1. La carte compte comme « à l'écran » seulement si sa boîte est dans la fenêtre **et** si le toucher au centre tombe sur l'agent (`touchVerdict`) ; sinon l'agent dit `card: false` et le bandeau natif joue. Mêmes règles pour la progression et le résumé.
2. Le conteneur de l'agent revient en dernier enfant du document au plus 3 fois par ouverture et jamais s'il a le focus (il se battait avec un site qui remet sa couche en dernier).
3. Le repli natif est remis à zéro à chaque plan et en fin de passage, armé aussi pour le plan de débogage ; le titre de la page du code est borné à 200 caractères avant d'être lu.
4. Le repère des magasins écarte un conteneur de liste (plus de 40 % de la hauteur de la fenêtre, ou au moins deux blocs larges dedans ET une balise de liste ou seulement des éléments de liste) et part du titre du panneau : une carte de plusieurs lignes (nom, adresse) reste une carte. Limite connue : une liste courte de `div` à curseur hérité, sans balise de liste, passe pour une carte.
5. Le diagnostic natif porte une ligne « Carte » de **formes seulement** : dernière étape du flux, écran affiché, état dit, boîte, ce que le toucher au centre rencontre, conteneur dernier du document, déplacements, balise de l'élément actif. À la prochaine recette elle dit laquelle des hypothèses est la bonne : mur en couche supérieure, carte effacée faute de place, bloc conteneur qui décale `position: fixed`, passage bloqué avant le premier écran.
6. Le message `log` est défini au protocole (schéma, validateur, 3 exemples valides, 9 invalides) ; [`architecture.md`](../architecture.md) § 8.
7. Le lien de connexion d'Auchan n'est cité que s'il est montré, de taille réelle et non recouvert ; le bandeau natif de la page du code dit « Tape le code reçu par e-mail, dans la page. ». Budget de l'agent : 300 000 octets (292 193 en 0.62.5, marge 7 807). Le bandeau de repli est aussi rafraîchi en fin de passage (variante de débogage), sans test unitaire de `DriveActivity`.

## Pas fait, pas vérifié

- **La cause du mur réel de Leclerc.** Aucun faux mur ne la reproduit. La PR détecte que la carte n'est pas visible et le dit ; elle ne la rend pas visible.
- Le `cursor: pointer` des cartes de magasin sur le vrai site (le repère retombe sur les contrôles sinon).
- Le titre de la page du code d'Auchan.
- La barre d'Auchan « Choisir… » cite le texte de l'élément, pas son nom accessible : non traité faute de mesure.
- `DriveActivity` (armement du plan de débogage) n'a pas de test unitaire.

## Ordre de livraison

Agent et application (`android-v1.6.3`, approuvée par le propriétaire, puis `APP_LATEST_VERSION` écrit par lui après le run vert). Le site et le back ne changent pas.

## Recette

Rejouer sur le téléphone de test, Leclerc et Auchan, en novice, et **lire la ligne « Carte » du diagnostic devant le mur de cookies de Leclerc** : elle tranche la cause. Si le toucher au centre « tombe sur autre chose » avec le conteneur dernier du document, le mur est une couche supérieure : la suite est un bandeau natif qui guide sans carte, ou une carte dessinée dans la couche supérieure (le relecteur a mesuré qu'elle y devient visible mais intouchable, la page étant inerte).
