# STORE-022 — (Conditionnel) Variante du store sans l'écran du drive

- **Dépôt** : front (`android/app/build.gradle.kts`, `android/app/src/main/`, `src/features/drive/bridge/`, `android/__tests__/`, `Makefile`, workflow)
- **Jalon** : M5 Transition et suites
- **Taille** : M
- **Dépend de** : **un refus ou une suspension de Google** liés à l'écran du drive (ou la décision D4 « variante sans drive dès le départ »)
- **Faisable sans téléphone ni compte Play** : oui (émulateur) ; la preuve exige la console
- **Bloquant pour AUTH-002 / la connexion Google** : non
- **Statut** : à faire (conditionnel)
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) §§ 6.b et 10 (R1) ; `docs/drive/runbook.md` ; ADR [0022](../../adr/0022-remplissage-du-panier-drive.md) ; skills `repas-android-dev` et `repas-front-dev`.

## Contexte

Si Google refuse l'application ou la suspend à cause de l'écran qui agit dans le site d'une enseigne, deux parades existent : couper la fonction pour tous (`DRIVE_ENABLED`, sans nouvelle version) ou publier une **variante du store sans cet écran**. Ce ticket écrit la seconde. Il n'est utile que si la première est jugée trop coûteuse pour la famille.

## Portée

1. Saveur de produit `play` (même paquet, même signature, **autre code de version** pour le store) : `BuildConfig.DRIVE_SCREEN = false`, `DriveActivity` non déclarée dans le manifeste de cette saveur, entrée du menu « Ouvrir le drive » absente, agent non embarqué.
2. Le pont : `app.info` annonce `drive: false` (drapeau de capacité, comme `google: true` d'AUTH-011) ; le front masque tout ce qui concerne l'envoi quand le drapeau est faux (bouton « Envoyer au drive », réglages du drive, aide).
3. Workflow : l'étiquette produit les deux saveurs ; le contrôle de chacune est adapté (la saveur `play` ne contient ni agent, ni `DriveActivity`).
4. La fiche (STORE-014) et la page de confidentialité (STORE-010) sont ajustées à la saveur publiée.

## Hors portée

Tout changement de l'agent ; le retrait de la fonction pour la famille (l'APK GitHub garde le drive).

## Critères d'acceptation

- Étant donné la saveur `play`, quand on l'installe, alors le menu n'a pas d'entrée du drive et `app.info` annonce `drive: false`, et le front n'affiche aucune action d'envoi.
- Étant donné la saveur complète, alors rien ne change.
- Étant donné le contrôle de l'AAB de la saveur `play`, alors il refuse un bundle qui contient l'agent ou `DriveActivity`.

## Tests exigés

Kotlin pur (`NativeBridgeTest` : `app.info`) ; Vitest (masquage) ; émulateur (`make android-smoke`) pour les deux saveurs ; `make android-release-selftest` ; `make check`.

## Definition of Done

Celle du dépôt front ; ADR 0025 mis à jour ; la procédure de publication des deux saveurs écrite dans `PUBLICATION.md`.

## Pièges connus

- **Un seul paquet, deux binaires** : Google Play sert à un utilisateur la version au **code le plus haut** parmi les pistes auxquelles il a accès ; une famille en test interne et un public en production recevraient la variante ayant le code le plus grand. Prévoir une règle de codes de version explicite (par exemple le test interne reçoit toujours un code supérieur) et la tester sur téléphone.
- Un APK GitHub (complet) et une application Play (variante) sur le même téléphone ne peuvent pas coexister avec des fonctions différentes : prévoir ce que voit la famille.
- Si Google suspend **tout le compte**, cette variante ne le résout pas : couper `DRIVE_ENABLED` d'abord.
- Maintenir deux saveurs coûte : ne l'écrire que si le refus est constaté.
