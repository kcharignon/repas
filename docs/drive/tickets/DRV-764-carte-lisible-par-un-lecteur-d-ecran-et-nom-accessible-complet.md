# DRV-764 — Nom accessible complet et carte lisible par un lecteur d'écran

- **Dépôt** : front (`drive-agent/`, `android/`)
- **Jalon** : M7 Plusieurs enseignes (restes de la recette réelle de 1.6.4)
- **Taille** : M
- **Dépend de** : [DRV-761](DRV-761-recette-reelle-de-la-1-6-3.md)
- **Statut** : en cours (agent de code sur la branche `fix/drv-764-carte-accessible`, relecture indépendante avant la fusion, puis une version de l'application)
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
