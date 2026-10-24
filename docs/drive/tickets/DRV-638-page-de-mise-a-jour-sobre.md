# DRV-638 — La page de mise à jour tient sur un écran

- **Dépôt** : front (web)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : S
- **Dépend de** : —
- **Statut** : fait (PR front #159, site v0.5.7, 2026-10-19) ; demande de l'utilisateur : « la page de mise à jour est trop bordélique, trop grosse, trop longue, on s'y perd ; on doit voir la version actuelle, un bouton pour aller directement sur le GitHub/tag et pas beaucoup plus »)
- **Lire d'abord** : `docs/drive/principes-produit.md` (règles 3 et 10), skills `repas-po`, `repas-front-dev`.

## Contexte

La page `/drive/aide` (`DriveHelpView.vue`) mélangeait installation, mise à jour, ancienne icône, désinstallation et une FAQ de dépannage : illisible pour qui veut seulement mettre à jour.

## Portée

1. **Dans l'application** : titre court, « Version installée : X.Y.Z » (donnée du pont natif), la dernière version connue seulement si elle est déjà disponible sans nouvel appel réseau, et **un seul bouton** vers la page GitHub de la release (`…/releases/tag/android-vX.Y.Z`, sinon la liste des releases).
2. **Dans un navigateur** : une phrase, le même bouton, au plus trois étapes (télécharger, ouvrir le fichier, Installer).
3. Le dépannage retiré de la page (Play Protect, installation bloquée, désinstallation…) est conservé tel quel dans `android/README.md` (« Dépannage de l'installation »).

## Hors portée

Télécharger et installer depuis l'application (DRV-639), bloquer une application trop ancienne (DRV-640).

## Critères d'acceptation

- Étant donné l'application en 0.1.10, la page montre la version et un bouton, rien d'autre.
- Étant donné un navigateur, la page tient en une phrase, un bouton et trois étapes.
- Aucun texte de peur ; thème clair et sombre ; cibles ≥ 44 px.

## Tests exigés

Vitest de la vue et de `appInstall.ts` ; e2e Playwright ; capture à 360 px lue ; `voice-and-glossary.spec.ts` vert.

## Definition of Done

Celle du dépôt front. Part avec une release du site.

## Pièges connus

- Ne pas toucher à l'agent ni à `android/agent.js.sha384` (web seulement).

## Suite

La page d'aide est devenue « un bouton qui fait tout » avec la page publique `/installer` (DRV-639, PR front #191).
