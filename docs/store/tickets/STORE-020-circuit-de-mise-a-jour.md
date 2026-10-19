# STORE-020 — Circuit de mise à jour : aide, lien Google Play, APK GitHub, `version.json`

- **Dépôt** : front (`src/features/drive/appInstall.ts`, `AppHelpLink.vue`, `src/i18n/fr.ts` clés `driveHelp.*`, tests de glossaire, `android/PUBLICATION.md`, `android/README.md`, `.github/workflows/android-release.yml` pour le retrait de l'APK) ; l'assistant met à jour `docs/drive/` du back après la fusion
- **Jalon** : M5 Transition et suites
- **Taille** : S
- **Dépend de** : STORE-016 (le lien d'inscription au test existe) ; sa **fin** dépend de STORE-019 (adresse publique de la fiche)
- **Faisable sans téléphone ni compte Play** : oui (code) ; le lien final vient de la console
- **Bloquant pour AUTH-002 / la connexion Google** : non
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) § 8 ; `src/features/drive/appInstall.ts`, `AppHelpLink.vue`, `src/i18n/fr.ts` (`driveHelp.*`) ; `android/PUBLICATION.md` (étape 8 et la note sur `version.json`) ; skill `repas-po` (glossaire : « Mettre à jour l'application » vs « Installer »).

## Contexte

Aujourd'hui « Moi » > Application Android mène à la page des versions GitHub (`APP_RELEASES_URL`) et à une aide qui dit de télécharger un `.apk`, d'autoriser Chrome, de passer l'avertissement de Play Protect. Avec Google Play, tout cela devient « ouvre Google Play ». `version.json` n'est lu par aucun code.

## Portée

1. **Lien** : `APP_RELEASES_URL` devient l'adresse publique de la fiche (`https://play.google.com/store/apps/details?id=eu.kantin.repas.drive`) ; **avant la publication**, le lien d'inscription au test interne (saisi par le propriétaire dans une constante de configuration, jamais dans les tests). Le libellé du bouton de l'aide : « Ouvrir Google Play ».
2. **Aide** (`driveHelp.*`, au tutoiement, glossaire) : rubrique « Installer l'application » en trois lignes (« Ouvre Google Play. Cherche Repas. Touche Installer. ») ; rubrique « Mettre à jour l'application » : « Google Play met l'application à jour tout seul. Pour la mettre à jour tout de suite, ouvre Google Play, cherche Repas, puis touche Mettre à jour. » ; suppression des rubriques « Autoriser Chrome » et « Play Protect » ; la page garde une rubrique « Sans Google Play » seulement tant que l'APK GitHub existe.
3. **Retrait progressif de l'APK GitHub** (décision D3) : deux versions après le passage de tous les téléphones de la famille par Google Play, l'étiquette ne publie plus l'APK : le workflow produit l'AAB seul ; `version.json` n'est plus produit ; `PUBLICATION.md` garde la section « Google Play » et archive la procédure APK ; la clé de publication n'est plus qu'une sauvegarde (mais reste, avec ses sauvegardes testées).
4. Tests : le glossaire de DRV-621 reste vert ; le test de `publication.spec.ts` est mis à jour (release sans APK le jour du retrait).

## Hors portée

Une vérification de version dans l'application (inutile : Google Play met à jour tout seul) ; les mises à jour « en cours d'utilisation » de l'API de Play ; l'ajout de l'installateur au pont (`app.info`).

## Critères d'acceptation

- Étant donné un téléphone Android sans l'application, quand l'utilisateur ouvre « Moi » > Application Android, alors un bouton « Ouvrir Google Play » mène à la fiche (ou au lien d'inscription tant que la fiche n'est pas publique) et l'aide tient en trois lignes.
- Étant donné l'application déjà installée, quand l'utilisateur ouvre la rubrique « Mettre à jour », alors le texte dit que Google Play s'en charge, sans parler de fichier ni d'avertissement.
- Étant donné le jour du retrait, alors la release d'une étiquette ne contient plus d'APK et le workflow reste vert.

## Tests exigés

Vitest (textes, longueur des phrases, glossaire, lien) ; Playwright (parcours de l'aide, capture à 360 px **lue**) ; `publication.spec.ts` ; `make check`.

## Definition of Done

Celle du dépôt front ; `CLAUDE.md` du front mis à jour ; description de PR avec les touches avant / après (installer : moins de gestes qu’avec le fichier `.apk`) ; l'assistant met à jour `docs/drive/recette.md` et `pivot-application-android.md` (mention « distribution par Google Play »).

## Pièges connus

- **Ne pas retirer l'APK avant** que tous les téléphones de la famille soient passés par Play : celui qui ne l'a pas fait n'aurait plus de mise à jour.
- L'adresse du test interne est personnelle à chaque testeur (lien d'inscription) : elle ne doit pas être publique ; avant la publication, le lien est donné de la main à la main, pas dans le code du site.
- « Installer » et « Mettre à jour » sont deux mots du glossaire : ne pas les mélanger.
- Un téléphone sans services Google Play (rare) ne pourra pas utiliser cette voie : l'APK de secours reste dans l'historique des releases.
