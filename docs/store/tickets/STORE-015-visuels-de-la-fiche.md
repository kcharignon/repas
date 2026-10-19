# STORE-015 — Visuels de la fiche : icône 512, image de présentation, captures d'écran

- **Dépôt** : front (`android/store/`, `android/emulator/`, scripts de rendu, `android/__tests__/store-assets.spec.ts`)
- **Jalon** : M3 Fiche du store
- **Taille** : M
- **Dépend de** : STORE-014 (textes alternatifs)
- **Faisable sans téléphone ni compte Play** : oui (émulateur Android de test + faux Repas ; nécessite KVM)
- **Bloquant pour AUTH-002 / la connexion Google** : non
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) §§ 6.c et 7 ; `android/README.md` (« Émulateur de test », `make android-smoke`, `make android-e2e`) ; `android/app/src/main/res/drawable/ic_launcher_foreground.xml` ; skills `repas-android-dev` et `repas-po`.

## Contexte

La console demande : une **icône** PNG 32 bits avec alpha de 512 × 512 px (≤ 1 024 Ko), une **image de présentation** JPEG ou PNG 24 bits sans alpha de 1 024 × 500 px, et **au moins 2 captures** (4 recommandées, 9:16, 1 080 × 1 920 minimum) en JPEG ou PNG 24 bits sans alpha. Règles : vraie interface, pas de cadre d'appareil, texte d'accroche ≤ 20 % de l'image, aucun terme de classement ni de promotion, **pas de marque ni de logo d'un tiers**.

## Portée

1. **Icône** : redessiner à 512 × 512 la feuille de basilic à nervure de safran de `ic_launcher_foreground.xml` sur fond basilic (`#1E6B4C`, safran `#F4B73F`), en SVG source versionné (`android/store/icon.svg`) rendu en PNG par un script déterministe (Playwright déjà présent) ; fond plein (Google applique ses coins) ; aucun texte.
2. **Image de présentation** : même identité, sans texte promotionnel ni capture ; zones sûres respectées ; rendu 1 024 × 500.
3. **Captures** : un script d'émulateur (`android/emulator/store-screens.sh`) qui joue le **faux Repas** avec des données fictives (aucune donnée réelle, aucun magasin, aucun nom d'enseigne) et enregistre, à 1 080 × 1 920, la barre d'état propre (batterie pleine, pas de notification) : la liste des recettes, une recette, la liste de courses, l'écran « Envoyer au drive » avec son récapitulatif (écran de Repas), « Moi ». **Jamais le site de l'enseigne.**
4. Sorties dans `android/store/images/` ; le test vérifie dimensions, format, absence d'alpha (hors icône), poids.

## Hors portée

Les textes (STORE-014) ; la vidéo ; les captures de tablette (l'application n'est pas faite pour les tablettes) ; le téléversement dans la console (STORE-017).

## Critères d'acceptation

- Étant donné `android/store/images/`, quand le test tourne, alors l'icône fait exactement 512 × 512 avec alpha et moins de 1 024 Ko ; l'image de présentation fait 1 024 × 500 sans alpha ; chaque capture fait 1 080 × 1 920 sans alpha, au format JPEG ou PNG.
- Étant donné les captures, quand elles sont **lues** par le relecteur, alors elles ne montrent ni nom de magasin, ni nom ou logo d'enseigne, ni donnée personnelle, ni notification, et la barre d'état est propre.
- Étant donné `make android-e2e`, alors il n'est pas modifié.

## Tests exigés

`store-assets.spec.ts` (dimensions, canal alpha, poids, nom des fichiers) ; captures **lues** à la main avant la fusion ; `make check` ; `make android-smoke` si le faux Repas change.

## Definition of Done

Celle du dépôt front ; description de PR avec les cinq captures et la liste de ce qu'elles montrent ; aucune donnée réelle.

## Pièges connus

- L'icône de l'application (`mipmap-anydpi-v26`) est adaptative : l'icône du store est un fichier **à part**, avec fond plein.
- La capture de l'écran « Envoyer au drive » doit s'arrêter à l'écran de Repas : ouvrir le drive montrerait le site de l'enseigne (marque).
- Les captures de l'émulateur contiennent par défaut l'heure et le réseau : fixer l'heure et masquer les notifications.
- Éviter les couleurs blanc pur, noir ou gris foncé aux bords de l'image de présentation (elles se fondent dans le fond du Play Store).
- Le texte alternatif de chaque image vient de STORE-014.
