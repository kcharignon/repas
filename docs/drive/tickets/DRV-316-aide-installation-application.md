# DRV-316 — Aide et installation de l'application dans Repas

- **Dépôt** : front (`kcharignon/repas-front`)
- **Jalon** : M3b Application Android
- **Taille** : S
- **Dépend de** : DRV-315, DRV-402
- **Statut** : fait (PR [repas-front#101](https://github.com/kcharignon/repas-front/pull/101), 2026-10-18)
- **Lire d'abord** : [`../pivot-application-android.md`](../pivot-application-android.md), [`../spikes/DRV-005-decision-g1.md`](../spikes/DRV-005-decision-g1.md), [`../spikes/DRV-011-rapport.md`](../spikes/DRV-011-rapport.md), `drive-agent/android-lab/` (le spike, `PROCEDURE.md` compris), `CLAUDE.md` du front, DRV-403, DRV-408

## Contexte

Ce ticket **remplace DRV-408** (aide du favori) : l'utilisateur et ses proches doivent pouvoir installer l'application Android depuis Repas, sans aide extérieure.

## Portée

1. Page `/drive/aide` en français simple : installer l'application (lien de la dernière release, autoriser l'installation depuis le navigateur, installer), se connecter à Leclerc la première fois, lancer un envoi, mises à jour, désinstaller ; dépannage (installation bloquée, Play Protect, connexion, « Leclerc a changé » → mode assisté).
2. Dans les réglages du drive (DRV-403) : état « Application détectée ou non » (si Repas ne peut pas le savoir de façon fiable, un bouton « J'ai installé l'application »), lien vers l'aide et vers la dernière version.
3. Textes dans `src/i18n/fr.ts`, aucune image externe, jetons de couleur du front, accessibilité (cibles de 44 px, contrastes).
4. Le mode assisté (liens de recherche) reste accessible depuis la même page quand l'application n'est pas installée.

## Hors portée

La publication (DRV-315).

## Critères d'acceptation

- Un proche qui ne connaît pas le projet peut installer l'application en suivant la page (relu par l'utilisateur).
- Tests Vitest et e2e Pixel 7 de la page ; test visuel Chromium (captures lues).

## Definition of Done

Celle du dépôt front (`make check`, e2e, couverture 80 %) ; PR concise ; fusion si CI verte.

## RÉVISION du 2026-10-18 (application Repas complète, D28)

L'application installée est **toute** l'application Repas (pas un compagnon du drive) : la page explique comment l'installer et la mettre à jour pour tout usage (recettes, listes, drive), et signale que la PWA actuelle peut être désinstallée une fois l'application en place.

## Réalisation (2026-10-18, PR repas-front#101)

Page `/drive/aide` (`DriveHelpView`) : installer l'application, retirer l'ancienne icône PWA, première connexion à Leclerc, lancer un envoi, mettre à jour, désinstaller, dépannage repliable, mode assisté (hors application), note de vie privée complète (ancre `#vie-privee`), version de l'application quand elle répond. Constante `APP_RELEASES_URL` dans `src/features/drive/appInstall.ts` (à faire viser la bonne page quand DRV-315 publiera ; l'aide suppose des étiquettes `android-…`). Entrée « Installer l'application Android » dans « Mon compte ». À essayer sur un vrai téléphone : noms des écrans Android, Play Protect (« Installer quand même »).
