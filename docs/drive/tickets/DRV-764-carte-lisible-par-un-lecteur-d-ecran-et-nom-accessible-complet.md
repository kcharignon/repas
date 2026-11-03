# DRV-764 — Nom accessible complet et carte lisible par un lecteur d'écran

- **Dépôt** : front (`drive-agent/`, `android/`)
- **Jalon** : M7 Plusieurs enseignes (restes de la recette réelle de 1.6.4)
- **Taille** : M
- **Dépend de** : [DRV-761](DRV-761-recette-reelle-de-la-1-6-3.md)
- **Statut** : fait (front PR #317 fusionnée le 2026-11-02, agent 0.62.9 ; application `android-v1.6.5` publiée ; relue par un agent indépendant ; parcours Auchan rejoué sur le téléphone de test en 1.6.5 sans régression). **Constats** : (1) `accessibleName` suit maintenant `aria-labelledby` > `aria-label` > texte, plafonné à 40 caractères par candidat, et arrête de lire les éléments cités dès que le nom dépasse 40 caractères ; (2) **le « texte de la carte absent de l'arbre d'accessibilité » s'explique très probablement par la barre réduite** : en barre, seul le bouton de la barre (« Étape N sur 2 · … Afficher ») est exposé, le titre et la phrase sont masqués par CSS (`.guide.min>:not(.g-bar){display:none}`) ; la barre est imposée devant un mur de cookies et pendant la saisie dans un champ ; carte pleine = 21 nœuds, barre = 8 (mesuré par CDP) ; ce que fait la WebView d'Android et TalkBack reste non prouvé ; (3) **le bouton ⋮ natif est voulu** : il est posé en bas à droite de la racine et monte avec le CLAVIER (pas avec la carte) ; la carte garde une marge basse de 88 px (`--clear`) pour ne pas le couvrir (spec `carte-menu-natif.spec.ts`). Hors PR, noté : le titre de la carte prend le focus à l'affichage d'un écran ; pour un champ de saisie de la page, la seule protection est la mise en barre (indirecte), à rendre explicite si un défaut apparaît
- **Lire d'abord** : « Recette de 1.6.4 » et « Restes connus » de [DRV-761](DRV-761-recette-reelle-de-la-1-6-3.md), `drive-agent/src/retailers/guide.ts` (`accessibleName`), `drive-agent/src/ui/`, `android/ARCHITECTURE.md`

## Contexte

Trois restes de la recette réelle de 1.6.4, tous sans effet sur le parcours des novices, mais qui rendent la carte moins fiable :

1. **`accessibleName`** cite le nom d'un contrôle de la page. Il lit `aria-label` puis le texte, mais pas `aria-labelledby`, et un `aria-label` trop long renvoie rien sans essayer le texte visible.
2. **Le texte de la carte n'apparaît pas toujours dans l'arbre d'accessibilité** (mesuré avec `uiautomator dump` : absent devant le mur de cookies de Leclerc et sur la connexion d'Auchan, présent sur d'autres pages). Un lecteur d'écran (TalkBack) ne lirait donc pas la carte. La cause n'est pas connue : DOM de la carte (`aria-hidden`, shadow root, rôle, annonce du texte) ou WebView.
3. **Le bouton ⋮ de l'application monte** quand la carte est dépliée (environ 2148 → 1365 px sur 2400). Probablement voulu (ne pas couvrir la carte) ; à vérifier, pas à changer sans défaut mesurable.

## À faire

1. `accessibleName` suit l'ordre des noms accessibles (`aria-labelledby`, `aria-label`, texte visible), passe au suivant si un candidat est vide ou trop long, lit seulement du texte (jamais de HTML de la page). Tests unitaires.
2. Enquêter dans Chromium réel sur l'exposition de la carte dans l'arbre d'accessibilité ; corriger les causes petites et réelles (rôle, nom accessible des boutons, annonce polie du texte sans voler le focus), sans changement visuel ni de géométrie ; sinon écrire le constat précis sans correctif spéculatif.
3. Établir si le déplacement du ⋮ est voulu et documenté ; ne le changer que s'il recouvre un contrôle que le guide demande de toucher.

## Non vérifié

- Un vrai lecteur d'écran (TalkBack) sur le téléphone : à jouer si un proche s'en sert.

## Livraison

Front (agent et application) : PR, relecture indépendante, fusion si la CI est verte, étiquette `android-vX.Y.Z`, approbation et `APP_LATEST_VERSION` par le propriétaire.
